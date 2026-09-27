<?php
/** Deployed Cacti hook dispatch, with connection-local temporary tables only. */
if (PHP_SAPI !== 'cli') exit(1);
$bootstrap=getenv('NMS_CACTI_BOOTSTRAP');
if (!$bootstrap || !is_file($bootstrap)) throw new RuntimeException('Set NMS_CACTI_BOOTSTRAP.');
require $bootstrap;
function check($ok,$message) { if (!$ok) throw new RuntimeException($message); }
check(!defined('IN_CACTI_INSTALL'),'Hook dispatch must be enabled for this test');
check((int)db_fetch_cell("SELECT status FROM plugin_config WHERE directory='nms'")===1,'NMS must already be enabled');
require_once $config['base_path'].'/plugins/nms/includes/functions.php';
require_once $config['base_path'].'/plugins/nms/includes/database.php';
check(nms_database_ready(),'Deployed schema is not ready');
$expected=['api_device_new'=>'nms_config_device_saved','device_remove'=>'nms_config_device_removed'];
$hooks=db_fetch_assoc("SELECT * FROM plugin_hooks WHERE name='nms' AND hook IN ('api_device_new','device_remove')");
check(count($hooks)===2,'Missing or duplicate lifecycle hook registration');
foreach($hooks as $row) {
    check((int)$row['status']===1 && $row['function']===$expected[$row['hook']]
        && $row['file']==='includes/configuration/lifecycle.php','Incorrect lifecycle hook registration');
}
// Retain actual registrations, but exclude unrelated plugins from fixture dispatch.
foreach(['plugin_hooks','plugin_nms_config_jobs','plugin_nms_serial_readings','plugin_nms_serial_devices','plugin_nms_config_devices','plugin_nms_node_devices'] as $table) {
    $ddl=db_fetch_row('SHOW CREATE TABLE '.$table);
    nms_category_execute(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl['Create Table']));
}
foreach($hooks as $row) nms_category_execute('INSERT INTO plugin_hooks (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')',array_values($row));
$host=16777214;
check(!db_fetch_cell_prepared('SELECT id FROM host WHERE id=?',[$host]),'Fixture ID belongs to a real host');
$jobs=[];
foreach(['queued','running'] as $state) {
    nms_category_execute("INSERT INTO plugin_nms_config_jobs(host_id,poller_id,user_id,operation,field_key,signature,before_json,requested_json,result_json,status,requested_at) VALUES (?,1,1,'write','fixture',?,'17','23','{}',?,NOW())",[$host,str_repeat('a',64),$state]);
    $jobs[$state]=(int)db_fetch_cell('SELECT LAST_INSERT_ID()');
}
$seed=function() use($host) { nms_category_execute("INSERT INTO plugin_nms_serial_readings(host_id,field_key,signature,value_json,status,observed_at) VALUES (?,'fixture',?,'17','complete',NOW())",[$host,str_repeat('a',64)]); };
$seed();
$saved=['id'=>$host,'site_id'=>0];
check(api_plugin_hook_function('api_device_new',$saved)===$saved,'Save hook changed native payload');
check(db_fetch_cell_prepared('SELECT status FROM plugin_nms_config_jobs WHERE id=?',[$jobs['queued']])==='failed','Save hook did not invalidate queued job');
check((int)db_fetch_cell('SELECT COUNT(*) FROM plugin_nms_serial_readings')===0,'Save hook kept obsolete readings');
nms_category_execute("INSERT INTO plugin_nms_serial_devices(host_id,connection_id,device_address,updated_by,updated_at) VALUES (?,1,1,1,NOW())",[$host]);
nms_category_execute("INSERT INTO plugin_nms_config_devices(host_id,profile_id,updated_by,updated_at) VALUES (?,1,1,NOW())",[$host]);
nms_category_execute('INSERT INTO plugin_nms_node_devices(host_id,node_id,updated_by,updated_at) VALUES (?,1,1,NOW())',[$host]);
$seed();
check(api_plugin_hook_function('device_remove',[$host])===[$host],'Remove hook changed native payload');
foreach(['plugin_nms_serial_devices','plugin_nms_config_devices','plugin_nms_serial_readings','plugin_nms_node_devices'] as $table) check((int)db_fetch_cell('SELECT COUNT(*) FROM '.$table)===0,'Remove hook left records in '.$table);
check(db_fetch_cell_prepared('SELECT status FROM plugin_nms_config_jobs WHERE id=?',[$jobs['running']])==='running','Hook finalized an uncertain running write');
check((int)db_fetch_cell('SELECT COUNT(*) FROM plugin_nms_config_jobs')===2,'Hook removed audit history');
echo "PASS: deployed hook registration and real Cacti dispatch for save/remove, invalidation, audit retention and in-flight uncertainty; temporary tables only\n";
