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

function aTVremote_install() {
	if(trim(shell_exec("lsb_release -c | grep 'jessie' | wc -l")) == "1")
		message::add('aTVremote', 'Attention, votre version de Debian est Jessie (8) et ce plugin n\'est compatible que avec Stretch (9) et au delà');
}

function aTVremote_update() {
    foreach (eqLogic::byType('aTVremote') as $aTVremote) {
		$aTVremote->save();
    }
}

function aTVremote_remove() {
 
}

?>
