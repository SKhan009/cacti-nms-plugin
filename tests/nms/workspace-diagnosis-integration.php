<?php
/** Real admission/SQL, session-local storage. No diagnostic reaches the live worker. */
if(PHP_SAPI!=='cli' || empty($argv[1]) || empty($argv[2]))exit('Supply Cacti root and staged diagnosis file');
require $argv[1].'/include/global.php';
require_once $argv[1].'/plugins/nms/includes/functions.php';
require_once $argv[1].'/plugins/nms/includes/diagnostics_queue.php';
require_once $argv[1].'/plugins/nms/includes/workspace/audit.php';
foreach(['plugin_nms_diagnostic_jobs','plugin_nms_workspace_audit'] as $table){
    $ddl=db_fetch_row('SHOW CREATE TABLE '.$table);
    $sql=preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl['Create Table']);
    if(!db_execute($sql))throw new RuntimeException('Could not isolate '.$table);
}
$source=file_get_contents($argv[2]);
$source=preg_replace('/^require_once .*;$/m','',$source);
$source=str_replace('nms_workspace_audit(', 'qa_diagnosis_audit(', $source);
eval(substr($source,5));
$failAudit=false;
function qa_diagnosis_audit(...$args){global $failAudit;if($failAudit)throw new RuntimeException('Injected audit failure');return nms_workspace_audit(...$args);}
function verify($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
function rejects($fn,$message){try{$fn();}catch(Throwable $e){verify(true,$message);return;}throw new RuntimeException('Unexpected acceptance: '.$message);}
$_SESSION['sess_user_id']=(int)db_fetch_cell("SELECT id FROM user_auth WHERE username='admin' AND enabled='on'");
$before=db_fetch_assoc('SELECT id,host_id,graph_template_id FROM graph_local ORDER BY id');
$failAudit=true;
rejects(fn()=>nms_workspace_diagnose_device(2),'Audit failure rejects admission');
verify((int)db_fetch_cell('SELECT COUNT(*) FROM plugin_nms_diagnostic_jobs')===0,'Audit failure rolls back queued diagnostic');
$failAudit=false;
$id=nms_workspace_diagnose_device(2);
verify((int)$id>0 && db_fetch_cell('SELECT tool FROM plugin_nms_diagnostic_jobs')==='ping','Combined action uses permitted ping admission');
verify((int)db_fetch_cell('SELECT COUNT(*) FROM plugin_nms_workspace_audit')===1,'Diagnosis request recorded in audit');
rejects(fn()=>nms_workspace_diagnose_device(2),'Pending collector request prevents duplicate submission');
verify((int)db_fetch_cell('SELECT COUNT(*) FROM plugin_nms_workspace_audit')===1,'Rejected duplicate adds no audit entry');
verify($before===db_fetch_assoc('SELECT id,host_id,graph_template_id FROM graph_local ORDER BY id'),'Native graphs remain unchanged');
echo "7 assertions passed; real SQL with isolated job and audit storage.\n";
