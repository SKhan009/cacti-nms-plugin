<?php
/** Single-page serial setup, populated from Cacti and saved NMS profiles. */
$input_field = function($name,$label,$attributes='') use($values) {
    print '<label>'.$label.'<input name="'.$name.'" '.$attributes.' value="'.nms_h($values[$name] ?? '').'"></label>';
};
$select_field = function($name,$label,$options,$attributes='') use($values) {
    print '<label>'.$label.'<select name="'.$name.'" '.$attributes.'>';
    foreach($options as $id=>$text) print '<option value="'.nms_h($id).'" '.((string)($values[$name] ?? '')===(string)$id?'selected':'').'>'.nms_h($text).'</option>';
    print '</select></label>';
};
$choices = function($rows) { return array_column($rows,'name','id'); };
$profile_data=[];
foreach($profiles as $profile) $profile_data[(int)$profile['id']]=['revision'=>(int)$profile['revision'],'settings'=>json_decode($profile['settings_json'],true)];
$connection_data=[];
foreach($wizard_connections as $connection) $connection_data[(int)$connection['id']]=['poller_id'=>(int)$connection['poller_id'],'settings'=>$connection['settings'],'endpoint'=>$connection['endpoint']];
$equipment_data=[];
foreach($equipment_choices as $equipment_choice) $equipment_data[(int)$equipment_choice['id']]=$equipment_choice;
?>
<main class="nms-shell nms-devices-shell nms-serial-page">
<div class="nms-heading"><div><p class="nms-eyebrow">NMS / Devices / Serial</p><h1><?php print $host_id?'Edit serial device':'Add device'; ?></h1><p>Device identity, serial communication, readings and Cacti graphs in one form.</p></div></div>
<?php if (!$host_id) { $device_nav_active='add'; $device_nav_id=0; require __DIR__.'/tabs.php'; $add_connection_type='serial'; require __DIR__.'/connection_type.php'; } ?>
<?php if($error){ ?><div role="alert" class="nms-form-message error"><?php print nms_h($error); ?></div><?php } ?>
<?php if(isset($_GET['saved'])){ ?><div role="status" class="nms-form-message success">Serial device saved.</div><?php } ?>
<?php if($association_error){ ?><div role="alert" class="nms-form-message error"><?php print nms_h($association_error); ?></div><?php } ?>
<?php if($host_id){ ?><div class="nms-serial-links"><a class="nms-cancel-button" href="devices.php?tab=readings&amp;id=<?php print $host_id; ?>&amp;view=serial">View readings</a><a class="nms-cancel-button" href="../../host.php?action=edit&amp;id=<?php print $host_id; ?>">Advanced Cacti settings</a></div><?php } ?>
<form method="post" class="nms-node-form" id="nmsSerialWizard">
<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
<input type="hidden" name="core_fields" value="1">
<input type="hidden" name="assignment_revision" value="<?php print (int)$values['assignment_revision']; ?>">
<input type="hidden" name="equipment_revision" value="<?php print (int)$values['equipment_revision']; ?>">
<fieldset><legend>Device identity and connection</legend>
<div class="nms-serial-section-head"><h2>Core device fields</h2><span>Cacti device</span></div>
<div class="nms-node-fields">
<?php
$input_field('description','Device name *','required maxlength="150"');
$input_field('hostname','Hostname / identifier','maxlength="255" placeholder="Defaults to the serial endpoint"');
$select_field('host_template_id','Device Template',[0=>'None']+$choices($host_templates));
$select_field('site_id','Site',[0=>'No site']+$choices($sites));
$input_field('location','Location');
$select_field('poller_id','Data Collector',$choices($pollers),'required');
$input_field('short_name','Short name','maxlength="8"');
$input_field('manual_serial_number','Serial number','maxlength="191"');
?>
<label>Node<select name="node_id"><option value="0">Unassigned</option><?php foreach($node_options as $node){ ?><option value="<?php print (int)$node['id']; ?>" data-site="<?php print (int)$node['site_id']; ?>" <?php print (int)$values['node_id']===(int)$node['id']?'selected':''; ?>><?php print nms_h($node['name'].' · '.$node['site_name']); ?></option><?php } ?></select><small>Choose a node at the selected site.</small></label>
</div>
<p class="nms-serial-note">SNMP: Not in Use · Cacti availability: None. Serial read health is reported separately. The reader uses the connection endpoint below.</p>
<details><summary>Inventory and operational options</summary><div class="nms-node-fields">
<?php
$input_field('external_id','External ID');
$select_field('disabled','Device status',[''=>'Enabled','on'=>'Disabled']);
$select_field('equipment_category_id','Segment',[0=>'Select segment']+$choices($categories));
$type_value=$values['device_type']; $type_segment=$values['equipment_category_id']; $type_keep_saved=(bool)$host_id; require __DIR__.'/type_select.php';
$select_field('device_threads','Collection threads',$fields_host_edit['device_threads']['array']);
?>
<label>Notes<textarea name="notes" maxlength="512" rows="2"><?php print nms_h($values['notes']); ?></textarea></label>
</div></details>
<div class="nms-serial-section-head"><h2>Connection and address</h2><span>NMS serial binding</span></div>
<div class="nms-node-fields">
<label>Connection<select name="connection_id" id="nmsSerialConnection"><option value="0">Create a connection</option><?php foreach($wizard_connections as $connection){ ?><option value="<?php print (int)$connection['id']; ?>" <?php print (int)$values['connection_id']===(int)$connection['id']?'selected':''; ?>><?php print nms_h($connection['name'].' · '.$connection['endpoint'].' · Collector '.$connection['poller_id']); ?></option><?php } ?></select></label>
<?php $input_field('device_address','Modbus unit address *','type="number" min="1" max="247" required'); ?>
</div>
<div id="nmsSerialEndpointFields" class="nms-node-fields">
<?php $input_field('connection_name','Connection name','maxlength="150" placeholder="Defaults to device name"');
$select_field('transport','Transport',['direct'=>'Direct serial port','rtu_tcp'=>'Transparent RTU over TCP']);
$input_field('endpoint','Serial port or gateway IP *','required placeholder="/dev/serial/by-id/… or gateway IP"'); ?>
<label>Gateway TCP port<input name="port" type="number" min="1" max="65535" required value="<?php print nms_h($values['port'] ?? 4001); ?>"></label>
</div>
<p id="nmsSerialConnectionInfo" class="nms-serial-note" role="status">Reuse a connection for devices sharing a bus. Each device needs a unique unit address.</p>
</fieldset>
<fieldset><legend>Serial line settings</legend>
<div class="nms-node-fields"><label>Serial protocol<select name="serial_protocol"><option value="modbus_rtu">Modbus RTU</option><option disabled>Modbus ASCII — adapter unavailable</option><option disabled>Vendor text / binary — adapter unavailable</option></select></label>
<label>Poller base interval<input readonly value="<?php print (int)nms_poller_interval(); ?> seconds"></label></div>
<div id="nmsSerialPresetFields">
<div class="nms-node-fields"><label>Serial profile *<select name="profile_id" required><option value="">Select a saved profile</option><?php foreach($profiles as $profile){ ?><option value="<?php print (int)$profile['id']; ?>" <?php print (int)($values['profile_id'] ?? 0)===(int)$profile['id']?'selected':''; ?>><?php print nms_h($profile['name']); ?></option><?php } ?></select></label></div>
<input type="hidden" name="profile_revision" value="<?php print nms_h($values['profile_revision'] ?? ''); ?>">
<label class="nms-serial-check"><input type="checkbox" name="custom_serial_settings" value="1" <?php print !empty($values['custom_serial_settings'])?'checked':''; ?>>Customize settings for this new connection</label>
<div class="nms-node-fields" id="nmsSerialSettings">
<?php
$select_field('interface','Serial interface',['unspecified'=>'Not specified (existing hardware)','rs232'=>'RS-232','rs485'=>'RS-485']);
$input_field('baud_rate','Baud rate','type="number" min="50" max="4000000" required');
$select_field('data_bits','Data bits',[8=>'8']);
$select_field('parity','Parity',['even'=>'Even','odd'=>'Odd','none'=>'None','mark'=>'Mark','space'=>'Space']);
$select_field('stop_bits','Stop bits',[1=>'1',2=>'2']);
$select_field('flow_control','Flow control',['none'=>'None','rtscts'=>'RTS/CTS']);
$input_field('timeout_ms','Response timeout (ms)','type="number" min="100" max="10000" required');
$input_field('retries','Additional read retries','type="number" min="0" max="3" required');
?>
</div></div>
<dl id="nmsSerialEffective" class="nms-serial-kv" aria-live="polite"></dl>
<p>Match the equipment's configured settings. For transparent RTU over TCP, configure the gateway's serial side separately. RS-232 requires matching hardware. RS-485 requires automatic direction control on the adapter or a gateway configured for RS-485, with flow control None. Selecting an interface does not switch the hardware's electrical mode.</p>
<a href="serial_profiles.php" target="_blank" rel="noopener">Manage serial profiles</a>
<?php if($host_id){ ?><a href="devices.php?tab=connection&amp;shared_settings=1&amp;id=<?php print $host_id; ?>">Review shared connection settings</a><?php } ?>
</fieldset>
<fieldset><legend>Equipment and reading definitions</legend>
<div class="nms-node-fields"><?php $select_field('equipment_profile_id','Equipment / reading profile',[0=>'No register readings']+$choices($equipment_choices));
$input_field('interval_seconds','Reading interval (seconds)','type="number" min="60" max="86400" required'); ?></div>
<p id="nmsSerialEquipmentInfo" class="nms-serial-note"></p>
<div class="nms-node-table"><table><thead><tr><th>Reading</th><th>Key</th><th>Register offset</th><th>Function</th><th>Type / unit</th><th>Range</th></tr></thead><tbody id="nmsSerialReadingRows"></tbody></table></div>
<p>Register offsets are zero-based and must match the model's manual. Select a saved equipment profile to collect readings.</p>
<a href="devices.php?tab=configuration&amp;view=profiles" target="_blank" rel="noopener">Manage equipment profiles</a>
</fieldset>
<fieldset><legend>Cacti Data Input Method and storage</legend>
<p>Serial readings use the installed NMS collector. Creating reading graphs provisions the Cacti Script/Command input, Data Template and Graph Template for each numeric field.</p>
<div class="nms-serial-note">Each numeric reading uses its own data source. Collection can run without graphs; RRD history starts when its graph and data source are created.</div>
<details><summary>Installed Cacti Data Source Profiles</summary>
<div class="nms-node-table"><table><thead><tr><th>Profile</th><th>Step (seconds)</th><th>Heartbeat (seconds)</th></tr></thead><tbody><?php foreach($source_profiles as $source){ ?><tr><td><?php print nms_h($source['name']); ?></td><td><?php print (int)$source['step']; ?></td><td><?php print (int)$source['heartbeat']; ?></td></tr><?php } ?></tbody></table></div>
<p>Graph creation uses a step supported by the Cacti poller. A 60-second reading interval with a 300-second poller produces a 300-second graph; faster NMS readings remain available in Readings. Retention follows that Cacti profile. Existing data sources retain their configuration.</p>
</details>
</fieldset>
<fieldset><legend>Graph and Device Templates</legend>
<p>The Device Template above and these graph/query choices come from this Cacti installation. Added associations may need graph-specific inputs after saving.</p>
<div class="nms-device-associations">
<?php foreach (['graph'=>['Associated Graph Templates','Add Graph Template','Search graph templates','graph_template_ids',$graph_choices,$associated_graphs], 'query'=>['Associated Data Queries','Add Data Query','Search data queries','data_query_ids',$query_choices,$associated_queries]] as $kind=>$group) {
    [$heading,$caption,$search,$field,$choices,$associated]=$group;
    $existing_ids=array_map('intval',array_column($associated,'id'));
?>
<section class="nms-panel" data-serial-association="<?php print $kind; ?>">
<div class="nms-panel-head"><div><h2><?php print $heading; ?></h2><p><?php print $kind==='graph'?'Select from Cacti graph templates.':'Choose a compatible non-SNMP script query for this device and collector.'; ?></p></div></div>
<select hidden multiple name="<?php print $field; ?>[]" data-serial-selected aria-label="Selected <?php print $kind==='graph'?'graph templates':'data queries'; ?>">
<?php foreach($choices as $choice){ if(in_array((int)$choice['id'],$existing_ids,true)) continue; ?><option value="<?php print (int)$choice['id']; ?>" <?php print in_array((string)$choice['id'],array_map('strval',(array)($values[$field] ?? [])),true)?'selected':''; ?>><?php print nms_h($choice['name']); ?></option><?php } ?>
</select>
<div class="nms-association-add <?php print $kind==='query'?'query ':''; ?>top">
<label><span><?php print $caption; ?></span><select class="nms-search-select" data-serial-choice data-search-placeholder="<?php print $search; ?>"><option value="">Select <?php print $kind==='graph'?'a graph template':'a data query'; ?></option><?php foreach($choices as $choice){ if(in_array((int)$choice['id'],$existing_ids,true)) continue; ?><option value="<?php print (int)$choice['id']; ?>"><?php print nms_h($choice['name']); ?></option><?php } ?></select></label>
<?php if($kind==='query'){ ?><label><span>Re-Index Method</span><select name="reindex_method"><?php foreach($reindex_types as $key=>$label){ ?><option value="<?php print (int)$key; ?>" <?php print (int)($values['reindex_method'] ?? 0)===(int)$key?'selected':''; ?>><?php print nms_h($label); ?></option><?php } ?></select></label><?php } ?>
<button type="button" data-serial-add>Add <?php print $kind==='graph'?'template':'query'; ?></button>
</div>
<div class="nms-association-table">
<div class="nms-association-row graph heading"><span><?php print $kind==='graph'?'Graph template':'Data query'; ?></span><span><?php print $kind==='graph'?'Status':'Re-Index Method'; ?></span><span>Action</span></div>
<?php foreach($associated as $item){ ?><div class="nms-association-row graph"><strong><?php print nms_h($item['name']); ?></strong><span><?php print $kind==='graph'?((int)$item['graph_count']>0?'Being graphed':'Not graphed'):nms_h($reindex_types[$item['reindex_method']] ?? ''); ?></span><span>Saved</span></div><?php } ?>
<div data-serial-pending></div>
<div class="nms-association-empty" data-serial-empty <?php print $associated?'hidden':''; ?>>No <?php print $kind==='graph'?'graph templates':'data queries'; ?> selected.</div>
</div>
<p class="nms-serial-selection-status" data-serial-selection-status role="status"></p>
</section>
<?php } ?>
</div>
<?php if($host_id){ ?><a class="nms-cancel-button" href="../../graphs_new.php?host_id=<?php print $host_id; ?>">Create / select Cacti graphs</a><?php } ?>

