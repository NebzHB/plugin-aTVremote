<?php

/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

/* * ***************************Includes**********************************/
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class aTVremote extends eqLogic {
	/***************************Attributs*******************************/	
	public static function cron($_eqlogic_id = null) {
		$eqLogics = ($_eqlogic_id !== null) ? array(eqLogic::byId($_eqlogic_id)) : eqLogic::byType('aTVremote', true);
		foreach ($eqLogics as $eqLogic) {
			try {
				if(is_object($eqLogic)) {
					if($eqLogic->getConfiguration('version','') == '3'){
						$play_state = $eqLogic->getCmd(null, 'play_state');
						if(is_object($play_state)) {
							$val=$play_state->getCache('value');
							if($val) {
								$eqLogic->setaTVremoteInfo();
							}
						}
					} else {
						$play_state = $eqLogic->getCmd(null, 'play_state');
						if(is_object($play_state)) {
							$val=$play_state->getCache('value');
							if($val) { // if playing : 1min
								$eqLogic->aTVdaemonExecute('volume');
								if($eqLogic->getConfiguration('device','') != 'HomePod') {
									$eqLogic->aTVdaemonExecute('features');
								}
							} else { // else : 5min
								$c = new Cron\CronExpression(checkAndFixCron('*/5 * * * *'), new Cron\FieldFactory);
								if ($c->isDue()) {
									$eqLogic->aTVdaemonExecute('volume');
									if($eqLogic->getConfiguration('device','') != 'HomePod') {
										$eqLogic->aTVdaemonExecute('features');
									}
								}
							}
						}
						if($eqLogic->getConfiguration('device','') != 'HomePod') {
							$nc = new Cron\CronExpression(checkAndFixCron('*/5 * * * *'), new Cron\FieldFactory);
							if ($nc->isDue()) {
								$eqLogic->aTVdaemonExecute('power_state');
							}
						}
					}
				}
			} catch (Exception $e) {
				log::add('aTVremote','error',json_encode($e));
			}
		}
	}	  
	public static function cronDaily() {
		// delete all artwork older than 30 days 
		$rel_folder='plugins/aTVremote/core/img/';
		$abs_folder=dirname(__FILE__).'/../../../../'.$rel_folder;
		exec(system::getCmdSudo()."find ".$abs_folder." -name *.jpg -mtime +30 -delete;");
	}
	
	public static function getaTVremote($withSudo=false,$realpath=false) {
		$cmd=(($withSudo)?system::getCmdSudo():''). (($realpath)?realpath(dirname(__FILE__) . '/../../resources/atvremote/bin/atvremote'):dirname(__FILE__) . '/../../resources/atvremote/bin/atvremote');
		return $cmd;
	}
	public static function getaTVscript($withSudo=false,$realpath=false) {
		$cmd=(($withSudo)?system::getCmdSudo():''). (($realpath)?realpath(dirname(__FILE__) . '/../../resources/atvremote/bin/atvscript'):dirname(__FILE__) . '/../../resources/atvremote/bin/atvscript');
		return $cmd;
	}
	
	public static function dependancy_info() {
		$return = array();
		$return['progress_file'] = jeedom::getTmpFolder('aTVremote') . '/dependance';
		$return['state'] = 'nok';

		$path=aTVremote::getaTVremote();
		if (file_exists($path)) {
			$return['state'] = 'ok';
		}
		return $return;
	}

	public static function dependancy_install() {
		//$dep_info = self::dependancy_info();
		log::remove(__CLASS__ . '_dep');
		$update=update::byTypeAndLogicalId('plugin',__CLASS__);
		$ver=$update->getLocalVersion();
		$conf=$update->getConfiguration();
		shell_exec('echo "'."== Jeedom ".jeedom::version()." sur ".trim(shell_exec("lsb_release -d -s")).'/'.trim(shell_exec('dpkg --print-architecture')).'/'.trim(shell_exec('arch')).'/'.trim(shell_exec('getconf LONG_BIT'))."bits aka '".jeedom::getHardwareName()."' avec nodeJS ".trim(shell_exec('node -v'))." et jsonrpc:".config::byKey('api::core::jsonrpc::mode', 'core', 'enable')." et ".__CLASS__." (".$conf['version'].") ".$ver." (avant:".config::byKey('previousVersion',__CLASS__,'inconnu',true).')" >> '.log::getPathToLog(__CLASS__ . '_dep'));
		return array('script' => dirname(__FILE__) . '/../../resources/install_#stype#.sh ' . jeedom::getTmpFolder(__CLASS__) . '/dependance', 'log' => log::getPathToLog(__CLASS__ . '_dep'));
	}

	public static function deamon_info() {
		$return = array();
		$return['log'] = 'aTVremote_deamon';
		$return['state'] = 'nok';
		$pid = trim( shell_exec ('ps ax | grep "resources/aTVremoted.js" | grep -v "grep" | wc -l') );
		if ($pid != '' && $pid != '0') {
			$return['state'] = 'ok';
		}
		$return['launchable'] = 'ok';
		return $return;
	}
	
	public static function getFreePort() {
		$freePortFound = false;
		while (!$freePortFound) {
			$port = mt_rand(1024, 65535);
			exec('sudo fuser '.$port.'/tcp',$out,$return);
			if ($return==1) {
				$freePortFound = true;
			}
		}
		config::save('socketport',$port,'aTVremote');
		return $port;
	}

	public static function deamon_start() {
		self::deamon_stop();

		$deamon_info = self::deamon_info();
		if ($deamon_info['launchable'] != 'ok') {
			throw new Exception(__("Veuillez vérifier la configuration", __FILE__));
		}
		log::add('aTVremote', 'info', "Lancement du démon aTVremote");
		$socketport = self::getFreePort();
		$url  = network::getNetworkAccess('internal').'/core/api/jeeApi.php' ;

		$logLevel = log::convertLogLevel(log::getLogLevel('aTVremote'));
		$deamonPath = realpath(dirname(__FILE__) . '/../../resources');
		
		$eqLogics = eqLogic::byType('aTVremote');
		$arrATV3=[];
		$arrATV4=[];
		$arrHP=[];
		foreach ($eqLogics as $eqLogic) {
			if($eqLogic->getIsEnable() == '1') {
				if($eqLogic->getConfiguration('device','0') == 'Apple TV' && $eqLogic->getConfiguration('pairingKeyAirplay','0') != '0') {
					if($eqLogic->getConfiguration('version','0') == '3') {
						array_push($arrATV3,$eqLogic->getConfiguration('mac',''));
					} else {
						array_push($arrATV4,$eqLogic->getConfiguration('mac',''));
					}
				} elseif($eqLogic->getConfiguration('device','0') == 'HomePod') {
					array_push($arrHP,$eqLogic->getConfiguration('mac',''));
				}
			}
		}
		if(count($arrATV3) == 0) {
			$arrATV3="None";
		} else {
			$arrATV3=join(',',$arrATV3);
		}
		if(count($arrATV4) == 0) {
			$arrATV4="None";
		} else {
			$arrATV4=join(',',$arrATV4);
		}
		if(count($arrHP) == 0) {
			$arrHP="None";
		} else {
			$arrHP=join(',',$arrHP);
		}
		
		$cmd = 'nice -n 19 node ' . $deamonPath . '/aTVremoted.js ' . $url . ' ' . jeedom::getApiKey('aTVremote') .' '. $socketport . ' ' . $logLevel . ' ' . $arrATV3 . ' ' . $arrATV4 . ' ' . $arrHP;

		log::add('aTVremote', 'debug', "Lancement démon aTVremote : " . $cmd);

		$result = exec('NODE_ENV=production nohup ' . $cmd . ' >> ' . log::getPathToLog('aTVremote_deamon') . ' 2>&1 &');
		if (strpos(strtolower($result), 'error') !== false || strpos(strtolower($result), 'traceback') !== false) {
			log::add('aTVremote', 'error', $result);
			return false;
		}

		$i = 0;
		while ($i < 30) {
			$deamon_info = self::deamon_info();
			if ($deamon_info['state'] == 'ok') break;
			sleep(1);
			$i++;
		}
		if ($i >= 30) {
			log::add('aTVremote', 'error', "Impossible de lancer le démon aTVremote, relancer le démon en debug et vérifiez le log", 'unableStartDeamon');
			return false;
		}
		message::removeAll('aTVremote', 'unableStartDeamon');
		log::add('aTVremote', 'info', "Démon aTVremote lancé");
		return true;

	}

	public static function deamon_stop() {
		$deamon_info = self::deamon_info();
		if ($deamon_info['state'] == 'ok') {
			@file_get_contents("http://" . config::byKey('internalAddr') . ":".config::byKey('socketport', 'aTVremote')."/stop");
			sleep(3);
		}
		
		if(shell_exec('ps aux | grep "resources/aTVremoted.js" | grep -v "grep" | wc -l') == '1') {
			exec('sudo kill $(ps aux | grep "resources/aTVremoted.js" | grep -v "grep" | awk \'{print $2}\') >/dev/null 2>&1');
		}
		log::add('aTVremote', 'info', "Arrêt du démon aTVremote");
		$deamon_info = self::deamon_info();
		if ($deamon_info['state'] == 'ok') {
			sleep(1);
			exec('sudo kill -9 $(ps aux | grep "resources/aTVremoted.js" | grep -v "grep" | awk \'{print $2}\') >/dev/null 2>&1');
		}
		$deamon_info = self::deamon_info();
		if ($deamon_info['state'] == 'ok') {
			sleep(1);
			exec('sudo kill -9 $(ps aux | grep "resources/aTVremoted.js" | grep -v "grep" | awk \'{print $2}\') >/dev/null 2>&1');
		}
	}	

	public static function reinstallNodeJS() { // Reinstall NODEJS from scratch (to use if there is errors in dependancy install)
		$pluginaTVremote = plugin::byId('aTVremote');
		log::add('aTVremote', 'info', "Suppression du Code NodeJS");
		$cmd = system::getCmdSudo() . 'rm -rf ' . dirname(__FILE__) . '/../../resources/node_modules >/dev/null 2>&1';
		exec($cmd);
		log::add('aTVremote', 'info', "Suppression de NodeJS");
		$cmd = system::getCmdSudo() . 'apt-get -y --purge autoremove npm';
		exec($cmd);
		$cmd = system::getCmdSudo() . 'apt-get -y --purge autoremove nodejs';
		exec($cmd);
		log::add('aTVremote', 'info', "Réinstallation des dependances");
		$pluginaTVremote->dependancy_install();
		return true;
	}

	public static function event() {
		$eventType = init('eventType');
		$changed=false;
		
		$eqLogic = aTVremote::byLogicalId(init('mac'), 'aTVremote');
		if(!is_object($eqLogic)) {
			log::add('aTVremote','debug',"Reçu un évenement pour ".init('mac')." mais il n'existe pas !");
			return;
		}
		
		log::add('aTVremote', 'debug', "Passage dans la fonction event " . $eventType . " pour ".$eqLogic->getName());
		if ($eventType == 'error'){
			log::add('aTVremote', 'error', init('description'));
			return;
		}
		if(init('data')) {
			log::add('aTVremote','debug',"Reçu du démon :".init('data'));
		}
		switch ($eventType)
		{
			case 'playing':
				$eqLogic->setaTVremoteInfo(json_decode(init('data'),true));
			break;
			case 'powerstate':
				$changed=$eqLogic->setPowerstate(init('data')) || $changed;
			break;
			case 'app':
				$apps = explode(', App: ',init('data'));
				$apps[0]=str_replace('App: ','',$apps[0]);
				
				$AppList=[];
				foreach($apps as $app) {
					$lib = explode(' (',str_replace(')','',$app));
					array_push($AppList,$lib[1].'|'.$lib[0]);
				}
				$AppList=join(';',$AppList);	

				$launch_app = $eqLogic->getCmd(null, 'launch_app');
				if (is_object($launch_app)) {
					$launch_app->setConfiguration('listValue', $AppList);
					$launch_app->save();
				}
			break;
			case 'volume':
				$volume = $eqLogic->getCmd(null, 'volume');
				if (is_object($volume)) {
					$changed=$eqLogic->checkAndUpdateCmd($volume, explode('.',init('data'))[0]) || $changed;
					if(!$changed) $volume->event(explode('.',init('data'))[0]);
				}
			break;
			case 'reaskArtwork':
				if($eqLogic->getConfiguration('version',0) != '3') {
					$app=$eqLogic->getCmd(null, 'app');
					if($app->getCache('value') != 'com.apple.TVAirPlay' && $app->getCache('value') != 'com.apple.tvairplayd') {
						$hash = $eqLogic->getCmd(null, 'hash');
						if(is_object($hash)) {
							if(! $eqLogic->setArtwork($hash->getCache('value'))) {
								$artwork = $eqLogic->getImage(true);
								$artwork_url = $eqLogic->getCmd(null, 'artwork_url');
								if(is_object($artwork_url)) {
									$changed=$eqLogic->checkAndUpdateCmd($artwork_url, $artwork) || $changed;
								}
							} else {
								$changed=true;
							}
						}
					} else {
						log::add('aTVremote','debug',"Pas de reask si airplay");
					}
				} else {
					log::add('aTVremote','debug',"Pas de reask sur atv3");
				}
			break;
			case 'features':
				$features=json_decode(init('data'),true);
				if($features) {
					$eqLogic->setConfiguration('features',$features);
					$eqLogic->save(true);
				}
			break;
		}
		/*if($changed) {
			$eqLogic->refreshWidget();
		}*/
	}
// OLDSCAN
    public static function discover($_mode) {
		log::add('aTVremote','info','============================');
		log::add('aTVremote','info',"Scan en cours...");
        	$output=shell_exec(aTVremote::getaTVremote(true,true)." scan");
		log::add('aTVremote','debug',"Résultat brut : ".$output);

		if($output) {
			$return = [];
			//$toMatch = '#Name: (.*)\s* Model/SW: (.*)\s* Address: (.*)\s* MAC: (.*)\s*#';
			$toMatch='#Name: (.*)\s*Model/SW: (.*)\s*Address: (.*)\s*MAC: (.*)\s*Deep Sleep: (.*)\s*Identifiers:\s[^S]*Services:\n';
			for($p=0;$p<4;$p++) {
				$toMatch.='(?: - Protocol: ([^,]*), Port: ([^,]*), Credentials: ([^,]*), Requires Password: ([^,]*), Password: ([^,]*), Pairing: ([^\n]*)\n)?';
			}
			$toMatch.='#';



			if(preg_match_all($toMatch, $output, $matches,PREG_SET_ORDER)) {
				foreach($matches as $device) {
					log::add('aTVremote','info','****************************');
					if(log::convertLogLevel(log::getLogLevel('aTVremote')) == 'debug') {
						$tempo=array_shift($device);
						log::add('aTVremote','debug',"PREG BRUT:".json_encode($device,JSON_UNESCAPED_UNICODE));
						array_unshift($device,$tempo);
					}
					
					if($device[4] == "None") {
						log::add('aTVremote','info',"Pas de MAC : on ignore ".$device[1]);
						continue;
					}
					if( (isset($device[6]) && $device[6] == 'AirPlay' && isset($device[9]) && $device[9] == "True") ||
					    (isset($device[12]) && $device[12] == 'AirPlay' && isset($device[15]) && $device[15] == "True") ||
					    (isset($device[18]) && $device[18] == 'AirPlay' && isset($device[21]) && $device[21] == "True") ||
					    (isset($device[24]) && $device[24] == 'AirPlay' && isset($device[27]) && $device[27] == "True") ) {
						log::add('aTVremote','info',"AppleTV avec mot de passe AirPlay : on ignore ".$device[1]);
						continue;    
					}
					if(strpos($device[2],'Apple TV') === false && strpos($device[2],'AppleTV') === false && strpos($device[2],'HomePod') === false && strpos($device[2],'AudioAccessory6,1') === false) {
						log::add('aTVremote','info',"Modèle non supporté ".$device[2]." : on ignore ".$device[1]);
						continue;
					}
					
					$res = [];
					$res["name"]=$device[1];
					$res["model"]=$device[2];
					$res["ip"]=$device[3];
					$res["mac"]=$device[4];

					
					log::add('aTVremote','info','Name :'.$res["name"]);
					log::add('aTVremote','info','Model/SW :'.$res["model"]);
					log::add('aTVremote','info','Address :'.$res["ip"]);
					log::add('aTVremote','info','MAC :'.$res["mac"]);
					
					
					$modElmt=explode(', ',$res['model']);
					
					if(strpos($res['model'],'Apple TV') !== false) {
						$res['device']="Apple TV";
						$res['version']=str_replace('Apple TV ','',$modElmt[0]);
					} elseif(strpos($res['model'],'HomePod') !== false || strpos($res['model'],'AudioAccessory6,1') !== false) {
						$res['device']="HomePod";
						if(strpos($res['model'],'Mini') !== false) {
							$res['version']="Mini";
						} elseif(strpos($res['model'],'AudioAccessory6,1') !== false) {
							$res['version']="2";
						} else {
							$res['version']="Original";
						}
					}
					
					$subModElmt=explode(' ',$modElmt[1]);
					$res['os']=$subModElmt[0];
					if($res['os'] == 'tvOS') {$res['os']='TvOS';}
					if(strpos($res['model'],'AudioAccessory6,1') !== false) {
						$res['os']='TvOS';
					}
					if($subModElmt[0] == 'tvOS' && !isset($subModElmt[1])) {
						log::add('aTVremote','info',"Pas une vraie AppleTV3: on Ignore ".$res['model']);
						continue;
					} elseif($subModElmt[1] == 'SW') {
						$res['osVersion']=$subModElmt[2];
					} else {
						$res['osVersion']=$subModElmt[1];
					}
					
					$wasExisting = aTVremote::byLogicalId($res["mac"], 'aTVremote');
					if (!is_object($wasExisting)) {
						$eqLogic = new aTVremote();
						$eqLogic->setName($res["name"].(($res['device']=="HomePod")?' (Airplay)':''));
						$eqLogic->setIsEnable(0);
						$eqLogic->setIsVisible(0);
						$eqLogic->setLogicalId($res["mac"]);
						$eqLogic->setEqType_name('aTVremote');
					} else $eqLogic = $wasExisting;
					
					if( (isset($device[6]) && $device[6] == 'AirPlay' && isset($device[11]) && $device[11] == "Mandatory") ||
					    (isset($device[12]) && $device[12] == 'AirPlay' && isset($device[17]) && $device[17] == "Mandatory") ||
					    (isset($device[18]) && $device[18] == 'AirPlay' && isset($device[23]) && $device[23] == "Mandatory") ||
					    (isset($device[24]) && $device[24] == 'AirPlay' && isset($device[29]) && $device[29] == "Mandatory") ) {
						log::add('aTVremote','info',"Appairage AirPlay obligatoire !");
						$eqLogic->setConfiguration('needAirplayPairing','1'); 
					} elseif((isset($device[6]) && $device[6] == 'AirPlay' && isset($device[11]) && $device[11] == "NotNeeded") ||
					    (isset($device[12]) && $device[12] == 'AirPlay' && isset($device[17]) && $device[17] == "NotNeeded") ||
					    (isset($device[18]) && $device[18] == 'AirPlay' && isset($device[23]) && $device[23] == "NotNeeded") ||
					    (isset($device[24]) && $device[24] == 'AirPlay' && isset($device[29]) && $device[29] == "NotNeeded") ) {
						log::add('aTVremote','info',"Appairage AirPlay pas nécessaire");
						$eqLogic->setConfiguration('needAirplayPairing','0'); 
					} elseif((isset($device[6]) && $device[6] == 'AirPlay' && isset($device[11]) && $device[11] == "Unsupported") ||
					    (isset($device[12]) && $device[12] == 'AirPlay' && isset($device[17]) && $device[17] == "Unsupported") ||
					    (isset($device[18]) && $device[18] == 'AirPlay' && isset($device[23]) && $device[23] == "Unsupported") ||
					    (isset($device[24]) && $device[24] == 'AirPlay' && isset($device[29]) && $device[29] == "Unsupported") ) {
						log::add('aTVremote','info',"Appairage AirPlay non supporté");
						$eqLogic->setConfiguration('needAirplayPairing','0'); 
					} elseif((isset($device[6]) && $device[6] == 'AirPlay' && isset($device[11]) && $device[11] == "Disabled") ||
					    (isset($device[12]) && $device[12] == 'AirPlay' && isset($device[17]) && $device[17] == "Disabled") ||
					    (isset($device[18]) && $device[18] == 'AirPlay' && isset($device[23]) && $device[23] == "Disabled") ||
					    (isset($device[24]) && $device[24] == 'AirPlay' && isset($device[29]) && $device[29] == "Disabled") ) {
						log::add('aTVremote','warning',"Appairage AirPlay désactivé, Réglages > Airplay > Airplay -> Oui");
						$eqLogic->setConfiguration('needAirplayPairing','0'); 
					} else {
						log::add('aTVremote','info',"Appairage AirPlay inconnu");
						$eqLogic->setConfiguration('needAirplayPairing','0'); 
					}
					if($res['device']=="Apple TV" && $res['version'] == 3) {
						log::add('aTVremote','info',"Appairage AirPlay obligatoire ! (car Apple TV 3)");
						$eqLogic->setConfiguration('needAirplayPairing','1'); 
					}
					
					if( (isset($device[6]) && $device[6] == 'Companion' && isset($device[11]) && $device[11] == "Mandatory") ||
					    (isset($device[12]) && $device[12] == 'Companion' && isset($device[17]) && $device[17] == "Mandatory") ||
					    (isset($device[18]) && $device[18] == 'Companion' && isset($device[23]) && $device[23] == "Mandatory") ||
					    (isset($device[24]) && $device[24] == 'Companion' && isset($device[29]) && $device[29] == "Mandatory") ) {
						log::add('aTVremote','info',"Appairage Companion obligatoire !");
						$eqLogic->setConfiguration('needCompanionPairing','1'); 
					} elseif((isset($device[6]) && $device[6] == 'Companion' && isset($device[11]) && $device[11] == "NotNeeded") ||
					    (isset($device[12]) && $device[12] == 'Companion' && isset($device[17]) && $device[17] == "NotNeeded") ||
					    (isset($device[18]) && $device[18] == 'Companion' && isset($device[23]) && $device[23] == "NotNeeded") ||
					    (isset($device[24]) && $device[24] == 'Companion' && isset($device[29]) && $device[29] == "NotNeeded") ) {
						log::add('aTVremote','info',"Appairage Companion pas nécessaire");
						$eqLogic->setConfiguration('needCompanionPairing','0'); 
					} elseif((isset($device[6]) && $device[6] == 'Companion' && isset($device[11]) && $device[11] == "Unsupported") ||
					    (isset($device[12]) && $device[12] == 'Companion' && isset($device[17]) && $device[17] == "Unsupported") ||
					    (isset($device[18]) && $device[18] == 'Companion' && isset($device[23]) && $device[23] == "Unsupported") ||
					    (isset($device[24]) && $device[24] == 'Companion' && isset($device[29]) && $device[29] == "Unsupported") ) {
						log::add('aTVremote','info',"Appairage Companion non supporté");
						$eqLogic->setConfiguration('needCompanionPairing','0'); 
					} elseif((isset($device[6]) && $device[6] == 'Companion' && isset($device[11]) && $device[11] == "Disabled") ||
					    (isset($device[12]) && $device[12] == 'Companion' && isset($device[17]) && $device[17] == "Disabled") ||
					    (isset($device[18]) && $device[18] == 'Companion' && isset($device[23]) && $device[23] == "Disabled") ||
					    (isset($device[24]) && $device[24] == 'Companion' && isset($device[29]) && $device[29] == "Disabled") ) {
						log::add('aTVremote','warning',"Appairage Companion désactivé, Réglages > Airplay > Accès -> Tout le monde");
						$eqLogic->setConfiguration('needCompanionPairing','0'); 
					} else {
						log::add('aTVremote','info',"Appairage Companion inconnu");
						$eqLogic->setConfiguration('needCompanionPairing','0');
					}
					
					$eqLogic->setConfiguration('device', $res['device']);
					$eqLogic->setConfiguration('ip', $res["ip"]);
					$eqLogic->setConfiguration('mac',$res["mac"]);
					
					$eqLogic->setConfiguration('fullModel',$res["model"]);
					$eqLogic->setConfiguration('version',$res["version"]);
					$eqLogic->setConfiguration('os',$res["os"]);
					$eqLogic->setConfiguration('osVersion',$res["osVersion"]);
					$eqLogic->getConfiguration('savingWithGui','0');

					$eqLogic->save();
					
					$result = array('id' => $eqLogic->getId(), 'name' => $res["name"], 'device' => $res['device'], 'ip' => $res["ip"], 'mac' => $res["mac"], 'fullModel' => $res["model"], 'version' => $res["version"], 'os' => $res["os"], 'osVersion' => $res["osVersion"]);
					if(!is_object($wasExisting)) { // NEW
						event::add('jeedom::alert', array(
							'level' => 'warning',
							'page' => 'aTVremote',
							'message' => __("Nouvelle AppleTV detectée " .$res["name"], __FILE__),
						));
						$result['type'] = 'newNode';
						event::add('aTVremote::scan', $result);
					} else { // UPDATED
						event::add('jeedom::alert', array(
							'level' => 'warning',
							'page' => 'aTVremote',
							'message' => __("AppleTV mise à jour avec succès " .$res["name"], __FILE__),
						));
						$result['type'] = 'updateNode';
						event::add('aTVremote::scan', $result);
					}
					$return[] = $res;
				}
			}

			log::add('aTVremote','info',"Ajouté : ".json_encode($return,JSON_UNESCAPED_UNICODE));
			log::add('aTVremote','info','============================');
		}
		return $return;
	}	
	/* public static function discover($_mode) {
		log::add('aTVremote','info','Scan en cours...');
		$output=shell_exec(aTVremote::getaTVscript(true,true)." scan");
		log::add('aTVremote','debug','Résultat brut : '.$output);
		$output=json_decode($output,true);
		if($output && $output['result'] == 'success') {
			foreach($output['devices'] as $device) {
				log::add('aTVremote','info','---------------------');

				if($device['identifier'] == "None") {
					log::add('aTVremote','info','--Ignore '.$device['name'].' -> Pas de MAC');
					continue;
				}
				if(strpos($device['device_info']['model_str'],'Apple TV') === false && strpos($device['device_info']['model_str'],'HomePod') === false) {
					log::add('aTVremote','info','--Ignore '.$device['device_info']['model_str']);
					continue;
				}

				if(strpos($device['device_info']['model_str'],'Apple TV') !== false) {
					$deviceName="Apple TV";
					$version=str_replace('Apple TV ','',$device['device_info']['model_str']);
				} elseif(strpos($device['device_info']['model_str'],'HomePod') !== false) {
					$deviceName="HomePod";
					if(strpos($device['device_info']['model_str'],'Mini') !== false) {
						$version="Mini";
					} else {
						$version="Original";
					}
				}				

				log::add('aTVremote','info','-Name :'.$device["name"]);
				log::add('aTVremote','info','-Model :'.$deviceName.' '.$version);
				log::add('aTVremote','info','-OS & Version :'.$device['device_info']['operating_system'].' '.$device['device_info']['version']);
				log::add('aTVremote','info','-Address :'.$device['address']);
				log::add('aTVremote','info','-MAC :'.$device["identifier"]);

				$wasExisting = aTVremote::byLogicalId($device["identifier"], 'aTVremote');
				if (!is_object($wasExisting)) {
					$eqLogic = new aTVremote();
					$eqLogic->setName($device["name"]);
					$eqLogic->setIsEnable(0);
					$eqLogic->setIsVisible(0);
					$eqLogic->setLogicalId($device["identifier"]);
					$eqLogic->setEqType_name('aTVremote');
					$eqLogic->setDisplay('width','138px');
					$eqLogic->setDisplay('height','500px');
				} else $eqLogic = $wasExisting;

				$eqLogic->setConfiguration('device', $deviceName);
				$eqLogic->setConfiguration('ip', $device['address']);
				$eqLogic->setConfiguration('mac',$device["identifier"]);

				$eqLogic->setConfiguration('fullModel',$device['device_info']['model_str']);
				$eqLogic->setConfiguration('version',$version);
				$eqLogic->setConfiguration('os',$device['device_info']['operating_system']);
				$eqLogic->setConfiguration('osVersion',$device['device_info']['version']);

				$eqLogic->save();

				$result = array('id' => $eqLogic->getId(), 'name' => $device["name"], 'device' => $deviceName, 'ip' => $device['address'], 'mac' => $device["identifier"], 'fullModel' => $device['device_info']['model_str'], 'version' => $version, 'os' => $device['device_info']['operating_system'], 'osVersion' => $device['device_info']['version']);
				if(!is_object($wasExisting)) { // NEW
					log::add('aTVremote','info','--'.$device["name"].'-Ajouté');
					event::add('jeedom::alert', array(
						'level' => 'warning',
						'page' => 'aTVremote',
						'message' => __('Nouvelle AppleTV detectée ' .$device["name"], __FILE__),
					));
					$result['type'] = 'newNode';
					event::add('aTVremote::scan', $result);
				} else { // UPDATED
					log::add('aTVremote','info','--'.$device["name"].'-Modifié');
					event::add('jeedom::alert', array(
						'level' => 'warning',
						'page' => 'aTVremote',
						'message' => __('AppleTV mise à jour avec succès ' .$device["name"], __FILE__),
					));
					$result['type'] = 'updateNode';
					event::add('aTVremote::scan', $result);
				}
				$return[] = $device;
			}


			log::add('aTVremote','debug','Résultat Scan Brut : '.json_encode($return));
		}
		return $return;
	}*/	
	
	public static function devicesParameters($os,$device='') {
		$path = dirname(__FILE__) . '/../config/devices/' . $os;

		if (!is_dir($path)) {
			return false;
		}
		try {
			$file = $path . '/' . $os.(($device)?'-'.$device:'').'.json';
			$content = file_get_contents($file);
			if($content) {
				$content=translate::exec($content,realpath($file));
			}
			$return = json_decode($content, true);
		} catch (Exception $e) {
			return false;
		}
		
        	return $return;
	}
	
	public function aTVremoteExecute($cmd,$runindir=null) {
		if($cmd) {
			$mac = $this->getConfiguration('mac','');
	
			$cmdToExec = "";
			if($runindir) $cmdToExec.='runindir() { (cd "$1" && shift && eval "$@"); };runindir '.$runindir.' ';
			
			$cmdToExec .= aTVremote::getaTVremote(true,true)." -i $mac --protocol airplay --airplay-credentials ".$this->getConfiguration('pairingKeyAirplay')." $cmd";
			$lastoutput=exec($cmdToExec,$return,$val_ret);
			if($val_ret)
				log::add('aTVremote','debug','ret:'.$val_ret.' -- '.$lastoutput.' -- '.json_encode($return).' -- '.$cmdToExec);

			return $return;
		}
	}
	public function aTVdaemonExecute($cmd,$params=[]) {
		if($cmd) {
			$deamon_info = aTVremote::deamon_info();
			if ($deamon_info['state'] == 'ok') {
				$mac = $this->getConfiguration('mac','');

				$url="http://" . config::byKey('internalAddr') . ":".config::byKey('socketport', 'aTVremote')."/cmd?cmd=";
				$url.=urlencode($cmd).'&mac='.$mac.((count($params))?"&".http_build_query($params):'');
				$json = @file_get_contents($url);
				if($json === false) log::add('aTVremote','error',"Problème de communication avec le démon : ".$url);
				log::add('aTVremote','debug',ucfirst($cmd).' brut : '.$json);
				return json_decode($json, true);
			} else {
				log::add('aTVremote','info',"Démon pas démarré, impossible de lui envoyer la commande ".ucfirst($cmd));
			}
		}
	}
	public function aTVdaemonConnectATV($params=[]) {
		$deamon_info = aTVremote::deamon_info();
		if ($deamon_info['state'] == 'ok') {
			$mac = $this->getConfiguration('mac','');
			$version = $this->getConfiguration('version',0);

			$url="http://" . config::byKey('internalAddr') . ":".config::byKey('socketport', 'aTVremote')."/connect?";
			$url.='mac='.$mac.'&version='.urlencode($version).((count($params))?"&".http_build_query($params):'');
			$json = @file_get_contents($url);
			if($json === false) log::add('aTVremote','error',"Problème de communication avec le démon : ".$url);
			log::add('aTVremote','debug',"Connect brut : ".$json);
			return json_decode($json, true);
		} else {
			log::add('aTVremote','info',"Démon pas démarré, impossible de lui envoyer la commande Connect");
		}
	}
	public function aTVdaemonDisconnectATV($params=[]) {
		$deamon_info = aTVremote::deamon_info();
		if ($deamon_info['state'] == 'ok') {
			$mac = $this->getConfiguration('mac','');

			$url="http://" . config::byKey('internalAddr') . ":".config::byKey('socketport', 'aTVremote')."/disconnect?";
			$url.='mac='.$mac.((count($params))?"&".http_build_query($params):'');
			$json = @file_get_contents($url);
			if($json === false) log::add('aTVremote','error',"Problème de communication avec le démon : ".$url);
			log::add('aTVremote','debug',"Disconnect brut : ".$json);
			return json_decode($json, true);
		} else {
			log::add('aTVremote','info',"Démon pas démarré, impossible de lui envoyer la commande Disconnect");
		}
	}

	public function setPowerstate($data=null) {	
		$changed = false;
		if($this->getConfiguration('version',0) != '3'){
			if($data && is_string($data)) {
				if($data[0] == "{") {
					$power_state=json_decode($data,true);
				} else {
					$power_state=[];
					$power_state['power_state']=strtolower(str_replace('PowerState.','',$data));
				}
			} elseif(is_object($data)) {
				$power_state=$data;
			}

			log::add('aTVremote','debug','power_state : '.$power_state['power_state']);
			
			if($power_state['power_state']=="off"){
				$power = $this->getCmd(null, 'power_state');
				$changed=$this->checkAndUpdateCmd($power, '0') || $changed;
			} else {
				$power = $this->getCmd(null, 'power_state');
				$changed=$this->checkAndUpdateCmd($power, '1') || $changed;
			}
		}
		return $changed;
	}

	public function setArtwork($hash) {
		$hash=md5($hash);
		
		$id=$this->getLogicalId();
		$id="artworks";
      
		$NEWheight=-1;
		$NEWwidth=138;
		
		$rel_folder = 'plugins/aTVremote/core/img/';
		$abs_folder = realpath(dirname(__FILE__).'/../../../../'.$rel_folder).'/';
		$artwork = $rel_folder.$id.'/'.$hash.'.jpg';
		$src =	   $abs_folder.$id.'/'.'artwork.png';
		$dest =    $abs_folder.$id.'/'.$hash.'.jpg';
	
		if (!file_exists($abs_folder.$id.'/')) {
			exec("cd $abs_folder && sudo mkdir $id && sudo chown www-data:www-data ".$abs_folder.$id."/ >/dev/null 2>&1;sudo chmod 775 ".$abs_folder.$id."/ >/dev/null 2>&1");
			log::add('aTVremote','debug',"Création répertoire pour ".$id);
		}

		if(!file_exists($dest)) {
			sleep(1);
			if(file_exists($src)) {
				log::add('aTVremote','debug',"--src already exists, remove it...".$src);
				exec("sudo rm -f $src >/dev/null 2>&1");
			}
			
			$this->aTVdaemonExecute('artwork_save='.$NEWwidth.','.$NEWheight);// create artwork.png
			
			$t=1;
			while(!file_exists($src) && $t < 16) {
				log::add('aTVremote','debug',"Pas encore de artwork.png, on attend...".$t.'/15');
				usleep(0.333*1000000);
				//sleep(1);
				$t++;
			}
			if($t == 16) {
				log::add('aTVremote','debug',"Pas de artwork.png !");
				return false;
			} else {
				log::add('aTVremote','debug',"Artwork trouvé !");
			}
			
			exec("sudo chown www-data:www-data $src >/dev/null 2>&1;sudo chmod 775 $src >/dev/null 2>&1"); // force rights
			rename($src,$dest);
			exec("sudo chown www-data:www-data $dest >/dev/null 2>&1;sudo chmod 775 $dest >/dev/null 2>&1"); // force rights
		} else {
			log::add('aTVremote','debug',"--Artwork déjà dans le cache, on l'utilise :".$dest);
			touch($dest); // update mtime
			exec("sudo rm -f $src >/dev/null 2>&1");
		}
		
		$artwork_url = $this->getCmd(null, 'artwork_url');
		if(is_object($artwork_url)) {
			log::add('aTVremote','debug',"--MàJ de la commande avec :".$artwork.'...');
			$this->checkAndUpdateCmd($artwork_url, $artwork);
			return true;
		}
		
		return false;
	}

	public function setaTVremoteInfo($aTVremoteinfo=null) {
      		try {
			if($aTVremoteinfo == null && $this->getConfiguration('version',0) == '3') { // is aTV3, fetch info
				$playing=$this->aTVremoteExecute('playing');
				foreach($playing as $line) {
					$elmt=explode(': ',$line);
					$info = trim($elmt[0]);
					if(count($elmt) > 2) {
						array_shift($elmt);
						$value= trim(join(': ',$elmt));
					} elseif(count($elmt) == 2){
						$value= trim($elmt[1]);
					}
					$info=str_replace(' ','_',strtolower($info));
					$aTVremoteinfo[$info]=$value;
				}
				$aTVremoteinfo['hash']=$this->aTVremoteExecute('hash')[0];	
				log::add('aTVremote','debug',"Reçu:".json_encode($aTVremoteinfo));
			}
			
			$changed = false;
			
			$hashChanged = false;
			$hash = $this->getCmd(null, 'hash');
			if(is_object($hash)) {
				if(isset($aTVremoteinfo['hash'])) {
					$hashChanged=$this->checkAndUpdateCmd($hash, $aTVremoteinfo['hash']) || $hashChanged;
				}
			}
			if($hashChanged) log::add('aTVremote','debug','hashChanged:'.$hashChanged);
			$changed = $hashChanged || $changed;

			$isPlaying=false;
			if(isset($aTVremoteinfo['device_state'])) {
				$play_state = $this->getCmd(null, 'play_state');
				$play_human = $this->getCmd(null, 'play_human');
				switch(strtolower($aTVremoteinfo['device_state'])) {
					case 'idle' :
						$changed=$this->checkAndUpdateCmd($play_human, __("Inactif", __FILE__)) || $changed;
						break;
					case 'paused':
						$changed=$this->checkAndUpdateCmd($play_human, __("En pause", __FILE__)) || $changed;
						break;
					case 'stopped':
						$changed=$this->checkAndUpdateCmd($play_human, __("Stoppé", __FILE__)) || $changed;
						break;
					case 'no media':
						$changed=$this->checkAndUpdateCmd($play_human, __("Aucun Media", __FILE__)) || $changed;
						break;
					case 'playing':
						$changed=$this->checkAndUpdateCmd($play_human, __("Lecture en cours", __FILE__)) || $changed;
						$isPlaying=true;
						break;
					case 'loading':
						$changed=$this->checkAndUpdateCmd($play_human, __("Chargement en cours", __FILE__)) || $changed;
						$isPlaying=true;
						break;
					case 'fast forward':
						$changed=$this->checkAndUpdateCmd($play_human, __("Avance rapide", __FILE__)) || $changed;
						$isPlaying=true;
						break;
					case 'fast backward':
						$changed=$this->checkAndUpdateCmd($play_human, __("Recul rapide", __FILE__)) || $changed;
						$isPlaying=true;
						break;
					default:
						$changed=$this->checkAndUpdateCmd($play_human, __("Inconnu", __FILE__)) || $changed;
						break;
				}
			}
			if($isPlaying) {
				$changed=$this->checkAndUpdateCmd($play_state, "1") || $changed;
			} else {
				$changed=$this->checkAndUpdateCmd($play_state, "0") || $changed;
			}
			
			// if hash changed
				$media_type = $this->getCmd(null, 'media_type');
				if(is_object($media_type)) {
					if(isset($aTVremoteinfo['media_type'])) {
						if(strtolower($aTVremoteinfo['media_type'])=='unknown'){
							$changed=$this->checkAndUpdateCmd($media_type, '-') || $changed;
						} else {
							$changed=$this->checkAndUpdateCmd($media_type, ucfirst($aTVremoteinfo['media_type'])) || $changed;
						}
					}  else {
						$changed=$this->checkAndUpdateCmd($media_type, '-') || $changed;
					}
				}
				$title = $this->getCmd(null, 'title');
				if(is_object($title)) {
					if(isset($aTVremoteinfo['title'])) {
						$changed=$this->checkAndUpdateCmd($title, $aTVremoteinfo['title']) || $changed;         
					} else {
						$changed=$this->checkAndUpdateCmd($title, '-') || $changed;
					}
				}
				$artist = $this->getCmd(null, 'artist');
				if(is_object($artist)) {
					if(isset($aTVremoteinfo['artist'])) {
						$changed=$this->checkAndUpdateCmd($artist, $aTVremoteinfo['artist']) || $changed;
					} else {
						$changed=$this->checkAndUpdateCmd($artist, '-') || $changed;
					}
				}
				$album = $this->getCmd(null, 'album');
				if(is_object($album)) {
					if(isset($aTVremoteinfo['album'])) {
						$changed=$this->checkAndUpdateCmd($album, $aTVremoteinfo['album']) || $changed;
					} else {
						$changed=$this->checkAndUpdateCmd($album, '-') || $changed;
					}
				}
				$genre = $this->getCmd(null, 'genre');
				if(is_object($genre)) {
					if(isset($aTVremoteinfo['genre'])) {
						$changed=$this->checkAndUpdateCmd($genre, $aTVremoteinfo['genre']) || $changed;
					} else {
						$changed=$this->checkAndUpdateCmd($genre, '-') || $changed;
					}
				}
			

				if($this->getConfiguration('version',0) != '3') {
					$app = $this->getCmd(null, 'app');
					if(is_object($app)) {
						if(isset($aTVremoteinfo['app_id'])) {
							$changed=$this->checkAndUpdateCmd($app, $aTVremoteinfo['app_id']) || $changed;
						} elseif(!isset($aTVremoteinfo['simplifiedPlaying'])){
							$changed=$this->checkAndUpdateCmd($app, '-') || $changed;
						}
					}
				}
			// end if changed hash
			
			$position = $this->getCmd(null, 'position');
			if (is_object($position)) {
				if(isset($aTVremoteinfo['position']) && $aTVremoteinfo['position'] != null && strpos($aTVremoteinfo['position'],'%') !== false) { // position from refreshed playing
					$sep=explode(' ',$aTVremoteinfo['position']);
					$posPart=explode('/',$sep[0]);
					$aTVremoteinfo['position']=intval($posPart[0]);
					$aTVremoteinfo['total_time']=intval(str_replace('s','',$posPart[1]));
				}
				
				if(isset($aTVremoteinfo['total_time']) && $aTVremoteinfo['total_time'] != null) {
					if($aTVremoteinfo['total_time'] <60) {
						$displayTT=$aTVremoteinfo['total_time'].'s';
					} elseif($aTVremoteinfo['total_time'] <3600) {
						$displayTT=gmdate("i:s",$aTVremoteinfo['total_time']);
					} else {
						$displayTT=gmdate("H:i:s",$aTVremoteinfo['total_time']);
					}
				}
				
				if(isset($aTVremoteinfo['position']) && $aTVremoteinfo['position'] != null) {
					if($aTVremoteinfo['position'] <60) {
						$displayPos=$aTVremoteinfo['position'].'s';
					} elseif($aTVremoteinfo['position'] <3600) {
						$displayPos=gmdate("i:s",$aTVremoteinfo['position']);
					} else {
						$displayPos=gmdate("H:i:s",$aTVremoteinfo['position']);
					}
						
					if(isset($aTVremoteinfo['total_time']) && $aTVremoteinfo['total_time'] != null) { //aTV4+
						$changed=$this->checkAndUpdateCmd($position, $displayPos.'/'.$displayTT.' ('.round(intval($aTVremoteinfo['position'])*100/intval($aTVremoteinfo['total_time'])).'%)') || $changed;
					} else {
						$changed=$this->checkAndUpdateCmd($position, '-') || $changed;
					}
				} else {
					if(isset($aTVremoteinfo['total_time']) && $aTVremoteinfo['total_time'] != null) { //aTV4+
						$changed=$this->checkAndUpdateCmd($position, $displayTT) || $changed;
					} else {
						$changed=$this->checkAndUpdateCmd($position, '-') || $changed;
					}
				}
			}
			if(isset($aTVremoteinfo['repeat'])) { // always return Off
				$repeat_human = $this->getCmd(null, 'repeat');
				$repeat_state = $this->getCmd(null, 'repeat_state');
				if (is_object($repeat_human) && is_object($repeat_state)) {
					switch(ucfirst($aTVremoteinfo['repeat'])) {
						case 'Off':
							$changed=$this->checkAndUpdateCmd($repeat_human, __("Non", __FILE__)) || $changed;
							$changed=$this->checkAndUpdateCmd($repeat_state, '0') || $changed;
						break;
						case 'Track':
							$changed=$this->checkAndUpdateCmd($repeat_human, __("Piste", __FILE__)) || $changed;
							$changed=$this->checkAndUpdateCmd($repeat_state, '1') || $changed;
						break;
						case 'All':
							$changed=$this->checkAndUpdateCmd($repeat_human, __("Tout", __FILE__)) || $changed;
							$changed=$this->checkAndUpdateCmd($repeat_state, '2') || $changed;
						break;
					}
				}
			}
			if(isset($aTVremoteinfo['shuffle'])) { // always return False
				$shuffle_human = $this->getCmd(null, 'shuffle');
				$shuffle_state = $this->getCmd(null, 'shuffle_state');
				if (is_object($shuffle_human) && is_object($shuffle_state)) {
				    switch(ucfirst($aTVremoteinfo['shuffle'])) {
					case 'Off':                     
								$changed=$this->checkAndUpdateCmd($shuffle_human, __("Non", __FILE__)) || $changed;
								$changed=$this->checkAndUpdateCmd($shuffle_state, '0') || $changed;
					break;
					case 'Songs':
								$changed=$this->checkAndUpdateCmd($shuffle_human, __("Chansons", __FILE__)) || $changed;
								$changed=$this->checkAndUpdateCmd($shuffle_state, '1') || $changed;
					break;
					/*case 'Albums':
								$changed=$this->checkAndUpdateCmd($shuffle_human, __("Albums", __FILE__)) || $changed;
								$changed=$this->checkAndUpdateCmd($shuffle_state, '2') || $changed;
					break;*/
				    }
				}
			}
			
			//if(isset($aTVremoteinfo['title']) && trim($aTVremoteinfo['title']) != "" && $isPlaying) {
			if($hashChanged && strtolower($aTVremoteinfo['device_state']) != 'idle' && strtolower($aTVremoteinfo['media_type']) != 'unknown') {
				if(! $this->setArtwork($aTVremoteinfo['hash'])) {
					$artwork = $this->getImage(true);
					$artwork_url = $this->getCmd(null, 'artwork_url');
					if(is_object($artwork_url)) {
						$changed=$this->checkAndUpdateCmd($artwork_url, $artwork) || $changed;
					}
				} else {
					$changed=true;
				}
			} elseif($this->getConfiguration('version',0) == '3' && strtolower($aTVremoteinfo['device_state']) != 'idle' && strtolower($aTVremoteinfo['media_type']) == 'unknown') {
				if(! $this->setArtwork($aTVremoteinfo['hash'])) {
					$artwork = $this->getImage(true);
					$artwork_url = $this->getCmd(null, 'artwork_url');
					if(is_object($artwork_url)) {
						$changed=$this->checkAndUpdateCmd($artwork_url, $artwork) || $changed;
					}
				} else {
					$changed=true;
				}
			} elseif(strtolower($aTVremoteinfo['media_type']) == 'unknown') {
				$artwork = $this->getImage(true);
				$artwork_url = $this->getCmd(null, 'artwork_url');
				if(is_object($artwork_url)) {
					$changed=$this->checkAndUpdateCmd($artwork_url, $artwork) || $changed;
				}
			}
			/*elseif($isPlaying) { // if not paused but no Title...
				$artwork = $this->getImage();
				$artwork_url = $this->getCmd(null, 'artwork_url');
				if (is_object($artwork_url)) {
					$changed=$this->checkAndUpdateCmd($artwork_url, $artwork) || $changed;
				}
			}*/
			
			/*if ($changed) {
				$this->refreshWidget();
			}*/
		} catch (Exception $e) {
			/*$aTVremoteCmd = $this->getCmd(null, 'status');
			if (is_object($aTVremoteCmd)) {
				$this->checkAndUpdateCmd($aTVremoteCmd, 'Erreur communication');
			}*/
		}
	} 
	
	public function getImage($justCenter=false){
		if($justCenter) {
			return 'plugins/aTVremote/core/template/inactive.png';
		}
		if($this->getConfiguration('device','') == 'HomePod') {
			if($this->getConfiguration('version','') == 'Mini') {
				return 'plugins/aTVremote/core/template/homepod_mini.png';
			} else {
				return 'plugins/aTVremote/core/template/homepod.png';
			}
		} else {
			return 'plugins/aTVremote/core/template/aTV.png';
		}
	}
	
	public function preSave() {
		if($this->getConfiguration('savingWithGui','') == '1') {
			$pairingKeyAirplay=$this->getConfiguration('pairingKeyAirplay','');
			if($this->getConfiguration('needAirplayPairing','') == '1' && $pairingKeyAirplay == '') {
				throw new Exception("Vous devez faire l'appairage Airplay !");
			} elseif ($pairingKeyAirplay != '') {
				$this->setConfiguration('pairingKeyAirplay', trim(str_replace('You may now use these credentials: ','',$pairingKeyAirplay)) );
			}
			
			$pairingKeyCompanion=$this->getConfiguration('pairingKeyCompanion','');
			if($this->getConfiguration('needCompanionPairing','') == '1' && $pairingKeyCompanion == '') {
				throw new Exception("Vous devez faire l'appairage Companion !");
			} elseif ($pairingKeyCompanion != '') {
				$this->setConfiguration('pairingKeyCompanion', trim(str_replace('You may now use these credentials: ','',$pairingKeyCompanion)) );
			}
		}
	}
	
	public function postSave() {
		$order=0;
		$os=$this->getConfiguration('os','');
		$device=$this->getConfiguration('device','');
		if($device=="HomePod") {
			$cmds = self::devicesParameters($os,$device);
		} else {
			$cmds = self::devicesParameters($os);
		}
		$pairingKeyAirplay=$this->getConfiguration('pairingKeyAirplay','');
		$pairingKeyCompanion=$this->getConfiguration('pairingKeyCompanion','');
		if($pairingKeyAirplay != '') {
			exec(system::getCmdSudo() . 'chown -R www-data:www-data ' . dirname(__FILE__) . '/../../data');
			exec(system::getCmdSudo() . 'chmod -R 775 ' . dirname(__FILE__) . '/../../data');
			@file_put_contents(dirname(__FILE__) . '/../../data/'.$this->getConfiguration('mac','unknown').'-airplay.key',$pairingKeyAirplay);
			exec(system::getCmdSudo() . 'chown -R www-data:www-data ' . dirname(__FILE__) . '/../../data');
			exec(system::getCmdSudo() . 'chmod -R 775 ' . dirname(__FILE__) . '/../../data');
		}
		
		if($pairingKeyCompanion != '') {
			exec(system::getCmdSudo() . 'chown -R www-data:www-data ' . dirname(__FILE__) . '/../../data');
			exec(system::getCmdSudo() . 'chmod -R 775 ' . dirname(__FILE__) . '/../../data');
			@file_put_contents(dirname(__FILE__) . '/../../data/'.$this->getConfiguration('mac','unknown').'-companion.key',$pairingKeyCompanion);
			exec(system::getCmdSudo() . 'chown -R www-data:www-data ' . dirname(__FILE__) . '/../../data');
			exec(system::getCmdSudo() . 'chmod -R 775 ' . dirname(__FILE__) . '/../../data');
		}
		
		if($cmds) {
			foreach($cmds['commands'] as $cmd) {
				$order++;
				
				$newCmd = $this->getCmd(null, $cmd['logicalId']);
				if (!is_object($newCmd)) {
					$newCmd = new aTVremoteCmd();
					$newCmd->setLogicalId($cmd['logicalId']);
					$newCmd->setType($cmd['type']);
				}
				$newCmd->setSubType($cmd['subtype']);
				$newCmd->setIsVisible($cmd['isVisible']);
				$newCmd->setOrder($order);
				$newCmd->setName(__($cmd['name'], __FILE__));
				$newCmd->setEqLogic_id($this->getId());
				
				if(isset($cmd['configuration'])) {
					foreach($cmd['configuration'] as $configuration_type=>$configuration_value) {
						$newCmd->setConfiguration($configuration_type, $configuration_value);
					}
				} 
				if(isset($cmd['template'])) {
					foreach($cmd['template'] as $template_type=>$template_value) {
						$newCmd->setTemplate($template_type, $template_value);
					}
				} 
				if(isset($cmd['display'])) {
					foreach($cmd['display'] as $display_type=>$display_value) {
						$newCmd->setDisplay($display_type, $display_value);
					}
				}
				
				if(isset($cmd['value'])) {
					$linkStatus = $this->getCmd(null, $cmd['value']);
					$newCmd->setValue($linkStatus->getId());
				}
				$newCmd->save();				
			}
		}
		
		if($this->getIsEnable() == "1") {
			$this->aTVdaemonConnectATV();
		} else {
			$this->aTVdaemonDisconnectATV();
		}
		
		if($this->getConfiguration('version',0) == '3') {
			$this->setaTVremoteInfo();
		} else {
			if($this->getIsEnable() == '1' && $this->getConfiguration('pairingKeyCompanion','') != '') {
				$app_list=$this->getCmd(null,'app_list');
				if(is_object($app_list)) {
					$app_list->execCmd();
				}
			}
		}
	}
	
	public function preRemove() {
		$this->aTVdaemonDisconnectATV();
	}
	
  	public function toHtml($_version = 'dashboard') {
        
		$replace = $this->preToHtml($_version);
 		if (!is_array($replace)) {
 			return $replace;
  		}
		$version = jeedom::versionAlias($_version);
		foreach ($this->getCmd('info') as $cmd) {
			$replace['#' . $cmd->getLogicalId() . '#'] = htmlspecialchars($cmd->getCache('value'), ENT_QUOTES,'UTF-8');
			$replace['#' . $cmd->getLogicalId() . '_id#'] = $cmd->getId();
			if ($cmd->getIsHistorized() == 1) {
				$replace['#' . $cmd->getLogicalId() . '_history#'] = 'history cursor';
			}
		}
      		
		$replace["#os#"] = $this->getConfiguration('os',0);
		$replace["#osVersion#"] = $this->getConfiguration('osVersion',0);
		$replace["#build#"] = $this->getConfiguration('build',0);
		
		$replace["#ATV#"] = $this->getConfiguration('version',0);
      	$replace["#model#"] = $this->getConfiguration('fullModel',0);
      	$Test = $this->getConfiguration('device',0);
      	$replace["#device#"] = $Test;
		
		$marquee = config::byKey('marquee', 'aTVremote', 0);
		if ($marquee == 1){
			$replace["#marquee#"] = "scroll";
			//log::add('aTVremote','debug','--dest already exists, just display it...'.$marquee);
		} else {
			$replace["#marquee#"] = "alternate";
		};
   
/** pour les tests sans HomePod **/   
      
      	$typeWidget = config::byKey('typeWidget', 'aTVremote', 0);
      	if ($typeWidget == 0){
			$replace["#typeWidget#"] = "ATV";
			//log::add('aTVremote','debug','Type widget : '.$typeWidget);
		} else {
			$replace["#typeWidget#"] = "HomePod";
		};
		
/** pour les tests sans HomePod **/   		
		
		foreach ($this->getCmd('action') as $cmd) {
			$replace['#cmd_' . $cmd->getLogicalId() . '_id#'] = $cmd->getId();
			if($cmd->getLogicalId() == 'launch_app') {
				$optList=[];
				foreach(explode(';',$cmd->getConfiguration('listValue')) as $value) {
					$temp=explode('|',$value);
					//log::add('aTVremote','debug','val:'.$temp[0].'|disp:'.$temp[1]);
					array_push($optList,'<option value="'.$temp[0].'">'.$temp[1].'</option>');
				}
				$replace['#cmd_' . $cmd->getLogicalId() . '_opt#'] = join('',$optList);;
			}
		}
		/**$lentocheck = 24;
		if ($version == 'mobile'){
			$lentocheck = 17;
		}**/
      
/** pour les tests sans HomePod **/  
      
		/**if ($typeWidget == 0){
			return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, 'eqLogic', 'aTVremote')));
        }else{
			return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, 'eqLogic1', 'aTVremote')));
        };**/
      
/** pour les tests sans HomePod **/  

/** THEMES HomePods **/
		$themes=[];
		$themes['black'] = [
			"handleColor"=>"#464646",
			"handleShadowColor"=>"#FFF",
			"rangeColor"=>"rgba(0,0,0,0.5)",
			"pathColor"=>"rgba(0,0,0,0)",
			"tooltipColor"=>"#B1B1B1"
		];
		$themes['white'] = [
			"handleColor"=>"#000",
			"handleShadowColor"=>"#000",
			"rangeColor"=>"rgba(0,0,0,0.5)",
			"pathColor"=>"rgba(255,255,255,0.4)",
			"tooltipColor"=>"#B1B1B1"
		];
		$themes['red'] = [
			"handleColor"=>"#FF3200",
			"handleShadowColor"=>"#FFF",
			//"rangeColor"=>"rgba(0,0,0,0.5)",
			"rangeColor"=>"rgba(255,50,0,0.4)",
			"pathColor"=>"rgba(255,50,0,0.4)",
			"tooltipColor"=>"#FFF"
		];
		$themes['yellow'] = [
			"handleColor"=>"#FDD514",
			"handleShadowColor"=>"#000",
			//"rangeColor"=>"rgba(0,0,0,0.5)",
			"rangeColor"=>"rgba(253,213,20,0.4)",
			"pathColor"=>"rgba(253,213,20,0.4)",
			"tooltipColor"=>"#FFF"
		];
		$themes['blue'] = [
			"handleColor"=>"#003D7E",
			"handleShadowColor"=>"#FFF",
			"rangeColor"=>"rgba(0,0,0,0.5)",
			"pathColor"=>"rgba(0,61,126,0.4)",
			"tooltipColor"=>"#FFF"
		];
		
		$theme=$this->getConfiguration('theme','black');
		foreach($themes[$theme] as $elmt=>$value) {
			$replace["#".$elmt."#"] = $value;
        }
    
		if ($Test == "Apple TV"){
			if($this->getConfiguration('version',0) == '3') {
				return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, 'atv3', 'aTVremote')));
			} else {
				return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, 'atv', 'aTVremote')));
			}
        }else{
			return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, 'homepod', 'aTVremote')));
        };
        
	}  
}

