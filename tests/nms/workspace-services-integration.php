<?php
/** Native SQL, host ACL/context and transactions; simulated service transport and temporary job tables. */
if(PHP_SAPI!=='cli'||empty($argv[1])||empty($argv[2]))exit('Supply Cacti root and staged workspace');
require $argv[1].'/include/global.php';
require_once $argv[1].'/plugins/nms/includes/functions.php';
require_once $argv[2].'/authorization.php';
require_once $argv[1].'/plugins/nms/includes/workspace/audit.php';
require_once $argv[1].'/plugins/nms/includes/workspace/admission.php';
require_once $argv[1].'/plugins/nms/includes/diagnostics_queue.php';
foreach(['service_checks.php','services.php','service_worker.php'] as $file){$s=preg_replace('/^require_once .*;$/m','',file_get_contents($argv[2].'/'.$file));if($file==='services.php')$s=str_replace('nms_workspace_audit(','qa_audit(',$s);if($file==='service_worker.php')$s=str_replace('nms_workspace_service_probe(','qa_probe(',$s);eval(substr($s,5));}
$s=str_replace(['function nms_workspace_schema(', 'CREATE TABLE IF NOT EXISTS'],['function qa_schema(', 'CREATE TEMPORARY TABLE'],file_get_contents($argv[2].'/schema.php'));eval(substr($s,5));qa_schema();
foreach(["CREATE TEMPORARY TABLE plugin_config(directory VARCHAR(64) PRIMARY KEY,status INT)","INSERT INTO plugin_config VALUES ('nms',1)","CREATE TEMPORARY TABLE plugin_nms_meta(meta_key VARCHAR(191) PRIMARY KEY,meta_value TEXT,updated_at DATETIME)","INSERT INTO plugin_nms_meta VALUES ('diagnostic_runner_1','{\"tools\":{\"ping\":true}}',NOW())"] as $sql)if(!db_execute($sql))throw new RuntimeException('Fixture failed');
$_SESSION['sess_user_id']=(int)db_fetch_cell("SELECT id FROM user_auth WHERE username='admin' AND enabled='on'");
$input=['kind'=>'http','port'=>80,'timeout'=>1];$calls=0;$cancelDuring=false;$failAudit=false;$count=0;
function qa_audit(...$args){global $failAudit;if($failAudit)throw new RuntimeException('Injected audit failure');return nms_workspace_audit(...$args);}
function qa_probe($host,$spec){global $calls,$cancelDuring;$calls++;if($cancelDuring)nms_workspace_service_cancel((int)db_fetch_cell("SELECT id FROM plugin_nms_service_jobs WHERE status='running'"));return ['passed'=>true,'category'=>'criteria_met','target'=>$host['hostname']];}
function check($ok,$message){global $count;if(!$ok)throw new RuntimeException($message);$count++;echo "PASS: $message\n";}
function rejects($fn,$message,$expected=''){try{$fn();}catch(RuntimeException $e){if($expected!==''&&strpos($e->getMessage(),$expected)===false)throw $e;check(true,$message);return;}throw new RuntimeException('Unexpected acceptance: '.$message);}
function status($id){return db_fetch_cell_prepared('SELECT status FROM plugin_nms_service_jobs WHERE id=?',[$id]);}
$failAudit=true;rejects(fn()=>nms_workspace_service_enqueue(2,$input),'Failed audit prevents enqueue','Injected audit failure');$failAudit=false;
check((int)db_fetch_cell('SELECT COUNT(*) FROM plugin_nms_service_jobs')===0,'Failed audit rolls back request');
$id=nms_workspace_service_enqueue(2,$input);rejects(fn()=>nms_workspace_service_enqueue(2,$input),'Pending collector request blocks a second check');
nms_workspace_service_poll(1);check(status($id)==='complete'&&$calls===1,'Collector executes one authorized request');
$id=nms_workspace_service_enqueue(2,$input);nms_workspace_service_cancel($id);nms_workspace_service_poll(1);check(status($id)==='cancelled'&&$calls===1,'Queued cancellation sends no request');
$id=nms_workspace_service_enqueue(2,$input);$cancelDuring=true;nms_workspace_service_poll(1);$cancelDuring=false;
check(status($id)==='cancelled'&&db_fetch_cell_prepared('SELECT result_json FROM plugin_nms_service_jobs WHERE id=?',[$id])==='{}','Cancellation during execution discards result');
$id=nms_workspace_service_enqueue(2,$input);db_execute_prepared('UPDATE plugin_nms_service_jobs SET config_hash=? WHERE id=?',[str_repeat('a',64),$id]);nms_workspace_service_poll(1);check(status($id)==='failed'&&$calls===2,'Changed configuration blocks transport');
$id=nms_workspace_service_enqueue(2,$input);db_execute_prepared("UPDATE plugin_nms_service_jobs SET requested_at=DATE_SUB(NOW(),INTERVAL 3 MINUTE) WHERE id=?",[$id]);nms_workspace_service_poll(1);check(status($id)==='failed'&&$calls===2,'Expired request is not executed');
$id=nms_workspace_service_enqueue(2,$input);db_execute_prepared("UPDATE plugin_nms_service_jobs SET status='running' WHERE id=?",[$id]);nms_workspace_service_poll(1);check(status($id)==='failed'&&$calls===2,'Interrupted check is not replayed');
$id=nms_workspace_service_enqueue(2,$input);db_execute("UPDATE plugin_config SET status=4 WHERE directory='nms'");nms_workspace_service_poll(1);check(status($id)==='failed'&&$calls===2,'Plugin disablement prevents queued execution');db_execute("UPDATE plugin_config SET status=1 WHERE directory='nms'");
$id=nms_workspace_service_enqueue(2,$input);db_execute_prepared('UPDATE plugin_nms_service_jobs SET user_id=999999 WHERE id=?',[$id]);nms_workspace_service_poll(1);check(status($id)==='failed'&&$calls===2,'Missing requester prevents execution');
rejects(fn()=>nms_workspace_service_cancel($id),'Another requester cannot cancel the job');
check(count(nms_workspace_service_history(2))===7,'History excludes other requesters');
echo "$count assertions passed; transport simulated; no persistent tables modified.\n";
