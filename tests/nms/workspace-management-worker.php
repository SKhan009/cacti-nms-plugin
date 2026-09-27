<?php
/** SQL lifecycle acceptance with simulated transport/native update, no live host modifications. */
if(PHP_SAPI!=='cli'||empty($argv[1])||empty($argv[2]))exit('Supply Cacti root and staged workspace');
require $argv[1].'/include/global.php';
require_once $argv[1].'/plugins/nms/includes/functions.php';
require_once $argv[1].'/plugins/nms/includes/workspace/audit.php';
$schema=str_replace(['function nms_workspace_schema(', 'CREATE TABLE IF NOT EXISTS'],['function qa_schema(', 'CREATE TEMPORARY TABLE'],file_get_contents($argv[2].'/schema.php'));eval(substr($schema,5));qa_schema();
$source=preg_replace('/^require_once .*;$/m','',file_get_contents($argv[2].'/management_worker.php'));
$source=str_replace(['nms_workspace_management_context(','nms_workspace_management_revision(','nms_workspace_management_review(','nms_workspace_management_identity_matches(','nms_nd_network_probe(','nms_nd_collect_identity(','$saved=nms_workspace_management_native_update(',"SELECT hostname FROM host WHERE"],['qa_context(','qa_revision(','qa_review(','qa_identity_matches(','qa_ping(','qa_identity(','$saved=qa_update(',"SELECT hostname FROM qa_management_host WHERE"],$source);eval(substr($source,5));
if(!db_execute("CREATE TEMPORARY TABLE qa_management_host(id INT PRIMARY KEY,hostname VARCHAR(255))")||!db_execute("INSERT INTO qa_management_host VALUES (2,'192.0.2.1')"))throw new RuntimeException('Fixture failed');
$user=(int)db_fetch_cell("SELECT id FROM user_auth WHERE username='admin' AND enabled='on'");$writes=0;$matches=true;
function nms_workspace_admission_rows($sql,$params=[]){return db_fetch_assoc_prepared($sql,$params);}
function qa_context(...$args){return [['target'=>'192.0.2.2'],['id'=>2,'poller_id'=>1,'hostname'=>'192.0.2.1']];}
function qa_revision(...$args){return str_repeat('a',64);}
function qa_review(...$args){return ['admission'=>['blocked'=>false],'host'=>['id'=>2]];}
function qa_identity_matches(...$args){global $matches;return $matches;}
function qa_ping(...$args){return ['reachable'=>true];}
function qa_identity(...$args){return ['uptime'=>123];}
function qa_update($host,$target){global $writes;$writes++;db_execute_prepared('UPDATE qa_management_host SET hostname=? WHERE id=?',[$target,$host['id']]);return 2;}
function queue_change(){global $user;db_execute_prepared("INSERT INTO plugin_nms_management_changes(host_id,proposal_id,user_id,poller_id,old_address,target,config_hash,status,result_json,requested_at) VALUES (2,?, ?,1,'192.0.2.1','192.0.2.2',?,'queued_verify','{}',NOW())",[str_repeat('b',64),$user,str_repeat('a',64)]);return (int)db_fetch_cell('SELECT LAST_INSERT_ID()');}
function state($id){return db_fetch_cell_prepared('SELECT status FROM plugin_nms_management_changes WHERE id=?',[$id]);}
function verify($ok,$msg){if(!$ok)throw new RuntimeException($msg);echo "PASS: $msg\n";}
$before=nms_workspace_management_associations(2);$id=queue_change();nms_workspace_management_poll(1);
verify(state($id)==='verified' && $writes===0,'Verification never updates the polling address');
db_execute_prepared("UPDATE plugin_nms_management_changes SET status='queued_apply' WHERE id=?",[$id]);nms_workspace_management_poll(1);
verify(state($id)==='applied' && $writes===1,'Explicit apply uses existing device ID');
verify($before===nms_workspace_management_associations(2),'Native graph/data-source associations remain unchanged');
$matches=false;$id=queue_change();nms_workspace_management_poll(1);verify(state($id)==='failed' && $writes===1,'Conflicting identity prevents update');
$id=queue_change();db_execute_prepared("UPDATE plugin_nms_management_changes SET status='applying' WHERE id=?",[$id]);nms_workspace_management_poll(1);verify(state($id)==='review_required' && $writes===1,'Interrupted apply is never replayed');
verify((int)db_fetch_cell('SELECT COUNT(*) FROM plugin_nms_workspace_audit')===4,'Verification and terminal results are audited');
echo "6 SQL lifecycle assertions passed; probes/native update simulated.\n";
