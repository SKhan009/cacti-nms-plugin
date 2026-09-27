<?php
require_once __DIR__.'/consolidation_jobs.php';
require_once __DIR__.'/consolidation_rrd.php';
require_once __DIR__.'/consolidation_writes.php';

/** Every durable transition and its audit commit together, before any next native side effect. */
function nms_workspace_consolidation_transition($job,$from,$to,$progress=[],$result=[],$error='')
{
    if(!db_execute('START TRANSACTION'))throw new RuntimeException('Could not begin transfer transition.');
    try {
        $status=db_fetch_cell_prepared('SELECT status FROM plugin_nms_consolidation_jobs WHERE id=? FOR UPDATE',[$job['id']]);
        if(!in_array($status,$from,true)){db_execute('ROLLBACK');return false;}
        nms_category_execute('UPDATE plugin_nms_consolidation_jobs SET status=?,progress_json=?,result_json=?,error=?,started_at=COALESCE(started_at,NOW()),finished_at=IF(? IN (\'complete\',\'failed\',\'review_required\',\'restored\'),NOW(),finished_at) WHERE id=?',[$to,json_encode($progress,JSON_THROW_ON_ERROR),json_encode($result,JSON_THROW_ON_ERROR),substr($error,0,255),$to,$job['id']]);
        foreach([$job['keep_id'],$job['other_id']] as $host)nms_workspace_audit('consolidation_'.$to,['job_id'=>(int)$job['id'],'phase'=>$progress['phase']??''],$host,0,$job['user_id']);
        if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit transfer transition.');return true;
    }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
}

