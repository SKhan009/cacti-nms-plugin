<?php
/** Native Cacti permission queries against connection-local copies; no persistent account/ACL edits. */
if(PHP_SAPI!=='cli'||empty($argv[1])||empty($argv[2]))exit('Supply Cacti root and staged workspace');
require $argv[1].'/include/global.php';require_once $argv[1].'/plugins/nms/includes/functions.php';require $argv[2].'/authorization.php';
$user=(int)db_fetch_cell("SELECT id FROM user_auth WHERE username='admin' AND enabled='on'");
foreach(['user_auth','user_auth_realm','user_auth_group_members','user_auth_perms'] as $table) {
    $rows=db_fetch_assoc("SELECT * FROM $table");$ddl=array_values(db_fetch_row('SHOW CREATE TABLE '.$table))[1];
    if(!db_execute(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl)))throw new RuntimeException('Fixture failed');
    foreach($rows as $row){$keys=array_keys($row);nms_category_execute('INSERT INTO '.$table.' (`'.implode('`,`',$keys).'`) VALUES ('.implode(',',array_fill(0,count($keys),'?')).')',array_values($row));}
}
$_SESSION=['sess_user_id'=>$user];$count=0;
function check($ok,$message){global $count;if(!$ok)throw new RuntimeException($message);$count++;echo "PASS: $message\n";}
function rejected($fn,$message){try{$fn();}catch(RuntimeException $e){check(true,$message);return;}throw new RuntimeException('Unexpected acceptance: '.$message);}
nms_workspace_authorize_current(2,['devices.php','diagnostics.php']);check(true,'Native current-user permissions accept authorized device');
// Prime the default session cache, then revoke only within this test connection.
is_realm_allowed(3);nms_category_execute('DELETE FROM user_auth_group_members WHERE user_id=?',[$user]);
nms_category_execute('DELETE FROM user_auth_realm WHERE user_id=? AND realm_id=3',[$user]);
rejected(fn()=>nms_workspace_authorize_current(2),'Explicit-user realm lookup detects revocation despite primed session cache');
nms_category_execute('INSERT INTO user_auth_realm(user_id,realm_id) VALUES (?,3)',[$user]);
$realm=(int)$user_auth_realm_filenames['diagnostics.php'];nms_category_execute('DELETE FROM user_auth_realm WHERE user_id=? AND realm_id=?',[$user,$realm]);
rejected(fn()=>nms_workspace_authorize_current(2,['devices.php','diagnostics.php']),'Native diagnostic-page revocation blocks authorization');
nms_category_execute('INSERT INTO user_auth_realm(user_id,realm_id) VALUES (?,?)',[$user,$realm]);
nms_category_execute('UPDATE user_auth SET policy_hosts=2,policy_graphs=2,policy_graph_templates=2,policy_trees=2 WHERE id=?',[$user]);nms_category_execute('DELETE FROM user_auth_perms WHERE user_id=?',[$user]);
rejected(fn()=>nms_workspace_authorize_current(2),'Native device allowlist without this host rejects access');
nms_category_execute('INSERT INTO user_auth_perms(user_id,item_id,type) VALUES (?,2,3)',[$user]);
nms_workspace_authorize_current(2);check(true,'Native type-3 device permission restores access');
nms_category_execute("UPDATE user_auth SET enabled='' WHERE id=?",[$user]);
rejected(fn()=>nms_workspace_authorize_current(2),'Disabled account rejects with a controlled error');
// Exercise the actual service queue/worker using the same native permission tables.
nms_category_execute("UPDATE user_auth SET enabled='on',policy_hosts=1,policy_graphs=1,policy_graph_templates=1,policy_trees=1 WHERE id=?",[$user]);
require_once $argv[1].'/plugins/nms/includes/workspace/admission.php';
require_once $argv[1].'/plugins/nms/includes/workspace/audit.php';
require_once $argv[1].'/plugins/nms/includes/workspace/service_checks.php';
require_once $argv[1].'/plugins/nms/includes/diagnostics_queue.php';
$schema=str_replace(['function nms_workspace_schema(', 'CREATE TABLE IF NOT EXISTS'],['function qa_schema(', 'CREATE TEMPORARY TABLE'],file_get_contents($argv[2].'/schema.php'));eval(substr($schema,5));qa_schema();
foreach(['services.php','service_worker.php'] as $file){$source=preg_replace('/^require_once .*;$/m','',file_get_contents($argv[2].'/'.$file));if($file==='service_worker.php')$source=str_replace('nms_workspace_service_probe(','qa_probe(',$source);eval(substr($source,5));}
foreach(["CREATE TEMPORARY TABLE plugin_config(directory VARCHAR(64) PRIMARY KEY,status INT)","INSERT INTO plugin_config VALUES ('nms',1)","CREATE TEMPORARY TABLE plugin_nms_meta(meta_key VARCHAR(191) PRIMARY KEY,meta_value TEXT,updated_at DATETIME)","INSERT INTO plugin_nms_meta VALUES ('diagnostic_runner_1','{\"tools\":{\"ping\":true}}',NOW())"] as $sql)if(!db_execute($sql))throw new RuntimeException('Worker fixture failed');
$calls=0;
function qa_probe($host,$spec){global $calls,$user;$calls++;is_realm_allowed(3);nms_category_execute('DELETE FROM user_auth_realm WHERE user_id=? AND realm_id=3',[$user]);return ['passed'=>true,'category'=>'criteria_met','target'=>$host['hostname']];}
$_SESSION=['sess_user_id'=>$user];$input=['kind'=>'http','timeout'=>1];
$id=nms_workspace_service_enqueue(2,$input);nms_category_execute('DELETE FROM user_auth_realm WHERE user_id=? AND realm_id=3',[$user]);nms_workspace_service_poll(1);
check($calls===0&&db_fetch_cell_prepared('SELECT status FROM plugin_nms_service_jobs WHERE id=?',[$id])==='failed','Queued worker sends no request after management realm revocation');
nms_category_execute('INSERT INTO user_auth_realm(user_id,realm_id) VALUES (?,3)',[$user]);$_SESSION=['sess_user_id'=>$user];
$id=nms_workspace_service_enqueue(2,$input);nms_workspace_service_poll(1);
$job=db_fetch_row_prepared('SELECT status,result_json FROM plugin_nms_service_jobs WHERE id=?',[$id]);
check($calls===1&&$job['status']==='failed'&&strpos($job['result_json'],'criteria_met')===false,'Revocation during transport discards success despite primed session realm');
echo "$count native authorization assertions passed; transport simulated; persistent accounts and permissions unchanged.\n";
