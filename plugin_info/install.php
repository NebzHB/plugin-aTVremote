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

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

// Debian 12 (Bookworm) minimum : avertissement dans le centre de messages sinon
function aTVremote_checkDebian() {
	$version = (int)trim((string)shell_exec('lsb_release -r -s 2>/dev/null'));
	if ($version > 0 && $version < 12) {
		message::add('aTVremote', 'Attention, votre version de Debian est la ' . $version . ' et ce plugin n\'est compatible qu\'avec Debian 12 (Bookworm) et au-delà');
	}
}

function aTVremote_install() {
	aTVremote_checkDebian();
}

function aTVremote_update() {
	aTVremote_checkDebian();
	// Le démon Python devient le démon par défaut : une seule fois, on y passe aussi les installations qui avaient
	// enregistré « nodejs » (c'était le choix par défaut de la configuration). Node.js reste choisissable ensuite.
	if (config::byKey('daemonTypePythonDefault', 'aTVremote', '0') != '1') {
		if (config::byKey('daemonType', 'aTVremote', '') == 'nodejs') {
			config::save('daemonType', 'python', 'aTVremote');
			message::add('aTVremote', 'Le plugin utilise maintenant le démon Python. En cas de problème, l\'ancien démon Node.js reste disponible dans la configuration du plugin (Type de démon).');
		}
		config::save('daemonTypePythonDefault', '1', 'aTVremote');
	}
	foreach (eqLogic::byType('aTVremote') as $aTVremote) {
		// Apple TV 3 : « Aléatoire / Répétition disponible » n'y sont pas utilisables (créées par erreur en beta)
		if ($aTVremote->getConfiguration('version', 0) == '3') {
			foreach (array('shuffle_available', 'repeat_available') as $logicalId) {
				$cmd = $aTVremote->getCmd(null, $logicalId);
				if (is_object($cmd)) {
					$cmd->remove();
				}
			}
		}
		$aTVremote->save();
	}
	message::add('aTVremote', 'Attention, cette version est toujours une beta ! relancez les dépendances après mise à jour !');
}

function aTVremote_remove() {
 
}

?>
