<?php
require_once __DIR__.'/verification.php';
require_once __DIR__.'/admission.php';

/** Completed collector evidence must still describe the reviewed candidate and settings. */
function nms_workspace_onboarding_evidence($check,$candidate,$host,$now)
{
    if(($check['status']??'')!=='complete' || !empty($check['cancel_requested']))throw new RuntimeException('Complete a candidate verification before onboarding.');
    $finished=strtotime((string)($check['finished_at']??''));
    if(!$finished || $finished>$now+30 || $now-$finished>900)throw new RuntimeException('Candidate verification is stale. Verify again before onboarding.');
    if(!hash_equals((string)$check['candidate_id'],(string)$candidate['id']) || $check['target']!==$candidate['target'] || (int)$check['poller_id']!==(int)$candidate['poller_id'] || !hash_equals($check['config_hash'],nms_workspace_verification_hash($candidate,$host)))throw new RuntimeException('Candidate or settings changed since verification. Verify again.');
    $result=json_decode($check['result_json'],true,512,JSON_THROW_ON_ERROR);
    if(($result['target']??null)!==$candidate['target'] || (int)($result['collector_id']??0)!==(int)$candidate['poller_id'] || empty($result['identity_usable']) || !is_array($result['identity']??null))throw new RuntimeException('Verification has no usable SNMP identity for this target and collector.');
    $identity=$result['identity'];
    if(empty($identity['interfaces']) && empty($identity['own_addresses']) && empty($identity['hardware']['chassis']) && !isset($identity['uptime']))throw new RuntimeException('The identity result contains no usable evidence.');
    return $identity;
}

/** Read-only review plan. Re-run immediately before collector-side Cacti API creation. */
function nms_workspace_onboarding_plan($check_id,$template_id,$description)
{
    nms_require_management(3);
    if(!is_string($description) || trim($description)==='' || strlen(trim($description))>150 || preg_match('/[\x00-\x1f\x7f]/',$description))throw new InvalidArgumentException('Enter a device name of 1–150 bytes without control characters.');
    $check=db_fetch_row_prepared('SELECT * FROM plugin_nms_candidate_checks WHERE id=? AND user_id=?',[(int)$check_id,nms_current_user_id()]);
    if(!$check)throw new RuntimeException('Verification is unavailable for your account.');
    [$candidate,$host]=nms_workspace_verification_context((int)$check['reporter_id'],$check['candidate_id']);
    $identity=nms_workspace_onboarding_evidence($check,$candidate,$host,time());
    $template=db_fetch_row_prepared('SELECT * FROM host_template WHERE id=?',[(int)$template_id]);
    if(!$template)throw new InvalidArgumentException('Select an existing Cacti device template.');
    $templateGraph=nms_workspace_admission_rows('SELECT * FROM host_template_graph WHERE host_template_id=? ORDER BY graph_template_id',[(int)$template_id]);
    $templateQuery=nms_workspace_admission_rows('SELECT * FROM host_template_snmp_query WHERE host_template_id=? ORDER BY snmp_query_id',[(int)$template_id]);
    $nativeSettings=[];
    foreach(['availability_method','ping_method','ping_port','ping_timeout','ping_retries','max_oids','device_threads'] as $field)$nativeSettings[$field]=$host[$field]??null;
    $candidate['verified_identity']=$identity;$candidate['verified_at']=$check['finished_at'];
    $admission=nms_workspace_admission_check($candidate);
    // No credential values enter the review response or audit payload.
    $plan=['check_id'=>(int)$check['id'],'reporter_id'=>(int)$check['reporter_id'],'candidate_id'=>$candidate['id'],
        'description'=>trim($description),'target'=>$candidate['target'],'poller_id'=>(int)$candidate['poller_id'],
        'site_id'=>(int)$candidate['site_id'],'template_id'=>(int)$template['id'],'template_name'=>$template['name'],
        'identity_evidence'=>['chassis'=>$identity['hardware']['chassis']??[],'addresses'=>$identity['own_addresses']??[]],
        'verified_at'=>$check['finished_at'],'snmp_source'=>'Reporting device settings','admission'=>$admission];
    $plan['revision']=hash('sha256',json_encode([$plan,$check['config_hash'],hash('sha256',$check['result_json']),$template,$templateGraph,$templateQuery,$nativeSettings],JSON_THROW_ON_ERROR));
    return $plan;
}

