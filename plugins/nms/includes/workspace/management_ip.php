<?php
require_once __DIR__.'/authorization.php';
require_once __DIR__.'/candidates.php';
require_once __DIR__.'/snmp_settings.php';
require_once __DIR__.'/admission.php';
require_once __DIR__.'/audit.php';
require_once __DIR__.'/../topology/discovery.php';

/** Current own-address observations suggest alternatives; they never change polling automatically. */
function nms_workspace_management_proposals($host,$snapshot)
{
    if(!$host || !$snapshot || empty($snapshot['valid']) || ($snapshot['status']??'')!=='success' || ($snapshot['protocol']??'')!=='identity' || (int)$snapshot['host_id']!==(int)$host['id'])return [];
    $old=nms_nd_address_key($host['hostname']);$rows=[];
    foreach($snapshot['data']['own_addresses']??[] as $address) {
        $target=nms_workspace_candidate_target($address['address']??null);
        if(!$target || $target===$old)continue;
        $eligible=nms_nd_address_matchable($address) && (int)($address['status']??0)===1;
        $id=hash('sha256',json_encode([(int)$host['id'],$host['hostname'],$target,(int)$host['poller_id'],(string)($host['snmp_context']??'')]));
        $row=['id'=>$id,'host_id'=>(int)$host['id'],'old_address'=>$host['hostname'],'target'=>$target,'poller_id'=>(int)$host['poller_id'],
            'site_id'=>(int)$host['site_id'],'snmp_context'=>(string)($host['snmp_context']??''),'ifindex'=>(int)($address['ifindex']??0),
            'source'=>(string)($address['source']??'IP-MIB'),'observed_at'=>(string)($snapshot['succeeded_at']??''),'eligible'=>$eligible,
            'reason'=>$eligible?'The current SNMP agent reports this preferred unicast address. Verify reachability and identity before changing management IP.':'Shared, deprecated, scoped or uncertain address; not eligible for an automatic management-IP change.',
            'chassis'=>nms_nd_chassis_key($snapshot['data'])];
        // Duplicate interface rows do not create multiple change requests for one target.
        if(!isset($rows[$target]) || (!$rows[$target]['eligible'] && $eligible))$rows[$target]=$row;
    }
    return array_values($rows);
}

