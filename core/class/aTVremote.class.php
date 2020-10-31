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
					if($aTVremote->getConfiguration('version',0) == '3'){
						$play_state = $aTVremote->getCmd(null, 'play_state');
						if(is_object($play_state)) {
							$val=$play_state->execCmd();
							if($val) {
								$aTVremote->setaTVremoteInfo();
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
		$rel_folder='plugins/aTVremote/resources/images/';
		$abs_folder=dirname(__FILE__).'/../../../../'.$rel_folder;
      		//$finale_folder= $abs_folder.$id.'/'; // no need to go to subfolder, find does it ;)
		exec(system::getCmdSudo()."find ".$abs_folder." -name *.jpg -mtime +30 -delete;");
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
		$arrATV3=[];
		$arrATV4=[];
		foreach ($eqLogics as $eqLogic) {
			if($eqLogic->getConfiguration('version',0) == '3') {
				array_push($arrATV3,$eqLogic->getConfiguration('mac',''));
			} else {
				array_push($arrATV4,$eqLogic->getConfiguration('mac',''));
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
		
		$cmd = 'nice -n 19 nodejs ' . $deamonPath . '/aTVremoted.js ' . $url . ' ' . jeedom::getApiKey('aTVremote') .' '. $socketport . ' ' . $logLevel . ' ' . $arrATV3 . ' ' . $arrATV4;

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
			exec('sudo kill $(ps aux | grep "resources/aTVremoted.js" | grep -v "grep" | awk \'{print $2}\') >/dev/null 2>&1');
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
						$eqLogic->setDisplay('width','138px');
                      	$eqLogic->setDisplay('height','500px');
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
						$eqLogic->aTVdaemonConnectATV();
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
	public function aTVdaemonConnectATV($params=[]) {

		$mac = $this->getConfiguration('mac','');
		$version = $this->getConfiguration('version',0);
		
		$url="http://" . config::byKey('internalAddr') . ":".config::byKey('socketport', 'aTVremote')."/connect?";
		$url.='mac='.$mac.'&version='.$version.((count($params))?"&".http_build_query($params):'');
		$json = @file_get_contents($url);
		if($json === false) log::add('aTVremote','error','Problème de communication avec le démon : '.$url);
		log::add('aTVremote','debug','connect brut : '.$json);
		return json_decode($json, true);

	}
	public function aTVdaemonDisconnectATV($params=[]) {

		$mac = $this->getConfiguration('mac','');
		
		$url="http://" . config::byKey('internalAddr') . ":".config::byKey('socketport', 'aTVremote')."/disconnect?";
		$url.='mac='.$mac.((count($params))?"&".http_build_query($params):'');
		$json = @file_get_contents($url);
		if($json === false) log::add('aTVremote','error','Problème de communication avec le démon : '.$url);
		log::add('aTVremote','debug','disconnect brut : '.$json);
		return json_decode($json, true);

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
        	
		$id=$this->getLogicalId();
        	//log::add('aTVremote','debug',$id);
      
		$NEWheight=-1;
		$NEWwidth=138;
		$changed=false;
		
		$rel_folder='plugins/aTVremote/resources/images/';
		$abs_folder=dirname(__FILE__).'/../../../../'.$rel_folder;
      		$finale_folder= $abs_folder.$id.'/';
      	
      		if (!file_exists($finale_folder)) {
      			exec("cd $abs_folder && sudo mkdir $id && sudo chown www-data:www-data $finale_folder >/dev/null 2>&1;sudo chmod 775 $finale_folder >/dev/null 2>&1");
          		log::add('aTVremote','debug','Création répertoire pour '.$id);
        	}
		
		if($this->getConfiguration('version',0) == '3') {
			$hash=$this->aTVremoteExecute('hash');
			$hash=$hash[0];	
		}
		$hash=md5($hash);
		
		$rel_folder2='plugins/aTVremote/resources/images/'.$id.'/';
		$artwork= $rel_folder2.$hash.'.jpg';
		$dest = $finale_folder.$hash.'.jpg';
		
		if(!file_exists($dest)) {
			$this->aTVdaemonExecute('artwork_save='.$NEWwidth.','.$NEWheight);//artwork.png
			
			$src=$finale_folder.'artwork.png';

            exec("sudo chown www-data:www-data $src >/dev/null 2>&1;sudo chmod 775 $src >/dev/null 2>&1"); // force rights
			if($this->getConfiguration('version',0) == '3') { 
					
				sleep(5);
		
				if(file_exists($src)) {
					rename($src,$dest);
					exec("sudo chown www-data:www-data $dest >/dev/null 2>&1;sudo chmod 775 $dest >/dev/null 2>&1"); // force rights
					log::add('aTVremote','debug','--displaying '.$dest.'...');				
				} else {
					$artwork=null;
				}
			} else {
				if(file_exists($src)) {
					rename($src,$dest);
					exec("sudo chown www-data:www-data $dest >/dev/null 2>&1;sudo chmod 775 $dest >/dev/null 2>&1"); // force rights
					log::add('aTVremote','debug','--displaying '.$dest.'...');				
				} else {
					$artwork=null;
				}
			}
		} else {
			log::add('aTVremote','debug','--dest already exists, just display it...'.$dest);
			touch($dest); // update mtime
			$src=$finale_folder.'artwork.png';
			exec("sudo rm $src >/dev/null 2>&1");
		}
		
		if($artwork) {
			$artwork_url = $this->getCmd(null, 'artwork_url');
			$changed=$this->checkAndUpdateCmd($artwork_url, $artwork) || $changed;
		}
		
		return $changed;
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
					} else if(count($elmt) == 2){
						$value= trim($elmt[1]);
					}
					$info=str_replace(' ','_',strtolower($info));
					$aTVremoteinfo[$info]=$value;
				}
			}
			
			
			
			$changed = false;
			
			log::add('aTVremote','debug','recu:'.json_encode($aTVremoteinfo));

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
					break;
				}
			} 			
			
			if(isset($aTVremoteinfo['media_type'])) {
				if($aTVremoteinfo['media_type']=='unknown'){
					$media_type = $this->getCmd(null, 'media_type');
					$changed=$this->checkAndUpdateCmd($media_type, '-') || $changed;    
				} else {
					$media_type = $this->getCmd(null, 'media_type');
					$changed=$this->checkAndUpdateCmd($media_type, ucfirst($aTVremoteinfo['media_type'])) || $changed;
				}
			} 

			if(isset($aTVremoteinfo['title'])) {
				$title = $this->getCmd(null, 'title');
				$changed=$this->checkAndUpdateCmd($title, htmlentities($aTVremoteinfo['title'],ENT_QUOTES)) || $changed;         
			} else {
				$title = $this->getCmd(null, 'title');
				$changed=$this->checkAndUpdateCmd($title, '-') || $changed;
			}

			if(isset($aTVremoteinfo['artist'])) {
				$artist = $this->getCmd(null, 'artist');
				$changed=$this->checkAndUpdateCmd($artist, htmlentities($aTVremoteinfo['artist'],ENT_QUOTES)) || $changed;
			} else {
				$artist = $this->getCmd(null, 'artist');
				$changed=$this->checkAndUpdateCmd($artist, '-') || $changed;
			}
			if(isset($aTVremoteinfo['album'])) {
				$album = $this->getCmd(null, 'album');
				$changed=$this->checkAndUpdateCmd($album, htmlentities($aTVremoteinfo['album'],ENT_QUOTES)) || $changed;
			} else {
				$album = $this->getCmd(null, 'album');
				$changed=$this->checkAndUpdateCmd($album, '-') || $changed;
			}
			if(isset($aTVremoteinfo['genre'])) {
				$genre = $this->getCmd(null, 'genre');
				$changed=$this->checkAndUpdateCmd($genre, htmlentities($aTVremoteinfo['genre'],ENT_QUOTES)) || $changed;
			} else {
				$genre = $this->getCmd(null, 'genre');
				$changed=$this->checkAndUpdateCmd($genre, '-') || $changed;
			}
			
			if(isset($aTVremoteinfo['position'])) {
				if(isset($aTVremoteinfo['total_time'])) { //aTV4+
					$position = $this->getCmd(null, 'position');
					$changed=$this->checkAndUpdateCmd($position, (($aTVremoteinfo['position']=='')?'0':$aTVremoteinfo['position']).'/'.$aTVremoteinfo['total_time']) || $changed;
				} else {
					$position = $this->getCmd(null, 'position');
					$changed=$this->checkAndUpdateCmd($position, $aTVremoteinfo['position']) || $changed;
				}
			} else {
				$position = $this->getCmd(null, 'position');
				$changed=$this->checkAndUpdateCmd($position, '-') || $changed;
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
			
			if($this->getConfiguration('version',0) != '3') {
				if(isset($aTVremoteinfo['app_id'])) {
					$changed=$this->setApp($aTVremoteinfo['app'],$aTVremoteinfo['app_id']) || $changed;
				} else {
					$app = $this->getCmd(null, 'app');
					$changed=$this->checkAndUpdateCmd($app, '-') || $changed;
				}
			}
			

			if(isset($aTVremoteinfo['title']) && trim($aTVremoteinfo['title']) != "" && isset($aTVremoteinfo['device_state']) && $aTVremoteinfo['device_state'] != "Paused") {
				$changed=$this->setArtwork($aTVremoteinfo['hash']) || $changed;
			} else if($aTVremoteinfo['device_state'] != "Paused") {
				$artwork = $this->getImage();
				$artwork_url = $this->getCmd(null, 'artwork_url');
				$changed=$this->checkAndUpdateCmd($artwork_url, $artwork) || $changed;
           	}	

			
			
			if ($changed) 
				$this->refreshWidget();
		} catch (Exception $e) {
			/*$aTVremoteCmd = $this->getCmd(null, 'status');
			if (is_object($aTVremoteCmd)) {
				$this->checkAndUpdateCmd($aTVremoteCmd, 'Erreur communication');
			}*/
		}
	} 
	
	public function getImage(){
		return 'plugins/aTVremote/core/template/aTVremote.png';
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
		if($this->getConfiguration('version',0) == '3')
			$this->setaTVremoteInfo();
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
			$replace['#' . $cmd->getLogicalId() . '#'] = $cmd->execCmd();
			$replace['#' . $cmd->getLogicalId() . '_id#'] = $cmd->getId();
			if ($cmd->getIsHistorized() == 1) {
				$replace['#' . $cmd->getLogicalId() . '_history#'] = 'history cursor';
			}
		}
      		
      		$replace["#os#"] = $this->getConfiguration('os',0);
      		$replace["#osVersion#"] = $this->getConfiguration('osVersion',0);
      		$replace["#build#"] = $this->getConfiguration('build',0);
		
		$replace["#ATV#"] = $this->getConfiguration('version',0);
		
      		$marquee = config::byKey('marquee', 'aTVremote', 0);
      		if ($marquee == 1){
      			$replace["#marquee#"] = "scroll";
      			//log::add('aTVremote','debug','--dest already exists, just display it...'.$marquee);
        	} else {
          		$replace["#marquee#"] = "alternate";
        	};
      
		foreach ($this->getCmd('action') as $cmd) {
			$replace['#cmd_' . $cmd->getLogicalId() . '_id#'] = $cmd->getId();
		}
		/**$lentocheck = 24;
		if ($version == 'mobile'){
			$lentocheck = 17;
		}**/

		return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, 'eqLogic', 'aTVremote')));
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
				break;
				case 'stop':
					$play_state = $eqLogic->getCmd(null, 'play_state');
					$eqLogic->checkAndUpdateCmd($play_state, "0");
					$play_human = $eqLogic->getCmd(null, 'play_human');
					$eqLogic->checkAndUpdateCmd($play_human, "En pause");
					$eqLogic->aTVdaemonExecute('stop');
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
					$eqLogic->aTVdaemonExecute($cmds);
					/*$subCmds=explode(' wait ',$cmds);
					foreach($subCmds as $subCmd) {
						$eqLogic->aTVremoteExecute($subCmd);
					}*/
				break;
              			case 'volume_down' :
					#$eqLogic->aTVdaemonExecute('volume_down');
					$cmds=$this->getConfiguration('LessVol');
					$cmdLessVol = cmd::byId(trim(str_replace('#', '', $cmds)));
					if (!is_object($cmdLessVol)) {
						return;
					}
					$cmdLessVol->execCmd();
				break;
                		case 'volume_up' :
					#$eqLogic->aTVdaemonExecute('volume_up');
					$cmds=$this->getConfiguration('MoreVol');
					$cmdMoreVol = cmd::byId(trim(str_replace('#', '', $cmds)));
					if (!is_object($cmdMoreVol)) {
						return;
					}
					$cmdMoreVol->execCmd();
				break;
			}
			log::add('aTVremote','debug','Command : '.$logical.(($cmds)?' -> '.$cmds:''));
		}
		if($eqLogic->getConfiguration('version',0) == '3')
			$eqLogic->setaTVremoteInfo();
	}

	/************************Getteur Setteur****************************/
}
?>
