<?php
/** All lifecycle mutations use temporary host/plugin tables; real Cacti devices are untouched. */
require __DIR__.'/serial-profiles-integration.php';
// This test supplies its own complete temporary plugin schema above.
function nms_database_ready() { return true; }
require __DIR__.'/../../plugins/nms/includes/configuration/lifecycle.php';
$native=db_fetch_assoc_prepared('SELECT * FROM host WHERE id IN (?,?)',[$hosts[0]['id'],$hosts[1]['id']]);
$ddl=db_fetch_row('SHOW CREATE TABLE host');
nms_category_execute(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl['Create Table']));
foreach($native as $row) nms_category_execute('INSERT INTO host (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')',array_values($row));
$ddl=db_fetch_row('SHOW CREATE TABLE plugin_nms_node_devices');
nms_category_execute(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl['Create Table']));
$host_id=$hosts[0]['id'];
nms_category_execute('INSERT INTO plugin_nms_node_devices(host_id,node_id,updated_by,updated_at) VALUES (?,1,1,NOW())',[$host_id]);
$seed=function() use($host_id) {
    $job=nms_config_job_enqueue($host_id,'limit');
    nms_category_execute("INSERT INTO plugin_nms_serial_readings(host_id,field_key,signature,value_json,status,observed_at) VALUES (?,'limit',?,'17','complete',NOW())",[$host_id,str_repeat('a',64)]);
    return $job;
};
$check_closed=function($job) use($host_id) {
    check(db_fetch_cell_prepared('SELECT status FROM plugin_nms_config_jobs WHERE id=?',[$job])==='failed','Queued job survived lifecycle change');
    check(!db_fetch_cell_prepared('SELECT host_id FROM plugin_nms_serial_readings WHERE host_id=?',[$host_id]),'Obsolete reading survived lifecycle change');
};
$job=$seed();
nms_category_execute("UPDATE host SET disabled='on' WHERE id=?",[$host_id]);
nms_config_reconcile(); $check_closed($job);
check((bool)db_fetch_cell_prepared('SELECT host_id FROM plugin_nms_serial_devices WHERE host_id=?',[$host_id]),'Disabling removed manual connection membership');
nms_category_execute("UPDATE host SET disabled='' WHERE id=?",[$host_id]);
$job=$seed();
nms_category_execute('UPDATE host SET poller_id=poller_id+10000 WHERE id=?',[$host_id]);
nms_config_reconcile(); $check_closed($job);
check((bool)db_fetch_cell_prepared('SELECT host_id FROM plugin_nms_serial_devices WHERE host_id=?',[$host_id]),'Collector move silently reassigned connection');
nms_category_execute('UPDATE host SET poller_id=? WHERE id=?',[$hosts[0]['poller_id'],$host_id]);
$job=$seed();
nms_config_device_saved(['id'=>$host_id,'site_id'=>0]); $check_closed($job);
$job=$seed();
nms_category_execute("UPDATE plugin_nms_config_jobs SET operation='write',status='running' WHERE id=?",[$job]);
nms_config_device_removed([$host_id]);
check(!db_fetch_cell_prepared('SELECT host_id FROM plugin_nms_node_devices WHERE host_id=?',[$host_id]),'Deleted device retained node membership');
// Recover a deletion missed while hooks were disabled, including non-serial members.
nms_category_execute('INSERT INTO plugin_nms_node_devices(host_id,node_id,updated_by,updated_at) VALUES (?,1,1,NOW())',[$hosts[1]['id']]);
nms_category_execute('DELETE FROM host WHERE id=?',[$hosts[1]['id']]);
nms_config_reconcile();
check(!db_fetch_cell_prepared('SELECT host_id FROM plugin_nms_node_devices WHERE host_id=?',[$hosts[1]['id']]),'Reconcile retained orphan node membership');
check(!db_fetch_cell_prepared('SELECT host_id FROM plugin_nms_serial_devices WHERE host_id=?',[$host_id]),'Deleted device retained bus address');
check(!db_fetch_cell_prepared('SELECT host_id FROM plugin_nms_config_devices WHERE host_id=?',[$host_id]),'Deleted device retained equipment assignment');
check(db_fetch_cell_prepared('SELECT status FROM plugin_nms_config_jobs WHERE id=?',[$job])==='running','Lifecycle hook falsely finalized an in-flight write');
check((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_config_jobs WHERE host_id=?',[$host_id])>0,'Deletion removed audit history');
echo "PASS: disable, collector move, device save, deletion cleanup, audit retention and preservation of in-flight write uncertainty; temporary native hosts only\n";
