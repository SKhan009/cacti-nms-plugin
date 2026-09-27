<?php
/** QA-only live collector acceptance. Explicit modes; loopback only, native auto-add disabled. */
if(PHP_SAPI!=='cli' || empty($argv[1]) || empty($argv[2]))exit('Supply Cacti root and prepare/check/cleanup');
require $argv[1].'/include/global.php';
require_once $argv[1].'/plugins/nms/includes/database.php';
require_once $argv[1].'/plugins/nms/includes/workspace/scans.php';
$_SESSION['sess_user_id']=(int)db_fetch_cell("SELECT id FROM user_auth WHERE username='admin' AND enabled='on'");
$stateFile='/tmp/nms-workspace-loopback-state.json';
$mode=$argv[2];
if(in_array($mode,['prepare','prepare_browser'],true)) {
 if(is_file($stateFile))throw new RuntimeException('Existing acceptance state requires inspection; refusing another run');
 $poller=(int)$config['poller_id'];
 $snmp=(int)db_fetch_cell('SELECT id FROM automation_snmp ORDER BY id LIMIT 1');
 $name='QA workspace loopback '.bin2hex(random_bytes(4));
 $id=nms_workspace_network_save(['network_id'=>0,'name'=>$name,'subnet_range'=>'127.0.0.1','poller_id'=>$poller,'site_id'=>0,'snmp_id'=>$snmp,'sched_type'=>1,'start_at'=>date('Y-m-d H:i:s'),'recur_every'=>1,'threads'=>1,'run_limit'=>60,'enabled'=>'on']);
 $state=['network_id'=>$id,'name'=>$name,'runs'=>[]];file_put_contents($stateFile,json_encode($state));chmod($stateFile,0600);
 if($mode==='prepare')$state['runs'][]=nms_scan_enqueue(['network_id'=>$id,'targets'=>'127.0.0.1,::1','methods'=>['icmp','tcp'],'ports'=>'80']);
 file_put_contents($stateFile,json_encode($state));echo json_encode($state)."\n";
} else {
 $state=json_decode(file_get_contents($stateFile),true,512,JSON_THROW_ON_ERROR);
 $network=db_fetch_row_prepared('SELECT * FROM automation_networks WHERE id=?',[$state['network_id']]);
 if(!$network || $network['name']!==$state['name'] || $network['subnet_range']!=='127.0.0.1' || $network['add_to_cacti']!=='')throw new RuntimeException('Fixture identity changed; refusing');
 $state['runs']=array_map('intval',array_column(db_fetch_assoc_prepared('SELECT id FROM plugin_nms_scan_runs WHERE network_id=? ORDER BY id',[$network['id']]),'id'));
 if($mode==='history_fixture') {
  if($state['runs'])throw new RuntimeException('History fixture requires an unused disposable network');
  // Terminal synthetic rows only: no job is ever eligible for collector execution.
  if(!db_execute('START TRANSACTION'))throw new RuntimeException('Cannot start history fixture');
  try {
   for($i=1;$i<=27;$i++) {
    nms_category_execute("INSERT INTO plugin_nms_scan_runs(network_id,poller_id,user_id,kind,status,config_hash,options_json,target_count,task_count,progress_cursor,requested_at,finished_at,summary_json) VALUES (?,?,?,'supplemental','complete',?,'{}',101,101,101,NOW(),NOW(),?)",[$network['id'],$network['poller_id'],nms_current_user_id(),str_repeat('0',64),json_encode(['fixture'=>'SYNTHETIC pagination only','sequence'=>$i])]);
    $run=(int)db_fetch_cell('SELECT LAST_INSERT_ID()');
    nms_workspace_audit('synthetic_pagination_fixture',['sequence'=>$i],0,$network['id']);
   }
   for($i=0;$i<101;$i++)nms_category_execute("INSERT INTO plugin_nms_scan_results(run_id,ordinal,address,method,reachable,detail,checked_at) VALUES (?,?,'127.0.0.1','icmp',0,?,NOW())",[$run,$i,'SYNTHETIC pagination row '.($i+1).'; no probe executed']);
   if(!db_execute('COMMIT'))throw new RuntimeException('Cannot commit fixture');
  }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
  echo json_encode(['network_id'=>$network['id'],'latest_run'=>$run,'runs'=>27,'results'=>101])."\n";
 } elseif($mode==='invalidate_form') {
  nms_category_execute('UPDATE automation_networks SET threads=? WHERE id=?',[(int)$network['threads']===1?2:1,$network['id']]);
  echo "Changed only guarded loopback fixture thread count for stale-form acceptance.\n";
 } elseif($mode==='native') {
  $state['runs'][]=nms_scan_enqueue(['network_id'=>$network['id'],'revision'=>nms_workspace_network_revision($network)],'native');
  file_put_contents($stateFile,json_encode($state));echo json_encode($state)."\n";
 } elseif($mode==='check')foreach($state['runs'] as $id){
  $run=db_fetch_row_prepared('SELECT id,status,error,progress_cursor,task_count FROM plugin_nms_scan_runs WHERE id=?',[$id]);
  echo json_encode($run)."\n";
  foreach(db_fetch_assoc_prepared('SELECT address,method,reachable,detail FROM plugin_nms_scan_results WHERE run_id=? ORDER BY ordinal',[$id]) as $r)echo json_encode($r)."\n";
 } elseif($mode==='cleanup'){
  if(db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_nms_scan_runs WHERE network_id=? AND status IN ('queued','dispatching','running')",[$network['id']]) || db_fetch_cell_prepared("SELECT COUNT(*) FROM automation_processes WHERE network_id=? AND status<>'done'",[$network['id']]))throw new RuntimeException('Fixture still has active work; refusing cleanup');
  foreach($state['runs'] as $id){db_execute_prepared('DELETE FROM plugin_nms_scan_results WHERE run_id=?',[$id]);db_execute_prepared('DELETE FROM plugin_nms_scan_runs WHERE id=? AND network_id=?',[$id,$network['id']]);}
  db_execute_prepared('DELETE FROM plugin_nms_workspace_audit WHERE network_id=?',[$network['id']]);
  db_execute_prepared('DELETE FROM automation_devices WHERE network_id=?',[$network['id']]);
  db_execute_prepared("DELETE FROM automation_processes WHERE network_id=? AND status='done'",[$network['id']]);
  db_execute_prepared('DELETE FROM automation_networks WHERE id=? AND name=?',[$network['id'],$state['name']]);unlink($stateFile);echo "Exact loopback fixture removed.\n";
 }else throw new InvalidArgumentException('Unknown acceptance mode');
}
