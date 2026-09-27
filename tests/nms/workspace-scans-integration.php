<?php
/** Real SQL transactions with session-local temporary tables and simulated probes. No packets. */
if(PHP_SAPI!=='cli' || empty($argv[1]) || empty($argv[2]))exit('Supply Cacti root and NMS includes directory');
require $argv[1].'/include/global.php';
require_once $argv[1].'/plugins/nms/includes/functions.php';
require_once $argv[2].'/workspace/scans.php';
$schema=file_get_contents($argv[2].'/workspace/schema.php');
$schema=str_replace(['function nms_workspace_schema(', 'CREATE TABLE IF NOT EXISTS'],['function qa_workspace_schema(', 'CREATE TEMPORARY TABLE'],$schema);
eval(substr($schema,5));
qa_workspace_schema();
// Shadow plugin/collector settings only in this connection; live polling remains untouched.
foreach(['plugin_config','poller'] as $table) {
    $rows=db_fetch_assoc("SELECT * FROM $table");$ddl=array_values(db_fetch_row('SHOW CREATE TABLE '.$table))[1];
    if(!db_execute(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl)))throw new RuntimeException('Could not isolate plugin/collector settings');
    foreach($rows as $row)nms_category_execute('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')',array_values($row));
}
// Use an explicitly cleaned native fixture. A self-referencing temporary LIKE failed on QA MariaDB.
$network=0;
$checks=0;
function verify($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);$checks++;echo "PASS: $message\n";}
function runrow($id){return db_fetch_row_prepared('SELECT * FROM plugin_nms_scan_runs WHERE id=?',[$id]);}
$_SESSION['sess_user_id']=(int)db_fetch_cell("SELECT id FROM user_auth WHERE username='admin' AND enabled='on'");
if(!$_SESSION['sess_user_id'])throw new RuntimeException('An enabled QA admin account is required');
$poller=(int)db_fetch_cell("SELECT id FROM poller WHERE disabled='' ORDER BY id LIMIT 1");
$snmp=(int)db_fetch_cell('SELECT id FROM automation_snmp ORDER BY id LIMIT 1');
try {
$network=nms_workspace_network_save(['network_id'=>0,'name'=>'Session-only scan fixture','subnet_range'=>'192.0.2.0/29','poller_id'=>$poller,'site_id'=>0,'snmp_id'=>$snmp,'sched_type'=>1,'start_at'=>date('Y-m-d H:i:s'),'recur_every'=>1,'threads'=>1,'run_limit'=>60,'enabled'=>'on']);
$input=['network_id'=>$network,'targets'=>'192.0.2.1,2001:db8::1','methods'=>['icmp','tcp'],'ports'=>'80'];
$calls=[];
$probe=function($task)use(&$calls){$calls[]=$task;return $task+['reachable'=>true,'detail'=>'Simulated response; no packets sent'];};
$id=nms_scan_enqueue($input);
nms_scan_supplemental_step(runrow($id),$poller,microtime(true)+10,$probe,2);
verify(runrow($id)['status']==='running' && (int)runrow($id)['progress_cursor']===2,'Bounded slice persists cursor while leaving the run resumable');
nms_scan_supplemental_step(runrow($id),$poller,microtime(true)+10,$probe,2);
verify(runrow($id)['status']==='complete' && count($calls)===4 && $calls[2]['ip']==='2001:db8::1','Resume continues at the next target without replaying completed probes');
verify((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_scan_results WHERE run_id=?',[$id])===4,'Each checkpoint retains exactly one result');
nms_scan_finish(runrow($id),'failed','Replay');
verify(runrow($id)['status']==='complete' && (int)db_fetch_cell("SELECT COUNT(*) FROM plugin_nms_workspace_audit WHERE action='scan_complete'")===1,'Terminal replay cannot replace the result or duplicate its audit event');
$rejected=false;try{nms_scan_checkpoint(runrow($id),4,$probe(['ip'=>'192.0.2.2','method'=>'icmp','port'=>null]));}catch(RuntimeException $e){$rejected=true;}
verify($rejected && (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_scan_results WHERE run_id=?',[$id])===4,'Terminal runs reject checkpoints without storing an orphan result');
$id=nms_scan_enqueue($input);$before=count($calls);nms_scan_cancel($id);
nms_scan_supplemental_step(runrow($id),$poller,microtime(true)+10,$probe);
verify(runrow($id)['status']==='cancelled' && count($calls)===$before,'Queued cancellation sends no probes');
$id=nms_scan_enqueue($input);
db_execute_prepared('UPDATE automation_networks SET name=? WHERE id=?',['Changed after admission',$network]);
nms_scan_supplemental_step(runrow($id),$poller,microtime(true)+10,$probe);
verify(runrow($id)['status']==='failed' && count($calls)===$before,'Changed configuration fails closed before the first probe');
$id=nms_scan_enqueue($input);
nms_scan_supplemental_step(runrow($id),$poller+100000,microtime(true)+10,$probe);
verify(runrow($id)['status']==='failed' && count($calls)===$before,'Wrong collector cannot execute queued work');
$id=nms_scan_enqueue($input);
db_execute_prepared('UPDATE plugin_nms_scan_runs SET requested_at=DATE_SUB(NOW(),INTERVAL 2 DAY) WHERE id=?',[$id]);
nms_scan_supplemental_step(runrow($id),$poller,microtime(true)+10,$probe);
verify(runrow($id)['status']==='failed' && count($calls)===$before,'Expired jobs stop without sending probes');
$id=nms_scan_enqueue($input);
db_execute_prepared('UPDATE plugin_nms_scan_runs SET user_id=0 WHERE id=?',[$id]);
nms_scan_supplemental_step(runrow($id),$poller,microtime(true)+10,$probe);
verify(runrow($id)['status']==='failed' && count($calls)===$before,'Missing authorized owner prevents execution');
$id=nms_scan_enqueue($input);
nms_scan_supplemental_step(runrow($id),$poller,microtime(true)+10,$probe,1);
$rejected=false;try{nms_scan_checkpoint(runrow($id),2,['ip'=>'192.0.2.2','method'=>'icmp','port'=>null,'reachable'=>true,'detail'=>'Out of order']);}catch(RuntimeException $e){$rejected=true;}
verify($rejected && (int)runrow($id)['progress_cursor']===1 && (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_scan_results WHERE run_id=?',[$id])===1,'Out-of-order checkpoint rolls back without advancing progress');
$before=count($calls);nms_scan_cancel($id);
nms_scan_supplemental_step(runrow($id),$poller,microtime(true)+10,$probe);
verify(runrow($id)['status']==='cancelled' && count($calls)===$before && (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_scan_results WHERE run_id=?',[$id])===1,'Cancellation between slices preserves partial results and prevents further probes');
$id=nms_scan_enqueue($input);
$failing=function(){throw new RuntimeException('Simulated transport timeout');};
nms_scan_supplemental_step(runrow($id),$poller,microtime(true)+10,$failing);
verify(runrow($id)['status']==='complete' && (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_scan_results WHERE run_id=? AND reachable=0 AND detail=?',[$id,'Simulated transport timeout'])===4,'Individual transport failures remain explicit results while the scan completes');
// Mutations occur inside simulated transport to exercise the post-I/O boundary.
$one=['network_id'=>$network,'targets'=>'192.0.2.1','methods'=>['icmp'],'ports'=>'80'];
$id=nms_scan_enqueue($one);
$changing=function($task)use($network){db_execute_prepared('UPDATE automation_networks SET threads=2 WHERE id=?',[$network]);return $task+['reachable'=>true,'detail'=>'Must not publish'];};
nms_scan_supplemental_step(runrow($id),$poller,microtime(true)+10,$changing);
verify(runrow($id)['status']==='failed' && (int)runrow($id)['progress_cursor']===0 && !(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_scan_results WHERE run_id=?',[$id]),'Configuration changed during final probe rejects result and completion');
$id=nms_scan_enqueue($one);
$cancelling=function($task)use($id){nms_scan_cancel($id);return $task+['reachable'=>true,'detail'=>'Must not publish'];};
nms_scan_supplemental_step(runrow($id),$poller,microtime(true)+10,$cancelling);
verify(runrow($id)['status']==='cancelled' && (int)runrow($id)['progress_cursor']===0 && !(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_scan_results WHERE run_id=?',[$id]),'Cancellation during final probe discards result and remains cancelled');
$id=nms_scan_enqueue($one);
db_execute_prepared("UPDATE plugin_nms_scan_runs SET status='running',cancel_requested=1 WHERE id=?",[$id]);
$saved=nms_scan_checkpoint(runrow($id),0,['ip'=>'192.0.2.1','method'=>'icmp','port'=>null,'reachable'=>true,'detail'=>'Must not publish']);
verify($saved===false && !(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_scan_results WHERE run_id=?',[$id]),'Locked checkpoint rejects a cancellation arriving after the post-probe check');
nms_scan_finish(runrow($id),'cancelled');
$id=nms_scan_enqueue($one);$sent=0;
nms_category_execute("UPDATE plugin_config SET status=4 WHERE directory='nms'");
nms_scan_supplemental_step(runrow($id),$poller,microtime(true)+10,function($task)use(&$sent){$sent++;return $task+['reachable'=>true,'detail'=>'Unexpected'];});
verify($sent===0 && runrow($id)['status']==='failed','Plugin disabled before execution sends no probes');
nms_category_execute("UPDATE plugin_config SET status=1 WHERE directory='nms'");
$id=nms_scan_enqueue($one);
$disablePlugin=function($task){nms_category_execute("UPDATE plugin_config SET status=4 WHERE directory='nms'");return $task+['reachable'=>true,'detail'=>'Must not publish'];};
nms_scan_supplemental_step(runrow($id),$poller,microtime(true)+10,$disablePlugin);
verify(runrow($id)['status']==='failed' && !(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_scan_results WHERE run_id=?',[$id]),'Plugin disabled during final probe rejects publication');
nms_category_execute("UPDATE plugin_config SET status=1 WHERE directory='nms'");
$id=nms_scan_enqueue($one);
$disableCollector=function($task)use($poller){nms_category_execute("UPDATE poller SET disabled='on' WHERE id=?",[$poller]);return $task+['reachable'=>true,'detail'=>'Must not publish'];};
nms_scan_supplemental_step(runrow($id),$poller,microtime(true)+10,$disableCollector);
verify(runrow($id)['status']==='failed' && !(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_scan_results WHERE run_id=?',[$id]),'Collector disabled during final probe rejects publication');
nms_category_execute("UPDATE poller SET disabled='' WHERE id=?",[$poller]);
require_once $argv[2].'/workspace/scan_worker.php';
$id=nms_scan_enqueue($one);nms_category_execute("UPDATE plugin_nms_scan_runs SET status='running' WHERE id=?",[$id]);
$finalLock='nms_scan_run_'.$id;$connection=(int)db_fetch_cell('SELECT CONNECTION_ID()');
if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,0)',[$finalLock])!==1)throw new RuntimeException('Final-check lock fixture failed');
try {
 verify(nms_scan_native_final_check(runrow($id),$poller,$finalLock,$connection)==='','Native completion accepts current authorized context and owned lock');
 nms_scan_cancel($id);
 verify(nms_scan_native_final_check(runrow($id),$poller,$finalLock,$connection)==='Cancelled by user.','Native completion observes cancellation after process exit');
 nms_category_execute('UPDATE plugin_nms_scan_runs SET cancel_requested=0 WHERE id=?',[$id]);
 nms_category_execute('UPDATE automation_networks SET threads=3 WHERE id=?',[$network]);
 verify(strpos(nms_scan_native_final_check(runrow($id),$poller,$finalLock,$connection),'settings changed')!==false,'Native completion rejects configuration changed since last periodic check');
} finally {db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$finalLock]);}
verify(strpos(nms_scan_native_final_check(runrow($id),$poller,$finalLock,$connection),'lost its database lock')!==false,'Native completion rejects lost ownership before copying results');
nms_scan_finish(runrow($id),'failed','Native final-check fixture complete');
echo "$checks assertions passed (real SQL, temporary tables, simulated probes).\n";

} finally {if($network)db_execute_prepared('DELETE FROM automation_networks WHERE id=? AND name IN (?,?)',[$network,'Session-only scan fixture','Changed after admission']);}