class aTVremoteCmd extends cmd {

	public function execute($_options = null) {
		if ($this->getType() == '') {
			return '';
		}
		$eqLogic = $this->getEqlogic();

		$logical = $this->getLogicalId();
		$changed=false;
		
		if ($logical != 'refresh'){
			switch (strtolower($logical)) {
				case 'play':
					$eqLogic->aTVdaemonExecute('play');
				break;
				case 'pause':
				case 'stop':
					$eqLogic->aTVdaemonExecute(strtolower($logical));
					// pre-set
					$play_state = $eqLogic->getCmd(null, 'play_state');
					if (is_object($play_state)) {
						$changed = $eqLogic->checkAndUpdateCmd($play_state, "0") || $changed;
					}
					$play_human = $eqLogic->getCmd(null, 'play_human');
					if (is_object($play_human)) {
						$changed = $eqLogic->checkAndUpdateCmd($play_human, "En pause") || $changed;
					}
				break;
				case 'set_repeat_all':
					$eqLogic->aTVdaemonExecute('set_repeat=2');
				break;
				case 'set_repeat_track':
					$eqLogic->aTVdaemonExecute('set_repeat=1');
				break;
				case 'set_repeat_off':
					$eqLogic->aTVdaemonExecute('set_repeat=0');
				break;
				case 'set_shuffle_on':
					$eqLogic->aTVdaemonExecute('set_shuffle=1');
				break;
				case 'set_shuffle_off':
					$eqLogic->aTVdaemonExecute('set_shuffle=0');
				break;
				case 'down':
					$eqLogic->aTVdaemonExecute('down');
				break;
				case 'up':
					$eqLogic->aTVdaemonExecute('up');
				break;
				case 'left':
					$eqLogic->aTVdaemonExecute('left');
				break;
				case 'right':
					$eqLogic->aTVdaemonExecute('right');
				break;
				case 'previous':
					$eqLogic->aTVdaemonExecute('previous');
				break;
				case 'next':
					$eqLogic->aTVdaemonExecute('next');
				break;
				case 'menu':
					$eqLogic->aTVdaemonExecute('menu');
				break;
				case 'select':
					$eqLogic->aTVdaemonExecute('select');
				break;
				case 'top_menu':
					$eqLogic->aTVdaemonExecute('top_menu');
				break;
				case 'turn_on':
					$eqLogic->aTVdaemonExecute('turn_on|delay=5000|power_state');
					// pre-set
					/*$power_state = $eqLogic->getCmd(null, 'power_state');
					if (is_object($power_state)) {
						$changed=$eqLogic->checkAndUpdateCmd($power_state, '1') || $changed;
					}*/
				break;
				case 'turn_off':
					$eqLogic->aTVdaemonExecute('turn_off|delay=5000|power_state');
					// pre-set
					/*$power_state = $eqLogic->getCmd(null, 'power_state');
					if (is_object($power_state)) {
						$changed=$eqLogic->checkAndUpdateCmd($power_state, '0') || $changed;
					}*/
				break;
				case 'chain':
					$cmds = $_options['title'];
					$eqLogic->aTVdaemonExecute($cmds);
				break;
				case 'volume_down' :
					if($eqLogic->getConfiguration('device','') == 'HomePod') {
						$play_state = $eqLogic->getCmd(null, 'play_state');
						if (is_object($play_state) && $play_state->getCache('value') == '1') { // or the deamon crash !
							$eqLogic->aTVdaemonExecute('volume_down|volume');
							// pre-set volume
							/*$volume = $eqLogic->getCmd(null, 'volume');
							if (is_object($volume)) {
								$currentVol=intval($volume->getCache('value'));
								$currentVol-=5;
								if($currentVol <0){$currentVol=0;}
								log::add('aTVremote','debug','PréChangement volume à '.$currentVol);
								$changed=$eqLogic->checkAndUpdateCmd($volume, $currentVol) || $changed;
							}*/
						} else {
							$cmds=" Annulée car pas encours de lecture et ca fait planter le démon";	
						}
					} else if($eqLogic->getConfiguration('version',0) != '3'){
						$features=$eqLogic->getConfiguration('features',null);
						if($features && $features['Volume']=="Available") {
							$eqLogic->aTVdaemonExecute('volume_down|volume');
						} else {
							$cmds=$eqLogic->getConfiguration('LessVol');
							if(!$cmds) {return;}
							$cmdLessVol = cmd::byId(trim(str_replace('#', '', $cmds)));
							if(!is_object($cmdLessVol)) {return;}
							$cmdLessVol->execCmd();
						}
					} else { // aTV3 using jeedom commands
						$cmds=$eqLogic->getConfiguration('LessVol');
						if(!$cmds) {return;}
						$cmdLessVol = cmd::byId(trim(str_replace('#', '', $cmds)));
						if(!is_object($cmdLessVol)) {return;}
						$cmdLessVol->execCmd();
					}
				break;
				case 'volume_up' :
					if($eqLogic->getConfiguration('device','') == 'HomePod') {
						$play_state = $eqLogic->getCmd(null, 'play_state');
						if (is_object($play_state) && $play_state->getCache('value') == '1') { // or the deamon crash !
							$eqLogic->aTVdaemonExecute('volume_up|volume');
							// pre-set volume
							/*$volume = $eqLogic->getCmd(null, 'volume');
							if (is_object($volume)) {
								$currentVol=intval($volume->getCache('value'));
								$currentVol+=5;
								if($currentVol >100){$currentVol=100;}
								log::add('aTVremote','debug','PréChangement volume à '.$currentVol);
								$changed=$eqLogic->checkAndUpdateCmd($volume, $currentVol) || $changed;
							}*/
						} else {
							$cmds=" Annulée car pas encours de lecture et ca fait planter le démon";	
						}
					} else if($eqLogic->getConfiguration('version',0) != '3'){
						$features=$eqLogic->getConfiguration('features',null);
						if($features && $features['Volume']=="Available") {
							$eqLogic->aTVdaemonExecute('volume_up|volume');
						} else {
							$cmds=$eqLogic->getConfiguration('MoreVol');
							if(!$cmds) {return;}
							$cmdMoreVol = cmd::byId(trim(str_replace('#', '', $cmds)));
							if(!is_object($cmdMoreVol)) {return;}
							$cmdMoreVol->execCmd();
						}
					} else { // aTV3 using jeedom commands
						$cmds=$eqLogic->getConfiguration('MoreVol');
						if(!$cmds) {return;}
						$cmdMoreVol = cmd::byId(trim(str_replace('#', '', $cmds)));
						if(!is_object($cmdMoreVol)) {return;}
						$cmdMoreVol->execCmd();
					}
				break;
				case 'set_volume' :
					if($eqLogic->getConfiguration('device','') == 'HomePod') {
						$play_human = $eqLogic->getCmd(null, 'play_human');
						if (is_object($play_human) && $play_human->getCache('value') != __("Inactif", __FILE__)) { // or the deamon crash !
							$eqLogic->aTVdaemonExecute('set_volume='.$_options['slider'].'|volume');
							// pre-set volume
							/*$volume = $eqLogic->getCmd(null, 'volume');
							if (is_object($volume)) {
								log::add('aTVremote','debug','PréChangement volume à '.$_options['slider']);
								$changed=$eqLogic->checkAndUpdateCmd($volume, $_options['slider']) || $changed;
							}*/
						} else {
							$eqLogic->aTVdaemonExecute('volume');
							$cmds=" Annulée car Inactif et ca fait planter le démon";	
						}
					} else if($eqLogic->getConfiguration('version',0) != '3'){
						$features=$eqLogic->getConfiguration('features',null);
						if($features && $features['Volume']=="Available") {
							$eqLogic->aTVdaemonExecute('set_volume='.$_options['slider'].'|volume');
						}
					}
				break;
				case 'channel_up':
					$eqLogic->aTVdaemonExecute('channel_up');
				break;
				case 'channel_down':
					$eqLogic->aTVdaemonExecute('channel_down');
				break;
				case 'app_list' :
					$eqLogic->aTVdaemonExecute('app_list');
				break;
				case 'launch_app' :
					if($eqLogic->getConfiguration('pairingKeyCompanion','') != '') {
						$eqLogic->aTVdaemonExecute($logical.'='.$_options['select']);
					} else {
						log::add('aTVremote','debug',"Impossible de lancer la commande : ".$logical.'='.$_options['select']." car pas d'appairage Companion".((isset($cmds))?' -> '.$cmds:''));
					}
				break;
			}
			log::add('aTVremote','info',"Commande sur ".$eqLogic->getName().' : '.$logical.((isset($cmds))?' -> '.$cmds:''));
		
		} elseif($eqLogic->getConfiguration('version',0) != '3') { // refresh on !atv3
			if($eqLogic->getConfiguration('device','') != 'HomePod') {
				$eqLogic->aTVdaemonExecute('power_state|app_list');
			}
			$eqLogic->aTVdaemonExecute('playing|delay=333|volume');
		}
		
		if($eqLogic->getConfiguration('device','') == 'Apple TV' && $eqLogic->getConfiguration('version',0) == '3') {
			$eqLogic->setaTVremoteInfo();
		}
		/* if ($changed) {
			$eqLogic->refreshWidget();
		}*/
	}

}
?>
