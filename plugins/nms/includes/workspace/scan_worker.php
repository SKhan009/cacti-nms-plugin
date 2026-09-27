<?php
/** Collector-only scan execution. Network I/O is never performed while rendering a page. */
require_once __DIR__.'/scans.php';
require_once __DIR__.'/../diagnostics_queue.php';

/** Use native transports with an explicit finite SNMP request budget. */
function nms_scan_probe($task,$network,$options)
{
    if($task['method']==='snmp') {
        $profile=nms_nd_network_profile($network,$options['snmp_item_id']);
        if((int)$profile['snmp_timeout']>2000 || (int)$profile['snmp_retries']>2)throw new RuntimeException('Supplemental SNMP scans require timeout at most 2000 ms and at most 2 retries.');
        $r=nms_nd_network_snmp_probe($task['ip'],$network,['snmp_item_id'=>$options['snmp_item_id']]);
    } else $r=nms_nd_network_probe($task['ip'],$task['method'],$task['port'] ?? 1,500);
    $r['ip']=$task['ip'];return $r;
}

/** Run small supplemental slices and dispatch isolated native controllers; never wait for a full native scan. */
function nms_scan_poll($collector)
{
    global $config;
    if(PHP_SAPI!=='cli')return;
    require_once $config['base_path'].'/lib/poller.php';
    $deadline=microtime(true)+12;
    $runs=db_fetch_assoc_prepared("SELECT * FROM plugin_nms_scan_runs WHERE poller_id=? AND status IN ('queued','dispatching','running') ORDER BY COALESCE(heartbeat_at,'1970-01-01'),id LIMIT 20",[$collector]);
    foreach($runs as $run) {
        if(microtime(true)>$deadline)break;
        if($run['kind']==='supplemental'){nms_scan_supplemental_step($run,$collector,$deadline);continue;}
        $lock='nms_scan_run_'.$run['id'];
        if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,0)',[$lock])!==1)continue;
        try {
            $run=db_fetch_row_prepared('SELECT * FROM plugin_nms_scan_runs WHERE id=?',[$run['id']]);
            if(!in_array($run['status'],['queued','dispatching','running'],true))continue;
            if($run['cancel_requested']){nms_scan_finish($run,'cancelled','Cancelled before native worker execution.');continue;}
            if($run['status']==='queued') {
                if(time()-strtotime($run['requested_at'])>86400)throw new RuntimeException('Native run expired before dispatch. Review current settings and queue a new run.');
                nms_scan_context($run,$collector);
                nms_category_execute("UPDATE plugin_nms_scan_runs SET status='dispatching',heartbeat_at=NOW() WHERE id=?",[$run['id']]);
                exec_background(PHP_BINARY,['-q',dirname(__DIR__,2).'/workspace_native.php','--run='.(int)$run['id']]);
            } elseif(strtotime($run['heartbeat_at'] ?: $run['requested_at'])<time()-120) {
                // A lost worker is never restarted: native auto-add may already have run.
                // No owned process handle remains. A newer native run may use this network.
                nms_scan_finish($run,'failed','Native worker stopped or failed to start. Check Cacti Automation for remaining processes; no unowned process was cancelled and this run was not replayed.');
            }
        } catch(Throwable $e){nms_scan_finish($run,'failed',$e->getMessage());}
        finally {db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
}

/** Give the owned native controller time to run its SIGTERM child cleanup. */
function nms_scan_stop_process($process,$pipes)
{
    $state=proc_get_status($process);
    if(!$state['running'])return;
    proc_terminate($process);
    $deadline=microtime(true)+7;
    do {
        foreach($pipes as $pipe)if(is_resource($pipe))stream_get_contents($pipe,65536);
        usleep(100000);
        $state=proc_get_status($process);
    } while($state['running'] && microtime(true)<$deadline);
    if($state['running'])proc_terminate($process,9);
}

/** Snapshot sanitized native discovered rows belonging to this scan start; never store native credentials. */
function nms_scan_native_results($run)
{
    $rows=db_fetch_assoc_prepared('SELECT ip,up,snmp,sysName,sysDescr FROM automation_devices WHERE network_id=? AND time>=UNIX_TIMESTAMP(?) ORDER BY id LIMIT 65536',[$run['network_id'],$run['started_at']]);
    $i=0;
    foreach($rows as $row) {
        if($i>=65536)break;
        nms_category_execute('INSERT INTO plugin_nms_scan_results(run_id,ordinal,address,method,port,reachable,detail,checked_at) VALUES (?,?,?,?,NULL,?,?,NOW())',[$run['id'],$i++,$row['ip'],'native',(int)$row['up'],substr(($row['snmp']?'SNMP: ':'Reachability: ').$row['sysName'].' '.$row['sysDescr'],0,1024)]);
    }
    return $i;
}

/** Recheck after process exit; the last periodic check may precede a state change. */
function nms_scan_native_final_check($run,$collector,$lock,$connection)
{
    if((int)db_fetch_cell_prepared('SELECT IS_USED_LOCK(?)',[$lock])!==(int)$connection)return 'Worker lost its database lock; native result publication stopped.';
    $current=db_fetch_row_prepared('SELECT * FROM plugin_nms_scan_runs WHERE id=?',[$run['id']]);
    if(!$current || $current['status']!=='running')return 'Native scan state changed before result publication.';
    if($current['cancel_requested'])return 'Cancelled by user.';
    try {nms_scan_context($current,$collector);}catch(Throwable $e){return $e->getMessage();}
    return '';
}

/** Observe an owned native Cacti subprocess, retain its exit status, and honor cancellation/revocation. */
function nms_scan_native_execute($id,$collector)
{
    global $config;
    $lock='nms_scan_run_'.(int)$id;
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,5)',[$lock])!==1)return;
    $connection=(int)db_fetch_cell('SELECT CONNECTION_ID()');
    $run=null;$process=null;$pipes=[];$interrupted='';
    try {
        $run=db_fetch_row_prepared('SELECT * FROM plugin_nms_scan_runs WHERE id=?',[(int)$id]);
        if(!$run || $run['kind']!=='native' || $run['status']!=='dispatching')return;
        if($run['cancel_requested']){nms_scan_finish($run,'cancelled','Cancelled before native start.');return;}
        [$network,$options]=nms_scan_context($run,$collector);
        if(db_fetch_cell_prepared("SELECT COUNT(*) FROM automation_processes WHERE network_id=? AND status<>'done'",[$run['network_id']]))throw new RuntimeException('Native Cacti discovery already active for this network.');
        nms_category_execute("UPDATE plugin_nms_scan_runs SET status='running',started_at=NOW(),heartbeat_at=NOW() WHERE id=?",[$run['id']]);
        $run=db_fetch_row_prepared('SELECT * FROM plugin_nms_scan_runs WHERE id=?',[$run['id']]);
        $command=[PHP_BINARY,'-q',$config['base_path'].'/poller_automation.php','--poller='.(int)$collector,'--network='.(int)$run['network_id'],'--force'];
        $process=@proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($process))throw new RuntimeException('Native Cacti process could not start.');
        $native_pid=(int)proc_get_status($process)['pid'];
        fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        $until=microtime(true)+max(60,min(86400,(int)$network['run_limit']))+60;
        $lastCheck=0;$stopAt=0;$exit=-1;$output='';$errors='';$truncated=false;
        while(true) {
            nms_diag_drain($pipes[1],$output,$truncated,65536);nms_diag_drain($pipes[2],$errors,$truncated,65536);
            $state=proc_get_status($process);
            if(!$state['running']){$exit=(int)$state['exitcode'];break;}
            if(microtime(true)-$lastCheck>=1) {
                if((int)db_fetch_cell_prepared('SELECT IS_USED_LOCK(?)',[$lock])!==$connection)$interrupted='Worker lost its database lock; native execution stopped.';
                $latest=db_fetch_row_prepared('SELECT * FROM plugin_nms_scan_runs WHERE id=?',[$run['id']]);
                if(!$latest || $latest['cancel_requested'])$interrupted='Cancelled by user.';
                if(!$interrupted)try{nms_scan_context($run,$collector);}catch(Throwable $e){$interrupted=$e->getMessage();}
                if(microtime(true)>$until)$interrupted='Native scan exceeded its configured run limit.';
                if($interrupted && !$stopAt) {
                    nms_category_execute("UPDATE automation_processes SET command='cancel' WHERE network_id=? AND poller_id=? AND pid=? AND task='tmaster'",[$run['network_id'],$collector,$native_pid]);
                    $stopAt=microtime(true)+6;
                }
                if($stopAt && microtime(true)>$stopAt){nms_scan_stop_process($process,$pipes);break;}
                $progress=(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM automation_ips WHERE network_id=? AND status=2',[$run['network_id']]);
                nms_category_execute('UPDATE plugin_nms_scan_runs SET progress_cursor=GREATEST(progress_cursor,?),heartbeat_at=NOW() WHERE id=?',[$progress,$run['id']]);
                $lastCheck=microtime(true);
            }
            usleep(100000);
        }
        foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);$pipes=[];$closed=proc_close($process);$process=null;if($exit<0)$exit=$closed;
        $latest=db_fetch_row_prepared('SELECT * FROM automation_networks WHERE id=?',[$run['network_id']]);
        if(!$interrupted)$interrupted=nms_scan_native_final_check($run,$collector,$lock,$connection);
        $count=$interrupted?0:nms_scan_native_results($run);
        $summary=['exit'=>$exit,'retained_results'=>$count,'up_hosts'=>(int)($latest['up_hosts']??0),'snmp_hosts'=>(int)($latest['snmp_hosts']??0),'native_started'=>$latest['last_started']??null,'runtime_seconds'=>(float)($latest['last_runtime']??0)];
        $started=$latest && strtotime($latest['last_started'])>=strtotime($run['started_at']);
        $finished=$latest && (string)$latest['last_runtime']!==(string)$options['baseline_runtime'];
        if(!$interrupted && $exit===0 && $started && $finished) {
            nms_category_execute('UPDATE plugin_nms_scan_runs SET progress_cursor=task_count WHERE id=?',[$run['id']]);
            nms_scan_finish($run,'complete','',$summary);
        } else nms_scan_finish($run,strpos($interrupted,'Cancelled by user')===0?'cancelled':'failed',$interrupted ?: 'Native discovery did not report a successful owned process exit with updated start and completion timing.',$summary);
    } catch(Throwable $e){if($run)nms_scan_finish($run,'failed',$e->getMessage());}
    finally {
        if(is_resource($process)){nms_scan_stop_process($process,$pipes);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}
        db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);
    }
}
