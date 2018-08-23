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
	
	public static function getStructure ($name) {
	
		switch($name) {
			case "cmds" :
				return ["down"=>["trad"=>"Bouton Bas","icon"=>"fa-arrow-down"],
						"up"=>["trad"=>"Bouton Haut","icon"=>"fa-arrow-up"],
						"left"=>["trad"=>"Bouton Gauche","icon"=>"fa-arrow-left"],
						"right"=>["trad"=>"Bouton Droit","icon"=>"fa-arrow-right"],
						"previous"=>["trad"=>"Bouton Précédent","icon"=>"fa-step-backward"],
						"next"=>["trad"=>"Bouton Suivant","icon"=>"fa-step-forward"],
						"menu"=>["trad"=>"Bouton Menu","icon"=>"fa-cog"],
						"select"=>["trad"=>"Bouton Selection","icon"=>"fa-crosshairs"],
						"top_menu"=>["trad"=>"Bouton Home","icon"=>"fa-desktop"]
					];
			break;
			case "infos" :
				return ["artwork_url"=>"URL Artwork",
						"artist"=>"Artiste",
						"title"=>"Titre",
						"album"=>"Album",
						"media_type"=>"Type Media",
						"position"=>"Position",
						"total_time"=>"Temp total"
					];
			break;
		}		
	}

	public static function dependancy_info() {
		$return = array();
		$return['progress_file'] = jeedom::getTmpFolder('aTVremote') . '/dependance';
		$cmd = "pip3 list --format=legacy | grep pyatv";
		exec($cmd, $output, $return_var);

		$return['state'] = 'nok';
		if (array_key_exists(0,$output)) {
			if ($output[0] != "" ) {
				$return['state'] = 'ok';
			}
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
		if($output) {
			$return = [];
			//v0.4.0
			//$toMatch = '#Device "(.*)" at (.*) supports these services:\s* - Protocol: DMAP, Port: (.*), Device Credentials: (.*)\s - Protocol: MRP, Port: (.*),.*#';
			$toMatch = '# - (.*) at (.*) \(login id: (.*)\)#';
			if(preg_match_all($toMatch, $output, $matches,PREG_SET_ORDER)) {
				foreach($matches as $device) {
					event::add('jeedom::alert', array(
						'level' => 'warning',
						'page' => 'aTVremote',
						'message' => __('Nouvelle AppleTV detectée ', __FILE__),
					));
					//v0.4.0
					//$cmdToExec="atvremote --address ".$device[2]." --port ".$device[3]." --protocol dmap --device_credentials ".$device[4]." device_id";
					$cmdToExec="sudo atvremote --address ".$device[2]." --login_id ".$device[3]." device_id";
					$device_id=trim(shell_exec($cmdToExec));
					$res = [];
					$res["name"] = $device[1];
					$res["device_id"] = $device_id;
					$res["ip"]= $device[2];
					//v0.4.0
					//$res["port"]= $device[3];
					//$res["credentials"]= $device[4];
					$res["port"]= null;
					$res["credentials"]=$device[3];
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
				}
			}
			
			log::add('aTVremote','info',json_encode($return));
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

			return $return;
		}
	}
	/*public function toHtml($_version = 'dashboard') {
		$replace = $this->preToHtml($_version, array('#background-color#' => '#4a89dc'));
		if (!is_array($replace)) {
			return $replace;
		}
		$version = jeedom::versionAlias($_version);
		
		//COVER
		$replace['#thumbnail#'] = 'plugins/kodi/core/template/images/kodi_icon.png?time=' . microtime();
		$cmd_thumbnail = $this->getCmd(null, 'thumbnail');
		if (is_object($cmd_thumbnail)) {
			$url = $cmd_thumbnail->execCmd();
			if ($url != '') {
				$thumb = $url . '?time=' . microtime();
				$replace['#thumbnail#'] = $thumb;
			}
		}
	}*/
	public function getaTVremoteInfo($data=null,$order=null,$hasToCheckPlaying=true) {
		try {
			$aTVremoteinfo = [];
			if(!$data && $hasToCheckPlaying == true) {
				$playing=$this->aTVremoteExecute('playing');
				foreach($playing as $line) {
					$elmt=explode(': ',$line);
					$info = trim($elmt[0]);
					$value= trim($elmt[1]);
					$aTVremoteinfo[$info]=$value;
				}
			}
			
			log::add('aTVremote','debug','recu:'.json_encode($aTVremoteinfo));
			
			
			if(isset($aTVremoteinfo['Media type'])) {
				$media_type = $this->getCmd(null, 'media_type');
				$this->checkAndUpdateCmd($media_type, $aTVremoteinfo['Media type']);
			} else {
				$media_type = $this->getCmd(null, 'media_type');
				$this->checkAndUpdateCmd($media_type, '');
			}
			
			$isPlaying=false;
			if(isset($aTVremoteinfo['Play state'])) {

				$play_state = $this->getCmd(null, 'play_state');
				switch($aTVremoteinfo['Play state']) {
					case 'Idle' :
					case 'Paused':
					case 'No media':
						$this->checkAndUpdateCmd($play_state, "0");
					break;
					case 'Playing':
					case 'Loading':
					case 'Fast forward':
					case 'Fast backward':
						$this->checkAndUpdateCmd($play_state, "1");
						$isPlaying=true;
					break;
				}
			}
			$NEWheight=150;
			$NEWwidth=150;
			if($isPlaying) {
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
							//log::add('aTVremote','debug',((!$imgSrc)?'noSRC':'').' '.((!$imgDest)?'noDEST':'').' '.$resample.' '.$ret.' = resize from '.$width.'x'.$height.' to '.$UPDATEDwidth.'x'.$UPDATEDheight.' (should be '.$NEWwidth.'x'.$NEWheight.')');
						} else {
							log::add('aTVremote','debug','no resize');
							//$ret=copy($src,$dest);
							$img = file_get_contents($src);
							$ret = file_put_contents($dest,$img);
						}

						exec("sudo chown www-data:www-data $dest;sudo chmod 775 $dest"); // force rights
						$img=null;
						
						unlink($src);
					}
				}
			} else {
				$artwork = $this->getImage();
			}
			$artwork_url = $this->getCmd(null, 'artwork_url');
			$this->checkAndUpdateCmd($artwork_url, "<img width='$NEWwidth' height='$NEWheight' src='".$artwork."' />");
			
			
			if(isset($aTVremoteinfo['Title'])) {
				$title = $this->getCmd(null, 'title');
				$this->checkAndUpdateCmd($title, $aTVremoteinfo['Title']);
			} else {
				$title = $this->getCmd(null, 'title');
				$this->checkAndUpdateCmd($title, '');
			}
			if(isset($aTVremoteinfo['Artist'])) {
				$artist = $this->getCmd(null, 'artist');
				$this->checkAndUpdateCmd($artist, $aTVremoteinfo['Artist']);
			} else {
				$artist = $this->getCmd(null, 'artist');
				$this->checkAndUpdateCmd($artist, '');
			}
			if(isset($aTVremoteinfo['Album'])) {
				$album = $this->getCmd(null, 'album');
				$this->checkAndUpdateCmd($album, $aTVremoteinfo['Album']);
			} else {
				$album = $this->getCmd(null, 'album');
				$this->checkAndUpdateCmd($album, '');
			}
			if(isset($aTVremoteinfo['Genre'])) {
				$genre = $this->getCmd(null, 'genre');
				$this->checkAndUpdateCmd($genre, $aTVremoteinfo['Genre']);
			} else {
				$genre = $this->getCmd(null, 'genre');
				$this->checkAndUpdateCmd($genre, '');
			}
			
			if(isset($aTVremoteinfo['Position'])) {
				$position = $this->getCmd(null, 'position');
				$this->checkAndUpdateCmd($position, $aTVremoteinfo['Position']);
			} else {
				$position = $this->getCmd(null, 'position');
				$this->checkAndUpdateCmd($position, '');
			}
			if(isset($aTVremoteinfo['Total time'])) {
				$total_time = $this->getCmd(null, 'total_time');
				$this->checkAndUpdateCmd($total_time, $aTVremoteinfo['Total time']);
			} else {
				$total_time = $this->getCmd(null, 'total_time');
				$this->checkAndUpdateCmd($total_time, '');
			}
			
			if(isset($aTVremoteinfo['Repeat'])) {
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
			if(isset($aTVremoteinfo['Shuffle'])) {
				$shuffle = $this->getCmd(null, 'shuffle');
				if (is_object($shuffle)) {
					$this->checkAndUpdateCmd($shuffle, $aTVremoteinfo['Shuffle']);
				}
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
		
		$order=1;
		$play_state = $this->getCmd(null, 'play_state');
		if (!is_object($play_state)) {
			$play_state = new aTVremoteCmd();
			$play_state->setLogicalId('play_state');
			$play_state->setIsVisible(1);
			$play_state->setOrder($order);
			$play_state->setName(__('Lecture en cours', __FILE__));
		}
		$play_state->setType('info');
		$play_state->setSubType('binary');
		$play_state->setEqLogic_id($this->getId());
		$play_state->setDisplay('generic_type', 'SWITCH_STATE');
		$play_state->save();
		
		$order++;
		$play = $this->getCmd(null, 'play');
		if (!is_object($play)) {
			$play = new aTVremoteCmd();
			$play->setLogicalId('play');
			$play->setDisplay('icon','<i class="fa fa-play"></i>');
			$play->setIsVisible(1);
			$play->setOrder($order);
			$play->setName(__('Bouton Lecture', __FILE__));
		}
		$play->setType('action');
		$play->setSubType('other');
		$play->setEqLogic_id($this->getId());
		$play->setValue($play_state->getId());
		$play->setDisplay('generic_type', 'SWITCH_ON');
		$play->save();
		
		$order++;
		$pause = $this->getCmd(null, 'pause');
		if (!is_object($pause)) {
			$pause = new aTVremoteCmd();
			$pause->setLogicalId('pause');
			$pause->setDisplay('icon','<i class="fa fa-pause"></i>');
			$pause->setIsVisible(1);
			$pause->setOrder($order);
			$pause->setName(__('Bouton Pause', __FILE__));
		}
		$pause->setType('action');
		$pause->setSubType('other');
		$pause->setEqLogic_id($this->getId());
		$pause->setValue($play_state->getId());
		$pause->setDisplay('generic_type', 'SWITCH_OFF');
		$pause->save();
		
		$order++;
		$stop = $this->getCmd(null, 'stop');
		if (!is_object($stop)) {
			$stop = new aTVremoteCmd();
			$stop->setLogicalId('stop');
			$stop->setDisplay('icon','<i class="fa fa-stop"></i>');
			$stop->setIsVisible(1);
			$stop->setOrder($order);
			$stop->setName(__('Bouton Stop', __FILE__));
		}
		$stop->setType('action');
		$stop->setSubType('other');
		$stop->setEqLogic_id($this->getId());
		$stop->setValue($play_state->getId());
		$stop->setDisplay('generic_type', 'GENERIC_ACTION');
		$stop->save();


// SHUFFLE		
/* // don't work now, appletv don't get status
		$order++;
		$shuffle = $this->getCmd(null, 'shuffle');
		if (!is_object($shuffle)) {
			$shuffle = new aTVremoteCmd();
			$shuffle->setLogicalId('shuffle');
			$shuffle->setIsVisible(1);
			$shuffle->setOrder($order);
			$shuffle->setName(__('Aléatoire', __FILE__));
		}
		$shuffle->setType('info');
		$shuffle->setSubType('binary');
		$shuffle->setEqLogic_id($this->getId());
		$shuffle->setDisplay('generic_type', 'SWITCH_STATE');
		$shuffle->save();
		
		$order++;
		$set_shuffle_on = $this->getCmd(null, 'set_shuffle_on');
		if (!is_object($set_shuffle_on)) {
			$set_shuffle_on = new aTVremoteCmd();
			$set_shuffle_on->setLogicalId('set_shuffle_on');
			$set_shuffle_on->setDisplay('icon','<i class="fa fa-random"></i>');
			$set_shuffle_on->setIsVisible(1);
			$set_shuffle_on->setOrder($order);
			$set_shuffle_on->setName(__('Bouton Aléatoire ON', __FILE__));
		}
		$set_shuffle_on->setType('action');
		$set_shuffle_on->setSubType('other');
		$set_shuffle_on->setEqLogic_id($this->getId());
		//$set_shuffle_on->setValue($play_state->getId());
		$set_shuffle_on->setDisplay('generic_type', 'SWITCH_ON');
		$set_shuffle_on->save();
		
		$order++;
		$set_shuffle_off = $this->getCmd(null, 'set_shuffle_off');
		if (!is_object($set_shuffle_off)) {
			$set_shuffle_off = new aTVremoteCmd();
			$set_shuffle_off->setLogicalId('set_shuffle_off');
			$set_shuffle_off->setDisplay('icon','<i class="fa fa-random" style="opacity:0.3"></i>');
			$set_shuffle_off->setIsVisible(1);
			$set_shuffle_off->setOrder($order);
			$set_shuffle_off->setName(__('Bouton Aléatoire OFF', __FILE__));
		}
		$set_shuffle_off->setType('action');
		$set_shuffle_off->setSubType('other');
		$set_shuffle_off->setEqLogic_id($this->getId());
		//$set_shuffle_off->setValue($play_state->getId());
		$set_shuffle_off->setDisplay('generic_type', 'SWITCH_OFF');
		$set_shuffle_off->save();
*/
		
// REPEAT		
/* // don't work now, appletv don't get status

		$order++;
		$repeat = $this->getCmd(null, 'repeat');
		if (!is_object($repeat)) {
			$repeat = new aTVremoteCmd();
			$repeat->setLogicalId('repeat');
			$repeat->setIsVisible(1);
			$repeat->setOrder($order);
			$repeat->setName(__('Répétition', __FILE__));
		}
		$repeat->setType('info');
		$repeat->setSubType('string');
		$repeat->setEqLogic_id($this->getId());
		$repeat->setDisplay('generic_type', 'GENERIC_INFO');
		$repeat->save();
		
		$order++;
		$set_repeat_off = $this->getCmd(null, 'set_repeat_off');
		if (!is_object($set_repeat_off)) {
			$set_repeat_off = new aTVremoteCmd();
			$set_repeat_off->setLogicalId('set_repeat_off');
			$set_repeat_off->setDisplay('icon','<i class="fa fa-repeat" style="opacity:0.3"></i>');
			$set_repeat_off->setIsVisible(1);
			$set_repeat_off->setOrder($order);
			$set_repeat_off->setName(__('Bouton Répétition OFF', __FILE__));
		}
		$set_repeat_off->setType('action');
		$set_repeat_off->setSubType('other');
		$set_repeat_off->setEqLogic_id($this->getId());
		//$set_repeat_off->setValue($play_state->getId());
		$set_repeat_off->setDisplay('generic_type', 'GENERIC_ACTION');
		$set_repeat_off->save();

		$order++;
		$set_repeat_track = $this->getCmd(null, 'set_repeat_track');
		if (!is_object($set_repeat_track)) {
			$set_repeat_track = new aTVremoteCmd();
			$set_repeat_track->setLogicalId('set_repeat_track');
			$set_repeat_track->setDisplay('icon','<i class="fa fa-repeat"></i>');
			$set_repeat_track->setIsVisible(1);
			$set_repeat_track->setOrder($order);
			$set_repeat_track->setName(__('Bouton Répétition Piste', __FILE__));
		}
		$set_repeat_track->setType('action');
		$set_repeat_track->setSubType('other');
		$set_repeat_track->setEqLogic_id($this->getId());
		//$set_repeat_track->setValue($play_state->getId());
		$set_repeat_track->setDisplay('generic_type', 'GENERIC_ACTION');
		$set_repeat_track->save();
		
		$order++;
		$set_repeat_all = $this->getCmd(null, 'set_repeat_all');
		if (!is_object($set_repeat_all)) {
			$set_repeat_all = new aTVremoteCmd();
			$set_repeat_all->setLogicalId('set_repeat_all');
			$set_repeat_all->setDisplay('icon','<i class="fa fa-repeat" style="color:green"></i>');
			$set_repeat_all->setIsVisible(1);
			$set_repeat_all->setOrder($order);
			$set_repeat_all->setName(__('Bouton Répétition Tout', __FILE__));
		}
		$set_repeat_all->setType('action');
		$set_repeat_all->setSubType('other');
		$set_repeat_all->setEqLogic_id($this->getId());
		//$set_repeat_all->setValue($play_state->getId());
		$set_repeat_all->setDisplay('generic_type', 'GENERIC_ACTION');
		$set_repeat_all->save();
*/
		
// REFRESH		
		$order++;
		$refresh = $this->getCmd(null, 'refresh');
		if (!is_object($refresh)) {
			$refresh = new aTVremoteCmd();
			$refresh->setLogicalId('refresh');
			$refresh->setIsVisible(1);
			$refresh->setOrder($order);
			$refresh->setName(__('Rafraîchir', __FILE__));
		}
		$refresh->setType('action');
		$refresh->setSubType('other');
		$refresh->setEqLogic_id($this->getId());
		$refresh->save();

		$infos = aTVremote::getStructure('infos');
	

	
		foreach($infos as $id => $trad) {
			$order++;
			$newInfo = $this->getCmd(null, $id);
			if (!is_object($newInfo)) {
				$newInfo = new aTVremoteCmd();
				$newInfo->setLogicalId($id);
				$newInfo->setIsVisible(1);
				$newInfo->setOrder($order);
				$newInfo->setName(__($trad, __FILE__));
			}
			$newInfo->setTemplate('dashboard', 'line');
			$newInfo->setTemplate('mobile', 'line');
			$newInfo->setType('info');
			$newInfo->setSubType('string');
			$newInfo->setEqLogic_id($this->getId());
			$newInfo->setDisplay('generic_type', 'GENERIC_INFO');
			if(strpos($id,'position') !== false) $newInfo->setUnite( 's' );
			$newInfo->save();		
		}
		
		$cmds = aTVremote::getStructure('cmds');
	
		foreach($cmds as $id => $trad) {
			$order++;
			$newCmd = $this->getCmd(null, $id);
			if (!is_object($newCmd)) {
				$newCmd = new aTVremoteCmd();
				$newCmd->setLogicalId($id);
				$newCmd->setDisplay('icon','<i class="fa '.$trad['icon'].'"></i>');
				$newCmd->setIsVisible(1);
				$newCmd->setOrder($order);
				$newCmd->setName(__($trad['trad'], __FILE__));
			}
			$newCmd->setType('action');
			$newCmd->setSubType('other');
			$newCmd->setEqLogic_id($this->getId());
			$newCmd->setDisplay('generic_type', 'GENERIC_ACTION');
			$newCmd->save();		
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
					$play_state = $eqLogic->getCmd(null, 'play_state');
					$eqLogic->checkAndUpdateCmd($play_state, "1");				
					$eqLogic->aTVremoteExecute('play');
				break;
				case 'pause':
					$play_state = $eqLogic->getCmd(null, 'play_state');
					$eqLogic->checkAndUpdateCmd($play_state, "0");
					$eqLogic->aTVremoteExecute('pause');
					$hasToCheckPlaying=false;
				break;
				case 'stop':
					$play_state = $eqLogic->getCmd(null, 'play_state');
					$eqLogic->checkAndUpdateCmd($play_state, "0");
					$eqLogic->aTVremoteExecute('stop');
					$hasToCheckPlaying=false;
				break;
				case 'set_repeat_all':
					$eqLogic->aTVremoteExecute('set_repeat=All');
				break;
				case 'set_repeat_track':
					$eqLogic->aTVremoteExecute('set_repeat=Track');
				break;
				case 'set_repeat_off':
					$eqLogic->aTVremoteExecute('set_repeat=Off');
				break;
				case 'set_shuffle_on':
					$eqLogic->aTVremoteExecute('set_shuffle=True');
				break;
				case 'set_shuffle_off':
					$eqLogic->aTVremoteExecute('set_shuffle=False');
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
