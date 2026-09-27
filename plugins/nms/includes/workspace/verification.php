<?php
require_once __DIR__.'/authorization.php';
require_once __DIR__.'/candidates.php';
require_once __DIR__.'/snmp_settings.php';
require_once __DIR__.'/audit.php';
require_once __DIR__.'/../topology/discovery.php';

/** Rebuild targets from current permitted advertisements; never trust a posted address. */
function nms_workspace_verification_context($reporter,$candidate_id)
{
    nms_workspace_authorize_current($reporter);
    if(!db_fetch_cell_prepared("SELECT id FROM user_auth WHERE id=? AND enabled='on'",[nms_current_user_id()]))throw new RuntimeException('Requesting account is unavailable.');
    if((int)db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory=?',['nms'])!==1)throw new RuntimeException('Enable the NMS plugin before verifying candidates.');
    if(!api_user_realm_auth('devices.php'))throw new RuntimeException('Device workspace permission is required.');
    if(!is_string($candidate_id) || !preg_match('/^[a-f0-9]{64}$/D',$candidate_id))throw new InvalidArgumentException('Invalid candidate.');
    $discovery=nms_topology_discovery(null);
    if(empty($discovery['ready']))throw new RuntimeException('Current neighbour evidence is unavailable.');
    $host=$discovery['hosts'][$reporter]??null;
    if(!$host)throw new RuntimeException('Reporting device is no longer available for discovery.');
    $candidates=nms_workspace_neighbour_candidates($discovery['hosts'],$discovery['snapshots'],[],(int)$reporter);
    foreach($candidates as $candidate)if(hash_equals($candidate['id'],$candidate_id)) {
        if(!$candidate['eligible'])throw new RuntimeException('The advertised address is no longer current or usable.');
        if(!db_fetch_cell_prepared("SELECT id FROM poller WHERE id=? AND disabled=''",[$candidate['poller_id']]))throw new RuntimeException('Assigned collector is unavailable.');
        $host=nms_workspace_bounded_snmp($host);
        return [$candidate,$host];
    }
    throw new RuntimeException('Candidate advertisement has changed or disappeared. Refresh neighbour evidence.');
}
function nms_workspace_verification_hash($candidate,$host)
{
    return hash('sha256',json_encode([$candidate['id'],$candidate['target'],$candidate['poller_id'],$candidate['site_id'],$candidate['observed_at'],nms_nd_hash($host),$host['nms_snmp_retries']??null],JSON_THROW_ON_ERROR));
}

/** One bounded pending verification per collector; only references and hashes are stored. */
function nms_workspace_verification_enqueue($reporter,$candidate_id)
{
    [$candidate,$host]=nms_workspace_verification_context($reporter,$candidate_id);
    $lock='nms_candidate_admit_'.$candidate['poller_id'];
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,2)',[$lock])!==1)throw new RuntimeException('Another verification is being submitted.');
    try {
        if(db_fetch_cell_prepared("SELECT id FROM plugin_nms_candidate_checks WHERE poller_id=? AND status IN ('queued','running') LIMIT 1",[$candidate['poller_id']]))throw new RuntimeException('This collector has a pending candidate verification. Wait or cancel it first.');
        if(!db_execute('START TRANSACTION'))throw new RuntimeException('Could not start verification request.');
        try {
            nms_category_execute("INSERT INTO plugin_nms_candidate_checks(reporter_id,candidate_id,poller_id,user_id,target,config_hash,status,result_json,requested_at) VALUES (?,?,?,?,?,?,'queued','{}',NOW())",
                [$reporter,$candidate_id,$candidate['poller_id'],nms_current_user_id(),$candidate['target'],nms_workspace_verification_hash($candidate,$host)]);
            $id=(int)db_fetch_cell('SELECT LAST_INSERT_ID()');
            nms_workspace_audit('candidate_verification_requested',['check_id'=>$id,'target'=>$candidate['target']],$reporter);
            if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit verification request.');
            return $id;
        }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
    }finally{db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}

/** Finish atomically; a cancelled or completed check is never overwritten. */
function nms_workspace_verification_finish($job,$status,$result=[],$error='')
{
    if(!in_array($status,['complete','failed','cancelled'],true))throw new InvalidArgumentException('Invalid verification status.');
    if(!db_execute('START TRANSACTION'))throw new RuntimeException('Could not start verification completion.');
    try {
        $current=db_fetch_row_prepared('SELECT status,cancel_requested FROM plugin_nms_candidate_checks WHERE id=? FOR UPDATE',[$job['id']]);
        if(!$current || !in_array($current['status'],['queued','running'],true)){db_execute('ROLLBACK');return;}
        if($current['cancel_requested']){$status='cancelled';$result=[];$error='Cancelled by requester.';}
        nms_category_execute('UPDATE plugin_nms_candidate_checks SET status=?,result_json=?,error=?,finished_at=NOW() WHERE id=?',[$status,json_encode($result,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE),substr($error,0,255),$job['id']]);
        nms_workspace_audit('candidate_verification_'.$status,['check_id'=>(int)$job['id']],$job['reporter_id'],0,$job['user_id']);
        if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit verification completion.');
    }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
}

function nms_workspace_verification_cancel($id)
{
    nms_require_management(3);
    $job=db_fetch_row_prepared('SELECT * FROM plugin_nms_candidate_checks WHERE id=? AND user_id=?',[(int)$id,nms_current_user_id()]);
    if(!$job)throw new RuntimeException('Verification is unavailable.');
    nms_require_device_access((int)$job['reporter_id']);
    if(!api_user_realm_auth('devices.php'))throw new RuntimeException('Workspace permission is required.');
    nms_category_execute("UPDATE plugin_nms_candidate_checks SET cancel_requested=1 WHERE id=? AND status IN ('queued','running')",[$job['id']]);
    if($job['status']==='queued')nms_workspace_verification_finish($job,'cancelled');
}

/** Requester-scoped results, additionally constrained by current reporter visibility. */
function nms_workspace_verification_history($reporter=0)
{
    nms_require_management(3);
    if(!api_user_realm_auth('devices.php'))throw new RuntimeException('Workspace permission is required.');
    $where=$reporter?' AND j.reporter_id='.(int)$reporter:'';
    return db_fetch_assoc_prepared("SELECT j.*,h.description FROM plugin_nms_candidate_checks j JOIN host h ON h.id=j.reporter_id
        WHERE j.user_id=? AND h.deleted='' AND ".nms_visible_host_sql('h.id').$where." ORDER BY j.id DESC LIMIT 50",[nms_current_user_id()]);
}
