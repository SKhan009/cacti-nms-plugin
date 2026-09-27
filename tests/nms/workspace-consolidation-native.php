<?php
/** Native graph/device transfer acceptance with two disposable disabled loopback devices. */
if(PHP_SAPI!=='cli'||empty($argv[1])||empty($argv[2]))exit('Supply Cacti root and staged workspace');
require $argv[1].'/include/global.php';require_once $argv[1].'/plugins/nms/includes/functions.php';
require_once $argv[1].'/plugins/nms/includes/workspace/onboarding_worker.php';
require_once $argv[1].'/lib/api_graph.php';
require_once $argv[2].'/consolidation_rrd.php';
require_once $argv[2].'/consolidation_writes.php';
require_once $argv[1].'/lib/api_data_source.php';
$_SESSION['sess_user_id']=(int)db_fetch_cell("SELECT id FROM user_auth WHERE username='admin' AND enabled='on'");
$sourceHost=db_fetch_row('SELECT * FROM host WHERE id=2');if(!$sourceHost || $sourceHost['hostname']!=='127.0.0.1')throw new RuntimeException('Loopback QA source required');
$job=['id'=>999999903,'target'=>'127.0.0.1','poller_id'=>1,'site_id'=>$sourceHost['site_id'],'template_id'=>0,'description'=>'QA consolidation native source'];
$marker=nms_workspace_onboarding_marker($job['id']);if(db_fetch_cell_prepared('SELECT id FROM host WHERE external_id=? OR description=?',[$marker,$job['description']]))throw new RuntimeException('Existing test fixture requires review');
$beforeHosts=db_fetch_assoc('SELECT id,hostname FROM host ORDER BY id');$beforeGraphs=db_fetch_assoc('SELECT id,host_id FROM graph_local ORDER BY id');$beforeData=db_fetch_assoc('SELECT id,host_id FROM data_local ORDER BY id');
function qa_rrd($arguments) {
    $process=proc_open(array_merge(['/usr/bin/rrdtool'],$arguments),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Could not start fixture rrdtool');
    fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0)throw new RuntimeException('Fixture rrdtool failed: '.$error);
    return $output;
}
$id=0;$destinationId=0;$destinationJob=array_replace($job,['id'=>999999904,'description'=>'QA consolidation native destination']);
$destinationMarker=nms_workspace_onboarding_marker($destinationJob['id']);
if(db_fetch_cell_prepared('SELECT id FROM host WHERE external_id=? OR description=?',[$destinationMarker,$destinationJob['description']]))throw new RuntimeException('Destination fixture already exists');
$rrdDirectory=sys_get_temp_dir().'/nms-consolidation-rrd-'.bin2hex(random_bytes(8));
if(!mkdir($rrdDirectory,0700))throw new RuntimeException('Could not create RRD fixture directory');
$rrdFile=$rrdDirectory.'/history.rrd';
try {
    $id=nms_workspace_onboarding_native_create($job,$sourceHost);
    if(!$id)throw new RuntimeException('Fixture creation failed');
    nms_category_execute("UPDATE host SET disabled='on' WHERE id=?",[$id]);
    $destinationId=nms_workspace_onboarding_native_create($destinationJob,$sourceHost);
    if(!$destinationId || $destinationId===$id)throw new RuntimeException('Distinct destination fixture required');
    nms_category_execute("UPDATE host SET disabled='on' WHERE id=?",[$destinationId]);
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
    $rrdProof=nms_workspace_consolidation_rrd_manifest($data,$rrdDirectory);
    $graphId=(int)$graph['local_graph_id'];
    $items=db_fetch_assoc_prepared('SELECT id,task_item_id FROM graph_templates_item WHERE local_graph_id=? ORDER BY id',[$graphId]);
    if(($argv[3]??'')==='worker') {
        require_once $argv[1].'/plugins/nms/includes/workspace/reviews.php';
        require_once $argv[1].'/plugins/nms/includes/diagnostics_queue.php';
        // Test the native review path for a reviewer who shows disabled devices.
        // This preference is changed only in this connection-local table.
        $settingsRows=db_fetch_assoc('SELECT * FROM settings_user');
        $ddl=preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',array_values(db_fetch_row('SHOW CREATE TABLE settings_user'))[1]);
        if(!db_execute($ddl))throw new RuntimeException('Temporary reviewer preferences failed');
        foreach($settingsRows as $setting){$keys=array_keys($setting);nms_category_execute('INSERT INTO settings_user (`'.implode('`,`',$keys).'`) VALUES ('.implode(',',array_fill(0,count($keys),'?')).')',array_values($setting));}
        nms_category_execute("REPLACE INTO settings_user(user_id,name,value) VALUES (?,'hide_disabled','')",[nms_current_user_id()]);
        unset($_SESSION['sess_user_config_array']);
        foreach(["CREATE TEMPORARY TABLE plugin_config(directory VARCHAR(64) PRIMARY KEY,status INT)","INSERT INTO plugin_config VALUES ('nms',1)","CREATE TEMPORARY TABLE plugin_nms_meta(meta_key VARCHAR(191) PRIMARY KEY,meta_value TEXT,updated_at DATETIME)","INSERT INTO plugin_nms_meta VALUES ('diagnostic_runner_1','{}',NOW())"] as $sql)if(!db_execute($sql))throw new RuntimeException('Temporary enabled-runner fixture failed');

        require_once $argv[2].'/consolidation_preflight.php';
        $schema=str_replace(['function nms_workspace_schema(', 'CREATE TABLE IF NOT EXISTS'],['function qa_schema(', 'CREATE TEMPORARY TABLE'],file_get_contents($argv[2].'/schema.php'));eval(substr($schema,5));qa_schema();
        foreach(['consolidation_permissions.php','consolidation.php','consolidation_jobs.php','consolidation_worker.php'] as $module) {
            $source=preg_replace('/^require_once .*;$/m','',file_get_contents($argv[2].'/'.$module));
            if($module==='consolidation.php')$source=str_replace(['nms_topology_discovery(','nms_nd_device_identities('],['qa_native_discovery(','qa_native_identities('],$source);
            eval(substr($source,5));
        }
        // Exercise retained native tree references without changing persistent user trees.
        $treeRows=db_fetch_assoc('SELECT * FROM graph_tree_items');
        $treeDdl=preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',array_values(db_fetch_row('SHOW CREATE TABLE graph_tree_items'))[1]);
        if(!db_execute($treeDdl))throw new RuntimeException('Temporary tree-reference fixture failed');
        foreach($treeRows as $row)nms_category_execute('INSERT INTO graph_tree_items (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')',array_values($row));
        $treeId=(int)db_fetch_cell('SELECT id FROM graph_tree ORDER BY id LIMIT 1');
        if(!$treeId)throw new RuntimeException('Existing QA tree required for reference acceptance');
        foreach([$id,$destinationId] as $treeHost)nms_category_execute('INSERT INTO graph_tree_items(graph_tree_id,host_id,title) VALUES (?,?,?)',[$treeId,$treeHost,'QA retained device reference']);
        nms_category_execute('INSERT INTO graph_tree_items(graph_tree_id,local_graph_id,title) VALUES (?,?,?)',[$treeId,$graphId,'QA retained graph reference']);
        $treeReferenceId=(int)db_fetch_cell('SELECT LAST_INSERT_ID()');
        $treeBefore=db_fetch_assoc('SELECT * FROM graph_tree_items ORDER BY id');
        $qaHosts=[];foreach(db_fetch_assoc_prepared('SELECT id,hostname,poller_id,site_id FROM host WHERE id IN (?,?)',[$id,$destinationId]) as $row)$qaHosts[(int)$row['id']]=$row;
        $qaIdentities=[$id=>['evidence'=>'synthetic transfer fixture'],$destinationId=>['evidence'=>'synthetic transfer fixture']];
        function qa_native_discovery(...$args){global $qaHosts;return ['hosts'=>$qaHosts,'snapshots'=>[]];}
        function qa_native_identities(...$args){global $qaIdentities;return $qaIdentities;}
        $evidence=nms_identity_review_hash($id,$destinationId,$qaHosts,$qaIdentities);
        nms_category_execute("INSERT INTO plugin_nms_identity_reviews VALUES (?,?,'same','Synthetic worker acceptance',?,1,?,NOW())",[min($id,$destinationId),max($id,$destinationId),$evidence,nms_current_user_id()]);
        $plan=nms_workspace_consolidation_plan($destinationId,$id);
        $request=nms_workspace_consolidation_enqueue(['keep_id'=>$destinationId,'other_id'=>$id,'revision'=>$plan['revision'],'confirm_transfer'=>'yes','confirm_references'=>'yes']);
        $originalRoot=$config['rra_path'];
        try {$config['rra_path']=$rrdDirectory;nms_workspace_consolidation_poll(1);}finally{$config['rra_path']=$originalRoot;}
        $outcome=db_fetch_row_prepared('SELECT status,error,result_json,progress_json FROM plugin_nms_consolidation_jobs WHERE id=?',[$request]);
        if($outcome['status']!=='complete')throw new RuntimeException('Worker transfer did not complete: '.$outcome['status'].' '.$outcome['error']);
        $result=json_decode($outcome['result_json'],true);$progress=json_decode($outcome['progress_json'],true);
        if(empty($result['device_records_retained'])||$progress['phase']!=='verified'||count($progress['rrd_before'])!==1)throw new RuntimeException('Durable result/proof incomplete');
        echo "PASS: actual reviewed queue and collector worker complete native transfer with durable RRD proof and retained device records.\n";
        if($treeBefore!==db_fetch_assoc('SELECT * FROM graph_tree_items ORDER BY id'))throw new RuntimeException('Native transfer changed retained tree references or placement');
        echo "PASS: native transfer preserves complete tree rows, graph placement and both retained device branches.\n";
        if(!db_execute('START TRANSACTION'))throw new RuntimeException('Could not start changed-reference fixture');
        try {
            nms_category_execute('UPDATE graph_tree_items SET local_graph_id=0 WHERE id=?',[$treeReferenceId]);
            $rejected=false;
            try {nms_workspace_consolidation_verify_transfer(['keep_id'=>$destinationId,'other_id'=>$id,'poller_id'=>1],$plan,$progress['rrd_before'],$rrdDirectory,$progress['graph_items']);}
            catch(RuntimeException $e){if($e->getMessage()!=='Permission inputs changed during transfer.')throw $e;$rejected=true;}
            if(!$rejected)throw new RuntimeException('Changed graph tree reference accepted');
        }finally{db_execute('ROLLBACK');}
        echo "PASS: changed graph-tree reference rejects transfer verification; transaction rolled back.\n";
        // Unreviewed destination assets must not be accepted as a verified transfer.
        foreach(['graph_local','data_local'] as $assetTable) {
            if(!db_execute('START TRANSACTION'))throw new RuntimeException('Could not begin extra-asset fixture');
            try {
                nms_category_execute('INSERT INTO '.$assetTable.' (host_id) VALUES (?)',[$destinationId]);
                $rejected=false;
                try {nms_workspace_consolidation_verify_transfer(['keep_id'=>$destinationId,'other_id'=>$id,'poller_id'=>1],$plan,$progress['rrd_before'],$rrdDirectory,$progress['graph_items']);}
                catch(RuntimeException $e) {if($e->getMessage()!=='Complete graph/data inventory changed during transfer.')throw $e;$rejected=true;}
                if(!$rejected)throw new RuntimeException('Unexpected destination asset was accepted');
            }finally{db_execute('ROLLBACK');}
            echo "PASS: unreviewed destination $assetTable asset rejects completion; transactional fixture rolled back.\n";
        }

    } else {
        if(!api_graph_change_device($graphId,$destinationId))throw new RuntimeException('Native graph transfer refused ordinary graph');
        $dataIds=array_values(array_unique(array_map('intval',array_column($data,'id'))));
        api_data_source_change_host($dataIds,$destinationId);
    }
    foreach($graphs as &$row)$row['host_id']=(string)$destinationId;unset($row);
    foreach($data as &$row)$row['host_id']=(string)$destinationId;unset($row);
    $afterGraphs=db_fetch_assoc_prepared('SELECT id,host_id,graph_template_id FROM graph_local WHERE host_id=? ORDER BY id',[$destinationId]);
    $afterData=db_fetch_assoc_prepared('SELECT dl.id,dl.host_id,dl.data_template_id,dtd.data_source_path FROM data_local dl JOIN data_template_data dtd ON dtd.local_data_id=dl.id WHERE dl.host_id=? ORDER BY dl.id',[$destinationId]);
    // Native DB drivers can return numeric columns as either strings or integers.
    if(json_encode($graphs,JSON_NUMERIC_CHECK)!==json_encode($afterGraphs,JSON_NUMERIC_CHECK)||json_encode($data,JSON_NUMERIC_CHECK)!==json_encode($afterData,JSON_NUMERIC_CHECK))throw new RuntimeException('Transfer changed graph/data IDs, templates or RRD path: '.json_encode(['before_graphs'=>$graphs,'after_graphs'=>$afterGraphs,'before_data'=>$data,'after_data'=>$afterData]));
    if($items!==db_fetch_assoc_prepared('SELECT id,task_item_id FROM graph_templates_item WHERE local_graph_id=? ORDER BY id',[$graphId]))throw new RuntimeException('Graph data references changed');
    if(db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_local WHERE host_id=?',[$id])||db_fetch_cell_prepared('SELECT COUNT(*) FROM data_local WHERE host_id=?',[$id]))throw new RuntimeException('Source still owns transferred assets');
    if((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM host WHERE id IN (?,?)',[$id,$destinationId])!==2)throw new RuntimeException('Native transfer removed a device');
    echo "PASS: native graph and data-source transfer retains IDs, graph items, templates and RRD path; both device records remain.\n";
    nms_workspace_consolidation_rrd_verify($rrdProof,$afterData,$rrdDirectory);
    echo "PASS: collector RRD manifest verification accepts the unchanged populated file after native transfer.\n";
    if(!is_file($rrdFile)||hash_file('sha256',$rrdFile)!==$digest||qa_rrd($fetchArgs)!==$history)throw new RuntimeException('Native consolidation transfer changed populated RRD history');
    if(($argv[3]??'')==='worker') {
        $originalRoot=$config['rra_path'];$config['rra_path']=$rrdDirectory;
        try {
            nms_category_execute("UPDATE plugin_nms_consolidation_jobs SET status='review_required' WHERE id=?",[$request]);
            nms_workspace_consolidation_recheck($request);nms_workspace_consolidation_poll(1);
            if(db_fetch_cell_prepared('SELECT status FROM plugin_nms_consolidation_jobs WHERE id=?',[$request])!=='complete')throw new RuntimeException('Completed recovery was not verified');
            echo "PASS: read-only recovery recognizes fully completed native layout.\n";
            // Simulate a manual partial restoration without repeating the worker's native actions.
            $dataIds=array_values(array_unique(array_map('intval',array_column($data,'id'))));
            api_data_source_change_host($dataIds,$id);
            nms_category_execute("UPDATE plugin_nms_consolidation_jobs SET status='review_required' WHERE id=?",[$request]);
            nms_workspace_consolidation_recheck($request);nms_workspace_consolidation_poll(1);
            if(db_fetch_cell_prepared('SELECT status FROM plugin_nms_consolidation_jobs WHERE id=?',[$request])!=='review_required')throw new RuntimeException('Partial recovery was incorrectly cleared');
            if((int)db_fetch_cell_prepared('SELECT host_id FROM graph_local WHERE id=?',[$graphId])!==$destinationId||(int)db_fetch_cell_prepared('SELECT host_id FROM data_local WHERE id=?',[$dataIds[0]])!==$id)throw new RuntimeException('Read-only recovery changed ownership');
            echo "PASS: partial layout remains blocked and recovery performs no ownership changes.\n";
            if(!api_graph_change_device($graphId,$id))throw new RuntimeException('Manual fixture restoration failed');
            api_data_source_change_host($dataIds,$id);
            nms_workspace_consolidation_recheck($request);nms_workspace_consolidation_poll(1);
            if(db_fetch_cell_prepared('SELECT status FROM plugin_nms_consolidation_jobs WHERE id=?',[$request])!=='restored')throw new RuntimeException('Original recovery layout was not verified');
            echo "PASS: read-only recovery recognizes manually restored original layout and RRD history.\n";
        }finally{$config['rra_path']=$originalRoot;}
    }
    qa_rrd(['update',$rrdFile,($start+240).':40']);
    $historicalRows=function($text)use($start){return array_values(array_filter(explode("\n",$text),function($line)use($start){return preg_match('/^([0-9]+):/',$line,$match) && (int)$match[1]<=$start+180;}));};
    if($historicalRows(qa_rrd($fetchArgs))!==$historicalRows($history) || strpos(qa_rrd(['fetch',$rrdFile,'AVERAGE','--start',(string)($start+180),'--end',(string)($start+240),'--resolution','60']),'4.0000000000e+01')===false)throw new RuntimeException('RRD history continuity failed after transfer');
    echo "PASS: populated RRD bytes and historical samples preserved; subsequent sample appends successfully.\n";
}finally {
    foreach([[$marker,$job['description']],[$destinationMarker,$destinationJob['description']]] as [$fixtureMarker,$description]) {
        $fixture=db_fetch_row_prepared('SELECT id,hostname,description FROM host WHERE external_id=?',[$fixtureMarker]);
        if(!$fixture)continue;
        if($fixture['hostname']!=='127.0.0.1'||$fixture['description']!==$description)throw new RuntimeException('Fixture changed; refusing cleanup');
        foreach(db_fetch_assoc_prepared('SELECT id FROM graph_local WHERE host_id=?',[$fixture['id']]) as $row)api_graph_remove((int)$row['id']);
        foreach(db_fetch_assoc_prepared('SELECT id FROM data_local WHERE host_id=?',[$fixture['id']]) as $row)api_data_source_remove((int)$row['id']);
        api_device_remove((int)$fixture['id']);
    }
    if(is_file($rrdFile))unlink($rrdFile);
    if(is_dir($rrdDirectory))rmdir($rrdDirectory);
}
if($beforeHosts!==db_fetch_assoc('SELECT id,hostname FROM host ORDER BY id') || $beforeGraphs!==db_fetch_assoc('SELECT id,host_id FROM graph_local ORDER BY id') || $beforeData!==db_fetch_assoc('SELECT id,host_id FROM data_local ORDER BY id'))throw new RuntimeException('Existing inventory changed');
echo "PASS: exact fixture cleaned; existing devices, graphs and data-source associations unchanged.\n";