/** Rebuild proposal under current Cacti permissions and collection configuration. */
function nms_workspace_management_context($host_id,$proposal_id)
{
    nms_workspace_authorize_current($host_id);
    if(!api_user_realm_auth('devices.php'))throw new RuntimeException('Workspace permission is required.');
    if((int)db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory=?',['nms'])!==1)throw new RuntimeException('Enable NMS before managing polling addresses.');
    if(!db_fetch_cell_prepared("SELECT id FROM user_auth WHERE id=? AND enabled='on'",[nms_current_user_id()]))throw new RuntimeException('Requesting account is unavailable.');
    if(!is_string($proposal_id)||!preg_match('/^[a-f0-9]{64}$/D',$proposal_id))throw new InvalidArgumentException('Invalid management-address proposal.');
    $discovery=nms_topology_discovery(null);$host=$discovery['hosts'][(int)$host_id]??null;
    $snapshot=$discovery['snapshots'][(int)$host_id.'|identity']??null;
    foreach(nms_workspace_management_proposals($host,$snapshot) as $proposal)if(hash_equals($proposal['id'],$proposal_id)) {
        if(!$proposal['eligible'])throw new RuntimeException('This address requires manual identity review.');
        if(!$proposal['chassis'])throw new RuntimeException('Current chassis serial/model evidence is required to verify that the new address belongs to this device.');
        $host=nms_workspace_bounded_snmp($host);
        if(!db_fetch_cell_prepared("SELECT id FROM poller WHERE id=? AND disabled=''",[$host['poller_id']]))throw new RuntimeException('Assigned collector is unavailable.');
        return [$proposal,$host,$snapshot];
    }
    throw new RuntimeException('The management-address evidence changed or is no longer current.');
}

/** Verification must return matching complete chassis evidence, not just a ping response. */
function nms_workspace_management_identity_matches($proposal,$identity)
{
    $key=nms_nd_chassis_key($identity);
    if(!$key || !$proposal['chassis'] || !hash_equals($proposal['chassis'],$key))return false;
    foreach($identity['own_addresses']??[] as $row)if(nms_nd_address_matchable($row) && (int)($row['status']??0)===1 && nms_workspace_candidate_target($row['address'])===$proposal['target'])return true;
    return false;
}

function nms_workspace_management_revision($proposal,$host)
{
    $native=[];foreach(['description','host_template_id','availability_method','ping_method','ping_port','ping_timeout','ping_retries','notes','max_oids','device_threads','external_id','location','bulk_walk_size'] as $field)$native[$field]=$host[$field]??null;
    return hash('sha256',json_encode([$proposal,nms_nd_hash($host),$host['nms_snmp_retries']??null,$native],JSON_THROW_ON_ERROR));
}

/** Queue only collector verification. Applying the change requires a separate reviewed action. */
function nms_workspace_management_verify($host_id,$proposal_id)
{
    [$proposal,$host]=nms_workspace_management_context($host_id,$proposal_id);
    $lock='nms_management_ip_'.(int)$host_id;
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,2)',[$lock])!==1)throw new RuntimeException('Another management-address action is in progress.');
    try {
        if(db_fetch_cell_prepared("SELECT id FROM plugin_nms_management_changes WHERE host_id=? AND status IN ('queued_verify','verifying','queued_apply','applying','review_required') LIMIT 1",[$host_id]))throw new RuntimeException('Review or finish the existing management-address request first.');
        if(!db_execute('START TRANSACTION'))throw new RuntimeException('Could not begin verification request.');
        try {
            nms_category_execute("INSERT INTO plugin_nms_management_changes(host_id,proposal_id,user_id,poller_id,old_address,target,config_hash,status,result_json,requested_at) VALUES (?,?,?,?,?,?,?,'queued_verify','{}',NOW())",[$host_id,$proposal_id,nms_current_user_id(),$host['poller_id'],$host['hostname'],$proposal['target'],nms_workspace_management_revision($proposal,$host)]);
            $id=(int)db_fetch_cell('SELECT LAST_INSERT_ID()');
            nms_workspace_audit('management_ip_verification_requested',['change_id'=>$id,'old_address'=>$host['hostname'],'target'=>$proposal['target']],$host_id);
            if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit verification request.');
            return $id;
        }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
    }finally{db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}

function nms_workspace_management_review($id)
{
    nms_require_management(3);
    $job=db_fetch_row_prepared('SELECT * FROM plugin_nms_management_changes WHERE id=? AND user_id=?',[(int)$id,nms_current_user_id()]);
    if(!$job || !in_array($job['status'],['verified','queued_apply'],true))throw new RuntimeException('A successful management-address verification is required.');
    [$proposal,$host]=nms_workspace_management_context((int)$job['host_id'],$job['proposal_id']);
    $at=strtotime($job['verified_at']??'');
    if(!$at || $at>time()+30 || time()-$at>900 || !hash_equals($job['config_hash'],nms_workspace_management_revision($proposal,$host)))throw new RuntimeException('Verification or device settings changed. Verify again.');
    $result=json_decode($job['result_json'],true,512,JSON_THROW_ON_ERROR);
    if($job['old_address']!==$host['hostname'] || $job['target']!==$proposal['target'] || (int)$job['poller_id']!==(int)$proposal['poller_id'] || ($result['target']??null)!==$job['target'] || (int)($result['collector_id']??0)!==(int)$job['poller_id'])throw new RuntimeException('Verification target or collector does not match the current request.');
    if(!nms_workspace_management_identity_matches($proposal,$result['identity']??[]))throw new RuntimeException('Verified identity does not match the current device.');
    $candidate=$proposal+['reporter_id'=>(int)$job['host_id'],'verified_identity'=>$result['identity'],'verified_at'=>$job['verified_at']];
    $admission=nms_workspace_admission_check($candidate,(int)$job['host_id']);
    return ['job'=>$job,'proposal'=>$proposal,'host'=>$host,'admission'=>$admission,
        'revision'=>hash('sha256',$job['config_hash'].'|'.$job['result_json'].'|'.json_encode($admission,JSON_THROW_ON_ERROR))];
}

/** Explicit apply confirmation only queues work; the collector performs the native update. */
function nms_workspace_management_apply($id,$revision)
{
    $review=nms_workspace_management_review($id);$job=$review['job'];
    $lock='nms_management_ip_'.(int)$job['host_id'];
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,2)',[$lock])!==1)throw new RuntimeException('This device has another address action in progress.');
    try {
        $review=nms_workspace_management_review($id);$job=$review['job'];
        if($job['status']!=='verified' || !is_string($revision) || !hash_equals($review['revision'],$revision))throw new RuntimeException('Review changed or was already submitted.');
        if($review['admission']['blocked'])throw new RuntimeException($review['admission']['message']);
        if(!db_execute('START TRANSACTION'))throw new RuntimeException('Could not start apply request.');
        try {
            nms_category_execute("UPDATE plugin_nms_management_changes SET status='queued_apply' WHERE id=?",[$id]);
            nms_workspace_audit('management_ip_apply_requested',['change_id'=>(int)$id,'old_address'=>$job['old_address'],'target'=>$job['target']],$job['host_id']);
            if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit apply request.');
        }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
    }finally{db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}

/** A running native update is never interrupted by a browser cancellation. */
function nms_workspace_management_cancel($id)
{
    nms_require_management(3);
    if(!api_user_realm_auth('devices.php'))throw new RuntimeException('Workspace permission is required.');
    $job=db_fetch_row_prepared('SELECT * FROM plugin_nms_management_changes WHERE id=? AND user_id=?',[(int)$id,nms_current_user_id()]);
    if(!$job)throw new RuntimeException('Address request is unavailable.');
    nms_require_device_access((int)$job['host_id']);$lock='nms_management_ip_'.(int)$job['host_id'];
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,0)',[$lock])!==1)throw new RuntimeException('Collector action is running. Wait for its result before requesting cancellation.');
    try {
        $status=db_fetch_cell_prepared('SELECT status FROM plugin_nms_management_changes WHERE id=?',[$id]);
        if(!in_array($status,['queued_verify','verified','queued_apply'],true))throw new RuntimeException('This action can no longer be cancelled.');
        if(!db_execute('START TRANSACTION'))throw new RuntimeException('Could not begin cancellation.');
        try {
            nms_category_execute("UPDATE plugin_nms_management_changes SET status='cancelled',finished_at=NOW() WHERE id=?",[$id]);
            nms_workspace_audit('management_ip_cancelled',['change_id'=>(int)$id],$job['host_id']);
            if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit cancellation.');
        }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
    }finally{db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}

function nms_workspace_management_history($host_id=0)
{
    nms_require_management(3);
    if(!api_user_realm_auth('devices.php'))throw new RuntimeException('Workspace permission is required.');
    $where=$host_id?' AND j.host_id='.(int)$host_id:'';
    return nms_workspace_admission_rows("SELECT j.*,h.description FROM plugin_nms_management_changes j JOIN host h ON h.id=j.host_id
        WHERE j.user_id=? AND h.deleted='' AND ".nms_visible_host_sql('h.id').$where." ORDER BY j.id DESC LIMIT 50",[nms_current_user_id()]);
}
