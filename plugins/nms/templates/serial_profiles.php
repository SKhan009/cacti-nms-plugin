<?php /** Reuses existing compact form/table styles. */ ?>
<main class="nms-shell nms-devices-shell">
<div class="nms-heading"><div><p class="nms-eyebrow">NMS / Presets</p><h1>Serial profiles</h1><p>Reusable connection defaults for supported serial devices.</p></div></div>
<?php if ($error) { ?><div class="nms-form-message error" role="alert"><?php print nms_h($error); ?></div><?php } ?>
<?php if (isset($_GET['saved']) && !$error) { ?><div class="nms-form-message success" role="status">Profile saved. Existing connections retain their settings.</div><?php } ?>
<?php if ($editing) { ?>
<section class="nms-panel"><div class="nms-panel-head"><h2><?php print (int)$values['id'] ? 'Edit serial profile' : 'Create serial profile'; ?></h2></div>
<form method="post" class="nms-node-form">
<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="action" value="save">
<input type="hidden" name="id" value="<?php print (int)$values['id']; ?>"><input type="hidden" name="revision" value="<?php print (int)$values['revision']; ?>">
<div class="nms-node-fields">
<?php foreach (['name'=>['Name',150],'description'=>['Description',512],'manufacturer'=>['Manufacturer',120],'model'=>['Model',120]] as $key=>[$label,$max]) { ?>
<label><?php print $label; ?><input name="<?php print $key; ?>" maxlength="<?php print $max; ?>" <?php print $key!=='description'?'required':''; ?> value="<?php print nms_h($values[$key]); ?>"></label><?php } ?>
<?php foreach (['interface'=>['Serial interface',['unspecified'=>'Not specified (existing hardware)','rs232'=>'RS-232','rs485'=>'RS-485']], 'protocol'=>['Protocol',['modbus_rtu'=>'Modbus RTU']], 'parity'=>['Parity',['even'=>'Even','odd'=>'Odd','none'=>'None','mark'=>'Mark','space'=>'Space']], 'flow_control'=>['Flow control',['none'=>'None','rtscts'=>'RTS/CTS']]] as $key=>[$label,$choices]) { ?>
<label><?php print $label; ?><select name="<?php print $key; ?>"><?php foreach($choices as $value=>$text) { ?><option value="<?php print $value; ?>" <?php print (string)$values[$key]===$value?'selected':''; ?>><?php print $text; ?></option><?php } ?><?php if($key==='flow_control'){ ?><option disabled>XON/XOFF — unavailable for binary RTU</option><option disabled>DTR/DSR — unavailable on this Linux adapter</option><?php } ?></select></label><?php } ?>
<?php
$baud_rates=[50,75,110,134,150,200,300,600,1200,1800,2400,4800,9600,19200,38400,57600,115200,230400,460800,500000,576000,921600,1000000,1152000,1500000,2000000,2500000,3000000,3500000,4000000];
$current_baud=(string)$values['baud_rate'];
if(ctype_digit($current_baud) && (int)$current_baud>=50 && (int)$current_baud<=4000000 && !in_array((int)$current_baud,$baud_rates,true)) { $baud_rates[]=(int)$current_baud; sort($baud_rates); }
?>
<label>Baud rate<select name="baud_rate"><?php foreach($baud_rates as $rate){ ?><option value="<?php print $rate; ?>" <?php print $current_baud===(string)$rate?'selected':''; ?>><?php print $rate; ?></option><?php } ?><option value="custom" <?php print $current_baud==='custom'?'selected':''; ?>>Custom</option></select></label>
<label>Custom baud rate<input type="number" name="custom_baud_rate" min="50" max="4000000" step="1" value="<?php print nms_h($values['custom_baud_rate'] ?? ''); ?>"><small>Used only when Baud rate is Custom. The port must support this rate.</small></label>
<p>RS-485 requires an adapter with automatic direction control or a gateway configured for RS-485. Select flow control None. This setting does not switch the electrical mode of hardware.</p>
<label>Data bits<select name="data_bits"><option value="8">8</option><option disabled>5 — not supported by Modbus RTU</option><option disabled>6 — not supported by Modbus RTU</option><option disabled>7 — not supported by Modbus RTU</option></select></label>
<label>Stop bits<select name="stop_bits"><?php foreach([1,2] as $bits){ ?><option value="<?php print $bits; ?>" <?php print (string)$values['stop_bits']===(string)$bits?'selected':''; ?>><?php print $bits; ?></option><?php } ?><option disabled>1.5 — not supported by this RTU adapter</option></select></label>
<?php foreach (['timeout_ms'=>['Timeout (ms)',100,10000], 'retries'=>['Read retries',0,3]] as $key=>[$label,$min,$max]) { ?>
<label><?php print $label; ?><input type="number" name="<?php print $key; ?>" required min="<?php print $min; ?>" max="<?php print $max; ?>" step="1" value="<?php print nms_h($values[$key]); ?>"></label><?php } ?>
</div>
<p>Match the device manual. Mark/Space parity and custom or high baud rates require port and device support. XON/XOFF can consume RTU data bytes; DTR/DSR is not implemented by this Linux adapter. Gateway serial settings must be configured on the gateway itself.</p>
<p><?php print $references; ?> connection(s) reference this profile. Saving changes does not update them or send commands to devices.</p>
<button type="submit" class="nms-node-button">Save profile</button> <a class="nms-cancel-button" href="serial_profiles.php">Cancel</a>
</form></section>
<?php } else { ?>
<section class="nms-panel"><div class="nms-panel-head"><h2>Configured profiles</h2><a class="nms-node-button" href="serial_profiles.php?new=1">Create profile</a></div>
<div class="nms-node-table"><table><thead><tr><th>Name</th><th>Manufacturer / model</th><th>Revision</th><th>Updated</th></tr></thead><tbody>
<?php foreach ($profiles as $profile) { ?><tr><td><a href="serial_profiles.php?id=<?php print (int)$profile['id']; ?>"><?php print nms_h($profile['name']); ?></a></td><td><?php print nms_h($profile['manufacturer'].' / '.$profile['model']); ?></td><td><?php print (int)$profile['revision']; ?></td><td><?php print nms_h($profile['updated_at']); ?></td></tr><?php } ?>
</tbody></table><?php if (!$profiles) { ?><p>No serial profiles configured.</p><?php } ?></div></section>
<?php } ?></main>
