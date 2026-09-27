<?php
require_once __DIR__.'/authorization.php';
require_once __DIR__.'/service_checks.php';
require_once __DIR__.'/audit.php';
require_once __DIR__.'/admission.php';
require_once __DIR__.'/../diagnostics_queue.php';

function nms_workspace_service_access()
{
    nms_workspace_authorize_current(0,['devices.php','diagnostics.php']);
}
function nms_workspace_service_context($id)
{
    nms_workspace_authorize_current($id,['devices.php','diagnostics.php']);
    if((int)db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory=?',['nms'])!==1)throw new RuntimeException('Enable NMS before running service checks.');
    $host=db_fetch_row_prepared("SELECT id,hostname,poller_id,site_id,disabled,deleted FROM host WHERE id=? AND deleted='' AND disabled=''",[(int)$id]);
    if(!$host || !db_fetch_cell_prepared("SELECT id FROM poller WHERE id=? AND disabled=''",[$host['poller_id']]))throw new RuntimeException('Device or assigned collector is unavailable.');
    nms_workspace_service_target($host);return $host;
}
function nms_workspace_service_revision($host,$spec)
{
    return hash('sha256',json_encode([$host,$spec],JSON_THROW_ON_ERROR));
}
function nms_workspace_service_enqueue($host_id,$input)
{
    $host=nms_workspace_service_context($host_id);$spec=nms_workspace_service_spec($input);
    if(!nms_diag_runner($host['poller_id']))throw new RuntimeException('Assigned collector runner is offline. No service check was submitted.');
    $lock='nms_service_submit';
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,2)',[$lock])!==1)throw new RuntimeException('Another check is being submitted. Retry shortly.');
    try {
        if(db_fetch_cell_prepared("SELECT id FROM plugin_nms_service_jobs WHERE (poller_id=? OR user_id=?) AND status IN ('queued','running') LIMIT 1",[$host['poller_id'],nms_current_user_id()]))throw new RuntimeException('Finish or cancel the pending service check first.');
        if(!db_execute('START TRANSACTION'))throw new RuntimeException('Could not begin service request.');
        try {
            nms_category_execute("INSERT INTO plugin_nms_service_jobs(host_id,poller_id,user_id,config_hash,spec_json,status,result_json,requested_at) VALUES (?,?,?,?,?,'queued','{}',NOW())",[$host_id,$host['poller_id'],nms_current_user_id(),nms_workspace_service_revision($host,$spec),json_encode($spec,JSON_THROW_ON_ERROR)]);
            $id=(int)db_fetch_cell('SELECT LAST_INSERT_ID()');
            nms_workspace_audit('service_check_requested',['job_id'=>$id,'kind'=>$spec['kind'],'port'=>$spec['port']],$host_id);
            if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit service request.');
            return $id;
        }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
    }finally{db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function nms_workspace_service_cancel($id)
{
    nms_workspace_service_access();
    if(!db_execute('START TRANSACTION'))throw new RuntimeException('Could not begin cancellation.');
    try {
        $job=db_fetch_row_prepared('SELECT * FROM plugin_nms_service_jobs WHERE id=? AND user_id=? FOR UPDATE',[(int)$id,nms_current_user_id()]);
        if(!$job)throw new RuntimeException('Service request is unavailable.');
        nms_require_device_access($job['host_id']);
        if(!in_array($job['status'],['queued','running'],true))throw new RuntimeException('Service check has already finished.');
        nms_category_execute("UPDATE plugin_nms_service_jobs SET status='cancelled',finished_at=NOW(),result_json='{}' WHERE id=?",[$id]);
        nms_workspace_audit('service_check_cancelled',['job_id'=>(int)$id],$job['host_id']);
        if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit cancellation.');
    }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
}
function nms_workspace_service_finish($job,$status,$result)
{
    if(!in_array($status,['complete','failed'],true))throw new InvalidArgumentException('Invalid service result status.');
    if(!db_execute('START TRANSACTION'))throw new RuntimeException('Could not begin service result.');
    try {
        $current=db_fetch_cell_prepared('SELECT status FROM plugin_nms_service_jobs WHERE id=? FOR UPDATE',[$job['id']]);
        if(!in_array($current,['queued','running'],true)){db_execute('ROLLBACK');return;}
        nms_category_execute('UPDATE plugin_nms_service_jobs SET status=?,result_json=?,finished_at=NOW() WHERE id=?',[$status,json_encode($result,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE),$job['id']]);
        nms_workspace_audit('service_check_'.$status,['job_id'=>(int)$job['id'],'category'=>$result['category']??'unknown'],$job['host_id'],0,$job['user_id']);
        if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit service result.');
    }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
}
function nms_workspace_service_history($host_id=0)
{
    nms_workspace_service_access();
    return nms_workspace_admission_rows("SELECT j.*,h.description FROM plugin_nms_service_jobs j JOIN host h ON h.id=j.host_id WHERE j.user_id=? AND h.deleted='' AND ".nms_visible_host_sql('h.id').($host_id?' AND h.id='.(int)$host_id:'').' ORDER BY j.id DESC LIMIT 50',[nms_current_user_id()]);
}
