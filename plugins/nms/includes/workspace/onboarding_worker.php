<?php
require_once __DIR__.'/onboarding.php';

/** Stable native external_id makes interrupted creation detectable without another create call. */
function nms_workspace_onboarding_marker($id){return 'nms-onboarding-'.(int)$id;}

function nms_workspace_onboarding_native_create($job,$host)
{
    global $config;
    require_once $config['base_path'].'/plugins/nms/includes/device_manager.php';
    return (int)api_device_save(0,(int)$job['template_id'],$job['description'],$job['target'],
        $host['snmp_community'],(int)$host['snmp_version'],$host['snmp_username'],$host['snmp_password'],
        (int)$host['snmp_port'],(int)$host['snmp_timeout'],'',
        (int)$host['availability_method'],(int)$host['ping_method'],(int)$host['ping_port'],(int)$host['ping_timeout'],(int)$host['ping_retries'],
        'Added after NMS neighbour verification.', $host['snmp_auth_protocol'],$host['snmp_priv_passphrase'],$host['snmp_priv_protocol'],
        $host['snmp_context'],$host['snmp_engine_id'],(int)$host['max_oids'],(int)$host['device_threads'],
        (int)$job['poller_id'],(int)$job['site_id'],nms_workspace_onboarding_marker($job['id']),'',-1);
}

/** Persist the native result and audit together; never delete a partially created Cacti device. */
function nms_workspace_onboarding_finish($job,$status,$host_id=0,$error='')
{
    if(!in_array($status,['created','failed','review_required','cancelled'],true))throw new InvalidArgumentException('Invalid onboarding result.');
    if(!db_execute('START TRANSACTION'))throw new RuntimeException('Could not begin onboarding result.');
    try {
        $current=db_fetch_row_prepared('SELECT status FROM plugin_nms_onboarding_requests WHERE id=? FOR UPDATE',[$job['id']]);
        if(!$current || !in_array($current['status'],['queued','applying'],true)){db_execute('ROLLBACK');return;}
        nms_category_execute('UPDATE plugin_nms_onboarding_requests SET status=?,host_id=?,error=?,finished_at=NOW() WHERE id=?',[$status,$host_id?:null,substr($error,0,255),$job['id']]);
        nms_workspace_audit('candidate_onboarding_'.$status,['request_id'=>(int)$job['id'],'host_id'=>$host_id?:null],$job['reporter_id'],0,$job['user_id']);
        if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit onboarding result.');
    }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
}

/** Run only on the assigned collector. Native creation may run template queries and automation. */
function nms_workspace_onboarding_poll($collector)
{
    if(PHP_SAPI!=='cli')return;
    $lock='nms_reviewed_onboarding';
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,0)',[$lock])!==1)return;
    $session=$_SESSION??[];
    try {
        $job=db_fetch_row_prepared("SELECT * FROM plugin_nms_onboarding_requests WHERE poller_id=? AND status IN ('queued','applying') ORDER BY id LIMIT 1",[$collector]);
        if(!$job)return;
        $host_id=0;
        try {
            // An applying record may have executed native side effects. It is never automatically replayed.
            if($job['status']==='applying') {
                $existing=nms_workspace_admission_rows('SELECT id FROM host WHERE external_id=?',[nms_workspace_onboarding_marker($job['id'])]);
                nms_workspace_onboarding_finish($job,'review_required',count($existing)===1?(int)$existing[0]['id']:0,'Creation was interrupted. Inspect native device/template state before taking further action; no create was repeated.');return;
            }
            if(time()-strtotime($job['requested_at'])>900)throw new RuntimeException('Onboarding request expired. Verify and review again.');
            if(!db_fetch_cell_prepared("SELECT id FROM user_auth WHERE id=? AND enabled='on'",[$job['user_id']]))throw new RuntimeException('Requesting account is unavailable.');
            $_SESSION=['sess_user_id'=>(int)$job['user_id']];
            $plan=nms_workspace_onboarding_plan($job['check_id'],$job['template_id'],$job['description']);
            if((int)$plan['poller_id']!==(int)$collector || !hash_equals($job['review_hash'],$plan['revision']))throw new RuntimeException('Review, template or verification evidence changed. Review again.');
            if($plan['admission']['blocked'])throw new RuntimeException('Existing-device evidence requires review before onboarding.');
            [$candidate,$host]=nms_workspace_verification_context((int)$job['reporter_id'],$plan['candidate_id']);
            $existing=nms_workspace_admission_rows('SELECT id FROM host WHERE external_id=?',[nms_workspace_onboarding_marker($job['id'])]);
            if($existing)throw new RuntimeException('The onboarding reference already exists in Cacti. Administrator review is required.');
            // Commit this boundary before invoking core APIs with non-transactional side effects.
            nms_category_execute("UPDATE plugin_nms_onboarding_requests SET status='applying',started_at=NOW() WHERE id=?",[$job['id']]);
            $job['status']='applying';
            $host_id=nms_workspace_onboarding_native_create($job,$host);
            $saved=db_fetch_row_prepared('SELECT id,hostname,poller_id,site_id,host_template_id,external_id FROM host WHERE id=? AND deleted=\'\'',[$host_id]);
            if(!$saved || $saved['hostname']!==$job['target'] || (int)$saved['poller_id']!==(int)$collector || (int)$saved['site_id']!==(int)$job['site_id'] || (int)$saved['host_template_id']!==(int)$job['template_id'] || $saved['external_id']!==nms_workspace_onboarding_marker($job['id']))throw new RuntimeException('Native creation did not return the expected device. Inspect Cacti before retrying.');
            nms_workspace_onboarding_finish($job,'created',$host_id);
        }catch(Throwable $e){
            if($job['status']==='applying' && !$host_id) {
                $existing=nms_workspace_admission_rows('SELECT id FROM host WHERE external_id=?',[nms_workspace_onboarding_marker($job['id'])]);
                if(count($existing)===1)$host_id=(int)$existing[0]['id'];
            }
            nms_workspace_onboarding_finish($job,$job['status']==='applying'?'review_required':'failed',$host_id,$e->getMessage());
        }
    }finally{$_SESSION=$session;db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}
