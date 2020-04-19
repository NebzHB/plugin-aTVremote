<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('aTVremote');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>
<div class="row row-overflow">
   <div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fa fa-cog"></i>  {{Gestion}}</legend>
   		<div class="eqLogicThumbnailContainer">
  			<div class="cursor eqLogicAction discover" data-action="scanAppleTV" style="background-color : #ffffff; height : 120px;margin-bottom : 10px;padding : 5px;border-radius: 2px;width : 160px;margin-left : 10px;">
        		<center>
          			<i class="fa fa-bullseye" style="font-size : 5em;color:#fcc127;"></i>
        		</center>
        		<span style="font-size : 1.1em;position:relative; top : 15px;word-break: break-all;white-space: pre-wrap;word-wrap: break-word;color:#fcc127"><center>{{Scan AppleTV}}</center></span>
      		</div>
  			<div class="cursor eqLogicAction" data-action="gotoPluginConf" style="background-color : #ffffff; height : 120px;margin-bottom : 10px;padding : 5px;border-radius: 2px;width : 160px;margin-left : 10px;">
    			<center>
      				<i class="fa fa-wrench" style="font-size : 5em;color:#767676;"></i>
    			</center>
    			<span style="font-size : 1.1em;position:relative; top : 15px;word-break: break-all;white-space: pre-wrap;word-wrap: break-word;color:#767676"><center>{{Configuration}}</center></span>
  			</div>
  			<div class="cursor" id="bt_healthaTVremote" style="background-color : #ffffff; height : 120px;margin-bottom : 10px;padding : 5px;border-radius: 2px;width : 160px;margin-left : 10px;" >
    			<center>
      				<i class="fa fa-medkit" style="font-size : 5em;color:#767676;"></i>
    			</center>
    			<span style="font-size : 1.1em;position:relative; top : 15px;word-break: break-all;white-space: pre-wrap;word-wrap: break-word;color:#767676"><center>{{Santé}}</center></span>
  			</div>
		</div>
		<legend><i class="fab fa-apple"></i>  {{Mes AppleTV}}</legend>
		<input class="form-control" placeholder="{{Rechercher}}" id="in_searchEqlogic" />
		<div class="eqLogicThumbnailContainer">
         	<?php
            foreach ($eqLogics as $eqLogic) {
                $opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
                echo '<div class="eqLogicDisplayCard cursor" data-eqLogic_id="' . $eqLogic->getId() . '" style="background-color : #ffffff; height : 200px;margin-bottom : 10px;padding : 5px;border-radius: 2px;width : 160px;margin-left : 10px;' . $opacity . '" >';
                echo "<center>";
                echo '<img src="plugins/aTVremote/plugin_info/aTVremote_icon.png" height="105" width="95" />';
                echo "</center>";
                echo '<span class="name"><center>' . $eqLogic->getHumanName(true, true) . '</center></span>';
                echo '</div>';
            }
			?>
		</div>  
	</div>
              
              
	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
  								<a class="btn btn-default eqLogicAction btn-sm roundedLeft" data-action="configure"><i class="fas fa-cogs"></i> {{Configuration avancée}}</a><a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a><a class="btn btn-danger btn-sm eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
    	<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a class="eqLogicAction cursor" aria-controls="home" role="tab" data-action="returnToThumbnailDisplay"><i class="fa fa-arrow-circle-left"></i></a></li>
        	<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fa fa-tachometer"></i> {{Equipement}}</a></li>
        	<li role="presentation"><a href="#commandtab" aria-controls="profile" role="tab" data-toggle="tab"><i class="fa fa-list-alt"></i> {{Commandes}}</a></li>
    </ul>

    <div class="tab-content" style="height:calc(100% - 50px);overflow:auto;overflow-x: hidden;">
        <div role="tabpanel" class="tab-pane active" id="eqlogictab">
			<br/>
			<div class="row">
    			<div class="col-sm-6">
       				<form class="form-horizontal">
            			<fieldset>
                			<div class="form-group">
                    			<label class="col-sm-4 control-label">{{Nom de l'appleTV}}</label>
                                
                    			<div class="col-sm-4">
                        			<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display : none;" />
                        			<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom de l'appleTV}}"/>
                    			</div>
					
                			</div>
                			<div class="form-group">
                				<label class="col-sm-4 control-label" >{{Objet parent}}</label>
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
                    				<label class="col-sm-4 control-label"></label>
                    				<div class="col-sm-8">
                        				<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked/>{{Activer}}</label>
                        				<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked/>{{Visible}}</label>
                    				</div>
                				</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Catégorie}}</label>
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
      								<label class="col-sm-4 control-label">{{Ip appleTV}}</label>
      								<div class="col-sm-4">
          								<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ip" placeholder="{{Ip appleTV}}"/>
									</div>
                				</div>
				  				<div class="form-group">
      								<label class="col-sm-4 control-label">{{Mac}}</label>
      								<div class="col-sm-4">
        								<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="credentials" placeholder="{{Mac}}"/>
      								</div>
                				</div>
                                </br>
                                </br>
				  				<div class="form-group">
      								<label class="col-sm-4 control-label">{{Model}}</label>
      								<div class="col-sm-4">
        								<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="model" placeholder="{{Model}}"/>
      								</div>
                				</div>
            				</fieldset>
        				</form>
					</div>
					<div class="col-sm-6">
  						<center>
    						<img src="plugins/aTVremote/plugin_info/aTVremote_icon.png" style="height : 300px;margin-top:5px" />
  						</center>
					</div>
				</div>
			</div>
			<div role="tabpanel" class="tab-pane" id="commandtab">
            	</br>
        		<legend><i class="fa fa-list-alt"></i>  {{Tableau de commandes}}</legend>
       			<table id="table_cmd" class="table table-bordered table-condensed">
             		<thead>
                		<tr>
                    		<th>{{Nom}}</th>
                            <th></th>
                            <th>{{Options}}</th>
                            <th>{{Action}}</th>
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