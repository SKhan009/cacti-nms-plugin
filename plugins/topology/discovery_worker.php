<?php
/** CLI-only queue worker, invoked by systemd as Apache; normal Cacti polling stays independent. */
if(PHP_SAPI!=='cli') {http_response_code(403);exit;}
require(__DIR__.'/../../include/cli_check.php');
require_once(__DIR__.'/includes/discovery.php');
if(!api_plugin_is_enabled('topology')) exit(0);
tp_ready();
if((int)db_fetch_cell('SELECT GET_LOCK("topology_discovery_worker",0)')!==1) exit(0);
try {
    // Acquiring the connection-owned lock proves that no previous worker is still running.
    tp_exec('UPDATE plugin_topology_jobs SET state="failed",message="Worker interrupted; retry discovery.",finished_at=NOW() WHERE state="running"');
    tp_exec('UPDATE plugin_topology_snapshots SET status="failed",error="Worker interrupted; previous evidence is historical." WHERE status="running"');
    foreach(db_fetch_assoc('SELECT * FROM plugin_topology_discovery_sites WHERE enabled=1 AND (last_queued IS NULL OR TIMESTAMPDIFF(SECOND,last_queued,NOW())>=interval_seconds)') as $policy) {
        $_SESSION=array('sess_user_id'=>(int)$policy['updated_by']);
        try {
            if(!db_fetch_cell_prepared('SELECT id FROM user_auth WHERE id=? AND enabled="on"',array($policy['updated_by']))) throw new RuntimeException('Schedule owner is disabled.');
            tp_discovery_queue($policy['unit_id']);
        } catch(Throwable $e) {
            tp_exec('INSERT INTO plugin_topology_jobs (unit_id,user_id,state,message,requested_at,finished_at) VALUES (?,?,"failed","Schedule owner is not authorized or device configuration changed. Save the policy with an authorized account.",NOW(),NOW())',array($policy['unit_id'],$policy['updated_by']));
            tp_exec('UPDATE plugin_topology_discovery_sites SET last_queued=NOW() WHERE unit_id=?',array($policy['unit_id']));
        }
    }
    $job=db_fetch_row('SELECT * FROM plugin_topology_jobs WHERE state="queued" ORDER BY id LIMIT 1');
    if(!$job) exit(0);
    tp_exec('UPDATE plugin_topology_jobs SET state="running",started_at=NOW() WHERE id=?',array($job['id']));
    try {
        if(!db_fetch_cell_prepared('SELECT id FROM user_auth WHERE id=? AND enabled="on"',array($job['user_id']))) throw new RuntimeException('Requesting user is disabled or missing.');
        $_SESSION=array('sess_user_id'=>(int)$job['user_id']);tp_require_core('topo_discovery.php');
        $hosts=tp_discovery_hosts($job['unit_id']);
        if(count($hosts)>32) throw new RuntimeException('Discovery is limited to 32 visible assigned devices.');
        $deadline=microtime(true)+180;$ok=0;$failed=0;
        foreach($hosts as $host) foreach(tp_protocols($host['protocol']) as $protocol) {
            if(tp_discovery_device($host,$protocol,$deadline)) $ok++; else $failed++;
        }
        if(!$ok && !$failed) throw new RuntimeException('No visible devices select LLDP or CDP. Set device discovery preferences first.');
        $state=$failed?($ok?'partial':'failed'):'complete';
        tp_exec('UPDATE plugin_topology_jobs SET state=?,message=?,finished_at=NOW() WHERE id=?',array($state,$ok.' protocol collections succeeded; '.$failed.' failed.',$job['id']));
        echo 'Job '.$job['id'].': '.$state."\n";
    } catch(Throwable $e) {
        tp_exec('UPDATE plugin_topology_jobs SET state="failed",message=?,finished_at=NOW() WHERE id=?',array(substr($e instanceof RuntimeException?$e->getMessage():'Discovery job failed.',0,255),$job['id']));
    }
    // Bounded diagnostic history; link observation history has its own seven-day limit.
    tp_exec('DELETE FROM plugin_topology_jobs WHERE state NOT IN ("running","queued") AND requested_at<DATE_SUB(NOW(),INTERVAL 30 DAY)');
} finally {db_fetch_cell('SELECT RELEASE_LOCK("topology_discovery_worker")');}
