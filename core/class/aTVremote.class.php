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
				$play_state = $aTVremote->getCmd(null, 'play_state');
				$val=$play_state->execCmd();
				if($val)
					$aTVremote->getaTVremoteInfo();
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
	
	public static function dependancy_info() {
		$return = array();
		$return['progress_file'] = jeedom::getTmpFolder('aTVremote') . '/dependance';
		$cmd = "pip3 list --format=legacy | grep pyatv";
		exec($cmd, $output, $return_var);

		$return['state'] = 'nok';
		if ($return_var==0) {
				$return['state'] = 'ok';
		}
		return $return;
	}

	public static function dependancy_install() {
		$dep_info = self::dependancy_info();
		log::remove(__CLASS__ . '_dep');
		return array('script' => dirname(__FILE__) . '/../../resources/install_#stype#.sh ' . jeedom::getTmpFolder('aTVremote') . '/dependance', 'log' => log::getPathToLog(__CLASS__ . '_dep'));
	}
	
    public static function discover($_mode) {
		log::add('aTVremote','info','Scan en cours...');
        $output=shell_exec("sudo atvremote scan");
		log::add('aTVremote','debug','Résultat brut :'.$output);
		
		if($output) {
			$return = [];
			//v0.4.0
			//$toMatch = '#Device "(.*)" at (.*) supports these services:\s* - Protocol: DMAP, Port: (.*), Device Credentials: (.*)\s - Protocol: MRP, Port: (.*),.*#';
			$toMatch = '# - (.*) at (.*) \((.*)\)#';
			if(preg_match_all($toMatch, $output, $matches,PREG_SET_ORDER)) {
				foreach($matches as $device) {
					event::add('jeedom::alert', array(
						'level' => 'warning',
						'page' => 'aTVremote',
						'message' => __('Nouvelle AppleTV detectée ', __FILE__),
					));
					//v0.4.0
					//if($device[4] != 'home sharing disabled') {
					if($device[3] != 'home sharing disabled') {
						//v0.4.0
						//$cred = $device[4];
						$cred = explode(': ',$device[3]);
												
						//v0.4.0
						//$cmdToExec="atvremote --address ".$device[2]." --port ".$device[3]." --protocol dmap --device_credentials ".$cred." device_id";
						$cmdToExec="sudo atvremote --address ".$device[2]." --login_id ".$cred[1]." device_id";
						$device_id=trim(shell_exec($cmdToExec));
						$res = [];
						$res["name"] = $device[1];
						$res["device_id"] = $device_id;
						$res["ip"]= $device[2];
						//v0.4.0
						//$res["port"]= $device[3];
						//$res["credentials"]= $cred;
						$res["port"]= 3689;
						$res["credentials"]=$cred[1];
						//v0.4.0
						//$res["MRPport"]= $device[5];
						$res["MRPport"]= null;
						
						$aTVremote = aTVremote::byLogicalId($res["device_id"], 'aTVremote');
						if (!is_object($aTVremote)) {
							$eqLogic = new aTVremote();
							$eqLogic->setName($res["name"]);
							$eqLogic->setIsEnable(0);
							$eqLogic->setIsVisible(0);
							$eqLogic->setLogicalId($res["device_id"]);
							$eqLogic->setEqType_name('aTVremote');
							$eqLogic->setConfiguration('device', 'AppleTV');
							$eqLogic->setDisplay('width','250px');
						} else $eqLogic = $aTVremote;
						
						$eqLogic->setConfiguration('ip', $res["ip"]);
						$eqLogic->setConfiguration('port', $res["port"]);
						$eqLogic->setConfiguration('credentials',$res["credentials"]);
						$eqLogic->setConfiguration('MRPport',$res["MRPport"]);
						
					
						$eqLogic->save();
						
						if(!is_object($aTVremote)) { // NEW
							event::add('jeedom::alert', array(
								'level' => 'warning',
								'page' => 'aTVremote',
								'message' => __('Module inclu avec succès ' .$res["name"], __FILE__),
							));
						} else { // UPDATED
							event::add('jeedom::alert', array(
								'level' => 'warning',
								'page' => 'aTVremote',
								'message' => __('Module mis à jour avec succès ' .$res["name"], __FILE__),
							));
						}
						$return[] = $res;
					} else {
						log::add('aTVremote','info',__('Partage à domicile non activé ! allez dans Réglages > Comptes > Partage à domicile puis redémarrez l\'AppleTV', __FILE__).' "' .$device[1].'"');
						event::add('jeedom::alert', array(
								'level' => 'warning',
								'page' => 'aTVremote',
								'message' => __('Partage à domicile non activé ! allez dans Réglages > Comptes > Partage à domicile puis redémarrez l\'AppleTV', __FILE__).' "' .$device[1].'"',
						));
					}
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
			$ip = $this->getConfiguration('ip','');
			$credentials = $this->getConfiguration('credentials','');
			$port = $this->getConfiguration('port','');
			//v0.4.0
			//$cmdToExec = "sudo atvremote --address $ip --port $port --protocol dmap --device_credentials $credentials $cmd";
	
			$cmdToExec = "";
			if($runindir) $cmdToExec.='runindir() { (cd "$1" && shift && eval "$@"); };runindir '.$runindir.' ';
			$cmdToExec .= "sudo atvremote --address $ip --login_id $credentials $cmd";
			$lastoutput=exec($cmdToExec,$return,$val_ret);
			if($val_ret)
				log::add('aTVremote','debug','ret:'.$val_ret.'--'.$lastoutput.'--'.json_encode($return).'--'.$cmdToExec);

			return $return;
		}
	}

	public function getaTVremoteInfo($data=null,$order=null,$hasToCheckPlaying=true) {
		try {

			if(!$data && $hasToCheckPlaying == true) {
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
					$aTVremoteinfo[$info]=$value;
				}
				if(!$aTVremoteinfo)
					log::add('aTVremote','debug','Résultat brut playing:'.json_encode($playing));
				log::add('aTVremote','debug','recu:'.json_encode($aTVremoteinfo));
			} else {
				$aTVremoteinfo = ((count($data))?$data:[]);
			}
			
	
			
			$isPlaying=false;
			if(isset($aTVremoteinfo['Play state'])) {

				$play_state = $this->getCmd(null, 'play_state');
				$play_human = $this->getCmd(null, 'play_human');
				switch($aTVremoteinfo['Play state']) {
					case 'Idle' :
						$this->checkAndUpdateCmd($play_state, "0");
						$this->checkAndUpdateCmd($play_human, "Inactif");
						break;
					case 'Paused':
						$this->checkAndUpdateCmd($play_state, "0");
						$this->checkAndUpdateCmd($play_human, "En pause");
						break;
					case 'No media':
						$this->checkAndUpdateCmd($play_state, "0");
						$this->checkAndUpdateCmd($play_human, "Aucun Media");
						break;
					case 'Playing':
						$this->checkAndUpdateCmd($play_state, "1");
						$this->checkAndUpdateCmd($play_human, "Lecture en cours");
						$isPlaying=true;
						break;
					case 'Loading':
						$this->checkAndUpdateCmd($play_state, "1");
						$this->checkAndUpdateCmd($play_human, "Chargement en cours");
						$isPlaying=true;
						break;
					case 'Fast forward':
						$this->checkAndUpdateCmd($play_state, "1");
						$this->checkAndUpdateCmd($play_human, "Avance rapide");
						$isPlaying=true;
						break;
					case 'Fast backward':
						$this->checkAndUpdateCmd($play_state, "1");
						$this->checkAndUpdateCmd($play_human, "Recul rapide");
						$isPlaying=true;
						break;
					default:
						$this->checkAndUpdateCmd($play_state, "0");
						$this->checkAndUpdateCmd($play_human, "Inconnu");
						break;
					break;
				}
			} 			
			
			if(isset($aTVremoteinfo['Media type'])) {
				$media_type = $this->getCmd(null, 'media_type');
				$this->checkAndUpdateCmd($media_type, $aTVremoteinfo['Media type']);
			} /*else {
				$media_type = $this->getCmd(null, 'media_type');
				$this->checkAndUpdateCmd($media_type, '');
			}			*/
			if(isset($aTVremoteinfo['Title'])) {
				$title = $this->getCmd(null, 'title');
				$this->checkAndUpdateCmd($title, $aTVremoteinfo['Title']);
			} /*else {
				$title = $this->getCmd(null, 'title');
				$this->checkAndUpdateCmd($title, '');
			}*/
			if(isset($aTVremoteinfo['Artist'])) {
				$artist = $this->getCmd(null, 'artist');
				$this->checkAndUpdateCmd($artist, $aTVremoteinfo['Artist']);
			} /*else {
				$artist = $this->getCmd(null, 'artist');
				$this->checkAndUpdateCmd($artist, '');
			}*/
			if(isset($aTVremoteinfo['Album'])) {
				$album = $this->getCmd(null, 'album');
				$this->checkAndUpdateCmd($album, $aTVremoteinfo['Album']);
			} /*else {
				$album = $this->getCmd(null, 'album');
				$this->checkAndUpdateCmd($album, '');
			}*/
			if(isset($aTVremoteinfo['Genre'])) {
				$genre = $this->getCmd(null, 'genre');
				$this->checkAndUpdateCmd($genre, $aTVremoteinfo['Genre']);
			} /*else {
				$genre = $this->getCmd(null, 'genre');
				$this->checkAndUpdateCmd($genre, '');
			}*/
			
			if(isset($aTVremoteinfo['Position'])) {
				$position = $this->getCmd(null, 'position');
				$this->checkAndUpdateCmd($position, $aTVremoteinfo['Position']);
			} /*else {
				$position = $this->getCmd(null, 'position');
				$this->checkAndUpdateCmd($position, '');
			}*/
			if(isset($aTVremoteinfo['Total time'])) { // no return < 0.4
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
							$this->checkAndUpdateCmd($repeat, 'Non');
						break;
						case 'Track':
							$this->checkAndUpdateCmd($repeat, 'Piste');
						break;
						case 'All':
							$this->checkAndUpdateCmd($repeat, 'Tout');
						break;
					}
				}
			}
			if(isset($aTVremoteinfo['Shuffle'])) { // always return False
				$shuffle = $this->getCmd(null, 'shuffle');
				if (is_object($shuffle)) {
					$this->checkAndUpdateCmd($shuffle, $aTVremoteinfo['Shuffle']);
				}
			}
			

			$NEWheight=150;
			$NEWwidth=150;
			if(isset($aTVremoteinfo['Title']) && trim($aTVremoteinfo['Title']) != "") {
				$artwork = $this->getImage();
				$rel_folder='plugins/aTVremote/resources/images/';
				$abs_folder=dirname(__FILE__).'/../../../../'.$rel_folder;
				
				$hash=$this->aTVremoteExecute('hash');
				$artwork= $rel_folder.$hash[0].'.png';
				$dest = $abs_folder.$hash[0].'.png';
				
				if(!file_exists($dest)) {
					$this->aTVremoteExecute('artwork_save',$abs_folder);//artwork.png
					
					$src=$abs_folder.'/artwork.png';
					exec("sudo chown www-data:www-data $src;sudo chmod 775 $src"); // force rights

					if(file_exists($src)) {
						
						$resize=true;
						if($resize) {
							list($width, $height) = getimagesize($src);
							$rapport = $height/$width;
							
							$NEWwidth=$NEWheight/$rapport;
							
							$imgSrc = imagecreatefrompng($src);
							$imgDest= imagecreatetruecolor($NEWwidth,$NEWheight);

							$resample=imagecopyresampled($imgDest, $imgSrc, 0, 0, 0, 0, $NEWwidth, $NEWheight, $width, $height);

							$ret = imagepng($imgDest,$dest);
		
							list($UPDATEDwidth, $UPDATEDheight) = getimagesize($dest);
							
							imagedestroy($imgSrc);
							imagedestroy($imgDest);
						} else {
							//$ret=copy($src,$dest);
							$img = file_get_contents($src);
							$ret = file_put_contents($dest,$img);
						}

						exec("sudo chown www-data:www-data $dest;sudo chmod 775 $dest"); // force rights
						$img=null;
						
						unlink($src);
					}
				}
				$artwork_url = $this->getCmd(null, 'artwork_url');
				$this->checkAndUpdateCmd($artwork_url, "<img width='$NEWwidth' height='$NEWheight' src='".$artwork."' />");
			}			
			
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
		$device = self::devicesParameters('aTV');
	
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
					$eqLogic->aTVremoteExecute('play');
				break;
				case 'pause':
					$play_state = $eqLogic->getCmd(null, 'play_state');
					$eqLogic->checkAndUpdateCmd($play_state, "0");
					$play_human = $eqLogic->getCmd(null, 'play_human');
					$eqLogic->checkAndUpdateCmd($play_human, "En pause");
					$eqLogic->aTVremoteExecute('pause');
					$hasToCheckPlaying=false;
				break;
				case 'stop':
					$play_state = $eqLogic->getCmd(null, 'play_state');
					$eqLogic->checkAndUpdateCmd($play_state, "0");
					$play_human = $eqLogic->getCmd(null, 'play_human');
					$eqLogic->checkAndUpdateCmd($play_human, "En pause");
					$eqLogic->aTVremoteExecute('stop');
					$hasToCheckPlaying=false;
				break;
				case 'set_repeat_all':
					$eqLogic->aTVremoteExecute('set_repeat=2');
				break;
				case 'set_repeat_track':
					$eqLogic->aTVremoteExecute('set_repeat=1');
				break;
				case 'set_repeat_off':
					$eqLogic->aTVremoteExecute('set_repeat=0');
				break;
				case 'set_shuffle_on':
					$eqLogic->aTVremoteExecute('set_shuffle=1');
				break;
				case 'set_shuffle_off':
					$eqLogic->aTVremoteExecute('set_shuffle=');
				break;
				
				case 'down':
					$eqLogic->aTVremoteExecute('down');
				break;
				case 'up':
					$eqLogic->aTVremoteExecute('up');
				break;
				case 'left':
					$eqLogic->aTVremoteExecute('left');
				break;
				case 'right':
					$eqLogic->aTVremoteExecute('right');
				break;
				case 'previous':
					$eqLogic->aTVremoteExecute('previous');
				break;
				case 'next':
					$eqLogic->aTVremoteExecute('next');
				break;
				case 'menu':
					$eqLogic->aTVremoteExecute('menu');
				break;
				case 'select':
					$eqLogic->aTVremoteExecute('select');
				break;
				case 'top_menu':
					$eqLogic->aTVremoteExecute('top_menu');
				break;
			}
			log::add('aTVremote','debug',$logical);
		}
		$eqLogic->getaTVremoteInfo(null,null,$hasToCheckPlaying);
	}

	/************************Getteur Setteur****************************/
}
?>
