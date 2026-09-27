<?php
/** Device setup stays in Edit device; presets remain reusable. */
require_once __DIR__.'/../../includes/configuration/equipment.php';
$setup_serial=nms_serial_assignment($device_id);
$setup_equipment=db_fetch_row_prepared('SELECT * FROM plugin_nms_config_devices WHERE host_id=?',[$device_id]);
$setup_profiles=db_fetch_assoc('SELECT id,name,protocol FROM plugin_nms_config_profiles ORDER BY name');
?>
<section class="nms-panel" id="serial-setup">
<div class="nms-panel-head"><h2><?php print $setup_serial?'Serial setup':'Equipment setup'; ?></h2></div>
<?php if($setup_serial){ ?>
<form method="post" action="devices.php?tab=connection&amp;id=<?php print $device_id; ?>" class="nms-device-form">
<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="action" value="assign"><input type="hidden" name="return_to" value="edit"><input type="hidden" name="assignment_revision" value="<?php print (int)$setup_serial['revision']; ?>">
<div class="nms-form-grid">
<label><span>Serial connection</span><select name="connection_id"><?php foreach(nms_serial_connections((int)db_fetch_cell_prepared('SELECT poller_id FROM host WHERE id=?',[$device_id])) as $bus){ ?><option value="<?php print (int)$bus['id']; ?>" <?php print (int)$bus['id']===(int)$setup_serial['connection_id']?'selected':''; ?>><?php print nms_h($bus['name'].' · '.$bus['endpoint']); ?></option><?php } ?></select></label>
<label><span>Modbus address</span><input type="number" name="device_address" min="1" max="247" required value="<?php print (int)$setup_serial['device_address']; ?>"></label>
</div><p>Devices sharing a serial bus use the same connection and different Modbus addresses.</p>
<div class="nms-form-actions"><button type="submit">Save connection</button></div>
</form>
<?php } ?>
<form method="post" action="devices.php?tab=configuration&amp;id=<?php print $device_id; ?>" class="nms-device-form">
<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="action" value="assign_equipment"><input type="hidden" name="return_to" value="edit"><input type="hidden" name="revision" value="<?php print (int)($setup_equipment['revision'] ?? 0); ?>">
<div class="nms-form-grid"><label><span>Equipment profile</span><select name="profile_id"><option value="0">Not assigned</option><?php foreach($setup_profiles as $equipment){ if($setup_serial && $equipment['protocol']!=='modbus_rtu') continue; if(!$setup_serial && $equipment['protocol']!=='snmp') continue; ?><option value="<?php print (int)$equipment['id']; ?>" <?php print (int)($setup_equipment['profile_id'] ?? 0)===(int)$equipment['id']?'selected':''; ?>><?php print nms_h($equipment['name']); ?></option><?php } ?></select></label>
<label><span>Reading interval (seconds)</span><input type="number" name="interval_seconds" min="60" max="86400" required value="<?php print (int)($setup_equipment['interval_seconds'] ?? 300); ?>"></label>
<?php if(!$setup_serial){ ?><label><span>SNMP write credential reference (optional)</span><input name="credential_ref" maxlength="100" value="<?php print nms_h($setup_equipment['credential_ref'] ?? ''); ?>"></label><?php } ?>
</div><p>Choose a reusable equipment profile from Presets to define the values collected for this device.</p>
<div class="nms-form-actions"><button type="submit">Save equipment setup</button><?php if($setup_equipment){ ?><a class="nms-cancel-button" href="devices.php?tab=configuration&amp;id=<?php print $device_id; ?>">Readings and equipment controls</a><?php } ?></div>
</form>
</section>
