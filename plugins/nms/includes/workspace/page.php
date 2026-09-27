<?php
/** Unified readings controller: device-independent networks and permission-scoped evidence. */
require_once __DIR__.'/evidence.php';
require_once __DIR__.'/networks.php';
require_once __DIR__.'/scans.php';
require_once __DIR__.'/reviews.php';
require_once __DIR__.'/consolidation.php';
require_once __DIR__.'/consolidation_jobs.php';
require_once __DIR__.'/candidates.php';
require_once __DIR__.'/verification.php';
require_once __DIR__.'/onboarding.php';
require_once __DIR__.'/management_ip.php';
require_once __DIR__.'/../discovery_network.php';
require_once __DIR__.'/../topology/discovery.php';
require_once __DIR__.'/../diagnostics_queue.php';
header('Cache-Control: no-store, private');
$workspace_tabs=nms_workspace_tabs();
$workspace_section=is_string($_GET['section'] ?? null)?$_GET['section']:'overview';
if(!isset($workspace_tabs[$workspace_section])) $workspace_section='overview';
$raw_id=$_GET['id'] ?? 0;
if(!is_scalar($raw_id) || !preg_match('/^[0-9]+$/D',(string)$raw_id) || strlen((string)$raw_id)>8) {http_response_code(400);exit('Invalid device ID.');}
$workspace_id=(int)$raw_id;
$devices=db_fetch_assoc("SELECT h.id,h.description,h.hostname,h.poller_id,h.site_id,h.status,h.disabled,h.last_updated FROM host h WHERE h.deleted='' AND ".nms_visible_host_sql('h.id')." ORDER BY h.description");
$workspace_host=null;
foreach($devices as $device) if((int)$device['id']===$workspace_id) $workspace_host=$device;
if($workspace_id && !$workspace_host) {http_response_code(403);exit('Device is not available to this account.');}
$can_networks=is_realm_allowed(23) && api_user_realm_auth('network_discovery.php');
$workspace_error=(string)($_SESSION['nms_workspace_error'] ?? '');unset($_SESSION['nms_workspace_error']);
if($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if(!csrf_check_tokens($_POST['__csrf_magic'] ?? '')) throw new RuntimeException('Invalid request token. Reload before trying again.');
        if(in_array($_POST['workspace_action']??'', ['transfer_consolidation','cancel_consolidation','recheck_consolidation'],true)) {
            if($_POST['workspace_action']==='transfer_consolidation')nms_workspace_consolidation_enqueue($_POST);
            else {
                $request=nms_workspace_integer($_POST['consolidation_job_id']??0,1,2147483647,'transfer request');
                if($_POST['workspace_action']==='cancel_consolidation')nms_workspace_consolidation_cancel($request);
                else nms_workspace_consolidation_recheck($request);
            }
            header('Location: '.nms_workspace_url('duplicates',$workspace_id),true,303);exit;
        }
        if(($_POST['workspace_action']??'')==='save_consolidation') {
            nms_workspace_consolidation_save($_POST);
            header('Location: '.nms_workspace_url('duplicates',$workspace_id),true,303);exit;
        }
        if(in_array($_POST['workspace_action']??'', ['verify_management_ip','apply_management_ip','cancel_management_ip'],true)) {
            if($_POST['workspace_action']==='verify_management_ip')nms_workspace_management_verify($workspace_id,$_POST['proposal_id']??'');
            elseif($_POST['workspace_action']==='apply_management_ip')nms_workspace_management_apply(nms_workspace_integer($_POST['change_id']??0,1,2147483647,'address request'),$_POST['revision']??'');
            else nms_workspace_management_cancel(nms_workspace_integer($_POST['change_id']??0,1,2147483647,'address request'));
            header('Location: '.nms_workspace_url('identity',$workspace_id),true,303);exit;
        }
        if(in_array($_POST['workspace_action']??'', ['confirm_onboarding','cancel_onboarding'],true)) {
            if($_POST['workspace_action']==='confirm_onboarding')nms_workspace_onboarding_enqueue($_POST);
            else nms_workspace_onboarding_cancel(nms_workspace_integer($_POST['request_id']??0,1,2147483647,'onboarding request'));
            header('Location: '.nms_workspace_url('neighbours',$workspace_id),true,303);exit;
        }
        if(in_array($_POST['workspace_action']??'', ['verify_candidate','cancel_candidate_check'],true)) {
            if($_POST['workspace_action']==='verify_candidate') {
                $reporter=nms_workspace_integer($_POST['reporter_id']??0,1,2147483647,'reporting device');
                nms_workspace_verification_enqueue($reporter,$_POST['candidate_id']??'');
            } else nms_workspace_verification_cancel(nms_workspace_integer($_POST['check_id']??0,1,2147483647,'verification'));
            header('Location: '.nms_workspace_url('neighbours',$workspace_id),true,303);exit;
        }
        if(($_POST['workspace_action']??'')==='review_identity') {
            nms_identity_review_save($_POST);
            header('Location: '.nms_workspace_url('duplicates',$workspace_id,['saved'=>1]),true,303);exit;
        }
        if (in_array($_POST['workspace_action'] ?? '', ['save_network','probe_network','start_native_scan','cancel_scan'], true)) {
            if (!$can_networks) throw new RuntimeException('Network management is not permitted.');
            if ($_POST['workspace_action'] === 'save_network') $network_id=nms_workspace_network_save($_POST);
            elseif ($_POST['workspace_action'] === 'cancel_scan') {nms_scan_cancel($_POST['run_id'] ?? 0);$network_id=0;}
            else {$run_id=nms_scan_enqueue($_POST,$_POST['workspace_action']==='start_native_scan'?'native':'supplemental');$network_id=(int)$_POST['network_id'];}

            header('Location: '.nms_workspace_url('networks',$workspace_id,['network_id'=>$network_id,'saved'=>1]),true,303);exit;
        }
        throw new InvalidArgumentException('Unsupported workspace action.');
    } catch(Throwable $e) {$_SESSION['nms_workspace_error']=$e->getMessage();header('Location: '.nms_workspace_url($workspace_section,$workspace_id),true,303);exit;}
}
$workspace_discovery=nms_topology_discovery($workspace_host ? (int)$workspace_host['site_id'] : null,$workspace_section==='duplicates');
$workspace_identities=nms_nd_device_identities($workspace_discovery['hosts'],$workspace_discovery['snapshots']);
$workspace_reviews=$workspace_section==='duplicates'?nms_identity_reviews():[];
$workspace_candidates=$workspace_section==='neighbours'?nms_workspace_neighbour_candidates($workspace_discovery['hosts'],$workspace_discovery['snapshots'],$workspace_identities,$workspace_id,$devices):[];
$workspace_checks=($workspace_section==='neighbours') && is_realm_allowed(3)?nms_workspace_verification_history($workspace_id):[];
$workspace_onboarding=[];$workspace_onboarding_plan=null;$workspace_templates=[];
if(($workspace_section==='neighbours') && is_realm_allowed(3)) {
    $workspace_onboarding=nms_workspace_onboarding_history($workspace_id);
    $workspace_templates=nms_workspace_admission_rows('SELECT id,name FROM host_template ORDER BY name');
    if(isset($_GET['onboard_check'])) {
        try {
            $workspace_onboarding_plan=nms_workspace_onboarding_plan(
                nms_workspace_integer($_GET['onboard_check'],1,2147483647,'verification'),
                nms_workspace_integer($_GET['onboard_template']??0,1,2147483647,'template'),$_GET['onboard_name']??'');
        }catch(Throwable $e){$workspace_error=$e->getMessage();}
    }
}
$workspace_management=[];$workspace_management_review=null;$workspace_management_proposals=[];
if(($workspace_section==='identity') && is_realm_allowed(3)) {
    $workspace_management=nms_workspace_management_history($workspace_id);
    if($workspace_id)$workspace_management_proposals=nms_workspace_management_proposals($workspace_discovery['hosts'][$workspace_id]??null,$workspace_discovery['snapshots'][$workspace_id.'|identity']??null);
    if(isset($_GET['management_review'])) {
        try{$workspace_management_review=nms_workspace_management_review(nms_workspace_integer($_GET['management_review'],1,2147483647,'address request'));}
        catch(Throwable $e){$workspace_error=$e->getMessage();}
    }
}
$workspace_consolidation=null;$workspace_consolidation_history=[];$workspace_consolidation_jobs=[];
if(is_realm_allowed(3) && $workspace_section==='duplicates') {
    $workspace_consolidation_history=nms_workspace_consolidation_history($workspace_id);
    $workspace_consolidation_jobs=nms_workspace_consolidation_job_history($workspace_id);
    if(isset($_GET['consolidation_keep'],$_GET['consolidation_other'])) {
        try{$workspace_consolidation=nms_workspace_consolidation_plan(nms_workspace_integer($_GET['consolidation_keep'],1,16777215,'preferred device'),nms_workspace_integer($_GET['consolidation_other'],1,16777215,'other device'));}
        catch(Throwable $e){$workspace_error=$e->getMessage();}
    }
}
$workspace_networks=[];
if($workspace_section==='networks' && $can_networks) {
    $workspace_networks=db_fetch_assoc("SELECT n.*,(SELECT MAX(r.finished_at) FROM plugin_nms_scan_runs r WHERE r.network_id=n.id AND r.kind='native' AND r.status='complete') AS nms_last_success,(SELECT COUNT(*) FROM automation_processes p WHERE p.network_id=n.id AND p.status<>'done') AS active_processes FROM automation_networks n ORDER BY name");

}
$workspace_scan_history=null;$workspace_scan=null;$workspace_scan_results=[];$workspace_audit=null;
if($can_networks && ($workspace_section==='networks')) {
    $workspace_scan_history=nms_scan_history(0,is_scalar($_GET['scan_page'] ?? null)?$_GET['scan_page']:1);
    if(isset($_GET['run_id'])) {
        try {
            $scan_id=nms_workspace_integer($_GET['run_id'],1,2147483647,'run ID');
            $workspace_scan=db_fetch_row_prepared('SELECT r.*,n.name FROM plugin_nms_scan_runs r LEFT JOIN automation_networks n ON n.id=r.network_id WHERE r.id=?',[$scan_id]);
            if(!$workspace_scan)throw new RuntimeException('Scan run not found.');
            $workspace_result_page=is_scalar($_GET['result_page'] ?? null)?max(1,min(10000,(int)$_GET['result_page'])):1;
            $workspace_result_count=(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_scan_results WHERE run_id=?',[$scan_id]);
            $workspace_result_pages=max(1,(int)ceil($workspace_result_count/100));$workspace_result_page=min($workspace_result_page,$workspace_result_pages);
            $workspace_scan_results=db_fetch_assoc_prepared('SELECT * FROM plugin_nms_scan_results WHERE run_id=? ORDER BY ordinal LIMIT 100 OFFSET '.(($workspace_result_page-1)*100),[$scan_id]);
        }catch(Throwable $e){$workspace_error=$e->getMessage();}
    }
}
if($workspace_section==='history')$workspace_audit=nms_workspace_audit_history($workspace_id,is_scalar($_GET['audit_page'] ?? null)?$_GET['audit_page']:1,$can_networks);
if($workspace_id) {
    $edit_device=db_fetch_row_prepared('SELECT h.*,p.name AS poller_name FROM host h LEFT JOIN poller p ON p.id=h.poller_id WHERE h.id=? AND h.deleted=\'\' AND '.nms_visible_host_sql('h.id'),[$workspace_id]);
    $device_readings=nms_readings_load_live_rrd_values(nms_device_readings($workspace_id));
    $device_discovery_readings=nms_device_discovery_readings($workspace_id);
}
nms_prepare_page('devices','NMS · Device readings & discovery','css/nms-devices.css,css/nms-topology-config.css,css/nms-workspace.css','js/nms-readings.js');
require __DIR__.'/../../templates/app_header.php';
require __DIR__.'/../../templates/workspace/index.php';
require __DIR__.'/../../templates/app_footer.php';
