<?php
if (!isConnect()) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
sendVarToJs('jeedomBackgroundImg', 'plugins/aTVremote/resources/img/panel.jpg');
if (init('object_id') == '') {
	$object = jeeObject::byId($_SESSION['user']->getOptions('defaultDashboardObject'));
} else {
	$object = jeeObject::byId(init('object_id'));
}
if (!is_object($object)) {
	$object = jeeObject::rootObject();
}
if (!is_object($object)) {
	throw new Exception('{{Aucun objet racine trouvé. Pour en créer un, allez dans Générale -> Objet.<br/> Si vous ne savez pas quoi faire ou que c\'est la premiere fois que vous utilisez Jeedom n\'hésitez pas a consulter cette <a href="http://jeedom.fr/premier_pas.php" target="_blank">page</a>}}');
}
$allObject = jeeObject::all();
$child_object = jeeObject::buildTree($object);
$parentNumber = array();
?>

<div class="row row-overflow">
	<?php
	if (init('report') != 1) {
		echo '<div class="col-lg-2 col-md-3 col-sm-4" id="div_displayObjectList">';
	} else {
		echo '<div class="col-lg-2 col-md-3 col-sm-4" style="display:none;" id="div_displayObjectList">';
	}
	?>
	<div class="bs-sidebar">
		<ul id="ul_object" class="nav nav-list bs-sidenav">
			<li class="filter" style="margin-bottom: 5px;"><input class="filter form-control input-sm" placeholder="{{Rechercher}}" style="width: 100%"/></li>
			<?php
			foreach ($allObject as $object_li) {
				if ($object_li->getIsVisible() != 1 || count($object_li->getEqLogic(true, false, 'aTVremote', null, true)) == 0) {
					continue;
				}
				$margin = 5 * $object_li->getConfiguration('parentNumber');
				if ($object_li->getId() == $object->getId()) {
					echo '<li class="cursor li_object active" ><a data-object_id="' . $object_li->getId() . '" href="index.php?v=d&p=panel&m=aTVremote&object_id=' . $object_li->getId() . '" style="padding: 2px 0px;"><span style="position:relative;left:' . $margin . 'px;">' . $object_li->getHumanName(true,true) . '</span></a></li>';
				} else {
					echo '<li class="cursor li_object" ><a data-object_id="' . $object_li->getId() . '" href="index.php?v=d&p=panel&m=aTVremote&object_id=' . $object_li->getId() . '" style="padding: 2px 0px;"><span style="position:relative;left:' . $margin . 'px;">' . $object_li->getHumanName(true,true) . '</span></a></li>';
				}
			}
			?>
		</ul>
	</div>
</div>
<?php
if (init('report') != 1) {
	echo '<div class="col-lg-10 col-md-9 col-sm-8" id="div_displayObject">';
} else {
	echo '<div class="col-lg-12 col-md-12 col-sm-12" id="div_displayObject">';
}
echo '<div class="div_displayEquipement" style="width: 100%;">';
if (init('object_id') == '') {
	foreach ($allObject as $object) {
		foreach ($object->getEqLogic(true, false, 'aTVremote') as $aTVremote) {
			echo $aTVremote->toHtml('dashboard');
		}
	}
} else {
	foreach ($object->getEqLogic(true, false, 'aTVremote') as $aTVremote) {
		echo $aTVremote->toHtml('dashboard');
	}
	foreach ($child_object as $child) {
		$aTVremotes = $child->getEqLogic(true, false, 'aTVremote');
		if (count($aTVremotes) > 0) {
			foreach ($aTVremotes as $aTVremote) {
				echo $aTVremote->toHtml('dashboard');
			}
		}
	}
}
echo '</div>';
?>
</div>
</div>
<?php include_file('desktop', 'panel', 'js', 'aTVremote');?>