/** Recheck mutable admission before each native step and before accepting its result. */
function nms_workspace_consolidation_authorize_execution($job)
{
    if((int)$job['poller_id']!==1)throw new RuntimeException('Consolidation requires the verified local collector.');
    if((int)db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory=?',['nms'])!==1||!db_fetch_cell_prepared("SELECT id FROM poller WHERE id=? AND disabled=''",[$job['poller_id']]))throw new RuntimeException('Plugin or collector is unavailable.');
    nms_workspace_authorize_current((int)$job['keep_id']);nms_workspace_authorize_current((int)$job['other_id']);
}

function nms_workspace_consolidation_worker_context($job,$collector)
{
    if((int)$job['poller_id']!==(int)$collector || $collector!==1)throw new RuntimeException('Transfer belongs to an unsupported or different collector.');
    nms_workspace_consolidation_authorize_execution($job);
    $plan=nms_workspace_consolidation_plan($job['keep_id'],$job['other_id']);
    if(!hash_equals($job['revision'],$plan['revision']))throw new RuntimeException('Reviewed consolidation plan changed before execution.');
    if($plan['transfer_preflight']['status']!=='structurally_eligible'||$plan['permission_preflight']['status']!=='native_inputs_equivalent')throw new RuntimeException('Transfer preflight is no longer satisfied.');
    nms_workspace_consolidation_pending_writes($plan['transfer_preflight']['data_ids'],$collector);
    return $plan;
}

/** Use the native APIs verified for ordinary local graphs; never delete either device. */
function nms_workspace_consolidation_native_graph($graph,$keep)
{
    global $config;require_once $config['base_path'].'/lib/api_graph.php';
    if(!api_graph_change_device((int)$graph,(int)$keep))throw new RuntimeException('Cacti refused a graph transfer.');
}
function nms_workspace_consolidation_native_data($data,$keep)
{
    global $config;require_once $config['base_path'].'/lib/utility.php';require_once $config['base_path'].'/lib/api_data_source.php';
    api_data_source_change_host(array_map('intval',$data),(int)$keep);
}

/** Verify native IDs, query mappings, paths, graph-item references, ACL inputs and retained records. */
function nms_workspace_consolidation_verify_transfer($job,$plan,$proof,$root,$items,$moved=true)
{
    nms_workspace_consolidation_authorize_execution($job);
    $permissions=nms_workspace_consolidation_permissions($job['keep_id'],$job['other_id']);
    if(!hash_equals($plan['permission_preflight']['fingerprint'],$permissions['fingerprint']))throw new RuntimeException('Permission inputs changed during transfer.');
    foreach($plan['devices'] as $device) {
        $current=db_fetch_row_prepared("SELECT id,description,hostname,poller_id,site_id,host_template_id,disabled FROM host WHERE id=? AND deleted=''",[$device['host']['id']]);
        if($current!==$device['host'])throw new RuntimeException('Device record changed during transfer.');
    }
    $graphs=$plan['transfer_preflight']['graph_ids'];$data=$plan['transfer_preflight']['data_ids'];
    foreach($plan['devices'] as $device)foreach($device['graphs'] as $graph) {
        $owner=$moved?$job['keep_id']:$device['host']['id'];
        $current=db_fetch_row_prepared('SELECT id,graph_template_id,snmp_query_id,snmp_index FROM graph_local WHERE id=? AND host_id=?',[$graph['id'],$owner]);
        if($current!==$graph)throw new RuntimeException('Graph transfer postcondition failed.');
    }
    if($moved && (db_fetch_cell_prepared('SELECT id FROM graph_local WHERE host_id=? LIMIT 1',[$job['other_id']])||db_fetch_cell_prepared('SELECT id FROM data_local WHERE host_id=? LIMIT 1',[$job['other_id']])))throw new RuntimeException('Source still owns assets after transfer.');
    $dataOwner=$moved?$job['keep_id']:$job['other_id'];
    $currentData=[];
    if($data)$currentData=nms_workspace_admission_rows('SELECT d.id,d.data_template_id,d.snmp_query_id,d.snmp_index,t.data_source_path,t.data_source_profile_id,t.rrd_step FROM data_local d LEFT JOIN data_template_data t ON t.local_data_id=d.id WHERE d.host_id=? AND d.id IN ('.implode(',',$data).') ORDER BY d.id,t.id',[$dataOwner]);
    if($currentData!==$plan['devices'][1]['data_sources'])throw new RuntimeException('Data-source transfer postcondition failed.');
    $currentItems=$graphs?nms_workspace_admission_rows('SELECT id,local_graph_id,task_item_id FROM graph_templates_item WHERE local_graph_id IN ('.implode(',',$graphs).') ORDER BY id'):[];
    if($currentItems!==$items)throw new RuntimeException('Graph data references changed during transfer.');
    $existingData=$plan['devices'][0]['data_sources'];
    foreach($existingData as $existing) {
        $rows=nms_workspace_admission_rows('SELECT d.id,d.data_template_id,d.snmp_query_id,d.snmp_index,t.data_source_path,t.data_source_profile_id,t.rrd_step FROM data_local d LEFT JOIN data_template_data t ON t.local_data_id=d.id WHERE d.id=? AND d.host_id=? ORDER BY t.id',[$existing['id'],$job['keep_id']]);
        if(!in_array($existing,$rows,true))throw new RuntimeException('Preexisting destination data source changed.');
    }
    // Verify complete ownership sets, including additions made concurrently to either record.
    // Checking only reviewed IDs would incorrectly accept an unexpected destination asset.
    foreach($plan['devices'] as $device) {
        $hostId=(int)$device['host']['id'];
        $expectedGraphs=$device['graphs'];$expectedData=$device['data_sources'];
        if($moved) {
            $expectedGraphs=$hostId===(int)$job['keep_id']?array_merge($plan['devices'][0]['graphs'],$plan['devices'][1]['graphs']):[];
            $expectedData=$hostId===(int)$job['keep_id']?array_merge($plan['devices'][0]['data_sources'],$plan['devices'][1]['data_sources']):[];
        }
        usort($expectedGraphs,static fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);
        usort($expectedData,static fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);
        $actualGraphs=nms_workspace_admission_rows('SELECT id,graph_template_id,snmp_query_id,snmp_index FROM graph_local WHERE host_id=? ORDER BY id LIMIT 4001',[$hostId]);
        $actualData=nms_workspace_admission_rows('SELECT d.id,d.data_template_id,d.snmp_query_id,d.snmp_index,t.data_source_path,t.data_source_profile_id,t.rrd_step FROM data_local d LEFT JOIN data_template_data t ON t.local_data_id=d.id WHERE d.host_id=? ORDER BY d.id,t.id LIMIT 4001',[$hostId]);
        if($actualGraphs!==$expectedGraphs||$actualData!==$expectedData)throw new RuntimeException('Complete graph/data inventory changed during transfer.');
    }
    nms_workspace_consolidation_pending_writes($data,(int)$job['poller_id']);
    nms_workspace_consolidation_rrd_verify($proof,$currentData,$root);
    return ['graph_ids'=>$graphs,'data_ids'=>$data,'rrd_files_verified'=>count($proof),'device_records_retained'=>true,'retirement'=>'not_performed','layout'=>$moved?'transferred':'original'];
}

/** Classify a stopped operation solely from retained proof and current native state. No writes to assets. */
function nms_workspace_consolidation_recover($job,$progress,$root)
{
    $plan=json_decode($job['plan_json'],true,512,JSON_THROW_ON_ERROR);
    if(!is_array($plan)||!isset($plan['revision'],$progress['rrd_before'],$progress['graph_items'])||!is_array($progress['rrd_before'])||!is_array($progress['graph_items']))throw new RuntimeException('Recovery proof is incomplete; manual inspection is required.');
    $revision=$plan['revision'];unset($plan['revision']);
    if(!hash_equals($job['revision'],$revision)||!hash_equals($revision,hash('sha256',json_encode($plan,JSON_THROW_ON_ERROR)))||(int)$plan['keep_id']!==(int)$job['keep_id']||(int)$plan['other_id']!==(int)$job['other_id'])throw new RuntimeException('Stored recovery plan is inconsistent.');
    try {
        $result=nms_workspace_consolidation_verify_transfer($job,$plan,$progress['rrd_before'],$root,$progress['graph_items']);
        return ['status'=>'complete','result'=>$result];
    }catch(Throwable $transferredError) {
        try {
            $result=nms_workspace_consolidation_verify_transfer($job,$plan,$progress['rrd_before'],$root,$progress['graph_items'],false);
            return ['status'=>'restored','result'=>$result];
        }catch(Throwable $originalError){throw new RuntimeException('Neither completed nor original layout could be verified. Keep both records and resolve the partial transfer manually.');}
    }
}

function nms_workspace_consolidation_poll($collector)
{
    global $config;
    if(PHP_SAPI!=='cli')return;
    $collector=(int)$collector;$locks=[];$session=$_SESSION??[];
    $lock='nms_consolidation_worker_'.$collector;
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,0)',[$lock])!==1)return;$locks[]=$lock;
    try {
        $job=db_fetch_row_prepared("SELECT * FROM plugin_nms_consolidation_jobs WHERE poller_id=? AND status IN ('queued','verifying','applying','queued_recovery') ORDER BY id LIMIT 1",[$collector]);
        if(!$job)return;
        $progress=json_decode($job['progress_json'],true)?:[];$applying=in_array($job['status'],['applying','queued_recovery'],true);
        try {
            if(!in_array($job['status'],['queued','queued_recovery'],true))throw new RuntimeException('Previous transfer worker stopped. No native action was replayed.');
            if($job['status']==='queued' && time()-strtotime($job['requested_at'])>300)throw new RuntimeException('Transfer request expired. Review the current plan again.');
            $_SESSION=['sess_user_id'=>(int)$job['user_id']];
            $hostIds=[(int)$job['keep_id'],(int)$job['other_id']];sort($hostIds);
            foreach($hostIds as $host) {
                $lock='nms_management_ip_'.$host;
                if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,0)',[$lock])!==1)return;$locks[]=$lock;
            }
            if($job['status']==='queued_recovery') {
                nms_workspace_consolidation_authorize_execution($job);
                $recovery=nms_workspace_consolidation_recover($job,$progress,$config['rra_path']);
                $progress['phase']='recovery_verified';
                nms_workspace_consolidation_transition($job,['queued_recovery'],$recovery['status'],$progress,$recovery['result']);return;
            }
            $plan=nms_workspace_consolidation_worker_context($job,$collector);
            if(!nms_workspace_consolidation_transition($job,['queued'],'verifying',['phase'=>'rrd_verification']))return;
            $root=$config['rra_path'];$proof=nms_workspace_consolidation_rrd_manifest($plan['devices'][1]['data_sources'],$root);
            $graphs=$plan['transfer_preflight']['graph_ids'];$data=$plan['transfer_preflight']['data_ids'];
            $items=$graphs?nms_workspace_admission_rows('SELECT id,local_graph_id,task_item_id FROM graph_templates_item WHERE local_graph_id IN ('.implode(',',$graphs).') ORDER BY id'):[];
            nms_workspace_consolidation_worker_context($job,$collector);
            $progress=['phase'=>'before_native_transfer','rrd_before'=>$proof,'graph_items'=>$items,'completed_graphs'=>[]];
            if(!nms_workspace_consolidation_transition($job,['verifying'],'applying',$progress))return;
            $applying=true;
            foreach($graphs as $graph) {
                nms_workspace_consolidation_authorize_execution($job);
                $progress['phase']='graph_transfer';$progress['current_graph']=$graph;
                if(!nms_workspace_consolidation_transition($job,['applying'],'applying',$progress))throw new RuntimeException('Transfer state changed.');
                nms_workspace_consolidation_native_graph($graph,$job['keep_id']);$progress['completed_graphs'][]=$graph;
            }
            nms_workspace_consolidation_authorize_execution($job);
            $progress['phase']='data_source_refresh';unset($progress['current_graph']);
            if(!nms_workspace_consolidation_transition($job,['applying'],'applying',$progress))throw new RuntimeException('Transfer state changed.');
            if($data)nms_workspace_consolidation_native_data($data,$job['keep_id']);
            $result=nms_workspace_consolidation_verify_transfer($job,$plan,$proof,$root,$items);$progress['phase']='verified';
            if(!nms_workspace_consolidation_transition($job,['applying'],'complete',$progress,$result))throw new RuntimeException('Could not finalize transfer.');
        }catch(Throwable $e){nms_workspace_consolidation_transition($job,['queued','verifying','applying','queued_recovery'],$applying?'review_required':'failed',$progress,[],$e->getMessage());}
    }finally{$_SESSION=$session;foreach(array_reverse($locks) as $lock)db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}