/** Explicit review submits a collector request; web requests never call the network-capable Cacti API. */
function nms_workspace_onboarding_enqueue($input)
{
    nms_require_management(3);
    if(!is_string($input['revision']??null) || !preg_match('/^[a-f0-9]{64}$/D',$input['revision']))throw new InvalidArgumentException('Open and confirm the onboarding review first.');
    $lock='nms_reviewed_onboarding';
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,2)',[$lock])!==1)throw new RuntimeException('Another onboarding request is being processed.');
    try {
        $plan=nms_workspace_onboarding_plan($input['check_id']??0,$input['template_id']??0,$input['description']??'');
        if(!hash_equals($plan['revision'],$input['revision']))throw new RuntimeException('The onboarding review changed. Review the current evidence again.');
        if($plan['admission']['blocked'])throw new RuntimeException($plan['admission']['message']);
        if(db_fetch_cell_prepared("SELECT id FROM plugin_nms_onboarding_requests WHERE poller_id=? AND site_id=? AND target=? AND status IN ('queued','applying','created','review_required') LIMIT 1",[$plan['poller_id'],$plan['site_id'],$plan['target']]))throw new RuntimeException('This candidate already has an onboarding request. Review its result instead of adding it again.');
        if(!db_execute('START TRANSACTION'))throw new RuntimeException('Could not begin onboarding request.');
        try {
            nms_category_execute("INSERT INTO plugin_nms_onboarding_requests(check_id,reporter_id,user_id,poller_id,site_id,target,template_id,description,review_hash,status,requested_at) VALUES (?,?,?,?,?,?,?,?,?,'queued',NOW())",
                [$plan['check_id'],$plan['reporter_id'],nms_current_user_id(),$plan['poller_id'],$plan['site_id'],$plan['target'],$plan['template_id'],$plan['description'],$plan['revision']]);
            $id=(int)db_fetch_cell('SELECT LAST_INSERT_ID()');
            nms_workspace_audit('candidate_onboarding_requested',['request_id'=>$id,'check_id'=>$plan['check_id'],'target'=>$plan['target'],'template_id'=>$plan['template_id']],$plan['reporter_id']);
            if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit onboarding request.');
            return $id;
        }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
    }finally{db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}

/** Cancel only before native creation; serialize with the collector's creation lock. */
function nms_workspace_onboarding_cancel($id)
{
    nms_require_management(3);
    if(!api_user_realm_auth('devices.php'))throw new RuntimeException('Workspace permission is required.');
    $lock='nms_reviewed_onboarding';
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,0)',[$lock])!==1)throw new RuntimeException('Creation may be in progress. Refresh its result; it cannot be cancelled during the native API call.');
    try {
        $job=db_fetch_row_prepared('SELECT * FROM plugin_nms_onboarding_requests WHERE id=? AND user_id=?',[(int)$id,nms_current_user_id()]);
        if(!$job)throw new RuntimeException('Onboarding request is unavailable.');
        nms_require_device_access((int)$job['reporter_id']);
        if($job['status']!=='queued')throw new RuntimeException('Only a queued request can be cancelled.');
        if(!db_execute('START TRANSACTION'))throw new RuntimeException('Could not begin cancellation.');
        try {
            nms_category_execute("UPDATE plugin_nms_onboarding_requests SET status='cancelled',finished_at=NOW() WHERE id=?",[$job['id']]);
            nms_workspace_audit('candidate_onboarding_cancelled',['request_id'=>(int)$job['id']],$job['reporter_id']);
            if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit cancellation.');
        }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
    }finally{db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}

function nms_workspace_onboarding_history($reporter=0)
{
    nms_require_management(3);
    if(!api_user_realm_auth('devices.php'))throw new RuntimeException('Workspace permission is required.');
    $where=$reporter?' AND j.reporter_id='.(int)$reporter:'';
    return nms_workspace_admission_rows("SELECT j.*,h.description AS reporter_name FROM plugin_nms_onboarding_requests j JOIN host h ON h.id=j.reporter_id
        WHERE j.user_id=? AND h.deleted='' AND ".nms_visible_host_sql('h.id').$where." ORDER BY j.id DESC LIMIT 50",[nms_current_user_id()]);
}
