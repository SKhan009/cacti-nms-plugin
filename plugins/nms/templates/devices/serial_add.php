<section class="nms-panel"><div class="nms-panel-head"><h2>Add serial device</h2><a class="nms-cancel-button" href="devices.php?tab=add">Network device</a></div><form method="post" class="nms-node-form">
<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="action" value="create_device">
<div class="nms-node-fields"><label>Device name<input name="description" required maxlength="150" value="<?php print nms_h($_POST['description'] ?? ''); ?>"></label>
<label>Connection<select name="connection_id" id="nmsSerialConnection"><option value="0">New connection from serial preset</option><?php foreach(nms_serial_connections() as $connection){ ?><option value="<?php print (int)$connection['id']; ?>" <?php print (int)($_POST['connection_id'] ?? 0)===(int)$connection['id']?'selected':''; ?>><?php print nms_h($connection['name'].' · '.$connection['endpoint'].' · Collector '.$connection['poller_id']); ?></option><?php } ?></select></label>
<div id="nmsSerialPresetFields" class="nms-node-fields" style="grid-column:1/-1">
<label>Serial preset<select name="profile_id" required><option value="">Select a serial preset</option><?php foreach($profiles as $profile){ ?><option value="<?php print (int)$profile['id']; ?>" <?php print (int)($_POST['profile_id'] ?? 0)===(int)$profile['id']?'selected':''; ?>><?php print nms_h($profile['name']); ?></option><?php } ?></select><small>Create reusable settings in <a href="serial_profiles.php">Presets → Serial profiles</a>.</small></label>
<label>Collector<select name="poller_id"><?php foreach($pollers as $poller){ ?><option value="<?php print (int)$poller['id']; ?>" <?php print (int)$host['poller_id']===(int)$poller['id']?'selected':''; ?>><?php print nms_h($poller['name']); ?></option><?php } ?></select></label>
<label>Transport<select name="transport"><option value="direct">Direct serial port</option><option value="rtu_tcp" <?php print ($_POST['transport'] ?? '')==='rtu_tcp'?'selected':''; ?>>Transparent RTU over TCP gateway</option></select></label>
<label>Serial port or gateway IP<input name="endpoint" required value="<?php print nms_h($_POST['endpoint'] ?? ''); ?>" placeholder="/dev/serial/by-id/… or gateway IP"></label>
<label>Gateway TCP port (gateway only)<input type="number" name="port" min="1" max="65535" value="<?php print nms_h($_POST['port'] ?? 4001); ?>"></label>
</div>
<label>Modbus address<input type="number" name="device_address" min="1" max="247" required value="<?php print nms_h($_POST['device_address'] ?? 1); ?>"></label>
<label>Site<select name="site_id"><option value="0">No site</option><?php foreach($sites as $site){ ?><option value="<?php print (int)$site['id']; ?>" <?php print (int)($_POST['site_id'] ?? 0)===(int)$site['id']?'selected':''; ?>><?php print nms_h($site['name']); ?></option><?php } ?></select></label>
<label>Node<select name="node_id"><option value="0">Unassigned</option><?php foreach($nodes as $node){ ?><option value="<?php print (int)$node['id']; ?>"><?php print nms_h($node['name'].' · '.$node['code']); ?></option><?php } ?></select></label>
<label>Equipment profile<select name="equipment_profile_id"><option value="0">Set up readings later</option><?php foreach(db_fetch_assoc("SELECT id,name FROM plugin_nms_config_profiles WHERE protocol='modbus_rtu' ORDER BY name") as $equipment){ ?><option value="<?php print (int)$equipment['id']; ?>" <?php print (int)($_POST['equipment_profile_id'] ?? 0)===(int)$equipment['id']?'selected':''; ?>><?php print nms_h($equipment['name']); ?></option><?php } ?></select><small>Defines the registers to read. Manage under Presets → Equipment profiles.</small></label>
<label>Reading interval (seconds)<input type="number" name="interval_seconds" min="60" max="86400" required value="<?php print nms_h($_POST['interval_seconds'] ?? 300); ?>"></label>
<label>Notes<textarea name="notes" maxlength="512"><?php print nms_h($_POST['notes'] ?? ''); ?></textarea></label></div>
<p>The preset supplies baud rate, parity and other serial settings. An existing shared connection keeps its saved settings and collector. Choose a node at the same site.</p>
<button class="nms-node-button" type="submit">Create device</button> <a class="nms-cancel-button" href="devices.php">Cancel</a>
</form></section>

<script>
(function(){
    var connection=document.getElementById('nmsSerialConnection');
    var fields=document.getElementById('nmsSerialPresetFields');
    function update(){
        var existing=connection.value!=='0';
        fields.hidden=existing;
        fields.style.display=existing?'none':'';
        fields.querySelectorAll('input,select').forEach(function(field){field.disabled=existing;});
    }
    connection.addEventListener('change',update);update();
})();
</script>
