<?php
/** Durable collector scan runs, explicit authorization and bounded result storage. */
require_once __DIR__.'/networks.php';
require_once __DIR__.'/scan_targets.php';
require_once __DIR__.'/audit.php';
require_once __DIR__.'/../discovery_network.php';

/** Bind queued work to all native settings and current SNMP credentials, without storing them. */
function nms_scan_signature($network,$options,$kind)
{
    $settings=$network;
    foreach(['last_started','last_runtime','last_status','next_start','up_hosts','snmp_hosts'] as $field)unset($settings[$field]);
    ksort($settings);
    $snmp=[];
    if($kind==='native')$snmp=db_fetch_assoc_prepared('SELECT * FROM automation_snmp_items WHERE snmp_id=? ORDER BY id',[$network['snmp_id']]);
    elseif(in_array('snmp',$options['methods'] ?? [],true))$snmp=nms_nd_network_profile($network,$options['snmp_item_id']);
    return hash('sha256',json_encode([$settings,$options,$snmp],JSON_THROW_ON_ERROR));
}

/** Revalidate active account, page realms and Automation at submission and before execution. */
function nms_scan_authorize($user)
{
    global $config;
    require_once $config['base_path'].'/lib/auth.php';
    if((int)db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory=?',['nms'])!==1)throw new RuntimeException('NMS is disabled or unavailable. Scan execution stopped.');
    if(!db_fetch_cell_prepared("SELECT id FROM user_auth WHERE id=? AND enabled='on'",[(int)$user]) || !is_realm_allowed(23,(int)$user))throw new RuntimeException('Scan owner no longer has Automation permission.');
    $session=$_SESSION ?? [];
    try {
        $_SESSION=['sess_user_id'=>(int)$user];
        if(!api_user_realm_auth('devices.php') || !api_user_realm_auth('network_discovery.php'))throw new RuntimeException('Scan owner no longer has NMS discovery permission.');
    } finally {$_SESSION=$session;}
}

/** Admit a bounded run without sending packets or launching a process in the web request. */
function nms_scan_enqueue($input,$kind='supplemental')
{
    nms_require_management(23);$user=nms_current_user_id();nms_scan_authorize($user);
    if(!in_array($kind,['native','supplemental'],true))throw new InvalidArgumentException('Invalid scan kind.');
    $network_id=nms_workspace_integer($input['network_id'] ?? 0,1,2147483647,'network');
    $lock='nms_scan_admit_'.$network_id;
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,2)',[$lock])!==1)throw new RuntimeException('Another scan request is being saved.');
    try {
        $network=db_fetch_row_prepared('SELECT * FROM automation_networks WHERE id=?',[$network_id]);
        if(!$network || $network['enabled']!=='on')throw new InvalidArgumentException('Enable this Cacti network before running a scan.');
        if(!db_fetch_cell_prepared("SELECT id FROM poller WHERE id=? AND disabled=''",[$network['poller_id']]))throw new RuntimeException('Assigned collector is unavailable or disabled.');
        if(db_fetch_cell_prepared("SELECT id FROM plugin_nms_scan_runs WHERE network_id=? AND status IN ('queued','dispatching','running') LIMIT 1",[$network_id]))throw new RuntimeException('This network already has an active run. Finish or cancel it first.');
        $options=[];$targets=[];$tasks=0;
        if($kind==='supplemental') {
            $text=$input['targets'] ?? '';
            if(!is_string($text))throw new InvalidArgumentException('Invalid supplemental targets.');
            $text=trim($text) ?: $network['subnet_range'];
            $targets=nms_scan_targets($text,'nms_scan_native_ipv4');
            $methods=$input['methods'] ?? [];
            if(!is_array($methods) || !$methods || count($methods)>4)throw new InvalidArgumentException('Select one to four probe methods.');
            foreach($methods as $method)if(!is_string($method) || !in_array($method,['icmp','tcp','udp','snmp'],true))throw new InvalidArgumentException('Invalid probe method.');
            $methods=array_values(array_unique($methods));
            $ports=array_intersect($methods,['tcp','udp'])?nms_nd_network_ports($input['ports'] ?? ''):[];
            $item=in_array('snmp',$methods,true)?nms_workspace_integer($input['snmp_item_id'] ?? 0,1,2147483647,'SNMP option'):0;
            if($item){$profile=nms_nd_network_profile($network,$item);if((int)$profile['snmp_timeout']>2000 || (int)$profile['snmp_retries']>2)throw new InvalidArgumentException('Use an SNMP option with timeout at most 2000 ms and at most 2 retries.');}
            $options=['targets'=>$targets,'target_expression'=>$text,'methods'=>$methods,'ports'=>$ports,'snmp_item_id'=>$item];
            $slots=0;foreach($methods as $method)$slots+=in_array($method,['tcp','udp'],true)?count($ports):1;
            $tasks=count($targets)*$slots;
        } else {
            if(!is_string($input['revision'] ?? null) || !hash_equals(nms_workspace_network_revision($network),$input['revision']))throw new RuntimeException('Network settings changed. Review automatic-add and scan settings again.');
            if(db_fetch_cell_prepared("SELECT COUNT(*) FROM automation_processes WHERE network_id=? AND status<>'done'",[$network_id]))throw new RuntimeException('A native Cacti scan is already running.');
            $options=['baseline_started'=>$network['last_started'],'baseline_runtime'=>$network['last_runtime']];
            $tasks=(int)$network['total_ips'];
        }
        $hash=nms_scan_signature($network,$options,$kind);
        db_execute('START TRANSACTION');
        try {
            nms_category_execute("INSERT INTO plugin_nms_scan_runs(network_id,poller_id,user_id,kind,config_hash,options_json,target_count,task_count,requested_at,summary_json) VALUES (?,?,?,?,?,?,?,?,NOW(),'{}')",[$network_id,$network['poller_id'],$user,$kind,$hash,json_encode($options,JSON_THROW_ON_ERROR),$kind==='native'?$tasks:count($targets),$tasks]);
            $id=(int)db_fetch_cell('SELECT LAST_INSERT_ID()');
            nms_workspace_audit('scan_queued',['run_id'=>$id,'kind'=>$kind,'target_count'=>$kind==='native'?$tasks:count($targets)],0,$network_id,$user);
            if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit scan.');
            return $id;
        } catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
    } finally {db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}

/** Cancellation is a flag, so users never wait for a long-held probe lock. */
function nms_scan_cancel($id)
{
    nms_require_management(23);nms_scan_authorize(nms_current_user_id());
    $id=nms_workspace_integer($id,1,2147483647,'run ID');
    $run=db_fetch_row_prepared('SELECT * FROM plugin_nms_scan_runs WHERE id=?',[$id]);
    if(!$run)throw new InvalidArgumentException('Scan run not found.');
    nms_category_execute("UPDATE plugin_nms_scan_runs SET cancel_requested=1 WHERE id=? AND status IN ('queued','dispatching','running')",[$id]);
    if(in_array($run['status'],['queued','dispatching','running'],true))nms_workspace_audit('scan_cancel_requested',['run_id'=>$id],0,$run['network_id']);
}

/** Fetch the current network only if permissions, assignment and settings still match. */
function nms_scan_context($run,$collector)
{
    nms_scan_authorize($run['user_id']);
    $network=db_fetch_row_prepared('SELECT * FROM automation_networks WHERE id=?',[$run['network_id']]);
    if(!$network || $network['enabled']!=='on')throw new RuntimeException('Network disabled or removed.');
    if((int)$network['poller_id']!==(int)$collector || (int)$run['poller_id']!==(int)$collector || !db_fetch_cell_prepared("SELECT id FROM poller WHERE id=? AND disabled=''",[$collector]))throw new RuntimeException('Collector changed or was disabled.');
    $options=json_decode($run['options_json'],true,512,JSON_THROW_ON_ERROR);
    if(!hash_equals($run['config_hash'],nms_scan_signature($network,$options,$run['kind'])))throw new RuntimeException('Network or SNMP settings changed. Queue a new run.');
    return [$network,$options];
}

/** Terminal state and audit entry commit together; replay never emits another terminal event. */
function nms_scan_finish($run,$status,$error='',$summary=[])
{
    if(!in_array($status,['complete','failed','cancelled'],true))throw new InvalidArgumentException('Invalid scan result.');
    db_execute('START TRANSACTION');
    try {
        $current=db_fetch_row_prepared('SELECT status FROM plugin_nms_scan_runs WHERE id=? FOR UPDATE',[$run['id']]);
        if(!$current || !in_array($current['status'],['queued','dispatching','running'],true)){db_execute('ROLLBACK');return;}
        nms_category_execute('UPDATE plugin_nms_scan_runs SET status=?,error=?,summary_json=?,finished_at=NOW(),heartbeat_at=NOW() WHERE id=?',[$status,substr($error,0,255),json_encode($summary,JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR),$run['id']]);
        nms_workspace_audit('scan_'.$status,['run_id'=>(int)$run['id'],'kind'=>$run['kind'],'error'=>substr($error,0,255)],0,$run['network_id'],$run['user_id']);
        if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit terminal scan state.');
    } catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
}

/** Store one result and advance the cursor atomically; crash recovery resumes the next task. */
function nms_scan_checkpoint($run,$cursor,$result)
{
    db_execute('START TRANSACTION');
    try {
        $current=db_fetch_row_prepared('SELECT status,progress_cursor,cancel_requested FROM plugin_nms_scan_runs WHERE id=? FOR UPDATE',[$run['id']]);
        if(!$current || $current['status']!=='running' || (int)$current['progress_cursor']!==$cursor)throw new RuntimeException('Scan progress changed before the result could be saved.');
        if($current['cancel_requested']){db_execute('ROLLBACK');return false;}
        nms_category_execute('INSERT INTO plugin_nms_scan_results(run_id,ordinal,address,method,port,reachable,detail,checked_at) VALUES (?,?,?,?,?,?,?,NOW())',[$run['id'],$cursor,$result['ip'],$result['method'],$result['port'],!empty($result['reachable'])?1:0,substr($result['detail'],0,1024)]);
        nms_category_execute('UPDATE plugin_nms_scan_runs SET progress_cursor=?,heartbeat_at=NOW() WHERE id=? AND progress_cursor=?',[$cursor+1,$run['id'],$cursor]);
        if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit probe result.');
        return true;
    } catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
}

/** Execute one small slice, checking cancellation and current authorization before each probe. */
function nms_scan_supplemental_step($run,$collector,$deadline,$probe=null,$max_tasks=32)
{
    $lock='nms_scan_run_'.$run['id'];
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,0)',[$lock])!==1)return;
    try {
        $run=db_fetch_row_prepared('SELECT * FROM plugin_nms_scan_runs WHERE id=?',[$run['id']]);
        if(!$run || !in_array($run['status'],['queued','running'],true))return;
        if(time()-strtotime($run['requested_at'])>86400)throw new RuntimeException('Run exceeded its 24-hour lifetime; partial results retained.');
        [$network,$options]=nms_scan_context($run,$collector);
        nms_category_execute("UPDATE plugin_nms_scan_runs SET status='running',started_at=COALESCE(started_at,NOW()),heartbeat_at=NOW() WHERE id=?",[$run['id']]);
        for($i=0;$i<$max_tasks && microtime(true)<$deadline;$i++) {
            $run=db_fetch_row_prepared('SELECT * FROM plugin_nms_scan_runs WHERE id=?',[$run['id']]);
            if($run['cancel_requested']){nms_scan_finish($run,'cancelled','Cancelled; completed probe results retained.');return;}
            [$network,$options]=nms_scan_context($run,$collector);
            $cursor=(int)$run['progress_cursor'];
            $task=nms_scan_task($options['targets'],$options['methods'],$options['ports'],$cursor);
            if(!$task){nms_scan_finish($run,'complete','',['tasks'=>$cursor,'targets'=>count($options['targets'])]);return;}
            try {$result=$probe?$probe($task,$network,$options):nms_scan_probe($task,$network,$options);}
            catch(Throwable $e){$result=$task+['reachable'=>false,'detail'=>$e instanceof RuntimeException?$e->getMessage():'Probe failed.'];}
            // A final probe may outlive permission/configuration changes or cancellation.
            // Do not publish its result merely because admission succeeded before I/O.
            $current=db_fetch_row_prepared('SELECT * FROM plugin_nms_scan_runs WHERE id=?',[$run['id']]);
            if(!$current || $current['status']!=='running')return;
            if($current['cancel_requested']){nms_scan_finish($current,'cancelled','Cancelled; completed probe results retained.');return;}
            nms_scan_context($current,$collector);
            if(nms_scan_checkpoint($current,$cursor,$result)===false){nms_scan_finish($current,'cancelled','Cancelled; completed probe results retained.');return;}
            if($cursor+1===(int)$run['task_count']){nms_scan_finish($run,'complete','',['tasks'=>$cursor+1,'targets'=>count($options['targets'])]);return;}
        }
    } catch(Throwable $e){if($run)nms_scan_finish($run,'failed',$e->getMessage());}
    finally {db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}

/** Paginated history includes completed runs even after their native network was removed. */
function nms_scan_history($network_id=0,$page=1)
{
    nms_require_management(23);$page=max(1,min(100000,(int)$page));
    $params=[];$where='';if($network_id){$where=' WHERE r.network_id=?';$params[]=(int)$network_id;}
    $total=(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_scan_runs r'.$where,$params);$pages=max(1,(int)ceil($total/25));$page=min($page,$pages);
    $rows=db_fetch_assoc_prepared('SELECT r.id,r.network_id,r.kind,r.poller_id,r.status,r.target_count,r.task_count,r.progress_cursor,r.requested_at,r.started_at,r.finished_at,r.cancel_requested,r.error,r.summary_json,n.name FROM plugin_nms_scan_runs r LEFT JOIN automation_networks n ON n.id=r.network_id'.$where.' ORDER BY r.id DESC LIMIT 25 OFFSET '.(($page-1)*25),$params);
    return compact('rows','page','pages','total');
}
