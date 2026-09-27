<main class="nms-shell nms-devices-shell">
<div class="nms-heading"><div><p class="nms-eyebrow">NMS / Device management</p><h1><?php print $host_id?'Serial connection':'Add device'; ?></h1><p><?php print nms_h($host['description']); ?> · Collector <?php print (int)$host['poller_id']; ?></p></div></div>
<?php $device_nav_active=$host_id?'edit':'add';$device_nav_id=(int)$host['id'];require __DIR__.'/tabs.php'; ?>
<?php if($error){ ?><div class="nms-form-message error" role="alert"><?php print nms_h($error); ?></div><?php } ?>
<?php if(isset($_GET['saved']) || isset($_GET['created'])){ ?><div class="nms-form-message success" role="status"><?php print isset($_GET['created'])?'Connection created. Select it below to assign this device.':'Device connection saved.'; ?></div><?php } ?>
<?php if($host_id){ ?>
<section class="nms-panel"><div class="nms-panel-head"><h2>Serial connection</h2></div><form method="post" class="nms-node-form">
<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="action" value="assign"><input type="hidden" name="assignment_revision" value="<?php print (int)($assignment['revision'] ?? 0); ?>">
<div class="nms-node-fields"><label>Shared connection<select name="connection_id"><option value="0">No serial connection</option><?php foreach($connections as $connection){ ?><option value="<?php print (int)$connection['id']; ?>" <?php print (int)($assignment['connection_id'] ?? 0)===(int)$connection['id']?'selected':''; ?>><?php print nms_h($connection['name'].' · '.$connection['endpoint']); ?></option><?php } ?></select></label>
<label>Modbus device address<input type="number" min="1" max="247" step="1" name="device_address" value="<?php print (int)($assignment['device_address'] ?? 1); ?>"></label></div>
<p>Each device on a shared connection needs a unique address. Existing SNMP and SSH settings remain available in Device Edit. Serial collection is not active until a supported equipment profile and collector adapter are configured.</p>
<button class="nms-node-button" type="submit">Save connection</button> <a class="nms-cancel-button" href="devices.php?tab=edit&amp;id=<?php print $host_id; ?>">Cancel</a></form></section>
<?php } else { require __DIR__.'/serial_add.php'; } ?>
<?php if($refresh_preview){ ?>
<section class="nms-panel"><div class="nms-panel-head"><h2>Review shared settings update</h2></div><form method="post" class="nms-node-form">
<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="action" value="apply_refresh"><input type="hidden" name="refresh_id" value="<?php print (int)$refresh_preview['connection']['id']; ?>"><input type="hidden" name="fingerprint" value="<?php print nms_h($refresh_preview['fingerprint']); ?>">
<p>Connection: <?php print nms_h($refresh_preview['connection']['name']); ?>. This updates the collector's connection settings. It does not change the settings on the equipment or gateway.</p>
<div class="nms-node-table"><table><thead><tr><th>Setting</th><th>Current</th><th>Profile default</th></tr></thead><tbody><?php foreach($refresh_preview['profile']['settings'] as $key=>$value){ ?><tr><td><?php print nms_h(ucwords(str_replace('_',' ',$key))); ?></td><td><?php print nms_h($refresh_preview['connection']['settings'][$key] ?? ''); ?></td><td><?php print nms_h($value); ?></td></tr><?php } ?></tbody></table></div>
<p>Affected devices:</p><ul><?php foreach($refresh_preview['members'] as $member){ ?><li><?php print nms_h($member['description']); ?> (address <?php print (int)$member['device_address']; ?>)</li><?php } ?></ul>
<button class="nms-node-button" type="submit">Apply shared settings</button> <a class="nms-cancel-button" href="devices.php?tab=connection&amp;id=<?php print $host_id; ?>">Cancel</a></form></section>
<?php } elseif($assignment){ ?>
<section class="nms-panel"><div class="nms-panel-head"><h2>Shared settings</h2></div><form method="post" class="nms-node-form">
<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="action" value="preview_refresh"><input type="hidden" name="refresh_id" value="<?php print (int)$assignment['connection_id']; ?>">
<p><?php print nms_h($assignment['connection_name'].' · '.$assignment['endpoint']); ?>. Review profile changes and all affected devices before updating this shared connection.</p><button class="nms-node-button" type="submit">Review profile update</button></form></section>
<?php } ?>
<?php if($host_id){ ?><section class="nms-panel"><div class="nms-panel-head"><h2>Create shared connection</h2></div><form method="post" class="nms-node-form">
<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
<?php if($preview){ ?>
<input type="hidden" name="poller_id" value="<?php print (int)$host['poller_id']; ?>"><input type="hidden" name="action" value="create_connection"><input type="hidden" name="profile_revision" value="<?php print (int)$preview['revision']; ?>">
<?php foreach($values as $key=>$value){ ?><input type="hidden" name="<?php print $key; ?>" value="<?php print nms_h($value); ?>"><?php } ?>
<p>Create <?php print nms_h($values['name']); ?> on collector <?php print (int)$host['poller_id']; ?>, endpoint <?php print nms_h($values['endpoint']); ?>.</p>
<p>Copy defaults from <?php print nms_h($preview['name']); ?>, revision <?php print (int)$preview['revision']; ?>:</p>
<dl><?php foreach($preview['settings'] as $key=>$value){ ?><dt><?php print nms_h(ucwords(str_replace('_',' ',$key))); ?></dt><dd><?php print nms_h($value); ?></dd><?php } ?></dl>
<p>No device commands will be sent. For a gateway, configure matching serial settings on the gateway itself.</p>
<button class="nms-node-button" type="submit">Create connection</button> <a class="nms-cancel-button" href="devices.php?tab=connection&amp;id=<?php print $host_id; ?>">Cancel</a>
<?php } else { ?>
<input type="hidden" name="action" value="preview_connection">
<div class="nms-node-fields"><?php if(!$host_id){ ?><label>Collector<select name="poller_id"><?php foreach($pollers as $poller){ ?><option value="<?php print (int)$poller['id']; ?>" <?php print (int)$host['poller_id']===(int)$poller['id']?'selected':''; ?>><?php print nms_h($poller['name']); ?></option><?php } ?></select></label><?php } ?><label>Name<input name="name" required maxlength="150" value="<?php print nms_h($values['name']); ?>"></label>
<label>Transport<select name="transport"><option value="direct" <?php print $values['transport']==='direct'?'selected':''; ?>>Direct serial port</option><option value="rtu_tcp" <?php print $values['transport']==='rtu_tcp'?'selected':''; ?>>Transparent RTU over TCP gateway</option></select></label>
<label>Serial port or gateway IP<input name="endpoint" required value="<?php print nms_h($values['endpoint']); ?>" placeholder="/dev/serial/by-id/… or gateway IP"></label>
<label>Gateway TCP port (gateway only)<input type="number" name="port" min="1" max="65535" value="<?php print nms_h($values['port']); ?>"></label>
<label>Serial profile<select name="profile_id" required><option value="">Select a profile</option><?php foreach($profiles as $profile){ ?><option value="<?php print (int)$profile['id']; ?>" <?php print (int)$values['profile_id']===(int)$profile['id']?'selected':''; ?>><?php print nms_h($profile['name'].' · '.$profile['manufacturer'].' / '.$profile['model']); ?></option><?php } ?></select></label></div>
<p>The connection belongs to the selected Cacti collector. Use the same connection for devices sharing a serial bus.</p>
<button class="nms-node-button" type="submit">Preview connection</button> <a class="nms-cancel-button" href="serial_profiles.php">Manage profiles</a>
<?php } ?></form></section><?php } ?></main>
