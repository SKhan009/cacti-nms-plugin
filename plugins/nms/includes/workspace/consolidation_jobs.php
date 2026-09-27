<?php
require_once __DIR__.'/consolidation.php';
require_once __DIR__.'/../diagnostics_queue.php';

/** Explicit request bound to the complete current review; collector performs all file/native work. */
function nms_workspace_consolidation_enqueue($input)
{
    $keep=nms_workspace_integer($input['keep_id']??0,1,16777215,'preferred device');
    $other=nms_workspace_integer($input['other_id']??0,1,16777215,'other device');
    $lock='nms_consolidation_submit';
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,2)',[$lock])!==1)throw new RuntimeException('Another consolidation is being requested. Retry shortly.');
    try {
        $plan=nms_workspace_consolidation_plan($keep,$other);
        if(!is_string($input['revision']??null)||!hash_equals($plan['revision'],$input['revision']))throw new RuntimeException('Consolidation evidence or dependencies changed. Review the new plan.');
        if($plan['transfer_preflight']['status']!=='structurally_eligible'||$plan['permission_preflight']['status']!=='native_inputs_equivalent')throw new RuntimeException('Resolve the structural and permission blockers before requesting transfer.');
        if(($input['confirm_transfer']??'')!=='yes')throw new RuntimeException('Explicit confirmation of the reviewed asset transfer is required.');
        if(($input['confirm_references']??'')!=='yes')throw new RuntimeException('Review retained references and external permission integrations before transfer.');
        $collector=(int)$plan['devices'][0]['host']['poller_id'];
        if((int)db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory=?',['nms'])!==1||!db_fetch_cell_prepared("SELECT id FROM poller WHERE id=? AND disabled=''",[$collector]))throw new RuntimeException('NMS or the assigned collector is unavailable.');
        if(!nms_diag_runner($collector))throw new RuntimeException('Collector runner is offline. No transfer was requested.');
        // Include recovery-required jobs: never blindly repeat a possibly partial migration.
        if(db_fetch_cell_prepared("SELECT id FROM plugin_nms_consolidation_jobs WHERE status IN ('queued','verifying','applying','review_required','queued_recovery') AND (poller_id=? OR keep_id IN (?,?) OR other_id IN (?,?)) LIMIT 1",[$collector,$keep,$other,$keep,$other]))throw new RuntimeException('An outstanding transfer or recovery review must finish first.');
        if(db_fetch_cell_prepared("SELECT id FROM plugin_nms_management_changes WHERE host_id IN (?,?) AND status IN ('queued_verify','verifying','verified','queued_apply','applying','review_required') LIMIT 1",[$keep,$other]))throw new RuntimeException('Resolve pending management-address work for these devices first.');
        if(!db_execute('START TRANSACTION'))throw new RuntimeException('Could not begin consolidation request.');
        try {
            nms_category_execute("INSERT INTO plugin_nms_consolidation_jobs(keep_id,other_id,user_id,poller_id,revision,plan_json,status,progress_json,result_json,requested_at) VALUES (?,?,?,?,?,?,'queued','{}','{}',NOW())",[$keep,$other,nms_current_user_id(),$collector,$plan['revision'],json_encode($plan,JSON_THROW_ON_ERROR)]);
            $id=(int)db_fetch_cell('SELECT LAST_INSERT_ID()');
            foreach([$keep,$other] as $host)nms_workspace_audit('consolidation_transfer_requested',['job_id'=>$id,'keep_id'=>$keep,'other_id'=>$other,'retire_devices'=>false],$host);
            if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit consolidation request.');
            return $id;
        }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
    }finally{db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}

/** Cancellation is safe only while queued. Started/partial native changes require recovery review. */
function nms_workspace_consolidation_cancel($id)
{
    nms_workspace_authorize_current();
    if(!db_execute('START TRANSACTION'))throw new RuntimeException('Could not begin consolidation cancellation.');
    try {
        $job=db_fetch_row_prepared('SELECT * FROM plugin_nms_consolidation_jobs WHERE id=? AND user_id=? FOR UPDATE',[(int)$id,nms_current_user_id()]);
        if(!$job)throw new RuntimeException('Transfer request is unavailable.');
        nms_workspace_authorize_current((int)$job['keep_id']);nms_workspace_authorize_current((int)$job['other_id']);
        if($job['status']!=='queued')throw new RuntimeException('Transfer has started or finished. Inspect its outcome; it cannot be cancelled or repeated blindly.');
        nms_category_execute("UPDATE plugin_nms_consolidation_jobs SET status='cancelled',finished_at=NOW() WHERE id=?",[$job['id']]);
        foreach([$job['keep_id'],$job['other_id']] as $host)nms_workspace_audit('consolidation_transfer_cancelled',['job_id'=>(int)$job['id']],$host);
        if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit consolidation cancellation.');
    }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
}

function nms_workspace_consolidation_job_history($host_id=0)
{
    nms_workspace_authorize_current();
    $args=[nms_current_user_id()];$filter='';
    if($host_id){nms_workspace_authorize_current((int)$host_id);$filter=' AND (j.keep_id=? OR j.other_id=?)';$args[]=(int)$host_id;$args[]=(int)$host_id;}
    return nms_workspace_admission_rows("SELECT j.id,j.keep_id,j.other_id,j.poller_id,j.status,j.error,j.requested_at,j.started_at,j.finished_at,j.result_json,j.progress_json,a.description AS keep_name,b.description AS other_name FROM plugin_nms_consolidation_jobs j JOIN host a ON a.id=j.keep_id JOIN host b ON b.id=j.other_id WHERE j.user_id=? AND a.deleted='' AND b.deleted='' AND ".nms_visible_host_sql('a.id').' AND '.nms_visible_host_sql('b.id').$filter.' ORDER BY j.id DESC LIMIT 50',$args);
}

/** Request read-only recovery verification; never treat acknowledgement as successful migration. */
function nms_workspace_consolidation_recheck($id)
{
    nms_workspace_authorize_current();
    if(!db_execute('START TRANSACTION'))throw new RuntimeException('Could not begin recovery review.');
    try {
        $job=db_fetch_row_prepared('SELECT * FROM plugin_nms_consolidation_jobs WHERE id=? AND user_id=? FOR UPDATE',[(int)$id,nms_current_user_id()]);
        if(!$job)throw new RuntimeException('Transfer request is unavailable.');
        nms_workspace_authorize_current((int)$job['keep_id']);nms_workspace_authorize_current((int)$job['other_id']);
        if($job['status']!=='review_required')throw new RuntimeException('Only a transfer requiring recovery can be rechecked.');
        if(!nms_diag_runner((int)$job['poller_id']))throw new RuntimeException('Collector runner is offline. Recovery was not queued.');
        nms_category_execute("UPDATE plugin_nms_consolidation_jobs SET status='queued_recovery',finished_at=NULL,error='' WHERE id=?",[$job['id']]);
        foreach([$job['keep_id'],$job['other_id']] as $host)nms_workspace_audit('consolidation_recovery_requested',['job_id'=>(int)$job['id'],'native_actions'=>false],$host);
        if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit recovery review.');
    }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
}
