
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

 $('#bt_healthaTVremote').on('click', function () {
    $('#md_modal').dialog({title: "{{Santé aTVremote}}"});
    $('#md_modal').load('index.php?v=d&plugin=aTVremote&modal=health').dialog('open');
});

$('.eqLogicAction[data-action=scanAppleTV]').on('click', function () {
	$('#div_alert').showAlert({message: '{{Détection en cours}}', level: 'warning'});
	$.showLoading();
	$.ajax({
                type: "POST", // méthode de transmission des données au fichier php
                url: "plugins/aTVremote/core/ajax/aTVremote.ajax.php",
                data: {
                    action: "discover",
                    mode: $(this).attr('data-action'),
                },
                dataType: 'json',
                global: false,
                error: function (request, status, error) {
                    handleAjaxError(request, status, error);
                },
                success: function (data) {
					//$.hideLoading();
                    if (data.state != 'ok') {
                        $('#div_alert').showAlert({message: data.result, level: 'danger'});
                        return;
                    }
                    $('#div_alert').showAlert({message: '{{Opération réalisée avec succès}}', level: 'success'});
                    location.reload();
                }
            });
});
 
 $("#table_cmd").sortable({axis: "y", cursor: "move", items: ".cmd", placeholder: "ui-state-highlight", tolerance: "intersect", forcePlaceholderSize: true});
function addCmdToTable(_cmd) {
    if (!isset(_cmd)) {
        var _cmd = {configuration: {}};
    }
    var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
    tr += '<td>';
		tr += '<input class="cmdAttr form-control input-sm" data-l1key="id" style="display : none;">';
		tr += '<div class="row">';
			tr += '<div class="col-sm-6">';
			tr += '<input class="cmdAttr form-control input-sm" data-l1key="name">';
			tr += '</div>';
		tr += '</div>';
    tr += '</td>'; 
  /* tr += '<td>';
		tr += '<div class="row">';
			tr += '<div class="col-sm-6">';
			tr += '<a class="cmdAction btn btn-default btn-sm" data-l1key="chooseIcon"><i class="fas fa-flag"></i> Icone</a>';
			tr += '<span class="cmdAttr" data-l1key="display" data-l2key="icon" style="margin-left : 10px;"></span>';
			tr += '</div>';
		tr += '</div>';
    tr += '</td>'; */
	tr += '<td>';
	if (_cmd.logicalId != 'refresh'){
    tr += '<span><label class="checkbox-inline" style="display : none;"><input type="checkbox" class="cmdAttr checkbox-inline" data-l1key="isVisible" checked/>{{Afficher}}</label></span> ';
    }
	if (_cmd.subType == "numeric") {
        tr += '<span><label class="checkbox-inline"><input type="checkbox" class="cmdAttr checkbox-inline" data-l1key="isHistorized" checked/>{{Historiser}}</label></span> ';
    }
	if (_cmd.subType == "binary") {
        tr += '<span><label class="checkbox-inline"><input type="checkbox" class="cmdAttr checkbox-inline" data-l1key="isHistorized" checked/>{{Historiser}}</label></span> ';
    }
	tr += '</td>';
	tr += '<td>';
    tr += '<input class="cmdAttr form-control input-sm" data-l1key="type" style="display : none;">';
    tr += '<input class="cmdAttr form-control input-sm" data-l1key="subType" style="display : none;">';
    if (is_numeric(_cmd.id)) {
        tr += '<a class="btn btn-default btn-xs cmdAction expertModeVisible" data-action="configure"><i class="fas fa-cogs"></i></a> ';
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>';
    }
	tr += '<i class="fas fa-minus-circle pull-right cmdAction cursor" data-action="remove" style="display : none;"></i></td>';
    tr += '</tr>';
    $('#table_cmd tbody').append(tr);
    $('#table_cmd tbody tr:last').setValues(_cmd, '.cmdAttr');
    jeedom.cmd.changeType($('#table_cmd tbody tr:last'), init(_cmd.subType));
}
