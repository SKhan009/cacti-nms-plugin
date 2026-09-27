<?php
/** Native transactions/ACLs with temporary job tables and controlled reviewed-plan evidence. */
if(PHP_SAPI!=='cli'||empty($argv[1])||empty($argv[2]))exit('Supply Cacti root and staged workspace');
require $argv[1].'/include/global.php';require_once $argv[1].'/plugins/nms/includes/functions.php';require_once $argv[1].'/plugins/nms/includes/workspace/admission.php';require_once $argv[1].'/plugins/nms/includes/workspace/authorization.php';require_once $argv[1].'/plugins/nms/includes/workspace/audit.php';require_once $argv[1].'/plugins/nms/includes/diagnostics_queue.php';
require_once $argv[1].'/plugins/nms/includes/workspace/networks.php';
$s=str_replace(['function nms_workspace_schema(', 'CREATE TABLE IF NOT EXISTS'],['function qa_schema(', 'CREATE TEMPORARY TABLE'],file_get_contents($argv[2].'/schema.php'));eval(substr($s,5));qa_schema();
$s=preg_replace('/^require_once .*;$/m','',file_get_contents($argv[2].'/consolidation_jobs.php'));$s=str_replace(['nms_workspace_consolidation_plan(','nms_workspace_audit('],['qa_plan(','qa_audit('],$s);eval(substr($s,5));
foreach(["CREATE TEMPORARY TABLE plugin_config(directory VARCHAR(64) PRIMARY KEY,status INT)","INSERT INTO plugin_config VALUES ('nms',1)","CREATE TEMPORARY TABLE plugin_nms_meta(meta_key VARCHAR(191) PRIMARY KEY,meta_value TEXT,updated_at DATETIME)","INSERT INTO plugin_nms_meta VALUES ('diagnostic_runner_1','{}',NOW())"] as $sql)if(!db_execute($sql))throw new RuntimeException('Fixture failed');
$_SESSION=['sess_user_id'=>(int)db_fetch_cell("SELECT id FROM user_auth WHERE username='admin' AND enabled='on'")];
$ids=array_column(db_fetch_assoc("SELECT id FROM host WHERE deleted='' AND poller_id=1 ORDER BY id LIMIT 2"),'id');if(count($ids)!==2)throw new RuntimeException('Two local visible hosts required');
$keep=(int)$ids[0];$other=(int)$ids[1];$count=0;$auditFails=false;$blocked=false;
function check($ok,$message){global $count;if(!$ok)throw new RuntimeException($message);$count++;echo "PASS: $message\n";}
function rejects($fn,$message){try{$fn();}catch(RuntimeException $e){check(true,$message);return;}throw new RuntimeException('Unexpected acceptance: '.$message);}
function qa_plan($keep,$other){global $blocked;nms_workspace_authorize_current($keep);nms_workspace_authorize_current($other);return ['revision'=>str_repeat('a',64),'transfer_preflight'=>['status'=>$blocked?'blocked':'structurally_eligible'],'permission_preflight'=>['status'=>'native_inputs_equivalent'],'devices'=>[['host'=>['poller_id'=>1]]]];}
function qa_audit(...$args){global $auditFails;if($auditFails)throw new RuntimeException('Injected audit failure');return nms_workspace_audit(...$args);}
$input=['keep_id'=>$keep,'other_id'=>$other,'revision'=>str_repeat('a',64),'confirm_transfer'=>'yes','confirm_references'=>'yes'];
rejects(fn()=>nms_workspace_consolidation_enqueue(array_replace($input,['confirm_transfer'=>''])),'Explicit confirmation required');
rejects(fn()=>nms_workspace_consolidation_enqueue(array_replace($input,['confirm_references'=>''])),'Retained-reference review confirmation required');
rejects(fn()=>nms_workspace_consolidation_enqueue(array_replace($input,['revision'=>str_repeat('b',64)])),'Stale review cannot queue');
$blocked=true;rejects(fn()=>nms_workspace_consolidation_enqueue($input),'Structural blocker prevents queue');$blocked=false;
$auditFails=true;rejects(fn()=>nms_workspace_consolidation_enqueue($input),'Audit failure aborts queue');$auditFails=false;
check((int)db_fetch_cell('SELECT COUNT(*) FROM plugin_nms_consolidation_jobs')===0,'Failed audit leaves no request');
$id=nms_workspace_consolidation_enqueue($input);
check($id>0&&(int)db_fetch_cell('SELECT COUNT(*) FROM plugin_nms_workspace_audit')===2,'Request and both-device audit committed together');
rejects(fn()=>nms_workspace_consolidation_enqueue($input),'Overlapping queued transfer blocked');
$auditFails=true;rejects(fn()=>nms_workspace_consolidation_cancel($id),'Audit failure aborts cancellation');$auditFails=false;
check(db_fetch_cell_prepared('SELECT status FROM plugin_nms_consolidation_jobs WHERE id=?',[$id])==='queued','Failed cancellation remains queued');
nms_workspace_consolidation_cancel($id);check(db_fetch_cell_prepared('SELECT status FROM plugin_nms_consolidation_jobs WHERE id=?',[$id])==='cancelled','Queued request cancels');
$id=nms_workspace_consolidation_enqueue($input);nms_category_execute("UPDATE plugin_nms_consolidation_jobs SET status='applying' WHERE id=?",[$id]);
rejects(fn()=>nms_workspace_consolidation_cancel($id),'Started native transfer cannot cancel blindly');
nms_category_execute("UPDATE plugin_nms_consolidation_jobs SET status='review_required' WHERE id=?",[$id]);
rejects(fn()=>nms_workspace_consolidation_enqueue($input),'Potential partial transfer blocks repeat until recovery');
check(count(nms_workspace_consolidation_job_history($keep))===2,'Requester can view both terminal and recovery history');
nms_category_execute('UPDATE plugin_nms_consolidation_jobs SET user_id=999999 WHERE id=?',[$id]);
rejects(fn()=>nms_workspace_consolidation_cancel($id),'Different requester cannot cancel transfer');
check(count(nms_workspace_consolidation_job_history($keep))===1,'History excludes other requesters');
check((int)db_fetch_cell_prepared('SELECT IS_FREE_LOCK(?)',['nms_consolidation_submit'])===1,'Submission lock released on all paths');
rejects(fn()=>nms_workspace_consolidation_recheck($id),'Different requester cannot request recovery');
nms_category_execute('UPDATE plugin_nms_consolidation_jobs SET user_id=? WHERE id=?',[nms_current_user_id(),$id]);
$auditFails=true;rejects(fn()=>nms_workspace_consolidation_recheck($id),'Recovery audit failure rolls back request');$auditFails=false;
check(db_fetch_cell_prepared('SELECT status FROM plugin_nms_consolidation_jobs WHERE id=?',[$id])==='review_required','Failed recovery request preserves blocked status');
nms_workspace_consolidation_recheck($id);
check(db_fetch_cell_prepared('SELECT status FROM plugin_nms_consolidation_jobs WHERE id=?',[$id])==='queued_recovery','Explicit recovery queues read-only verification');
rejects(fn()=>nms_workspace_consolidation_enqueue($input),'Queued recovery prevents overlapping transfer');
rejects(fn()=>nms_workspace_consolidation_recheck($id),'Recovery cannot be queued twice');
echo "$count queue assertions passed; plan evidence controlled, native inventory untouched.\n";
