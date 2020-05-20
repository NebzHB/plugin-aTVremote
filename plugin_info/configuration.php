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
include_file('core', 'authentification', 'php');
if (!isConnect()) {
    include_file('desktop', '404', 'php');
    die();
}
?>
<style>
pre#pre_eventlog {
    font-family: "CamingoCode", monospace !important;
}
</style>
<form class="form-horizontal">
	<fieldset>
		<legend>
			<i class="fas fa-wrench"></i> {{Réparations}}
		</legend>
		<center>
			<a class="btn btn-danger btn-sm" id="bt_reinstallNodeJS"><i class="fa fa-recycle"></i> {{Réparation de NodeJS}} </a>
		</center>
		<legend>
			<i class="fas fa-palette"></i> {{Personnalisation Widget}}
		</legend>
		<br />
		<div class="form-group">
			<label class="col-lg-6 control-label">{{Mode de défilement des champs Titre, Artiste et Album}}</label>
			<div class="col-lg-3">
				<select class="configKey form-control" data-l1key="marquee">
					<option value="0">Alterné (Pas compatible certains navigateurs)</option>
					<option value="1">Défilement</option>
				</select>
			</div>
		</div>
	</fieldset>
</form>
<script>

  $('#bt_reinstallNodeJS').off('click').on('click', function() {
		bootbox.confirm('{{Etes-vous sûr de vouloir supprimer et reinstaller NodeJS ? <br /> Merci de patienter 10-20 secondes quand vous aurez cliqué...}}', function(result) {
			if (result) {
				$.showLoading();
				$.ajax({
					type : 'POST',
					url : 'plugins/aTVremote/core/ajax/aTVremote.ajax.php',
					data : {
						action : 'reinstallNodeJS',
					},
					dataType : 'json',
					global : false,
					error : function(request, status, error) {
						$.hideLoading();
						$('#div_alert').showAlert({
							message : error.message,
							level : 'danger'
						});
					},
					success : function(data) {
						$.hideLoading();
						$('li.li_plugin.active').click();
						$('#div_alert').showAlert({
							message : "{{Réinstallation NodeJS effectuée, merci de patienter jusqu'à la fin de l'installation des dépendances}}",
							level : 'success'
						});
					}
				});
			}
		});
	});	

</script>