</fieldset>
<fieldset><legend>Create graphs and review</legend>
<label class="nms-serial-check"><input type="checkbox" name="create_serial_graphs" value="1" <?php print !empty($values['create_serial_graphs'])?'checked':''; ?>>Create Cacti graphs for numeric readings in the selected equipment profile</label>
<p>Creates graph instances and their data sources. Graph samples appear after successful collection and Cacti polling.</p>
<details open><summary>Review device configuration</summary><dl id="nmsSerialReview" class="nms-serial-kv"></dl></details>
<p>Saving records the device and its monitoring setup. It does not change the physical equipment's settings.</p>
</fieldset>
<div class="nms-serial-footer"><a class="nms-cancel-button" href="devices.php">Cancel</a><button type="submit" class="nms-node-button" id="nmsSerialSave"><?php print $host_id?'Save changes':'Create serial device'; ?></button></div>
</form>
<script type="application/json" id="nmsSerialFormData"><?php print json_encode(['profiles'=>$profile_data,'connections'=>$connection_data,'equipment'=>$equipment_data,'submitted'=>$_SERVER['REQUEST_METHOD']==='POST'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR); ?></script>
<?php if($host_id){ ?><section class="nms-panel" id="serial-test"><div class="nms-node-form"><h2>Serial communication test</h2><p>Read one configured register through the assigned collector. This checks serial communication without changing the equipment.</p>
<?php if($last_test){
    $test_result=json_decode($last_test['result_json'],true);
    if(!is_array($test_result)) $test_result=[];
    $test_pending=in_array($last_test['status'],['queued','running'],true);
?><div class="nms-serial-test-result" role="status">
<p><strong><?php print nms_h(['queued'=>'Waiting for collector','running'=>'Reading device','complete'=>'Read complete','failed'=>'Read failed'][$last_test['status']] ?? $last_test['status']); ?></strong> · Request #<?php print (int)$last_test['id']; ?></p>
<?php if($last_test['status']==='complete' && array_key_exists('value',$test_result) && $test_result['value']!==null){ ?>
<p><strong><?php print nms_h($last_test['field_key']); ?>:</strong> <?php print nms_h(is_scalar($test_result['value'])?(string)$test_result['value']:json_encode($test_result['value'])); ?></p>
<?php } elseif($test_pending){ ?><p>The collector is processing this test. Select Refresh result to check again.</p>
<?php } elseif(!empty($test_result['error'])){ ?><p><?php print nms_h($test_result['error']); ?></p>
<?php } else { ?><p>No reading was returned by this test. Run the test again to request a new reading.</p><?php } ?>
<p><?php print $test_pending?'Requested':'Finished'; ?>: <?php print nms_h(($test_pending?$last_test['requested_at']:$last_test['finished_at']) ?? 'Not recorded'); ?></p>
</div><?php } else { ?><p>No test result yet. Select Test serial response to read the device.</p><?php } ?>
<form method="post"><input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="action" value="test_serial"><button class="nms-node-button" type="submit" <?php print empty($equipment['profile_id'])?'disabled':''; ?>>Test serial response</button> <a class="nms-cancel-button" href="devices.php?tab=serial&amp;id=<?php print $host_id; ?>#serial-test">Refresh result</a></form>
</div></section><?php } ?></main>
