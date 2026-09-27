<?php
/** Native reader/ACL acceptance using connection-local copies and synthetic identity evidence. */
if(PHP_SAPI!=='cli'||empty($argv[1])||empty($argv[2]))exit('Supply Cacti root and staged includes');
require $argv[1].'/include/global.php';
require_once $argv[1].'/plugins/nms/includes/functions.php';
require_once $argv[1].'/plugins/nms/includes/topology/discovery.php';
$_SESSION=['sess_user_id'=>(int)db_fetch_cell("SELECT id FROM user_auth WHERE username='admin' AND enabled='on'")];
foreach(['host','plugin_nms_discovery_devices','plugin_nms_discovery_presets','plugin_nms_discovery_snapshots','settings_user'] as $table){
 $rows=db_fetch_assoc('SELECT * FROM '.$table);
 $ddl=preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',array_values(db_fetch_row('SHOW CREATE TABLE '.$table))[1]);
 if(!db_execute($ddl))throw new RuntimeException('Temporary table failed');
 foreach($rows as $row){$keys=array_keys($row);if(!db_execute_prepared('INSERT INTO '.$table.' (`'.implode('`,`',$keys).'`) VALUES ('.implode(',',array_fill(0,count($keys),'?')).')',array_values($row)))throw new RuntimeException('Temporary copy failed');}
}
db_execute_prepared("REPLACE INTO settings_user(user_id,name,value) VALUES (?,'hide_disabled','')",[$_SESSION['sess_user_id']]);
$s=file_get_contents($argv[2].'/discovery.php');$start=strpos($s,'function nms_nd_hosts(');$end=strpos($s,'/** Poller-owned collection',$start);eval(str_replace('function nms_nd_hosts(','function qa_hosts(',substr($s,$start,$end-$start)));
$s=file_get_contents($argv[2].'/topology/discovery.php');$start=strpos($s,'function nms_topology_discovery(');$end=strpos($s,'/** Build a current port matrix',$start);eval(str_replace(['function nms_topology_discovery(','nms_nd_hosts('],['function qa_reader(','qa_hosts('],substr($s,$start,$end-$start)));
if(!function_exists('nms_nd_review_snapshot_matches'))eval(substr($s,strpos($s,'function nms_nd_review_snapshot_matches(')));
$hosts=qa_hosts();if(!$hosts)throw new RuntimeException('An enabled assigned discovery device required');$host=$hosts[0];$id=(int)$host['id'];
db_execute_prepared('UPDATE plugin_nms_discovery_presets SET enabled=1,stale_seconds=3600 WHERE id=?',[$host['preset_id']]);
db_execute_prepared('UPDATE plugin_nms_discovery_devices SET collection_enabled=1 WHERE host_id=?',[$id]);
foreach(qa_hosts() as $row)if((int)$row['id']===$id)$host=$row;
$data=['collected'=>time(),'hardware'=>['chassis'=>[['serial'=>'QA-PAUSED-IDENTITY','model'=>'SYNTHETIC']]],'interfaces'=>[],'own_addresses'=>[]];
db_execute_prepared("REPLACE INTO plugin_nms_discovery_snapshots(host_id,protocol,status,attempted_at,succeeded_at,config_hash,data_json,error) VALUES (?,'identity','success',NOW(),NOW(),?,?,'')",[$id,nms_nd_hash($host),json_encode($data)]);
db_execute_prepared("UPDATE host SET disabled='on' WHERE id=?",[$id]);
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
check(!isset(qa_reader(null)['hosts'][$id]),'normal topology excludes disabled device');
$review=qa_reader(null,true);check(isset($review['hosts'][$id])&&!empty($review['snapshots'][$id.'|identity']['valid']),'authorized review reads pre-pause identity');
check(!empty($review['snapshots'][$id.'|identity']['polling_paused']),'paused provenance retained');
$identities=nms_nd_device_identities($review['hosts'],$review['snapshots']);check($identities[$id]['checked_at']!=='','saved identity reaches review model');
$data['collected']=time()-7200;db_execute_prepared("UPDATE plugin_nms_discovery_snapshots SET data_json=? WHERE host_id=? AND protocol='identity'",[json_encode($data),$id]);
$review=qa_reader(null,true);check($review['snapshots'][$id.'|identity']['status']==='stale','paused evidence still expires');
db_execute_prepared("UPDATE host SET hostname='192.0.2.199' WHERE id=?",[$id]);$review=qa_reader(null,true);check(empty($review['snapshots'][$id.'|identity']['valid']),'connection change invalidates pre-pause evidence');
echo "6 reader checks passed; native device/queue/preference changes are connection-local only.\n";
