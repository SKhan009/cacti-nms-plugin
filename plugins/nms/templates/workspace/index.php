<?php /** Common workspace navigation, rendered without relying on a selected device. */ ?>
<main class="nms-shell nms-readings-workspace">
<header class="nms-heading"><div><p class="nms-eyebrow">NMS / Device Management</p><h1>Device readings &amp; discovery</h1></div></header>
<?php $device_nav_active='readings';$device_nav_id=$workspace_id;require __DIR__.'/../devices/tabs.php'; ?>
<form class="nms-reading-controls nms-reading-device-picker" method="get"><input type="hidden" name="tab" value="readings"><input type="hidden" name="section" value="<?php print nms_h($workspace_section); ?>"><input type="hidden" name="view" value="<?php print nms_h(is_string($_GET['view']??null)?$_GET['view']:'all'); ?>"><label>Device<select name="id" data-reading-device><option value="0">No device selected — network workspace</option><?php foreach($devices as $device) { ?><option value="<?php print (int)$device['id']; ?>" <?php print (int)$device['id']===$workspace_id?'selected':''; ?>><?php print nms_h($device['description'].' · '.$device['hostname']); ?></option><?php } ?></select></label><a href="<?php print nms_h(nms_workspace_url($workspace_section,$workspace_id,['view'=>is_string($_GET['view']??null)?$_GET['view']:'all'])); ?>">Refresh</a></form>
<?php if($workspace_error) { ?><p class="nms-form-message" role="alert"><?php print nms_h($workspace_error); ?></p><?php } ?>
<?php require __DIR__.'/overview.php'; ?>
</main>
