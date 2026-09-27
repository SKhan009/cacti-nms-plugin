<?php
/** Explicit synthetic loopback pair for browser QA; guarded exact native cleanup. */
if(PHP_SAPI!=='cli'||empty($argv[1])||!in_array($argv[2]??'', ['create','transfer','verify','verify_cancel','change_evidence','cleanup'],true))exit('Supply Cacti root and create/cleanup');
require $argv[1].'/include/global.php';
require_once $argv[1].'/plugins/nms/includes/functions.php';
require_once $argv[1].'/plugins/nms/includes/workspace/onboarding_worker.php';
require_once $argv[1].'/plugins/nms/includes/discovery.php';
$_SESSION['sess_user_id']=(int)db_fetch_cell("SELECT id FROM user_auth WHERE username='admin' AND enabled='on'");
$statePath='/tmp/nms-consolidation-browser-state.json';
function fixture_rrd($args){$p=proc_open(array_merge(['/usr/bin/rrdtool'],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new RuntimeException('RRD fixture failed');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException($err);return $out;}
function inventory(){return [db_fetch_assoc('SELECT id,hostname FROM host ORDER BY id'),db_fetch_assoc('SELECT id,host_id FROM graph_local ORDER BY id'),db_fetch_assoc('SELECT id,host_id FROM data_local ORDER BY id')];}
if($argv[2]==='change_evidence') {
    $state=json_decode(file_get_contents($statePath),true,512,JSON_THROW_ON_ERROR);$fixture=$state['hosts'][0];
    $host=db_fetch_row_prepared('SELECT hostname,description,external_id FROM host WHERE id=?',[$fixture['id']]);
    if(!$host||$host['hostname']!=='127.0.0.1'||$host['description']!==$fixture['description']||$host['external_id']!==$fixture['marker'])throw new RuntimeException('Fixture identity changed');
    nms_category_execute("UPDATE plugin_nms_discovery_snapshots SET succeeded_at=NOW(),attempted_at=NOW() WHERE host_id=? AND protocol='identity'",[$fixture['id']]);
    echo "Refreshed only synthetic fixture evidence timestamp for stale-browser review test.\n";exit;
}
if($argv[2]==='verify_cancel') {
    $state=json_decode(file_get_contents($statePath),true,512,JSON_THROW_ON_ERROR);$ids=array_column($state['hosts'],'id');
    $job=db_fetch_row_prepared('SELECT * FROM plugin_nms_consolidation_jobs WHERE keep_id=? AND other_id=? ORDER BY id DESC LIMIT 1',$ids);
    if(!$job||$job['status']!=='cancelled'||$job['started_at']!==null)throw new RuntimeException('Cancellation was not before execution');
    $plan=json_decode($job['plan_json'],true,512,JSON_THROW_ON_ERROR);
    foreach($plan['devices'] as $device){
        $graphs=db_fetch_assoc_prepared('SELECT id,graph_template_id,snmp_query_id,snmp_index FROM graph_local WHERE host_id=? ORDER BY id',[$device['host']['id']]);
        $data=db_fetch_assoc_prepared('SELECT d.id,d.data_template_id,d.snmp_query_id,d.snmp_index,t.data_source_path,t.data_source_profile_id,t.rrd_step FROM data_local d LEFT JOIN data_template_data t ON t.local_data_id=d.id WHERE d.host_id=? ORDER BY d.id,t.id',[$device['host']['id']]);
        if($graphs!==$device['graphs']||$data!==$device['data_sources'])throw new RuntimeException('Cancelled transfer changed native assets');
    }
    foreach($state['rrds'] as $rrd)if(hash_file('sha256',$rrd['path'])!==$rrd['sha256']||fixture_rrd($rrd['fetch'])!==$rrd['history'])throw new RuntimeException('Cancelled transfer changed RRD history');
    echo 'PASS: browser-cancelled job #'.$job['id'].' never started; exact native graph/data ownership and populated RRD history unchanged.'."\n";exit;
}
if($argv[2]==='verify') {
    $state=json_decode(file_get_contents($statePath),true,512,JSON_THROW_ON_ERROR);$ids=array_column($state['hosts'],'id');
    $job=db_fetch_row_prepared("SELECT id,status,error,result_json FROM plugin_nms_consolidation_jobs WHERE keep_id=? AND other_id=? ORDER BY id DESC LIMIT 1",[$ids[1],$ids[0]]);
    if(!$job||$job['status']!=='complete')throw new RuntimeException('Final browser transfer did not complete');
    foreach($state['rrds'] as $rrd)if(!is_file($rrd['path'])||hash_file('sha256',$rrd['path'])!==$rrd['sha256']||fixture_rrd($rrd['fetch'])!==$rrd['history'])throw new RuntimeException('Browser transfer changed populated RRD history');
    if(db_fetch_cell_prepared('SELECT id FROM graph_local WHERE host_id=? LIMIT 1',[$ids[0]])||db_fetch_cell_prepared('SELECT id FROM data_local WHERE host_id=? LIMIT 1',[$ids[0]]))throw new RuntimeException('Final source still owns assets');
    if((int)db_fetch_cell_prepared("SELECT COUNT(*) FROM host WHERE id IN (?,?) AND disabled='on'",$ids)!==2)throw new RuntimeException('Both paused records were not retained');
    echo 'PASS: live browser/collector transfer #'.$job['id'].'; both records retained, source empty, both populated RRD hashes and fetched history unchanged.'."\n";exit;
}
if($argv[2]==='cleanup') {
    if(!is_file($statePath))throw new RuntimeException('No fixture state');$state=json_decode(file_get_contents($statePath),true,512,JSON_THROW_ON_ERROR);
    require_once $argv[1].'/plugins/nms/includes/device_manager.php';require_once $argv[1].'/lib/api_data_source.php';
    foreach($state['hosts'] as $fixture) {
        $host=db_fetch_row_prepared('SELECT id,hostname,description,external_id FROM host WHERE id=?',[$fixture['id']]);
        if(!$host||$host['hostname']!=='127.0.0.1'||$host['description']!==$fixture['description']||$host['external_id']!==$fixture['marker'])throw new RuntimeException('Fixture identity changed; refusing cleanup');
        $id=$fixture['id'];
        foreach(db_fetch_assoc_prepared('SELECT id FROM graph_local WHERE host_id=?',[$id]) as $row)api_graph_remove((int)$row['id']);
        foreach(db_fetch_assoc_prepared('SELECT id FROM data_local WHERE host_id=?',[$id]) as $row)api_data_source_remove((int)$row['id']);
        foreach(['plugin_nms_discovery_snapshots','plugin_nms_discovery_devices','plugin_nms_workspace_audit'] as $table)nms_category_execute("DELETE FROM $table WHERE host_id=?",[$id]);
        nms_category_execute('DELETE FROM plugin_nms_identity_reviews WHERE host_a=? OR host_b=?',[$id,$id]);
        nms_category_execute('DELETE FROM plugin_nms_consolidation_reviews WHERE keep_id=? OR other_id=?',[$id,$id]);
        nms_category_execute('DELETE FROM plugin_nms_consolidation_jobs WHERE keep_id=? OR other_id=?',[$id,$id]);
        api_device_remove((int)$id);
    }
    nms_category_execute('DELETE FROM plugin_nms_discovery_presets WHERE id=? AND name=?',[$state['preset'],'QA SYNTHETIC consolidation browser']);
    foreach($state['rrds']??[] as $rrd)if(is_file($rrd['path'])){if(dirname($rrd['path'])!==realpath($config['rra_path'])||strpos(basename($rrd['path']),'nms_qa_consolidation_')!==0)throw new RuntimeException('Unexpected fixture path');unlink($rrd['path']);}
    if(array_key_exists('preference',$state)){if($state['preference'])nms_category_execute("REPLACE INTO settings_user(user_id,name,value) VALUES (?,'hide_disabled',?)",[$state['reviewer'],$state['preference']['value']]);else nms_category_execute("DELETE FROM settings_user WHERE user_id=? AND name='hide_disabled'",[$state['reviewer']]);}
    if($state['before']!==inventory())throw new RuntimeException('Existing inventory differs after cleanup');
    unlink($statePath);echo "Exact fixtures removed; existing hosts/graphs/data associations unchanged.\n";exit;
}
if(is_file($statePath))throw new RuntimeException('Fixture state already exists; cleanup first');
$source=db_fetch_row('SELECT * FROM host WHERE id=2');if(!$source||$source['hostname']!=='127.0.0.1')throw new RuntimeException('Loopback source required');
foreach([999999910,999999911] as $number)if(db_fetch_cell_prepared('SELECT id FROM host WHERE external_id=?',[nms_workspace_onboarding_marker($number)]))throw new RuntimeException('Marker already exists');
$state=['before'=>inventory(),'hosts'=>[],'preset'=>0,'rrds'=>[]];
if($argv[2]==='transfer'){$state['reviewer']=nms_current_user_id();$state['preference']=db_fetch_row_prepared("SELECT value FROM settings_user WHERE user_id=? AND name='hide_disabled'",[$state['reviewer']]);}file_put_contents($statePath,json_encode($state,JSON_THROW_ON_ERROR));
if($argv[2]==='transfer')nms_category_execute("REPLACE INTO settings_user(user_id,name,value) VALUES (?,'hide_disabled','')",[$state['reviewer']]);
nms_category_execute("INSERT INTO plugin_nms_discovery_presets(name,protocol,enabled,interval_seconds,stale_seconds,refresh_seconds,updated_by,updated_at) VALUES ('QA SYNTHETIC consolidation browser','lldp',1,3600,3600,30,?,NOW())",[nms_current_user_id()]);
$state['preset']=(int)db_fetch_cell('SELECT LAST_INSERT_ID()');file_put_contents($statePath,json_encode($state,JSON_THROW_ON_ERROR));
foreach([999999910=>'A',999999911=>'B'] as $number=>$suffix) {
    $job=['id'=>$number,'target'=>'127.0.0.1','poller_id'=>1,'site_id'=>$source['site_id'],'template_id'=>0,'description'=>'QA SYNTHETIC consolidation '.$suffix];
    $id=nms_workspace_onboarding_native_create($job,$source);if(!$id)throw new RuntimeException('Native fixture creation failed');
    $state['hosts'][]=['id'=>$id,'description'=>$job['description'],'marker'=>nms_workspace_onboarding_marker($number)];file_put_contents($statePath,json_encode($state,JSON_THROW_ON_ERROR));
    $template=(int)db_fetch_cell('SELECT graph_template_id FROM graph_local WHERE host_id=2 AND snmp_query_id=0 ORDER BY id LIMIT 1');$suggested=[];
    $graph=create_complete_graph_from_template($template,$id,null,$suggested);if(empty($graph['local_graph_id']))throw new RuntimeException('Graph fixture failed');
    if($argv[2]==='transfer') {
        foreach(db_fetch_assoc_prepared('SELECT id FROM data_local WHERE host_id=?',[$id]) as $ds){
            $path=realpath($config['rra_path']).'/nms_qa_consolidation_'.$id.'_'.$ds['id'].'.rrd';if(file_exists($path))throw new RuntimeException('RRD fixture exists');
            $start=intdiv(time()-3600,60)*60;$fetch=['fetch',$path,'AVERAGE','--start',(string)$start,'--end',(string)($start+180),'--resolution','60'];
            $state['rrds'][]=['path'=>$path];file_put_contents($statePath,json_encode($state,JSON_THROW_ON_ERROR));
            fixture_rrd(['create',$path,'--start',(string)$start,'--step','60','DS:value:GAUGE:120:U:U','RRA:AVERAGE:0.5:1:120']);
            fixture_rrd(['update',$path,($start+60).':10',($start+120).':20',($start+180).':30']);
            $state['rrds'][count($state['rrds'])-1]=['path'=>$path,'sha256'=>hash_file('sha256',$path),'fetch'=>$fetch,'history'=>fixture_rrd($fetch)];file_put_contents($statePath,json_encode($state,JSON_THROW_ON_ERROR));
            nms_category_execute('UPDATE data_template_data SET data_source_path=? WHERE local_data_id=?',[$path,$ds['id']]);
        }
    }
    nms_category_execute('INSERT INTO plugin_nms_discovery_devices(host_id,preset_id,last_attempt) VALUES (?,?,NOW())',[$id,$state['preset']]);
    $current=null;foreach(nms_nd_hosts() as $h)if((int)$h['id']===$id)$current=$h;
    $data=['collected'=>time(),'hardware'=>['chassis'=>[['serial'=>'QA-SYNTHETIC-BROWSER-PAIR','model'=>'TEST FIXTURE']]],'interfaces'=>[],'own_addresses'=>[['address'=>'192.0.2.254','type'=>1,'status'=>1,'zone'=>0,'ifindex'=>1,'source'=>'synthetic acceptance fixture']]];
    nms_category_execute("INSERT INTO plugin_nms_discovery_snapshots(host_id,protocol,status,attempted_at,succeeded_at,config_hash,data_json,error) VALUES (?,'identity','success',NOW(),NOW(),?,?,'')",[$id,nms_nd_hash($current),json_encode($data,JSON_THROW_ON_ERROR)]);
    if($argv[2]==='transfer')nms_category_execute("UPDATE host SET disabled='on' WHERE id=?",[$id]);
}
echo json_encode(['fixture_host_ids'=>array_column($state['hosts'],'id'),'preset'=>$state['preset']])."\n";
