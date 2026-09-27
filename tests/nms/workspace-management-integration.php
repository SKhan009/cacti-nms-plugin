<?php
/** Native SQL/ACL/inventory with synthetic own-address evidence and temporary request/audit tables. No network or core writes. */
if(PHP_SAPI!=='cli'||empty($argv[1])||empty($argv[2]))exit('Supply Cacti root and staged workspace');
require $argv[1].'/include/global.php';
require_once $argv[1].'/plugins/nms/includes/functions.php';
require_once $argv[2].'/authorization.php';
require_once $argv[1].'/plugins/nms/includes/discovery.php';
require_once $argv[1].'/plugins/nms/includes/discovery_identity.php';
require_once $argv[1].'/plugins/nms/includes/workspace/candidates.php';
require_once $argv[1].'/plugins/nms/includes/workspace/audit.php';
require_once $argv[2].'/snmp_settings.php';
$schema=str_replace(['function nms_workspace_schema(', 'CREATE TABLE IF NOT EXISTS'],['function qa_schema(', 'CREATE TEMPORARY TABLE'],file_get_contents($argv[2].'/schema.php'));eval(substr($schema,5));qa_schema();
$source=preg_replace('/^\s*require_once .*;$/m','',file_get_contents($argv[2].'/admission.php'));eval(substr($source,5));
$source=preg_replace('/^require_once .*;$/m','',file_get_contents($argv[2].'/management_ip.php'));
$source=str_replace(['nms_topology_discovery(null)', 'nms_workspace_audit('],['qa_discovery()', 'qa_audit('],$source);eval(substr($source,5));
$_SESSION['sess_user_id']=(int)db_fetch_cell("SELECT id FROM user_auth WHERE username='admin' AND enabled='on'");
$owner=nms_current_user_id();$host=db_fetch_row('SELECT * FROM host WHERE id=2');$host['nms_snmp_retries']=1;
$identity=['own_addresses'=>[['address'=>'192.0.2.200','type'=>1,'status'=>1,'zone'=>0,'ifindex'=>2]],'hardware'=>['chassis'=>[['serial'=>'QA-MANAGEMENT-UNIQUE','model'=>'QA']]]];
$snapshot=['host_id'=>2,'protocol'=>'identity','valid'=>true,'status'=>'success','succeeded_at'=>date('Y-m-d H:i:s'),'data'=>$identity];
$proposal=nms_workspace_management_proposals($host,$snapshot)[0];$failAudit=false;$assertions=0;
function qa_discovery(){global $host,$snapshot;return ['hosts'=>[2=>$host],'snapshots'=>['2|identity'=>$snapshot]];}
function qa_audit(...$args){global $failAudit;if($failAudit)throw new RuntimeException('Injected audit failure');return nms_workspace_audit(...$args);}
function verify($ok,$message){global $assertions;if(!$ok)throw new RuntimeException($message);$assertions++;echo "PASS: $message\n";}
function rejects($fn,$message,$expected=''){try{$fn();}catch(Throwable $e){if($expected!=='' && strpos($e->getMessage(),$expected)===false)throw $e;verify(true,$message);return;}throw new RuntimeException('Unexpected acceptance: '.$message);}
function markVerified($id){global $identity,$proposal;nms_category_execute("UPDATE plugin_nms_management_changes SET status='verified',verified_at=NOW(),result_json=? WHERE id=?",[json_encode(['identity'=>$identity,'target'=>$proposal['target'],'collector_id'=>$proposal['poller_id']]),$id]);}
$failAudit=true;rejects(fn()=>nms_workspace_management_verify(2,$proposal['id']),'Failed audit rejects verification queue','Injected audit failure');$failAudit=false;
verify((int)db_fetch_cell('SELECT COUNT(*) FROM plugin_nms_management_changes')===0,'Audit failure rolls back request');
$id=nms_workspace_management_verify(2,$proposal['id']);
verify(db_fetch_cell_prepared('SELECT status FROM plugin_nms_management_changes WHERE id=?',[$id])==='queued_verify','Verification queues without changing core');
rejects(fn()=>nms_workspace_management_verify(2,$proposal['id']),'Outstanding verification blocks a second request');
rejects(fn()=>nms_workspace_management_review($id),'Unverified request cannot be reviewed for apply');
nms_workspace_management_cancel($id);
verify(db_fetch_cell_prepared('SELECT status FROM plugin_nms_management_changes WHERE id=?',[$id])==='cancelled','Queued verification can be cancelled');
$id=nms_workspace_management_verify(2,$proposal['id']);markVerified($id);
$review=nms_workspace_management_review($id);
verify(!$review['admission']['blocked'] && strlen($review['revision'])===64,'Native inventory admission excludes current device and produces review');
rejects(fn()=>nms_workspace_management_apply($id,str_repeat('0',64)),'Changed review cannot authorize apply');
$host['nms_snmp_retries']=2;rejects(fn()=>nms_workspace_management_review($id),'Changed effective retries invalidate verification');$host['nms_snmp_retries']=1;
nms_category_execute('UPDATE plugin_nms_management_changes SET result_json=? WHERE id=?',[json_encode(['identity'=>$identity,'target'=>'192.0.2.201','collector_id'=>$proposal['poller_id']]),$id]);
rejects(fn()=>nms_workspace_management_review($id),'Result for a different target cannot authorize apply');
markVerified($id);
nms_category_execute('UPDATE plugin_nms_management_changes SET result_json=? WHERE id=?',[json_encode(['identity'=>$identity,'target'=>$proposal['target'],'collector_id'=>999]),$id]);
rejects(fn()=>nms_workspace_management_review($id),'Result from a different collector cannot authorize apply');
markVerified($id);
$failAudit=true;rejects(fn()=>nms_workspace_management_apply($id,$review['revision']),'Failed audit rejects apply queue','Injected audit failure');$failAudit=false;
verify(db_fetch_cell_prepared('SELECT status FROM plugin_nms_management_changes WHERE id=?',[$id])==='verified','Failed apply audit retains unapplied verification');
nms_workspace_management_apply($id,$review['revision']);
verify(db_fetch_cell_prepared('SELECT status FROM plugin_nms_management_changes WHERE id=?',[$id])==='queued_apply','Explicit review queues collector apply');
nms_workspace_management_cancel($id);
verify(db_fetch_cell_prepared('SELECT status FROM plugin_nms_management_changes WHERE id=?',[$id])==='cancelled','Queued apply can be cancelled before native update');
$id=nms_workspace_management_verify(2,$proposal['id']);markVerified($id);
nms_category_execute('UPDATE plugin_nms_management_changes SET verified_at=DATE_SUB(NOW(),INTERVAL 16 MINUTE) WHERE id=?',[$id]);
rejects(fn()=>nms_workspace_management_review($id),'Expired verification requires a new check');
markVerified($id);$host['notes'].=' changed';
rejects(fn()=>nms_workspace_management_review($id),'Changed native settings invalidate verified review');
$host=db_fetch_row('SELECT * FROM host WHERE id=2');$host['nms_snmp_retries']=1;
verify(count(nms_workspace_management_history(2))===3,'History includes only this account and selected accessible device');
nms_category_execute('UPDATE plugin_nms_management_changes SET user_id=? WHERE id=?',[$owner+100000,$id]);
rejects(fn()=>nms_workspace_management_review($id),'Another requester cannot reuse a verification');
rejects(fn()=>nms_workspace_management_cancel($id),'Another requester cannot cancel a request');
verify(count(nms_workspace_management_history(2))===2,'History excludes another requester');
verify(db_fetch_cell('SELECT hostname FROM host WHERE id=2')===$host['hostname'],'Review/queue/cancel preserve original polling address');
echo "$assertions assertions passed; no network requests or native updates.\n";
