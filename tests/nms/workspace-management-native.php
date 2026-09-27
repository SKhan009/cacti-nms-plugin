<?php
/** Actual Cacti update with a temporary loopback device, graph and data source. */
if(PHP_SAPI!=='cli'||empty($argv[1])||empty($argv[2]))exit('Supply Cacti root and staged workspace');
require $argv[1].'/include/global.php';require_once $argv[1].'/plugins/nms/includes/functions.php';
require_once $argv[1].'/plugins/nms/includes/workspace/onboarding_worker.php';
$source=file_get_contents($argv[2].'/management_worker.php');$start=strpos($source,'function nms_workspace_management_native_update');$end=strpos($source,'function nms_workspace_management_associations');eval(substr($source,$start,$end-$start));
$_SESSION['sess_user_id']=(int)db_fetch_cell("SELECT id FROM user_auth WHERE username='admin' AND enabled='on'");
$sourceHost=db_fetch_row('SELECT * FROM host WHERE id=2');if(!$sourceHost || $sourceHost['hostname']!=='127.0.0.1')throw new RuntimeException('Loopback QA source required');
$job=['id'=>999999902,'target'=>'127.0.0.1','poller_id'=>1,'site_id'=>$sourceHost['site_id'],'template_id'=>$sourceHost['host_template_id'],'description'=>'QA management IP native loopback'];
$marker=nms_workspace_onboarding_marker($job['id']);if(db_fetch_cell_prepared('SELECT id FROM host WHERE external_id=? OR description=?',[$marker,$job['description']]))throw new RuntimeException('Existing test fixture requires review');
$beforeHosts=db_fetch_assoc('SELECT id,hostname FROM host ORDER BY id');$beforeGraphs=db_fetch_assoc('SELECT id,host_id FROM graph_local ORDER BY id');$beforeData=db_fetch_assoc('SELECT id,host_id FROM data_local ORDER BY id');
function qa_rrd($arguments) {
    $process=proc_open(array_merge(['/usr/bin/rrdtool'],$arguments),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Could not start fixture rrdtool');
    fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0)throw new RuntimeException('Fixture rrdtool failed: '.$error);
    return $output;
}
$id=0;$rrdDirectory=sys_get_temp_dir().'/nms-management-rrd-'.bin2hex(random_bytes(8));
if(!mkdir($rrdDirectory,0700))throw new RuntimeException('Could not create RRD fixture directory');
$rrdFile=$rrdDirectory.'/history.rrd';
try {
    $id=nms_workspace_onboarding_native_create($job,$sourceHost);
    if(!$id)throw new RuntimeException('Fixture creation failed');
    nms_category_execute("UPDATE host SET disabled='on' WHERE id=?",[$id]);
    $template=(int)db_fetch_cell("SELECT graph_template_id FROM graph_local WHERE host_id=2 AND snmp_query_id=0 ORDER BY id LIMIT 1");
    $suggested=[];$graph=create_complete_graph_from_template($template,$id,null,$suggested);
    if(empty($graph['local_graph_id']))throw new RuntimeException('Fixture graph creation failed');
    $graphs=db_fetch_assoc_prepared('SELECT id,host_id,graph_template_id FROM graph_local WHERE host_id=? ORDER BY id',[$id]);
    $data=db_fetch_assoc_prepared('SELECT dl.id,dl.host_id,dl.data_template_id,dtd.data_source_path FROM data_local dl JOIN data_template_data dtd ON dtd.local_data_id=dl.id WHERE dl.host_id=? ORDER BY dl.id',[$id]);
    if(!$graphs||!$data)throw new RuntimeException('Nonempty graph/data fixtures required');
    // Attach a populated, private RRD to this fixture's existing data source only.
    $start=intdiv(time()-3600,60)*60;
    qa_rrd(['create',$rrdFile,'--start',(string)$start,'--step','60','DS:value:GAUGE:120:U:U','RRA:AVERAGE:0.5:1:120']);
    qa_rrd(['update',$rrdFile,($start+60).':10',($start+120).':20',($start+180).':30']);
    nms_category_execute('UPDATE data_template_data SET data_source_path=? WHERE local_data_id=?',[$rrdFile,$data[0]['id']]);
    $data=db_fetch_assoc_prepared('SELECT dl.id,dl.host_id,dl.data_template_id,dtd.data_source_path FROM data_local dl JOIN data_template_data dtd ON dtd.local_data_id=dl.id WHERE dl.host_id=? ORDER BY dl.id',[$id]);
    $fetchArgs=['fetch',$rrdFile,'AVERAGE','--start',(string)$start,'--end',(string)($start+180),'--resolution','60'];
    $history=qa_rrd($fetchArgs);$digest=hash_file('sha256',$rrdFile);
    if(strpos($history,'1.0000000000e+01')===false || strpos($history,'3.0000000000e+01')===false)throw new RuntimeException('Fixture history was not populated');
    $host=db_fetch_row_prepared('SELECT * FROM host WHERE id=?',[$id]);
    $saved=nms_workspace_management_native_update($host,'127.0.0.2');
    if($saved!==$id || db_fetch_cell_prepared('SELECT hostname FROM host WHERE id=?',[$id])!=='127.0.0.2')throw new RuntimeException('Native update failed to preserve ID');
    if($graphs!==db_fetch_assoc_prepared('SELECT id,host_id,graph_template_id FROM graph_local WHERE host_id=? ORDER BY id',[$id]) || $data!==db_fetch_assoc_prepared('SELECT dl.id,dl.host_id,dl.data_template_id,dtd.data_source_path FROM data_local dl JOIN data_template_data dtd ON dtd.local_data_id=dl.id WHERE dl.host_id=? ORDER BY dl.id',[$id]))throw new RuntimeException('Native update changed graph/data associations or RRD path');
    if(!is_file($rrdFile)||hash_file('sha256',$rrdFile)!==$digest||qa_rrd($fetchArgs)!==$history)throw new RuntimeException('Native management update changed populated RRD history');
    qa_rrd(['update',$rrdFile,($start+240).':40']);
    $historicalRows=function($text)use($start){return array_values(array_filter(explode("\n",$text),function($line)use($start){return preg_match('/^([0-9]+):/',$line,$match) && (int)$match[1]<=$start+180;}));};
    if($historicalRows(qa_rrd($fetchArgs))!==$historicalRows($history) || strpos(qa_rrd(['fetch',$rrdFile,'AVERAGE','--start',(string)($start+180),'--end',(string)($start+240),'--resolution','60']),'4.0000000000e+01')===false)throw new RuntimeException('RRD history continuity failed after address update');
    echo "PASS: populated RRD bytes and historical samples preserved; subsequent sample appends successfully.\n";
    echo "PASS: native management-address update preserves device ID, nonempty graphs/data sources and RRD path metadata.\n";
}finally {
    $fixture=db_fetch_row_prepared('SELECT id,hostname,description FROM host WHERE external_id=?',[$marker]);
    if($fixture) {
        if(!in_array($fixture['hostname'],['127.0.0.1','127.0.0.2'],true)||$fixture['description']!==$job['description'])throw new RuntimeException('Fixture changed; refusing cleanup');
        require_once $argv[1].'/lib/api_data_source.php';
        foreach(db_fetch_assoc_prepared('SELECT id FROM graph_local WHERE host_id=?',[$fixture['id']]) as $row)api_graph_remove((int)$row['id']);
        foreach(db_fetch_assoc_prepared('SELECT id FROM data_local WHERE host_id=?',[$fixture['id']]) as $row)api_data_source_remove((int)$row['id']);
        api_device_remove((int)$fixture['id']);
    }
    if(is_file($rrdFile))unlink($rrdFile);
    if(is_dir($rrdDirectory))rmdir($rrdDirectory);
}
if($beforeHosts!==db_fetch_assoc('SELECT id,hostname FROM host ORDER BY id') || $beforeGraphs!==db_fetch_assoc('SELECT id,host_id FROM graph_local ORDER BY id') || $beforeData!==db_fetch_assoc('SELECT id,host_id FROM data_local ORDER BY id'))throw new RuntimeException('Existing inventory changed');
echo "PASS: exact fixture cleaned; existing devices, graphs and data-source associations unchanged.\n";
