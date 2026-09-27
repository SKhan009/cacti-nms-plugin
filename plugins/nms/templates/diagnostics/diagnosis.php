<?php /** Device diagnosis and application checks share the dedicated diagnostics destination. */ ?>
<main class="nms-shell nms-topology-config nms-readings-workspace">
<?php require __DIR__.'/header.php'; ?>
<form method="get" class="nms-reading-controls nms-diagnosis-device-picker"><input type="hidden" name="section" value="diagnosis"><label>Device<select name="host_id" onchange="this.form.requestSubmit()"><option value="0">Select a device</option><?php foreach($devices as $device){ ?><option value="<?php print (int)$device['id']; ?>" <?php print (int)$device['id']===$workspace_id?'selected':''; ?>><?php print nms_h($device['description'].' · '.$device['hostname']); ?></option><?php } ?></select></label><a href="<?php print nms_h(nms_workspace_url('diagnosis',$workspace_id)); ?>">Refresh</a></form>
<?php if($workspace_error){ ?><p role="alert" class="nms-form-message"><?php print nms_h($workspace_error); ?></p><?php } ?>
<?php if($can_diagnose) require __DIR__.'/services.php'; ?>
</main>
