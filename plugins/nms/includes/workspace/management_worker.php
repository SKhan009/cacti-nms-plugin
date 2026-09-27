<?php
require_once __DIR__.'/management_ip.php';
require_once __DIR__.'/../discovery_network.php';
require_once __DIR__.'/../discovery_snmp.php';

/** Existing ID and every native setting are retained except the reviewed hostname. */
function nms_workspace_management_native_update($host,$target)
{
    global $config;
    require_once $config['base_path'].'/plugins/nms/includes/device_manager.php';
    return (int)api_device_save((int)$host['id'],(int)$host['host_template_id'],$host['description'],$target,
        $host['snmp_community'],(int)$host['snmp_version'],$host['snmp_username'],$host['snmp_password'],(int)$host['snmp_port'],(int)$host['snmp_timeout'],$host['disabled'],
        (int)$host['availability_method'],(int)$host['ping_method'],(int)$host['ping_port'],(int)$host['ping_timeout'],(int)$host['ping_retries'],$host['notes'],
        $host['snmp_auth_protocol'],$host['snmp_priv_passphrase'],$host['snmp_priv_protocol'],$host['snmp_context'],$host['snmp_engine_id'],
        (int)$host['max_oids'],(int)$host['device_threads'],(int)$host['poller_id'],(int)$host['site_id'],$host['external_id'],$host['location'],(int)($host['bulk_walk_size']??-1));
}
function nms_workspace_management_associations($id)
{
    return [nms_workspace_admission_rows('SELECT id,host_id,graph_template_id FROM graph_local WHERE host_id=? ORDER BY id',[$id]),
        nms_workspace_admission_rows('SELECT id,host_id,data_template_id FROM data_local WHERE host_id=? ORDER BY id',[$id])];
}
function nms_workspace_management_finish($job,$status,$result=[],$error='')
{
    if(!in_array($status,['verified','applied','failed','review_required','cancelled'],true))throw new InvalidArgumentException('Invalid address change state.');
    if(!db_execute('START TRANSACTION'))throw new RuntimeException('Could not begin address result.');
    try {
        nms_category_execute('UPDATE plugin_nms_management_changes SET status=?,result_json=?,error=?,verified_at=IF(?=\'verified\',NOW(),verified_at),finished_at=NOW() WHERE id=?',[$status,json_encode($result,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE),substr($error,0,255),$status,$job['id']]);
        nms_workspace_audit('management_ip_'.$status,['change_id'=>(int)$job['id'],'old_address'=>$job['old_address'],'target'=>$job['target']],$job['host_id'],0,$job['user_id']);
        if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit address result.');
    }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
}

function nms_workspace_management_poll($collector)
{
    if(PHP_SAPI!=='cli')return;
    $job=db_fetch_row_prepared("SELECT * FROM plugin_nms_management_changes WHERE poller_id=? AND status IN ('queued_verify','verifying','queued_apply','applying') ORDER BY id LIMIT 1",[$collector]);
    if(!$job)return;
    $lock='nms_management_ip_'.(int)$job['host_id'];
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,0)',[$lock])!==1)return;
    $session=$_SESSION??[];$admissionLock=false;
    try {
        $job=db_fetch_row_prepared('SELECT * FROM plugin_nms_management_changes WHERE id=?',[$job['id']]);
        if(!in_array($job['status'],['queued_verify','verifying','queued_apply','applying'],true))return;
        try {
            if(in_array($job['status'],['verifying','applying'],true))throw new RuntimeException('Previous collector action was interrupted. Inspect current Cacti address and settings; no action was replayed.');
            if(!db_fetch_cell_prepared("SELECT id FROM user_auth WHERE id=? AND enabled='on'",[$job['user_id']]))throw new RuntimeException('Requesting account is unavailable.');
            $_SESSION=['sess_user_id'=>(int)$job['user_id']];
            [$proposal,$host]=nms_workspace_management_context((int)$job['host_id'],$job['proposal_id']);
            if((int)$host['poller_id']!==(int)$collector || $host['hostname']!==$job['old_address'] || $proposal['target']!==$job['target'] || !hash_equals($job['config_hash'],nms_workspace_management_revision($proposal,$host)))throw new RuntimeException('Device address or settings changed. Verify again.');
            if($job['status']==='queued_verify') {
                if(time()-strtotime($job['requested_at'])>900)throw new RuntimeException('Verification request expired.');
                nms_category_execute("UPDATE plugin_nms_management_changes SET status='verifying',started_at=NOW() WHERE id=?",[$job['id']]);$job['status']='verifying';
                $ping=nms_nd_network_probe($job['target'],'icmp',1,500);$probe=$host;$probe['hostname']=$job['target'];
                $identity=nms_nd_collect_identity($probe,microtime(true)+18);
                if(!nms_workspace_management_identity_matches($proposal,$identity))throw new RuntimeException('New address did not return matching chassis and owned-address evidence. No polling change was made.');
                [$current,$currentHost]=nms_workspace_management_context((int)$job['host_id'],$job['proposal_id']);
                if(!hash_equals($job['config_hash'],nms_workspace_management_revision($current,$currentHost)))throw new RuntimeException('Device settings changed during verification.');
                nms_workspace_management_finish($job,'verified',['ping'=>$ping,'identity'=>$identity,'target'=>$job['target'],'collector_id'=>(int)$collector]);return;
            }
            if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,0)',['nms_reviewed_onboarding'])!==1)return;
            $admissionLock=true;$review=nms_workspace_management_review($job['id']);
            if($review['admission']['blocked'])throw new RuntimeException('Existing-device overlap requires review before changing the polling address.');
            $before=nms_workspace_management_associations($job['host_id']);
            $result=json_decode($job['result_json'],true,512,JSON_THROW_ON_ERROR);$result['associations_before']=$before;
            nms_category_execute("UPDATE plugin_nms_management_changes SET status='applying',result_json=? WHERE id=?",[json_encode($result,JSON_THROW_ON_ERROR),$job['id']]);$job['status']='applying';$job['result_json']=json_encode($result,JSON_THROW_ON_ERROR);
            $saved=nms_workspace_management_native_update($review['host'],$job['target']);
            if($saved!==(int)$job['host_id'] || db_fetch_cell_prepared('SELECT hostname FROM host WHERE id=?',[$saved])!==$job['target'] || $before!==nms_workspace_management_associations($saved))throw new RuntimeException('Native update did not preserve the expected address or associations. Review the existing device; no retry was attempted.');
            nms_workspace_management_finish($job,'applied',$result);
        }catch(Throwable $e){nms_workspace_management_finish($job,$job['status']==='applying'?'review_required':'failed',json_decode($job['result_json'],true)?:[],$e->getMessage());}
    }finally{$_SESSION=$session;if($admissionLock)db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',['nms_reviewed_onboarding']);db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}
