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
	foreach (eqLogic::byType('aTVremote') as $aTVremote) {
		$aTVremote->save();
	}
	message::add('aTVremote', 'Attention, cette version est toujours une beta ! relancez les dépendances après mise à jour !');
}

function aTVremote_remove() {
 
}

?>
