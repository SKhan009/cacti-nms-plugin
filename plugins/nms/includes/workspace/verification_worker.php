<?php
require_once __DIR__.'/verification.php';
require_once __DIR__.'/../discovery_network.php';
require_once __DIR__.'/../discovery_snmp.php';

/** Short collector task. A lost running check fails instead of silently repeating probes. */
function nms_workspace_verification_poll($collector)
{
    if(PHP_SAPI!=='cli')return;
    $lock='nms_candidate_worker_'.(int)$collector;
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,0)',[$lock])!==1)return;
    $session=$_SESSION??[];
    try {
        $job=db_fetch_row_prepared("SELECT * FROM plugin_nms_candidate_checks WHERE poller_id=? AND status IN ('queued','running') ORDER BY id LIMIT 1",[$collector]);
        if(!$job)return;
        try {
            if($job['cancel_requested']){nms_workspace_verification_finish($job,'cancelled');return;}
            if($job['status']==='running')throw new RuntimeException('Previous collector execution stopped. Submit a new verification after reviewing the result.');
            if(time()-strtotime($job['requested_at'])>900)throw new RuntimeException('Verification expired before execution. Refresh the candidate and retry.');
            if(!db_fetch_cell_prepared("SELECT id FROM user_auth WHERE id=? AND enabled='on'",[$job['user_id']]))throw new RuntimeException('Requesting account is disabled or missing.');
            $_SESSION=['sess_user_id'=>(int)$job['user_id']];
            [$candidate,$host]=nms_workspace_verification_context((int)$job['reporter_id'],$job['candidate_id']);
            if((int)$candidate['poller_id']!==(int)$collector || !hash_equals($job['config_hash'],nms_workspace_verification_hash($candidate,$host)) || $job['target']!==$candidate['target'])throw new RuntimeException('Candidate or collector settings changed. Review and verify again.');
            nms_category_execute("UPDATE plugin_nms_candidate_checks SET status='running',started_at=NOW() WHERE id=?",[$job['id']]);
            $ping=nms_nd_network_probe($candidate['target'],'icmp',1,500);
            if(db_fetch_cell_prepared('SELECT cancel_requested FROM plugin_nms_candidate_checks WHERE id=?',[$job['id']])){nms_workspace_verification_finish($job,'cancelled');return;}
            $host['hostname']=$candidate['target'];
            $identity=nms_nd_collect_identity($host,microtime(true)+18);
            $usable=!empty($identity['hardware']['chassis']) || !empty($identity['own_addresses']) || !empty($identity['interfaces']) || isset($identity['uptime']);
            $result=['target'=>$candidate['target'],'collector_id'=>(int)$collector,'checked_at'=>date('Y-m-d H:i:s'),'ping'=>$ping,
                'identity'=>$identity,'identity_usable'=>$usable,'identity_note'=>$usable?'SNMP returned identity evidence; physical identity still requires review.':'No usable SNMP identity evidence; do not infer authentication success or device failure.'];
            // Recheck permission/configuration after network work, before publishing verification.
            [$current,$currentHost]=nms_workspace_verification_context((int)$job['reporter_id'],$job['candidate_id']);
            if(!hash_equals($job['config_hash'],nms_workspace_verification_hash($current,$currentHost)))throw new RuntimeException('Candidate changed during verification. Review and verify again.');
            nms_workspace_verification_finish($job,'complete',$result);
        }catch(Throwable $e){nms_workspace_verification_finish($job,'failed',[],$e->getMessage());}
    }finally{$_SESSION=$session;db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}
