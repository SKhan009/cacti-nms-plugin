<?php
/** Real Cacti SQL/ACL and duplicate inventory; synthetic candidate, temporary job/audit tables. */
if(PHP_SAPI!=='cli'||empty($argv[1])||empty($argv[2]))exit('Supply Cacti root and staged workspace');
require $argv[1].'/include/global.php';
require_once $argv[1].'/plugins/nms/includes/functions.php';
require_once $argv[1].'/plugins/nms/includes/discovery.php';
require_once $argv[1].'/plugins/nms/includes/discovery_identity.php';
require_once $argv[1].'/plugins/nms/includes/workspace/verification.php';
require_once $argv[1].'/plugins/nms/includes/workspace/audit.php';
$schema=str_replace(['function nms_workspace_schema(', 'CREATE TABLE IF NOT EXISTS'],['function qa_schema(', 'CREATE TEMPORARY TABLE'],file_get_contents($argv[2].'/schema.php'));eval(substr($schema,5));qa_schema();
$source=preg_replace('/^\s*require_once .*;$/m','',file_get_contents($argv[2].'/admission.php'));eval(substr($source,5));
$source=preg_replace('/^require_once .*;$/m','',file_get_contents($argv[2].'/onboarding.php'));
$source=str_replace(['nms_workspace_verification_context(','nms_workspace_audit('],['qa_context(','qa_audit('],$source);eval(substr($source,5));
$_SESSION['sess_user_id']=(int)db_fetch_cell("SELECT id FROM user_auth WHERE username='admin' AND enabled='on'");
$host=db_fetch_row('SELECT * FROM host WHERE id=2');
$c=['id'=>str_repeat('a',64),'reporter_id'=>2,'target'=>'192.0.2.200','poller_id'=>(int)$host['poller_id'],'site_id'=>(int)$host['site_id'],'snmp_context'=>$host['snmp_context'],'observed_at'=>date('Y-m-d H:i:s')];
$failAudit=false;
function qa_context(...$args){global $c,$host;nms_require_device_access(2);return [$c,$host];}
function qa_audit(...$args){global $failAudit;if($failAudit)throw new RuntimeException('Injected audit failure');return nms_workspace_audit(...$args);}
function verify($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
function rejects($fn,$message){try{$fn();}catch(Throwable $e){verify(true,$message);return;}throw new RuntimeException('Unexpected acceptance: '.$message);}
$result=['target'=>$c['target'],'collector_id'=>$c['poller_id'],'identity_usable'=>true,'identity'=>['uptime'=>10]];
nms_category_execute("INSERT INTO plugin_nms_candidate_checks(reporter_id,candidate_id,poller_id,user_id,target,config_hash,status,result_json,requested_at,finished_at) VALUES (2,?,?,?,?,?,'complete',?,NOW(),NOW())",[$c['id'],$c['poller_id'],nms_current_user_id(),$c['target'],nms_workspace_verification_hash($c,$host),json_encode($result)]);
$id=(int)db_fetch_cell('SELECT LAST_INSERT_ID()');$template=(int)$host['host_template_id'];
$plan=nms_workspace_onboarding_plan($id,$template,'QA temporary review');
verify(!$plan['admission']['blocked'] && strlen($plan['revision'])===64,'Native template and inventory produce a review revision');
verify(!isset($plan['snmp_community']) && !isset($plan['snmp_password']),'Review includes no credential fields');
$input=['check_id'=>$id,'template_id'=>$template,'description'=>$plan['description'],'revision'=>$plan['revision']];
$failAudit=true;rejects(fn()=>nms_workspace_onboarding_enqueue($input),'Audit failure rejects enqueue');$failAudit=false;
verify((int)db_fetch_cell('SELECT COUNT(*) FROM plugin_nms_onboarding_requests')===0,'Audit failure rolls back pending addition');
$request=nms_workspace_onboarding_enqueue($input);
verify((int)$request>0 && (int)db_fetch_cell('SELECT COUNT(*) FROM plugin_nms_workspace_audit')===1,'Explicit review queues one audited addition');
rejects(fn()=>nms_workspace_onboarding_enqueue($input),'Duplicate pending addition is rejected');
nms_workspace_onboarding_cancel($request);
verify(db_fetch_cell('SELECT status FROM plugin_nms_onboarding_requests')==='cancelled','Queued cancellation persists through native permissions');
$input['description']='Changed name';rejects(fn()=>nms_workspace_onboarding_enqueue($input),'Changed review fields cannot reuse confirmation');
echo "8 assertions passed; no native device created.\n";
