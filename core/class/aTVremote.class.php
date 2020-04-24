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
					$play_state = $aTVremote->getCmd(null, 'play_state');
					if(is_object($play_state)) {
						$val=$play_state->execCmd();
						if($val)
							$aTVremote->getaTVremoteInfo();
					}
				}
			} catch (Exception $e) {
				log::add('aTVremote','error',json_encode($e));
			}
		}
	}	  
	public static function cronDaily() {
		// delete all artwork older than 7 days 
		$rel_folder='plugins/aTVremote/resources/images/';
		$abs_folder=dirname(__FILE__).'/../../../../'.$rel_folder;
		exec("find ".$abs_folder."*.png -mtime +7 -exec rm {} \;");
	}
	
	public static function getaTVremote($withSudo=false,$realpath=false) {
		$cmd=(($withSudo)?system::getCmdSudo():''). (($realpath)?realpath(dirname(__FILE__) . '/../../resources/atvremote/bin/atvremote'):dirname(__FILE__) . '/../../resources/atvremote/bin/atvremote');
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
	

	public static function deamon_start() {
		self::deamon_stop();

		$deamon_info = self::deamon_info();
		if ($deamon_info['launchable'] != 'ok') {
			throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
		}
		log::add('aTVremote', 'info', 'Lancement du démon aTVremote');
		$socketport = config::byKey('socketport', 'aTVremote');
		$url  = network::getNetworkAccess('internal').'/core/api/jeeApi.php' ;

		$logLevel = log::convertLogLevel(log::getLogLevel('aTVremote'));
		$deamonPath = realpath(dirname(__FILE__) . '/../../resources');
		
		$eqLogics = eqLogic::byType('aTVremote');
		$arrATV=[];
		foreach ($eqLogics as $eqLogic) {
			array_push($arrATV,$eqLogic->getConfiguration('mac',''));
		}
		
		$cmd = 'nice -n 19 nodejs ' . $deamonPath . '/aTVremoted.js ' . $url . ' ' . jeedom::getApiKey('aTVremote') .' '. $socketport . ' ' . $logLevel . ' ' . join('|',$arrATV);

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
		@file_get_contents("http://" . config::byKey('internalAddr') . ":".config::byKey('socketport', 'aTVremote')."/stop");
		sleep(3);
		if(shell_exec('ps aux | grep "resources/aTVremoted.js" | grep -v "grep" | wc -l') == '1')
			exec('sudo kill $(ps aux | grep "resources/aTVremoted.js" | grep -v "grep" | awk \'{print $2}\') &>/dev/null');
		log::add('aTVremote', 'info', 'Arrêt du démon aTVremote');
		$deamon_info = self::deamon_info();
		if ($deamon_info['state'] == 'ok') {
			sleep(1);
			exec('sudo kill -9 $(ps aux | grep "resources/aTVremoted.js" | grep -v "grep" | awk \'{print $2}\') &>/dev/null');
		}
		$deamon_info = self::deamon_info();
		if ($deamon_info['state'] == 'ok') {
			sleep(1);
			exec('sudo kill -9 $(ps aux | grep "resources/aTVremoted.js" | grep -v "grep" | awk \'{print $2}\') &>/dev/null');
		}
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

			break;
		}
	}



	
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
					$res["port"]= 3689;
					
					
					log::add('aTVremote','debug','Name :'.$res["name"]);
					log::add('aTVremote','debug','Model/SW :'.$res["model"]);
					log::add('aTVremote','debug','Address :'.$res["ip"]);
					log::add('aTVremote','debug','MAC :'.$res["mac"]);
					
					$res['device']="AppleTV";
					$modElmt=explode(' ',$res['model']);
					$res['version']=$modElmt[0];
					$res['os']=$modElmt[1];
					if($res['version'] == '3') {
						$res['osVersion']=$modElmt[3];
						$res['build']='Unknown';
					} elseif($res['version'] == '4' || $res['version'] == '4K') {
						$res['osVersion']=$modElmt[2];
						$res['build']=$modElmt[4];
					} else {
						$res['os']=$modElmt[2];
						$res['osVersion']=$modElmt[3];
						$res['build']=$modElmt[5];
						$res['device']=$modElmt[0];
					}
					
					$aTVremote = aTVremote::byLogicalId($res["mac"], 'aTVremote');
					if (!is_object($aTVremote)) {
						$eqLogic = new aTVremote();
						$eqLogic->setName($res["name"]);
						$eqLogic->setIsEnable(0);
						$eqLogic->setIsVisible(0);
						$eqLogic->setLogicalId($res["mac"]);
						$eqLogic->setEqType_name('aTVremote');
						$eqLogic->setDisplay('width','250px');
                      				$eqLogic->setDisplay('height','810px');
					} else $eqLogic = $aTVremote;
					
					$eqLogic->setConfiguration('device', $res['device']);
					$eqLogic->setConfiguration('ip', $res["ip"]);
					$eqLogic->setConfiguration('port', $res["port"]);
					$eqLogic->setConfiguration('mac',$res["mac"]);
					
					$eqLogic->setConfiguration('fullModel',$res["model"]);
					$eqLogic->setConfiguration('version',$res["version"]);
					$eqLogic->setConfiguration('os',$res["os"]);
					$eqLogic->setConfiguration('osVersion',$res["osVersion"]);
					$eqLogic->setConfiguration('build',$res["build"]);

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
	
	public static function devicesParameters($device = '') {
		$path = dirname(__FILE__) . '/../config/devices/' . $device;

		if (!is_dir($path)) {
			return false;
		}
		try {
			$file = $path . '/' . $device.'.json';
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
			
			$cmdToExec .= aTVremote::getaTVremote(true,true)." -i $mac $cmd";
			$lastoutput=exec($cmdToExec,$return,$val_ret);
			if($val_ret)
				log::add('aTVremote','debug','ret:'.$val_ret.' -- '.$lastoutput.' -- '.json_encode($return).' -- '.$cmdToExec);

			return $return;
		}
	}
	public function aTVdaemonExecute($cmd,$params=[]) {
		if($cmd) {
			$mac = $this->getConfiguration('mac','');
			
			$url="http://" . config::byKey('internalAddr') . ":".config::byKey('socketport', 'aTVremote')."/cmd?cmd=";
			$url.=urlencode($cmd).'&mac='.$mac.((count($params))?"&".http_build_query($params):'');
			$json = @file_get_contents($url);
			if($json === false) log::add('aTVremote','error','Problème de communication avec le démon : '.$url);
			log::add('aTVremote','debug',ucfirst($cmd).' brut : '.$json);
			return json_decode($json, true);
		}
	}

	public function getaTVremoteInfo($data=null,$order=null,$hasToCheckPlaying=true) {
      	try {
			$changed = false;
			
			if($this->getConfiguration('version',0) != '3'){
				$oneQuery=$this->aTVremoteExecute('power_state app playing');
				
				$power_state=$oneQuery[0];
				array_shift($oneQuery);
				log::add('aTVremote','debug','power_state : '.$power_state);
				
				if($power_state=="PowerState.Off"){
					$power = $this->getCmd(null, 'power_state');
					$changed=$this->checkAndUpdateCmd($power, '0') || $changed;
				} else {
					$power = $this->getCmd(null, 'power_state');
					$changed=$this->checkAndUpdateCmd($power, '1') || $changed;
				}
			  
				// Retour App Active 		  
				$app=$oneQuery[0];
				array_shift($oneQuery);
				$app = explode(': ',$app);
				log::add('aTVremote','debug','app : '.$app[1]);
				$app = explode(' (',$app[1]);
				$app_active = $app[0];
				log::add('aTVremote','debug','app active : '.$app_active);
				$app = explode(')',$app[1]);
				$app = explode('.',$app[0]);
				$app_secour = $app[2];
				log::add('aTVremote','debug','app active secour : '.$app_secour);
		  
				
		  
				if(isset($app_active)){
					if($app_active!='None'&&$app_active!='Unknown'){
						$app_run = $this->getCmd(null, 'app');
						$changed=$this->checkAndUpdateCmd($app_run, $app_active) || $changed;
                          
                        		} elseif($app_active!='Unknown') {
						$app_run = $this->getCmd(null, 'app');
						$changed=$this->checkAndUpdateCmd($app_run, $app_secour) || $changed;
                          
					} else {
						$app_run = $this->getCmd(null, 'app');
						$changed=$this->checkAndUpdateCmd($app_run, '-') || $changed;
                  				
					}
				} else {
					$app_run = $this->getCmd(null, 'app');
					$changed=$this->checkAndUpdateCmd($app_run, '-') || $changed;
                  			
				}
			} else {
					$oneQuery=$this->aTVremoteExecute('playing');
			}
		
      	

			if(!$data && $hasToCheckPlaying == true) {
				$playing=$oneQuery;

				foreach($playing as $line) {
					$elmt=explode(': ',$line);
					$info = trim($elmt[0]);
					if(count($elmt) > 2) {
						array_shift($elmt);
						$value= trim(join('',$elmt));
					} else if(count($elmt) == 2){
						$value= trim($elmt[1]);
					}
					$aTVremoteinfo[$info]=$value;
				}
				if(!$aTVremoteinfo)
					log::add('aTVremote','debug','Résultat brut playing:'.json_encode($playing));

				log::add('aTVremote','debug','recu:'.json_encode($aTVremoteinfo));
			} else {
				$aTVremoteinfo = ((count($data))?$data:[]);
			}
			
	
			
			$isPlaying=false;
			if(isset($aTVremoteinfo['Device state'])) {

				$play_state = $this->getCmd(null, 'play_state');
				$play_human = $this->getCmd(null, 'play_human');
				switch($aTVremoteinfo['Device state']) {

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
					break;
				}
			} 			
			
			if(isset($aTVremoteinfo['Media type'])) {
				if($aTVremoteinfo['Media type']=='Unknown'){
					$media_type = $this->getCmd(null, 'media_type');
					$changed=$this->checkAndUpdateCmd($media_type, '-') || $changed;    
				} else {
					$media_type = $this->getCmd(null, 'media_type');
					$changed=$this->checkAndUpdateCmd($media_type, $aTVremoteinfo['Media type']) || $changed;
				}
			} 

			if(isset($aTVremoteinfo['Title'])) {
				$title = $this->getCmd(null, 'title');
				$changed=$this->checkAndUpdateCmd($title, $aTVremoteinfo['Title']) || $changed;         
			} else {
				$title = $this->getCmd(null, 'title');
				$changed=$this->checkAndUpdateCmd($title, '-') || $changed;
			}

			if(isset($aTVremoteinfo['Artist'])) {
				$artist = $this->getCmd(null, 'artist');
				$changed=$this->checkAndUpdateCmd($artist, $aTVremoteinfo['Artist']) || $changed;
			} else {
				$artist = $this->getCmd(null, 'artist');
				$changed=$this->checkAndUpdateCmd($artist, '-') || $changed;
			}
			if(isset($aTVremoteinfo['Album'])) {
				$album = $this->getCmd(null, 'album');
				$changed=$this->checkAndUpdateCmd($album, $aTVremoteinfo['Album']) || $changed;
			} else {
				$album = $this->getCmd(null, 'album');
				$changed=$this->checkAndUpdateCmd($album, '-') || $changed;
			}
			if(isset($aTVremoteinfo['Genre'])) {
				$genre = $this->getCmd(null, 'genre');
				$changed=$this->checkAndUpdateCmd($genre, $aTVremoteinfo['Genre']) || $changed;
			} else {
				$genre = $this->getCmd(null, 'genre');
				$changed=$this->checkAndUpdateCmd($genre, '-') || $changed;
			}
			
			if(isset($aTVremoteinfo['Position'])) {
				$position = $this->getCmd(null, 'position');
				$changed=$this->checkAndUpdateCmd($position, $aTVremoteinfo['Position']) || $changed;
			} else {
				$position = $this->getCmd(null, 'position');
				$changed=$this->checkAndUpdateCmd($position, '-') || $changed;
			}

			/*if(isset($aTVremoteinfo['Total time'])) { // no return < 0.4
				$total_time = $this->getCmd(null, 'total_time');
				if (is_object($total_time)) {
					$this->checkAndUpdateCmd($total_time, $aTVremoteinfo['Total time']);
				}
			} /*else {
				$total_time = $this->getCmd(null, 'total_time');
				if (is_object($total_time)) {
					$this->checkAndUpdateCmd($total_time, '');
				}
			}*/
			
			if(isset($aTVremoteinfo['Repeat'])) { // always return Off
				$repeat = $this->getCmd(null, 'repeat');
				if (is_object($repeat)) {
					switch($aTVremoteinfo['Repeat']) {
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
			if(isset($aTVremoteinfo['Shuffle'])) { // always return False
				$shuffle = $this->getCmd(null, 'shuffle');
				if (is_object($shuffle)) {
				    switch($aTVremoteinfo['Shuffle']) {
					case 'Off':                     
								$changed=$this->checkAndUpdateCmd($shuffle, 'Non') || $changed;
					break;
					case 'Songs':
								$changed=$this->checkAndUpdateCmd($shuffle, 'Oui') || $changed;
					break;
					case 'Albums':
								$changed=$this->checkAndUpdateCmd($shuffle, 'Oui') || $changed;
					break;
				    }
				}
			}
			

			$NEWheight=150;
			$NEWwidth=150;
			if(isset($aTVremoteinfo['Title']) && trim($aTVremoteinfo['Title']) != "") {
				$rel_folder='plugins/aTVremote/resources/images/';
				$abs_folder=dirname(__FILE__).'/../../../../'.$rel_folder;
				
				$hash=$this->aTVremoteExecute('hash');
				$hash=md5($hash[0]);
				$artwork= $rel_folder.$hash.'.jpg';
				$dest = $abs_folder.$hash.'.jpg';
				
				if(!file_exists($dest)) {
					$this->aTVremoteExecute('artwork_save',$abs_folder);//artwork.png
					
					$src=$abs_folder.'artwork.png';
					exec("sudo chown www-data:www-data $src;sudo chmod 775 $src"); // force rights

					if(file_exists($src)) {
						$resize=false;
						list($width, $height) = getimagesize($src);
						if($width != $NEWwidth && $height != $NEWheight) $resize=true;
						if($resize) {
							$rapport = $height/$width;
							
							$NEWwidth=$NEWheight/$rapport;
							$exif=exif_imagetype($src);
							log::add('aTVremote','debug','artwork is format :'.$exif);
							if($exif == IMAGETYPE_JPEG) {
								$imgSrc = imagecreatefromjpeg($src);
							} elseif($exif == IMAGETYPE_PNG) {
								$imgSrc = imagecreatefrompng($src);
							}
							$imgDest= imagecreatetruecolor($NEWwidth,$NEWheight);

							$resample=imagecopyresampled($imgDest, $imgSrc, 0, 0, 0, 0, $NEWwidth, $NEWheight, $width, $height);

							$ret = imagejpeg($imgDest,$dest);
		
							list($UPDATEDwidth, $UPDATEDheight) = getimagesize($dest);
							
							imagedestroy($imgSrc);
							imagedestroy($imgDest);
						} else {
							rename($src,$dest);
						}

						exec("sudo chown www-data:www-data $dest;sudo chmod 775 $dest"); // force rights
						$img=null;
						if($src=realpath($src)) {
							unlink($src);
						}
					} else {
						$artwork = $this->getImage();
						log::add('aTVremote','debug',$src.' doesnt exists, display default image...');
					}
				} else {
					log::add('aTVremote','debug',$dest.' already exists, just display it...');
				}
			} else {
				$artwork = $this->getImage();
            }		
			$artwork_url = $this->getCmd(null, 'artwork_url');
			$changed=$this->checkAndUpdateCmd($artwork_url, "<img width='$NEWwidth' height='$NEWheight' src='".$artwork."' />") || $changed;
			if ($changed) 
				$this->refreshWidget();
		} catch (Exception $e) {
			/*$aTVremoteCmd = $this->getCmd(null, 'status');
			if (is_object($aTVremoteCmd)) {
				$this->checkAndUpdateCmd($aTVremoteCmd, 'Erreur communication');
			}*/
		}
	} 
	
	/*public static function getImage(){
		return 'plugins/aTVremote/plugin_info/aTVremote_icon.png';
	}*/
	
	public function getImage(){
		return 'plugins/aTVremote/plugin_info/aTVremote_icon.png';
	}
	
	public function postSave() {
		$order=0;
		$os=$this->getConfiguration('os','');
		$device = self::devicesParameters($os);
	
		if($device) {
			foreach($device['commands'] as $cmd) {
				$order++;
				
				$newCmd = $this->getCmd(null, $cmd['logicalId']);
				if (!is_object($newCmd)) {
					$newCmd = new aTVremoteCmd();
					$newCmd->setLogicalId($cmd['logicalId']);
					$newCmd->setIsVisible($cmd['isVisible']);
					$newCmd->setOrder($order);
					$newCmd->setName(__($cmd['name'], __FILE__));
				}
				
				$newCmd->setType($cmd['type']);
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
				$newCmd->setSubType($cmd['subtype']);
				$newCmd->setEqLogic_id($this->getId());
				if(isset($cmd['value'])) {
					$linkStatus = $this->getCmd(null, $cmd['value']);
					$newCmd->setValue($linkStatus->getId());
				}
				$newCmd->save();				
			}
		
		}

		$this->getaTVremoteInfo(null,$order);
	}
}

class aTVremoteCmd extends cmd {
	/***************************Attributs*******************************/


	/*************************Methode static****************************/

	/***********************Methode d'instance**************************/

	public function execute($_options = null) {
		if ($this->getType() == '') {
			return '';
		}
		$eqLogic = $this->getEqlogic();

		$logical = $this->getLogicalId();
		$result=null;
		$hasToCheckPlaying=true;
		
		if ($logical != 'refresh'){
			switch ($logical) {
				case 'play':
					$eqLogic->aTVdaemonExecute('play');
				break;
				case 'pause':
					$play_state = $eqLogic->getCmd(null, 'play_state');
					$eqLogic->checkAndUpdateCmd($play_state, "0");
					$play_human = $eqLogic->getCmd(null, 'play_human');
					$eqLogic->checkAndUpdateCmd($play_human, "En pause");
					$eqLogic->aTVdaemonExecute('pause');
					$hasToCheckPlaying=false;
				break;
				case 'stop':
					$play_state = $eqLogic->getCmd(null, 'play_state');
					$eqLogic->checkAndUpdateCmd($play_state, "0");
					$play_human = $eqLogic->getCmd(null, 'play_human');
					$eqLogic->checkAndUpdateCmd($play_human, "En pause");
					$eqLogic->aTVdaemonExecute('stop');
					$hasToCheckPlaying=false;
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
				break;
				case 'turn_off':
					//$eqLogic->aTVremoteExecute('turn_off set_repeat=0 set_shuffle=0');
					$eqLogic->aTVdaemonExecute('turn_off');
				break;
				case 'chain':
					$cmds = $_options['title'];
					$subCmds=explode(' wait ',$cmds);
					foreach($subCmds as $subCmd) {
						$eqLogic->aTVremoteExecute($subCmd);
					}
				break;
			}
			log::add('aTVremote','debug','Command : '.$logical.(($cmds)?' -> '.$cmds:''));
		}
		$eqLogic->getaTVremoteInfo(null,null,$hasToCheckPlaying);
	}

	/************************Getteur Setteur****************************/
}
?>
