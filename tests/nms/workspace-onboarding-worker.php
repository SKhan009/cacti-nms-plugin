<?php
/** Real SQL state transitions; simulated core API, session-local host and request tables. */
if(PHP_SAPI!=='cli'||empty($argv[1])||empty($argv[2]))exit('Supply Cacti root and staged workspace');
require $argv[1].'/include/global.php';
require_once $argv[1].'/plugins/nms/includes/functions.php';
require_once $argv[1].'/plugins/nms/includes/workspace/audit.php';
$schema=str_replace(['function nms_workspace_schema(', 'CREATE TABLE IF NOT EXISTS'],['function qa_schema(', 'CREATE TEMPORARY TABLE'],file_get_contents($argv[2].'/schema.php'));eval(substr($schema,5));qa_schema();
if(!db_execute('CREATE TEMPORARY TABLE qa_onboarding_hosts(id INT AUTO_INCREMENT PRIMARY KEY,hostname VARCHAR(45),poller_id INT,site_id INT,host_template_id INT,external_id VARCHAR(100),deleted VARCHAR(2) DEFAULT \'\')'))throw new RuntimeException('Fixture failed');
$worker=preg_replace('/^require_once .*;$/m','',file_get_contents($argv[2].'/onboarding_worker.php'));
$worker=str_replace(['nms_workspace_onboarding_plan($job','nms_workspace_verification_context((int)$job', '$host_id=nms_workspace_onboarding_native_create(', 'FROM host WHERE'],['qa_plan($job','qa_context((int)$job','$host_id=qa_create(','FROM qa_onboarding_hosts WHERE'],$worker);eval(substr($worker,5));
function nms_workspace_admission_rows($sql,$params=[]){$r=db_execute_prepared($sql,$params,true,false,'Row',false,'db_fetch_assoc_return');if(!is_array($r))throw new RuntimeException('Read failed');return $r;}
$blocked=false;$crash=false;$calls=0;$user=(int)db_fetch_cell("SELECT id FROM user_auth WHERE username='admin' AND enabled='on'");
function qa_plan(...$args){global $blocked;return ['poller_id'=>1,'revision'=>str_repeat('a',64),'candidate_id'=>'candidate','admission'=>['blocked'=>$blocked]];}
function qa_context(...$args){return [[],[]];}
function qa_create($job,$host){global $calls,$crash;$calls++;db_execute_prepared('INSERT INTO qa_onboarding_hosts(hostname,poller_id,site_id,host_template_id,external_id) VALUES (?,?,?,?,?)',[$job['target'],1,1,1,nms_workspace_onboarding_marker($job['id'])]);$id=(int)db_fetch_cell('SELECT LAST_INSERT_ID()');if($crash)throw new RuntimeException('Simulated core failure after host save');return $id;}
function queue_job(){global $user;db_execute_prepared("INSERT INTO plugin_nms_onboarding_requests(check_id,reporter_id,user_id,poller_id,site_id,target,template_id,description,review_hash,status,requested_at) VALUES (1,2,?,1,1,'192.0.2.20',1,'QA',?,'queued',NOW())",[$user,str_repeat('a',64)]);return (int)db_fetch_cell('SELECT LAST_INSERT_ID()');}
function row($id){return db_fetch_row_prepared('SELECT * FROM plugin_nms_onboarding_requests WHERE id=?',[$id]);}
function verify($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
$id=queue_job();nms_workspace_onboarding_poll(1);verify(row($id)['status']==='created' && (int)row($id)['host_id']>0,'Successful native result is associated with its request');
$crash=true;$id=queue_job();nms_workspace_onboarding_poll(1);verify(row($id)['status']==='review_required' && (int)row($id)['host_id']>0,'Failure after native save preserves created ID for review');
$before=$calls;nms_workspace_onboarding_poll(1);verify($calls===$before,'Review-required request is never automatically replayed');
$crash=false;$id=queue_job();db_execute_prepared("UPDATE plugin_nms_onboarding_requests SET status='applying' WHERE id=?",[$id]);nms_workspace_onboarding_poll(1);verify(row($id)['status']==='review_required' && $calls===$before,'Interrupted applying state never repeats native creation');
$blocked=true;$id=queue_job();nms_workspace_onboarding_poll(1);verify(row($id)['status']==='failed' && $calls===$before,'New duplicate evidence prevents API invocation');
$blocked=false;$id=queue_job();db_execute_prepared('UPDATE plugin_nms_onboarding_requests SET user_id=4294967294 WHERE id=?',[$id]);nms_workspace_onboarding_poll(1);
verify(row($id)['status']==='failed' && $calls===$before,'Missing requester prevents native API execution');
$id=queue_job();db_execute_prepared('UPDATE plugin_nms_onboarding_requests SET requested_at=DATE_SUB(NOW(),INTERVAL 1 HOUR) WHERE id=?',[$id]);nms_workspace_onboarding_poll(1);
verify(row($id)['status']==='failed' && $calls===$before,'Expired review cannot create a device');
verify((int)db_fetch_cell('SELECT COUNT(*) FROM plugin_nms_workspace_audit')===6,'Terminal states are audited once');
echo "8 SQL lifecycle assertions passed; native API simulated.\n";
