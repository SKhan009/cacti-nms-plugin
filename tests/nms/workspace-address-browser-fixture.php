<?php
/** Disposable native device for real browser/collector management-IP and neighbour verification. */
if(PHP_SAPI!=='cli'||empty($argv[1])||!in_array($argv[2]??'',['create','cleanup','verify','refresh','verify_onboarding'],true))exit('Supply root and create/verify/cleanup');
require $argv[1].'/include/global.php';require_once $argv[1].'/plugins/nms/includes/functions.php';
require_once $argv[1].'/plugins/nms/includes/workspace/onboarding_worker.php';
require_once $argv[1].'/plugins/nms/includes/discovery_snmp.php';
require_once $argv[1].'/lib/api_data_source.php';
require_once $argv[1].'/lib/api_graph.php';
require_once $argv[1].'/lib/api_device.php';
$_SESSION=['sess_user_id'=>(int)db_fetch_cell("SELECT id FROM user_auth WHERE username='admin' AND enabled='on'")];
$path='/tmp/nms-address-browser-state.json';$description='QA SYNTHETIC address browser';$marker=nms_workspace_onboarding_marker(999999930);
function qa_address_inventory(){return [db_fetch_assoc('SELECT id,hostname FROM host ORDER BY id'),db_fetch_assoc('SELECT id,host_id FROM graph_local ORDER BY id'),db_fetch_assoc('SELECT id,host_id FROM data_local ORDER BY id')];}
if($argv[2]!=='create'){
 $s=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$h=db_fetch_row_prepared('SELECT * FROM host WHERE id=?',[$s['id']]);
 if(!$h||$h['description']!==$description||$h['external_id']!==$marker||!in_array($h['hostname'],['192.0.2.249','192.0.2.250'],true))throw new RuntimeException('Fixture changed unexpectedly');
 if($argv[2]==='refresh'){
  foreach(nms_nd_hosts() as $row)if((int)$row['id']===$s['id'])$host=$row;
  $probe=$host;$probe['nms_snmp_retries']=0;$identity=nms_nd_collect_identity($probe,microtime(true)+18);
  if(!nms_nd_chassis_key($identity))throw new RuntimeException('Fresh identity missing');
  $neighbor=json_decode(db_fetch_cell_prepared("SELECT data_json FROM plugin_nms_discovery_snapshots WHERE host_id=? AND protocol='lldp'",[$s['id']]),true,512,JSON_THROW_ON_ERROR);$neighbor['collected']=time();
  foreach(['identity'=>$identity,'lldp'=>$neighbor] as $protocol=>$data)nms_category_execute("UPDATE plugin_nms_discovery_snapshots SET config_hash=?,data_json=?,attempted_at=NOW(),succeeded_at=NOW(),status='success' WHERE host_id=? AND protocol=?",[nms_nd_hash($host),json_encode($data,JSON_THROW_ON_ERROR),$s['id'],$protocol]);
  echo "Refreshed actual synthetic-agent identity and labelled synthetic neighbour advertisement.\n";exit;
 }
 if($argv[2]==='verify'){
  $job=db_fetch_row_prepared("SELECT id,status,error FROM plugin_nms_management_changes WHERE host_id=? ORDER BY id DESC LIMIT 1",[$s['id']]);
  if($h['hostname']!=='192.0.2.250'||!$job||$job['status']!=='applied')throw new RuntimeException('Management-IP browser apply is not complete');
  if($s['graphs']!==db_fetch_assoc_prepared('SELECT id,host_id FROM graph_local WHERE host_id=? ORDER BY id',[$s['id']])||$s['data']!==db_fetch_assoc_prepared('SELECT id,host_id FROM data_local WHERE host_id=? ORDER BY id',[$s['id']]))throw new RuntimeException('Graph/data associations changed');
  echo 'PASS: browser/collector management-IP job #'.$job['id'].' applied; native device ID and graph/data associations preserved.'."\n";exit;
 }
 if(db_fetch_cell_prepared("SELECT id FROM plugin_nms_management_changes WHERE host_id=? AND status IN ('queued_verify','verifying','queued_apply','applying')",[$s['id']]))throw new RuntimeException('Fixture still has active work');
 if(db_fetch_cell_prepared("SELECT id FROM plugin_nms_candidate_checks WHERE reporter_id=? AND status IN ('queued','running')",[$s['id']]))throw new RuntimeException('Candidate fixture still active');
 $onboarding=db_fetch_assoc_prepared('SELECT * FROM plugin_nms_onboarding_requests WHERE reporter_id=?',[$s['id']]);
 foreach($onboarding as $request){
  if($request['status']==='cancelled' && !$request['host_id']) {
   if(db_fetch_cell_prepared('SELECT id FROM host WHERE external_id=?',[nms_workspace_onboarding_marker($request['id'])]))throw new RuntimeException('Cancelled onboarding unexpectedly created a device');
   continue;
  }
  if($request['status']!=='created')throw new RuntimeException('Unexpected onboarding state; inspect before cleanup');
  $added=db_fetch_row_prepared('SELECT * FROM host WHERE id=?',[$request['host_id']]);
  if(!$added || $added['description']!=='QA SYNTHETIC onboarded neighbour' || $added['external_id']!==nms_workspace_onboarding_marker($request['id']) || $added['hostname']!=='192.0.2.251' || (int)$added['host_template_id']!==18 || (int)$added['poller_id']!==1 || (int)$added['site_id']!==(int)$h['site_id'])throw new RuntimeException('Onboarded fixture does not match expected native properties');
 }
 if($argv[2]==='verify_onboarding'){
  if(count($onboarding)!==1 || $onboarding[0]['status']!=='created')throw new RuntimeException('Expected one completed onboarding request');
  $candidate=['reporter_id'=>$s['id'],'target'=>'192.0.2.251','poller_id'=>1,'site_id'=>$h['site_id'],'snmp_context'=>$h['snmp_context']];
  $admission=nms_workspace_admission_check($candidate);
  if(empty($admission['blocked']))throw new RuntimeException('Existing onboarded target was not blocked');
  echo "PASS: browser onboarding created native template/site/collector device; repeat admission blocked.\n";exit;
 }
 foreach($onboarding as $request){
  if($request['status']==='cancelled')continue;
  $added_id=(int)$request['host_id'];
  foreach(db_fetch_assoc_prepared('SELECT id FROM graph_local WHERE host_id=?',[$added_id]) as $r)api_graph_remove((int)$r['id']);
  foreach(db_fetch_assoc_prepared('SELECT id FROM data_local WHERE host_id=?',[$added_id]) as $r)api_data_source_remove((int)$r['id']);
  nms_category_execute('DELETE FROM plugin_nms_workspace_audit WHERE host_id=?',[$added_id]);
  api_device_remove($added_id);
 }
 nms_category_execute('DELETE FROM plugin_nms_onboarding_requests WHERE reporter_id=?',[$s['id']]);
 foreach(db_fetch_assoc_prepared('SELECT id FROM graph_local WHERE host_id=?',[$s['id']]) as $r)api_graph_remove((int)$r['id']);
 foreach(db_fetch_assoc_prepared('SELECT id FROM data_local WHERE host_id=?',[$s['id']]) as $r)api_data_source_remove((int)$r['id']);
 foreach(['plugin_nms_management_changes','plugin_nms_discovery_snapshots','plugin_nms_discovery_devices','plugin_nms_workspace_audit'] as $table)nms_category_execute("DELETE FROM $table WHERE host_id=?",[$s['id']]);
 nms_category_execute('DELETE FROM plugin_nms_candidate_checks WHERE reporter_id=?',[$s['id']]);
 api_device_remove($s['id']);nms_category_execute('DELETE FROM plugin_nms_discovery_presets WHERE id=? AND name=?',[$s['preset'],'QA SYNTHETIC address browser']);
 if(qa_address_inventory()!==$s['before'])throw new RuntimeException('Existing inventory changed');unlink($path);echo "Exact address fixture removed; existing native inventory unchanged.\n";exit;
}
if(is_file($path)||db_fetch_cell_prepared('SELECT id FROM host WHERE external_id=?',[$marker]))throw new RuntimeException('Fixture already exists');
$source=db_fetch_row('SELECT * FROM host WHERE id=2');if(!$source||$source['hostname']!=='127.0.0.1')throw new RuntimeException('Loopback source required');
$source['snmp_version']=2;$source['snmp_community']='nms-qa';$source['snmp_port']=1163;$source['snmp_timeout']=500;
$s=['before'=>qa_address_inventory()];
$job=['id'=>999999930,'target'=>'192.0.2.249','poller_id'=>1,'site_id'=>$source['site_id'],'template_id'=>0,'description'=>$description];
$s['id']=nms_workspace_onboarding_native_create($job,$source);if(!$s['id'])throw new RuntimeException('Native fixture failed');file_put_contents($path,json_encode($s,JSON_THROW_ON_ERROR));
$template=(int)db_fetch_cell('SELECT graph_template_id FROM graph_local WHERE host_id=2 AND snmp_query_id=0 ORDER BY id LIMIT 1');$suggested=[];
$graph=create_complete_graph_from_template($template,$s['id'],null,$suggested);if(empty($graph['local_graph_id']))throw new RuntimeException('Graph fixture failed');
nms_category_execute("INSERT INTO plugin_nms_discovery_presets(name,protocol,enabled,interval_seconds,stale_seconds,refresh_seconds,updated_by,updated_at) VALUES ('QA SYNTHETIC address browser','lldp',1,3600,3600,30,?,NOW())",[nms_current_user_id()]);
$s['preset']=(int)db_fetch_cell('SELECT LAST_INSERT_ID()');file_put_contents($path,json_encode($s,JSON_THROW_ON_ERROR));
nms_category_execute('INSERT INTO plugin_nms_discovery_devices(host_id,preset_id,last_attempt) VALUES (?,?,NOW())',[$s['id'],$s['preset']]);
foreach(nms_nd_hosts() as $h)if((int)$h['id']===$s['id'])$host=$h;
$probe=$host;$probe['nms_snmp_retries']=0;$identity=nms_nd_collect_identity($probe,microtime(true)+18);
if(!nms_nd_chassis_key($identity))throw new RuntimeException('Synthetic SNMP identity missing');
$neighbor=['collected'=>time(),'neighbors'=>['qa-neighbour'=>['present'=>true,'peer_key'=>'QA-WORKSPACE-neighbour-0001','remote_name'=>'QA SYNTHETIC neighbour','local_port'=>'fixture1','remote_port'=>'fixture2','management_addresses'=>[['address'=>'192.0.2.251']]]]];
foreach(['identity'=>$identity,'lldp'=>$neighbor] as $protocol=>$data)nms_category_execute("INSERT INTO plugin_nms_discovery_snapshots(host_id,protocol,status,attempted_at,succeeded_at,config_hash,data_json,error) VALUES (?,?,'success',NOW(),NOW(),?,?,'')",[$s['id'],$protocol,nms_nd_hash($host),json_encode($data,JSON_THROW_ON_ERROR)]);
$s['graphs']=db_fetch_assoc_prepared('SELECT id,host_id FROM graph_local WHERE host_id=? ORDER BY id',[$s['id']]);$s['data']=db_fetch_assoc_prepared('SELECT id,host_id FROM data_local WHERE host_id=? ORDER BY id',[$s['id']]);file_put_contents($path,json_encode($s,JSON_THROW_ON_ERROR));
echo json_encode(['host_id'=>$s['id'],'old'=>'192.0.2.249','proposal'=>'192.0.2.250','neighbour'=>'192.0.2.251'])."\n";
