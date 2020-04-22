<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}

sendVarToJS('eqType', 'aTVremote');
$eqLogics = eqLogic::byType('aTVremote');
?>
<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i>  {{Gestion}}</legend>
   		<div class="eqLogicThumbnailContainer">
  			<div class="cursor logoPrimary eqLogicAction" data-action="scanAppleTV">
        		<i class="fas fa-bullseye"></i>
				<br />
        		<span>{{Scan AppleTV}}</span>
      		</div>
  			<div class="cursor logoSecondary eqLogicAction" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br />
				<span>{{Configuration}}</span>
			</div>
			<div class="cursor logoSecondary" id="bt_healthaTVremote">
				<i class="fas fa-medkit"></i>
				</br />
				<span>{{Santé}}</span>
			</div>
		</div>
		<legend><i class="fab fa-apple"></i>  {{Mes AppleTV}}</legend>
		<div class="input-group" style="margin-bottom:5px;">
			<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic" />
			<div class="input-group-btn">
				<a id="bt_resetEqlogicSearch" class="btn roundedRight" style="width:30px"><i class="fas fa-times"></i></a>
			</div>
		</div>
		<div class="panel">
			<div class="panel-body">
				<div class="eqLogicThumbnailContainer">
					<?php
					foreach ($eqLogics as $eqLogic) {
						$opacity = ($eqLogic->getIsEnable()) ? '' : ' disableCard';
						$img=$eqLogic->getImage();
						echo '<div class="eqLogicDisplayCard cursor '.$opacity.'" data-eqLogic_id="' . $eqLogic->getId() . '">';
						echo '<img class="lazy" src="'.$img.'" style="min-height:75px !important;" />';
						echo "<br />";
						echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
						echo '</div>';
					}
					?>
				</div>
			</div>
		</div>
	</div>
	<div class="col-lg-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
  				<a class="btn btn-default eqLogicAction btn-sm roundedLeft" data-action="configure"><i class="fas fa-cogs"></i> {{Configuration avancée}}</a>
				<a class="btn btn-success eqLogicAction btn-sm" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-danger btn-sm eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
    	<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a class="eqLogicAction cursor" aria-controls="home" role="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
        	<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Equipement}}</a></li>
        	<li role="presentation"><a href="#commandtab" aria-controls="profile" role="tab" data-toggle="tab"><i class="fas fa-list-alt"></i> {{Commandes}}</a></li>
		</ul>
		<div class="tab-content" style="height:calc(100% - 50px);overflow:auto;overflow-x: hidden;">
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<div class="row">
					<div class="col-sm-9">
						<form class="form-horizontal">
							<fieldset>
								<div class="form-group">
									<br/>
									<label class="col-sm-3 control-label">{{Nom de l'appleTV}}</label>
									<div class="col-sm-4">
										<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display : none;" />
										<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom de l'appleTV}}"/>
										
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-3 control-label" >{{Objet parent}}</label>
									<div class="col-sm-4">
										<select id="sel_object" class="eqLogicAttr form-control" data-l1key="object_id">
											<option value="">{{Aucun}}</option>
											<?php
											foreach (jeeObject::all() as $object) {
												echo '<option value="' . $object->getId() . '">' . $object->getName() . '</option>';
											}
											?>
										</select>
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-3 control-label">&nbsp;</label>
									<div class="col-sm-4">
										<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked/>{{Activer}}</label>
										<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked/>{{Visible}}</label>
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-3 control-label">{{Catégorie}}</label>
									<div class="col-sm-8">
										<?php
										foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
											echo '<label class="checkbox-inline">';
											echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '" />' . $value['name'];
											echo '</label>';
										}
										?>
										
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-3 control-label">{{Ip appleTV}}</label>
									<div class="col-sm-4">
										<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ip" placeholder="{{Ip appleTV}}"/>
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-3 control-label">{{Mac}}</label>
									<div class="col-sm-4">
										<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="credentials" placeholder="{{Mac}}"/>
									</div>
								</div>
								</br>
								</br>
								<div class="form-group">
									<label class="col-sm-3 control-label">{{Model}}</label>
									<div class="col-sm-4">
										<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="model" placeholder="{{Model}}"/>
									</div>
								</div>
							</fieldset>
						</form>
					</div>
					<div class="form-horizontal col-sm-3">
						<fieldset>
							<div class="form-group">
								<img src="plugins/aTVremote/plugin_info/aTVremote_icon.png" style="height : 200px;margin-top:5px" />
							</div>
						</fieldset>
					</div>
				</div>
			</div>
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<legend><i class="fas fa-list-alt"></i>  {{Tableau de commandes}}</legend>
				<table id="table_cmd" class="table table-bordered table-condensed">
					<thead>
						<tr>
							<th>{{Nom}}</th>
							<th>{{Icone}}</th>
							<th>{{Paramètres}}</th>
							<th>&nbsp;</th>
						</tr>
					</thead>
					<tbody>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</div>
<?php include_file('desktop', 'aTVremote', 'js', 'aTVremote'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
