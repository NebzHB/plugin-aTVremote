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

if (!isConnect('admin')) {
	throw new Exception('401 Unauthorized');
}
$eqLogics = aTVremote::byType('aTVremote');
?>

<table class="table table-condensed tablesorter" id="table_healthaTVremote">
	<thead>
		<tr>
			<th>{{Module}}</th>
			<th>{{ID}}</th>
			<th>{{IP}}</th>
  			<th>{{MAC}}</th>
  			<th>{{Modèle}}</th>
			<th>{{Appairage}}</th>
			<th>{{Date création}}</th>
		</tr>
	</thead>
	<tbody>
	 <?php
foreach ($eqLogics as $eqLogic) {
	if($eqLogic->getIsEnable()) {
		echo '<tr>';
	} else {
		echo '<tr style="background-color:lightgrey !important;">';
	}
	echo '<td><a href="' . $eqLogic->getLinkToConfiguration() . '" style="text-decoration: none;">' . $eqLogic->getHumanName(true) . ((!$eqLogic->getIsvisible())?'&nbsp;<i class="fas fa-eye-slash"></i>':'') . '</a></td>';
	echo '<td><span class="label label-info" style="font-size : 1em;">' . $eqLogic->getId() . '</span></td>';
	echo '<td><span class="label label-info" style="font-size : 1em;">' . $eqLogic->getConfiguration('ip') . '</span></td>';
	echo '<td><span class="label label-info" style="font-size : 1em;">' . $eqLogic->getConfiguration('mac') . '</span></td>';
	echo '<td><span class="label label-info" style="font-size : 1em;">' . $eqLogic->getConfiguration('fullModel') . '</span></td>';
	
	if($eqLogic->getConfiguration('device') == 'Apple TV') {
		if($eqLogic->getConfiguration('version') == '3') {
			if($eqLogic->getConfiguration('pairingKeyAirplay')) {
				echo '<td><span class="label label-success" style="font-size : 1em; cursor : default;width:100%">{{OUI}}</span></td>';
			} else {
				echo '<td><span class="label label-danger" style="font-size : 1em; cursor : default;width:100%">{{NON}}</span></td>';
			}
		} else {
			if($eqLogic->getConfiguration('pairingKeyAirplay') && $eqLogic->getConfiguration('pairingKeyCompanion')) {
				echo '<td><span class="label label-success" style="font-size : 1em; cursor : default;width:100%">{{OUI}}</span></td>';
			} else {
				echo '<td><span class="label label-danger" style="font-size : 1em; cursor : default;width:100%">{{NON}}</span></td>';
			}
		}
	} else {
		echo '<td><span class="label label-info" style="font-size : 1em; cursor : default;width:100%" title="{{Non nécessaire}}">{{OK}}</span></td>';
	}
	
	echo '<td><span class="label label-info" style="font-size : 1em;">' . $eqLogic->getConfiguration('createtime') . '</span></td></tr>';
}
?>
	</tbody>
</table>
