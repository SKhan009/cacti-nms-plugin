<?php
/** Diagnosis lives only on diagnostics.php; shared collector services retain their authorization. */
require_once __DIR__.'/workspace/evidence.php';
require_once __DIR__.'/workspace/networks.php';
require_once __DIR__.'/workspace/diagnosis.php';
require_once __DIR__.'/workspace/services.php';

$workspace_section='diagnosis';
$raw_id=$_GET['host_id'] ?? 0;
if(!is_scalar($raw_id) || !ctype_digit((string)$raw_id) || strlen((string)$raw_id)>8){http_response_code(400);exit('Invalid device ID.');}
$workspace_id=(int)$raw_id;
$devices=db_fetch_assoc("SELECT h.id,h.description,h.hostname,h.poller_id,h.site_id,h.status,h.disabled,h.last_updated FROM host h WHERE h.deleted='' AND ".nms_visible_host_sql('h.id')." ORDER BY h.description");
if($node_id){$members=array_map('intval',array_column(nms_node_members($node_id),'id'));$devices=array_values(array_filter($devices,static fn($h)=>in_array((int)$h['id'],$members,true)));}
$workspace_host=null;foreach($devices as $device)if((int)$device['id']===$workspace_id)$workspace_host=$device;
if($workspace_id && !$workspace_host){http_response_code(403);exit('Device is not available to this account.');}
$can_diagnose=is_realm_allowed(3) && api_user_realm_auth('diagnostics.php');
$workspace_error=(string)($_SESSION['nms_diagnosis_error'] ?? '');unset($_SESSION['nms_diagnosis_error']);
if($_SERVER['REQUEST_METHOD']==='POST'){
    $extra=[];
    try{
        if(!csrf_check_tokens($_POST['__csrf_magic'] ?? ''))throw new RuntimeException('Invalid request token. Reload before trying again.');
        if(!$can_diagnose)throw new RuntimeException('Diagnostic action is not permitted.');
        $action=$_POST['workspace_action'] ?? '';
        if($action==='cancel_service_check')nms_workspace_service_cancel(nms_workspace_integer($_POST['service_job_id']??0,1,2147483647,'service request'));
        else {
            if(!$workspace_id)throw new RuntimeException('Select a device first.');
            nms_require_device_access($workspace_id);
            if($action==='diagnose_device')$extra['job_id']=nms_workspace_diagnose_device($workspace_id);
            elseif($action==='run_diagnostic'){
                if(!is_string($_POST['tool']??null))throw new RuntimeException('Select a diagnostic tool.');
                $extra['job_id']=nms_diag_run($workspace_id,$_POST['tool']);
            }elseif($action==='run_service_check')nms_workspace_service_enqueue($workspace_id,$_POST);
            else throw new RuntimeException('Unsupported diagnostic action.');
        }
    }catch(Throwable $e){$_SESSION['nms_diagnosis_error']=$e->getMessage();}
    header('Location: '.(isset($extra['job_id'])?'diagnostics.php?section=run&job_id='.(int)$extra['job_id']:nms_workspace_url('diagnosis',$workspace_id)),true,303);exit;
}
$workspace_services=nms_workspace_service_history($workspace_id);
// Existing saved-result links use the single diagnostic results view.
if(isset($_GET['job_id'])) {
    $id=nms_workspace_integer($_GET['job_id'],1,2147483647,'diagnostic job');
    header('Location: diagnostics.php?section=run&job_id='.$id,true,302);exit;
}
nms_prepare_page('diagnostics','NMS · Device diagnosis','css/nms-devices.css,css/nms-topology-config.css,css/nms-workspace.css','js/nms-services.js');
require __DIR__.'/../templates/app_header.php';
require __DIR__.'/../templates/diagnostics/diagnosis.php';
require __DIR__.'/../templates/app_footer.php';
