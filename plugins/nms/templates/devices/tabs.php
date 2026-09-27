<?php
/** One persistent navigation row for all Device Management pages. */
$device_nav_active=$device_nav_active??'inventory';
$device_nav_id=max(0,(int)($device_nav_id??0));
$device_nav_links=['inventory'=>'Device dashboard','add'=>'Add device','serial'=>'Add serial device','nodes'=>'Nodes'];
$device_nav_links['readings']='Device readings';
?>
<nav class="nms-page-tabs" aria-label="Device management">
<?php foreach($device_nav_links as $key=>$label) {
$url='devices.php?tab='.$key;
if($device_nav_id && in_array($key,['configuration','readings'],true))$url.='&id='.$device_nav_id;
?><a href="<?php print nms_h($url); ?>"<?php print $device_nav_active===$key?' class="selected" aria-current="page"':''; ?>><?php print nms_h($label); ?></a><?php } ?>
<?php if($device_nav_active==='edit' && $device_nav_id) { ?><a class="selected" aria-current="page" href="devices.php?tab=edit&amp;id=<?php print $device_nav_id; ?>">Edit device</a><?php } ?>
</nav>
