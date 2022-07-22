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
		foreach ($eqLogics as $aTVremote) {
			try {
				if(is_object($aTVremote)) {
					if($aTVremote->getConfiguration('version','') == '3'){
						$play_state = $aTVremote->getCmd(null, 'play_state');
						if(is_object($play_state)) {
							$val=$play_state->execCmd();
							if($val) {
								$aTVremote->setaTVremoteInfo();
							}
						}
					} elseif($aTVremote->getConfiguration('device','') == 'HomePod') {
						$play_state = $aTVremote->getCmd(null, 'play_state');
						if(is_object($play_state)) {
							$val=$play_state->execCmd();
							if($val) { // if playing : 1min
								$aTVremote->aTVdaemonExecute('volume');
							} else { // else : 5min
								$c = new Cron\CronExpression(checkAndFixCron('*/5 * * * *'), new Cron\FieldFactory);
								if ($c->isDue()) {
									$aTVremote->aTVdaemonExecute('volume');
								}
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
      		//$finale_folder= $abs_folder.$id.'/'; // no need to go to subfolder, find does it ;)
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
		$dep_info = self::dependancy_info();
		log::remove(__CLASS__ . '_dep');
		return array('script' => dirname(__FILE__) . '/../../resources/install_#stype#.sh ' . jeedom::getTmpFolder('aTVremote') . '/dependance', 'log' => log::getPathToLog(__CLASS__ . '_dep'));
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
			throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
		}
		log::add('aTVremote', 'info', 'Lancement du démon aTVremote');
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

		log::add('aTVremote', 'debug', 'Lancement démon aTVremote : ' . $cmd);

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
			log::add('aTVremote', 'error', 'Impossible de lancer le démon aTVremote, relancer le démon en debug et vérifiez la log', 'unableStartDeamon');
			return false;
		}
		message::removeAll('aTVremote', 'unableStartDeamon');
		log::add('aTVremote', 'info', 'Démon aTVremote lancé');
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
		log::add('aTVremote', 'info', 'Arrêt du démon aTVremote');
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
		log::add('aTVremote', 'info', 'Suppression du Code NodeJS');
		$cmd = system::getCmdSudo() . 'rm -rf ' . dirname(__FILE__) . '/../../resources/node_modules >/dev/null 2>&1';
		exec($cmd);
		log::add('aTVremote', 'info', 'Suppression de NodeJS');
		$cmd = system::getCmdSudo() . 'apt-get -y --purge autoremove npm';
		exec($cmd);
		$cmd = system::getCmdSudo() . 'apt-get -y --purge autoremove nodejs';
		exec($cmd);
		log::add('aTVremote', 'info', 'Réinstallation des dependances');
		$pluginaTVremote->dependancy_install();
		return true;
	}

	public static function event() {
		$eventType = init('eventType');
		log::add('aTVremote', 'debug', 'Passage dans la fonction event ' . $eventType);
		if ($eventType == 'error'){
			log::add('aTVremote', 'error', init('description'));
			return;
		}
		
		switch ($eventType)
		{
			case 'playing':
				log::add('aTVremote','debug','Reçu du démon :'.init('data'));
				$aTVremote = aTVremote::byLogicalId(init('mac'), 'aTVremote');
				$aTVremote->setaTVremoteInfo(json_decode(init('data'),true));
			break;
			case 'powerstate':
				log::add('aTVremote','debug','Reçu du démon :'.init('data'));
				$aTVremote = aTVremote::byLogicalId(init('mac'), 'aTVremote');
				$aTVremote->setPowerstate(json_decode(init('data'),true));
			break;
			case 'app':
				log::add('aTVremote','debug','Reçu du démon :'.init('data'));
				$eqLogic=aTVremote::byLogicalId(init('mac'), 'aTVremote');
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
				log::add('aTVremote','debug','Reçu du démon :'.init('data'));
				$eqLogic=aTVremote::byLogicalId(init('mac'), 'aTVremote');
				$volume = $eqLogic->getCmd(null, 'volume');
				if (is_object($volume)) {
					$changed=$eqLogic->checkAndUpdateCmd($volume, explode('.',init('data'))[0]) || $changed;
				}
			break;
		}
	}
// OLDSCAN
    	public static function discover($_mode) {
		log::add('aTVremote','info','Scan en cours...');
        	$output=shell_exec(aTVremote::getaTVremote(true,true)." scan");
		log::add('aTVremote','debug','Résultat brut : '.$output);

		if($output) {
			$return = [];
			$toMatch = '#Name: (.*)\s* Model/SW: (.*)\s* Address: (.*)\s* MAC: (.*)\s*#';


			if(preg_match_all($toMatch, $output, $matches,PREG_SET_ORDER)) {
				foreach($matches as $device) {
					if($device[4] == "None") {
						log::add('aTVremote','debug','Pas de MAC : on ignore');
						continue;
					}
					
					$res = [];
					$res["name"]=$device[1];
					$res["model"]=$device[2];
					$res["ip"]=$device[3];
					$res["mac"]=$device[4];
					
					if(strpos($res['model'],'Apple TV') === false && strpos($res['model'],'HomePod') === false) {
						log::add('aTVremote','debug','Ignore '.$res['model']);
						continue;
					}
					
					log::add('aTVremote','debug','Name :'.$res["name"]);
					log::add('aTVremote','debug','Model/SW :'.$res["model"]);
					log::add('aTVremote','debug','Address :'.$res["ip"]);
					log::add('aTVremote','debug','MAC :'.$res["mac"]);
					
					$modElmt=explode(', ',$res['model']);
					
					if(strpos($res['model'],'Apple TV') !== false) {
						$res['device']="Apple TV";
						$res['version']=str_replace('Apple TV ','',$modElmt[0]);
					} elseif(strpos($res['model'],'HomePod') !== false) {
						$res['device']="HomePod";
						if(strpos($res['model'],'Mini') !== false) {
							$res['version']="Mini";
						} else {
							$res['version']="Original";
						}
					}
					
					$subModElmt=explode(' ',$modElmt[1]);
					$res['os']=$subModElmt[0];
					if($res['os'] == 'tvOS') {$res['os']='TvOS';}
					$res['osVersion']=$subModElmt[1];
					
					$aTVremote = aTVremote::byLogicalId($res["mac"], 'aTVremote');
					if (!is_object($aTVremote)) {
						$eqLogic = new aTVremote();
						$eqLogic->setName($res["name"]);
						$eqLogic->setIsEnable(0);
						$eqLogic->setIsVisible(0);
						$eqLogic->setLogicalId($res["mac"]);
						$eqLogic->setEqType_name('aTVremote');
						$eqLogic->setDisplay('width','138px');
                      				$eqLogic->setDisplay('height','500px');
					} else $eqLogic = $aTVremote;
					
					$eqLogic->setConfiguration('device', $res['device']);
					$eqLogic->setConfiguration('ip', $res["ip"]);
					$eqLogic->setConfiguration('mac',$res["mac"]);
					
					$eqLogic->setConfiguration('fullModel',$res["model"]);
					$eqLogic->setConfiguration('version',$res["version"]);
					$eqLogic->setConfiguration('os',$res["os"]);
					$eqLogic->setConfiguration('osVersion',$res["osVersion"]);

					$eqLogic->save();
					
					if(!is_object($aTVremote)) { // NEW
						event::add('jeedom::alert', array(
							'level' => 'warning',
							'page' => 'aTVremote',
							'message' => __('Nouvelle AppleTV detectée ' .$res["name"], __FILE__),
						));
					} else { // UPDATED
						event::add('jeedom::alert', array(
							'level' => 'warning',
							'page' => 'aTVremote',
							'message' => __('AppleTV mise à jour avec succès ' .$res["name"], __FILE__),
						));
					}
					$return[] = $res;
				}
			}

			log::add('aTVremote','info','Ajouté : '.json_encode($return));
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

				$aTVremote = aTVremote::byLogicalId($device["identifier"], 'aTVremote');
				if (!is_object($aTVremote)) {
					$eqLogic = new aTVremote();
					$eqLogic->setName($device["name"]);
					$eqLogic->setIsEnable(0);
					$eqLogic->setIsVisible(0);
					$eqLogic->setLogicalId($device["identifier"]);
					$eqLogic->setEqType_name('aTVremote');
					$eqLogic->setDisplay('width','138px');
					$eqLogic->setDisplay('height','500px');
				} else $eqLogic = $aTVremote;

				$eqLogic->setConfiguration('device', $deviceName);
				$eqLogic->setConfiguration('ip', $device['address']);
				$eqLogic->setConfiguration('mac',$device["identifier"]);

				$eqLogic->setConfiguration('fullModel',$device['device_info']['model_str']);
				$eqLogic->setConfiguration('version',$version);
				$eqLogic->setConfiguration('os',$device['device_info']['operating_system']);
				$eqLogic->setConfiguration('osVersion',$device['device_info']['version']);

				$eqLogic->save();

				if(!is_object($aTVremote)) { // NEW
					log::add('aTVremote','info','--'.$device["name"].'-Ajouté');
					event::add('jeedom::alert', array(
						'level' => 'warning',
						'page' => 'aTVremote',
						'message' => __('Nouvelle AppleTV detectée ' .$device["name"], __FILE__),
					));
				} else { // UPDATED
					log::add('aTVremote','info','--'.$device["name"].'-Modifié');
					event::add('jeedom::alert', array(
						'level' => 'warning',
						'page' => 'aTVremote',
						'message' => __('AppleTV mise à jour avec succès ' .$device["name"], __FILE__),
					));
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
				if($json === false) log::add('aTVremote','error','Problème de communication avec le démon : '.$url);
				log::add('aTVremote','debug',ucfirst($cmd).' brut : '.$json);
				return json_decode($json, true);
			} else {
				log::add('aTVremote','info','Démon pas démarré, impossible de lui envoyer la commande '.ucfirst($cmd));
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
			if($json === false) log::add('aTVremote','error','Problème de communication avec le démon : '.$url);
			log::add('aTVremote','debug','Connect brut : '.$json);
			return json_decode($json, true);
		} else {
			log::add('aTVremote','info','Démon pas démarré, impossible de lui envoyer la commande Connect');
		}
	}
	public function aTVdaemonDisconnectATV($params=[]) {
		$deamon_info = aTVremote::deamon_info();
		if ($deamon_info['state'] == 'ok') {
			$mac = $this->getConfiguration('mac','');

			$url="http://" . config::byKey('internalAddr') . ":".config::byKey('socketport', 'aTVremote')."/disconnect?";
			$url.='mac='.$mac.((count($params))?"&".http_build_query($params):'');
			$json = @file_get_contents($url);
			if($json === false) log::add('aTVremote','error','Problème de communication avec le démon : '.$url);
			log::add('aTVremote','debug','Disconnect brut : '.$json);
			return json_decode($json, true);
		} else {
			log::add('aTVremote','info','Démon pas démarré, impossible de lui envoyer la commande Disconnect');
		}
	}

	public function setPowerstate($data=null) {	
		if($this->getConfiguration('version',0) != '3'){
			$changed = false;
			
			$power_state=$data;

			log::add('aTVremote','debug','power_state : '.$power_state['power_state']);
			
			if($power_state['power_state']=="off"){
				$power = $this->getCmd(null, 'power_state');
				$changed=$this->checkAndUpdateCmd($power, '0') || $changed;
			} else {
				$power = $this->getCmd(null, 'power_state');
				$changed=$this->checkAndUpdateCmd($power, '1') || $changed;
			}
			
			if ($changed) 
				$this->refreshWidget();
		}		
	}
	public function setApp($app,$app_id) {
		$changed = false;
		if($this->getConfiguration('version',0) != '3'){
			
			// Retour App Active 		  

			/*log::add('aTVremote','debug','app active : '.$app);
			$app_id = explode('.',$app_id);
			$app_secour = $app_id[2];
			log::add('aTVremote','debug','app active secour : '.$app_secour);*/
	  
			$app_run = $this->getCmd(null, 'app');
			$changed=$this->checkAndUpdateCmd($app_run, $app_id) || $changed;
			/*
			if($app!=null && $app!='Unknown'){
				$app_run = $this->getCmd(null, 'app');
				$changed=$this->checkAndUpdateCmd($app_run, $app) || $changed;
				  
			} elseif($app==null) {
				$app_run = $this->getCmd(null, 'app');
				$changed=$this->checkAndUpdateCmd($app_run, $app_secour) || $changed;
				  
			} else {
				$app_run = $this->getCmd(null, 'app');
				$changed=$this->checkAndUpdateCmd($app_run, '-') || $changed;
						
			}*/	
		}
		return $changed;
	}

	public function setArtwork($hash) {
        	
		$hash=md5($hash);
		
		$id=$this->getLogicalId();
      
		$NEWheight=-1;
		$NEWwidth=138;
		
		$rel_folder = 'plugins/aTVremote/core/img/';
		$abs_folder = realpath(dirname(__FILE__).'/../../../../'.$rel_folder).'/';
		$artwork = $rel_folder.$id.'/'.$hash.'.jpg';
		$src =	   $abs_folder.$id.'/'.'artwork.png';
		$dest =    $abs_folder.$id.'/'.$hash.'.jpg';
	
		if (!file_exists($abs_folder.$id.'/')) {
			exec("cd $abs_folder && sudo mkdir $id && sudo chown www-data:www-data ".$abs_folder.$id."/ >/dev/null 2>&1;sudo chmod 775 ".$abs_folder.$id."/ >/dev/null 2>&1");
			log::add('aTVremote','debug','Création répertoire pour '.$id);
		}

		if(!file_exists($dest)) {
			if(file_exists($src)) {
				log::add('aTVremote','debug','--src already exists, remove it...'.$src);
				exec("sudo rm -f $src >/dev/null 2>&1");
			}
			
			$this->aTVdaemonExecute('artwork_save='.$NEWwidth.','.$NEWheight);// create artwork.png
			
			$t=1;
			while(!file_exists($src) && $t < 11) {
				log::add('aTVremote','debug','Pas encore de artwork.png, on attend...'.$t.'/10');
				sleep(1);
				$t++;
			}
			if($t == 11) {
				log::add('aTVremote','debug','Pas de artwork.png !');
				return false;
			} else {
				log::add('aTVremote','debug','Artwork trouvé !');
			}
			
			exec("sudo chown www-data:www-data $src >/dev/null 2>&1;sudo chmod 775 $src >/dev/null 2>&1"); // force rights
			rename($src,$dest);
			exec("sudo chown www-data:www-data $dest >/dev/null 2>&1;sudo chmod 775 $dest >/dev/null 2>&1"); // force rights
		} else {
			log::add('aTVremote','debug','--Artwork déjà dans le cache, on l\'utilise :'.$dest);
			touch($dest); // update mtime
			exec("sudo rm -f $src >/dev/null 2>&1");
		}
		
		$artwork_url = $this->getCmd(null, 'artwork_url');
		if(is_object($artwork_url)) {
			log::add('aTVremote','debug','--MàJ de la commande avec :'.$artwork.'...');
			$this->checkAndUpdateCmd($artwork_url, $artwork);
		}
		
		return true;
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
						$value= trim(join('',$elmt));
					} elseif(count($elmt) == 2){
						$value= trim($elmt[1]);
					}
					$info=str_replace(' ','_',strtolower($info));
					$aTVremoteinfo[$info]=$value;
				}
				$aTVremoteinfo['hash']=$this->aTVremoteExecute('hash')[0];	
			}
			
			$changed = false;
			log::add('aTVremote','debug','recu:'.json_encode($aTVremoteinfo));
			
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
				$aTVremoteinfo['device_state']=ucfirst($aTVremoteinfo['device_state']);
				$play_state = $this->getCmd(null, 'play_state');
				$play_human = $this->getCmd(null, 'play_human');
				switch($aTVremoteinfo['device_state']) {
					case 'Idle' :
						$changed=$this->checkAndUpdateCmd($play_state, "0") || $changed;
						$changed=$this->checkAndUpdateCmd($play_human, "Inactif") || $changed;
						break;
					case 'Paused':
						$changed=$this->checkAndUpdateCmd($play_state, "0") || $changed;
						$changed=$this->checkAndUpdateCmd($play_human, "En pause") || $changed;
						break;
					case 'No media':
						$changed=$this->checkAndUpdateCmd($play_state, "0") || $changed;
						$changed=$this->checkAndUpdateCmd($play_human, "Aucun Media") || $changed;
						break;
					case 'Playing':
						$changed=$this->checkAndUpdateCmd($play_state, "1") || $changed;
						$changed=$this->checkAndUpdateCmd($play_human, "Lecture en cours") || $changed;
						$isPlaying=true;
						break;
					case 'Loading':
						$changed=$this->checkAndUpdateCmd($play_state, "1") || $changed;
						$changed=$this->checkAndUpdateCmd($play_human, "Chargement en cours") || $changed;
						$isPlaying=true;
						break;
					case 'Fast forward':
						$changed=$this->checkAndUpdateCmd($play_state, "1") || $changed;
						$changed=$this->checkAndUpdateCmd($play_human, "Avance rapide") || $changed;
						$isPlaying=true;
						break;
					case 'Fast backward':
						$changed=$this->checkAndUpdateCmd($play_state, "1") || $changed;
						$changed=$this->checkAndUpdateCmd($play_human, "Recul rapide") || $changed;
						$isPlaying=true;
						break;
					default:
						$changed=$this->checkAndUpdateCmd($play_state, "0") || $changed;
						$changed=$this->checkAndUpdateCmd($play_human, "Inconnu") || $changed;
						break;
				}
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
					if(isset($aTVremoteinfo['app_id'])) {
						$changed=$this->setApp($aTVremoteinfo['app'],$aTVremoteinfo['app_id']) || $changed;
					} else {
						$app = $this->getCmd(null, 'app');
						if(is_object($app)) {
							$changed=$this->checkAndUpdateCmd($app, '-') || $changed;
						}
					}
				}
			
				//if(isset($aTVremoteinfo['title']) && trim($aTVremoteinfo['title']) != "" && $isPlaying) {
				if($hashChanged) {
					if(! $this->setArtwork($aTVremoteinfo['hash'])) {
						$artwork = $this->getImage(false);
						$artwork_url = $this->getCmd(null, 'artwork_url');
						if(is_object($artwork_url)) {
							$changed=$this->checkAndUpdateCmd($artwork_url, $artwork) || $changed;
						}
					}
				} /*elseif($isPlaying) { // if not paused but no Title...
					$artwork = $this->getImage();
					$artwork_url = $this->getCmd(null, 'artwork_url');
					if (is_object($artwork_url)) {
						$changed=$this->checkAndUpdateCmd($artwork_url, $artwork) || $changed;
					}
				}*/
			// end if changed hash
			
			$position = $this->getCmd(null, 'position');
			if (is_object($position)) {
				if(isset($aTVremoteinfo['position'])) {
					if(isset($aTVremoteinfo['total_time'])) { //aTV4+
						$changed=$this->checkAndUpdateCmd($position, (($aTVremoteinfo['position']=='')?'0':$aTVremoteinfo['position']).'/'.$aTVremoteinfo['total_time']) || $changed;
					} else {
						$changed=$this->checkAndUpdateCmd($position, $aTVremoteinfo['position']) || $changed;
					}
				} else {
					$changed=$this->checkAndUpdateCmd($position, '-') || $changed;
				}
			}
			if(isset($aTVremoteinfo['repeat'])) { // always return Off
				$repeat = $this->getCmd(null, 'repeat');
				if (is_object($repeat)) {
					switch(ucfirst($aTVremoteinfo['repeat'])) {
						case 'Off':
							$changed=$this->checkAndUpdateCmd($repeat, 'Non') || $changed;
						break;
						case 'Track':
							$changed=$this->checkAndUpdateCmd($repeat, 'Piste') || $changed;
						break;
						case 'All':
							$changed=$this->checkAndUpdateCmd($repeat, 'Tout') || $changed;
						break;
					}
				}
			}
			if(isset($aTVremoteinfo['shuffle'])) { // always return False
				$shuffle = $this->getCmd(null, 'shuffle');
				if (is_object($shuffle)) {
				    switch(ucfirst($aTVremoteinfo['shuffle'])) {
					case 'Off':                     
								$changed=$this->checkAndUpdateCmd($shuffle, 'Non') || $changed;
					break;
					case 'Songs':
								$changed=$this->checkAndUpdateCmd($shuffle, 'Songs') || $changed;
					break;
					case 'Albums':
								$changed=$this->checkAndUpdateCmd($shuffle, 'Albums') || $changed;
					break;
				    }
				}
			}
			
			if ($changed) {
				$this->refreshWidget();
			}
		} catch (Exception $e) {
			/*$aTVremoteCmd = $this->getCmd(null, 'status');
			if (is_object($aTVremoteCmd)) {
				$this->checkAndUpdateCmd($aTVremoteCmd, 'Erreur communication');
			}*/
		}
	} 
	
	public function getImage($default=true){
		if($this->getConfiguration('device','') == "HomePod" && !$default) {
			return 'plugins/aTVremote/core/template/Homepod-center.png';
		} else {
			return 'plugins/aTVremote/core/template/aTVremote.png';
		}
	}
	
	public function preSave() {
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
			$replace['#' . $cmd->getLogicalId() . '#'] = htmlspecialchars($cmd->execCmd(), ENT_QUOTES,'UTF-8');
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
			log::add('aTVremote','debug','Type widget : '.$typeWidget);
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
      
    
		if ($Test == "Apple TV"){
			return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, 'eqLogic', 'aTVremote')));
        }else{
			return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, 'eqLogic1', 'aTVremote')));
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
		$result=null;
		
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
						$eqLogic->checkAndUpdateCmd($play_state, "0");
					}
					$play_human = $eqLogic->getCmd(null, 'play_human');
					if (is_object($play_human)) {
						$eqLogic->checkAndUpdateCmd($play_human, "En pause");
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
					$eqLogic->aTVdaemonExecute('turn_on');
					// pre-set
					$power_state = $eqLogic->getCmd(null, 'power_state');
					if (is_object($power_state)) {
						$changed=$eqLogic->checkAndUpdateCmd($power_state, '1') || $changed;
					}
				break;
				case 'turn_off':
					//$eqLogic->aTVremoteExecute('turn_off set_repeat=0 set_shuffle=0');
					$eqLogic->aTVdaemonExecute('turn_off');
					// pre-set
					$power_state = $eqLogic->getCmd(null, 'power_state');
					if (is_object($power_state)) {
						$changed=$eqLogic->checkAndUpdateCmd($power_state, '0') || $changed;
					}
				break;
				case 'chain':
					$cmds = $_options['title'];
					$eqLogic->aTVdaemonExecute($cmds);
					/*$subCmds=explode(' wait ',$cmds);
					foreach($subCmds as $subCmd) {
						$eqLogic->aTVremoteExecute($subCmd);
					}*/
				break;
				case 'volume_down' :
					if($eqLogic->getConfiguration('device','') == 'HomePod') {
						$eqLogic->aTVdaemonExecute('volume_down');
						// pre-set volume
						$volume = $eqLogic->getCmd(null, 'volume');
						if (is_object($volume)) {
							$currentVol=intval($volume->execCmd());
							$currentVol-=5;
							if($currentVol <0){$currentVol=0;}
							$changed=$eqLogic->checkAndUpdateCmd($volume, $currentVol) || $changed;
						}
					} else {
						$cmds=$eqLogic->getConfiguration('LessVol');
						$cmdLessVol = cmd::byId(trim(str_replace('#', '', $cmds)));
						if(!is_object($cmdLessVol)) {return;}
						$cmdLessVol->execCmd();
					}
				break;
				case 'volume_up' :
					if($eqLogic->getConfiguration('device','') == 'HomePod') {
						$eqLogic->aTVdaemonExecute('volume_up');
						// pre-set volume
						$volume = $eqLogic->getCmd(null, 'volume');
						if (is_object($volume)) {
							$currentVol=intval($volume->execCmd());
							$currentVol+=5;
							if($currentVol >100){$currentVol=100;}
							$changed=$eqLogic->checkAndUpdateCmd($volume, $currentVol) || $changed;
						}
					} else {
						$cmds=$eqLogic->getConfiguration('MoreVol');
						$cmdMoreVol = cmd::byId(trim(str_replace('#', '', $cmds)));
						if(!is_object($cmdMoreVol)) {return;}
						$cmdMoreVol->execCmd();
					}
				break;
				case 'set_volume' :
					if($eqLogic->getConfiguration('device','') == 'HomePod') {
						$eqLogic->aTVdaemonExecute('set_volume='.$_options['slider']);
						// pre-set volume
						$volume = $eqLogic->getCmd(null, 'volume');
						if (is_object($volume)) {
							$changed=$eqLogic->checkAndUpdateCmd($volume, $_options['slider']) || $changed;
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
						log::add('aTVremote','debug','Impossible de lancer la commande : '.$logical.'='.$_options['select'].' car pas d\'appairage Companion'.(($cmds)?' -> '.$cmds:''));
					}
				break;
			}
			log::add('aTVremote','debug','Command : '.$logical.(($cmds)?' -> '.$cmds:''));
		}
		if($eqLogic->getConfiguration('version',0) == '3') {
			$eqLogic->setaTVremoteInfo();
		}
	}

}
?>
