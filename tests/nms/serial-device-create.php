<?php
/** QA-only: create and remove a native Cacti fixture, with temporary serial tables. */
require __DIR__.'/serial-profiles-integration.php';
require_once $config['base_path'].'/include/global_form.php';
require __DIR__.'/../../plugins/nms/includes/configuration/device.php';
$name='NMS serial creation QA '.bin2hex(random_bytes(5));
$input=['description'=>$name,'site_id'=>nms_single_topology_site() ?: 0,'node_id'=>0,'connection_id'=>$connection,'device_address'=>3];
try {
    $created=nms_serial_device_create($input);
    $host=db_fetch_row_prepared('SELECT hostname,poller_id,snmp_version,availability_method FROM host WHERE id=?',[$created]);
    check($host['hostname']==='/dev/ttyUSB97','Serial device received a fabricated network address');
    check((int)$host['poller_id']===(int)$hosts[0]['poller_id'],'Serial collector was not inherited');
    check((int)$host['snmp_version']===0 && (int)$host['availability_method']===0,'Network probes were left enabled for serial device');
    check((int)nms_serial_assignment($created)['device_address']===3,'Serial unit was not assigned');
    try { nms_serial_device_create(array_replace($input,['description'=>$name.' duplicate'])); throw new LogicException('Duplicate unit accepted'); } catch(InvalidArgumentException $e) {}
    check(!db_fetch_cell_prepared('SELECT id FROM host WHERE description=?',[$name.' duplicate']),'Rejected duplicate created an orphan native host');
    $preset_input=array_replace($input,['description'=>$name.' preset','connection_id'=>0,'profile_id'=>$id,
        'poller_id'=>$hosts[0]['poller_id'],'transport'=>'direct','endpoint'=>'/dev/ttyUSB96','equipment_profile_id'=>$model_id,'interval_seconds'=>120,'host_template_id'=>0,'device_threads'=>1,'location'=>'QA rack','external_id'=>'serial-wizard-qa','device_type'=>'Meter','equipment_category_id'=>0]);
    $preset_device=nms_serial_device_create($preset_input);
    $preset_assignment=nms_serial_assignment($preset_device);
    check(db_fetch_cell_prepared('SELECT external_id FROM host WHERE id=?',[$preset_device])==='serial-wizard-qa','Native External ID was not saved');
    check(db_fetch_cell_prepared('SELECT location FROM host WHERE id=?',[$preset_device])==='QA rack','Native location was not saved');
    check(db_fetch_cell_prepared('SELECT device_type FROM plugin_nms_device_classification WHERE host_id=?',[$preset_device])==='Meter','Device type was not saved');
    check((int)db_fetch_cell_prepared('SELECT profile_id FROM plugin_nms_config_devices WHERE host_id=?',[$preset_device])===$model_id,'Equipment profile not assigned during creation');
    check((int)db_fetch_cell_prepared('SELECT interval_seconds FROM plugin_nms_config_devices WHERE host_id=?',[$preset_device])===120,'Reading interval not saved');
    $copied=nms_serial_connection_get($preset_assignment['connection_id']);
    check($copied['settings_json']===nms_serial_profile_get($id)['settings_json'],'Preset settings were not copied');
    check($copied['endpoint']==='/dev/ttyUSB96','Preset endpoint mismatch');
    try { nms_serial_device_create(array_replace($preset_input,['description'=>$name.' rejected'])); throw new LogicException('Duplicate endpoint accepted'); } catch(InvalidArgumentException $e) {}
    check(!db_fetch_cell_prepared('SELECT id FROM host WHERE description=?',[$name.' rejected']),'Rejected endpoint left a device');
    $edit=array_replace($preset_input,['connection_id'=>$preset_assignment['connection_id'],'assignment_revision'=>$preset_assignment['revision'],'equipment_revision'=>1,'notes'=>'Wizard update','interval_seconds'=>180]);
    nms_serial_device_update($preset_device,$edit);
    check(db_fetch_cell_prepared('SELECT notes FROM host WHERE id=?',[$preset_device])==='Wizard update','Core notes not updated');
    check((int)db_fetch_cell_prepared('SELECT interval_seconds FROM plugin_nms_config_devices WHERE host_id=?',[$preset_device])===180,'Equipment update not saved');
    try { nms_serial_device_update($preset_device,array_replace($edit,['notes'=>'Stale update'])); throw new LogicException('Stale wizard accepted'); } catch(RuntimeException $e) {}
    check(db_fetch_cell_prepared('SELECT notes FROM host WHERE id=?',[$preset_device])==='Wizard update','Stale update changed native host');
    $fresh=nms_serial_assignment($preset_device);
    try { nms_serial_device_update($preset_device,array_replace($edit,['assignment_revision'=>$fresh['revision'],'equipment_revision'=>1,'device_address'=>4])); throw new LogicException('Stale equipment accepted'); } catch(RuntimeException $e) {}
    check((int)nms_serial_assignment($preset_device)['device_address']===3,'Failed equipment save did not roll back connection');
    echo "PASS: unified serial update, stale edit rejection and atomic rollback\n";
    echo "PASS: preset dropdown input creates connection and native device; duplicate endpoint rejected\n";
    echo "PASS: native Cacti serial creation, actual endpoint identity, collector assignment, no network probes and duplicate address rejected before creation\n";
} finally {
    // Only this invocation's randomly named fixture can be deleted, including partial native saves.
    foreach([$name,$name.' preset'] as $fixture_name) {
    $fixture=(int)db_fetch_cell_prepared('SELECT id FROM host WHERE description=?',[$fixture_name]);
    if($fixture) { api_device_remove($fixture); nms_managed_object_forget('device',$fixture); }
}
}
