<?php
require_once __DIR__.'/node.php';
try {
    nms_require_management();
    $node_id=nms_node_id($_GET['node_id'] ?? 0);
    $node=nms_node_get($node_id);
} catch(Throwable $e) {
    // Expected access failures must not reach Cacti's plugin failure handler.
    http_response_code(403);
    die(nms_h($e->getMessage()));
}
$error=''; $review=null;
$draft=$_SESSION['nms_node_config_drafts'][$node_id] ?? ['members'=>[],'field_key'=>'','requested'=>''];
foreach($_SESSION['nms_node_config_reviews'] ?? [] as $token=>$stored) if($stored['expires']<time()) unset($_SESSION['nms_node_config_reviews'][$token]);
if($_SERVER['REQUEST_METHOD']==='POST') try {
    if(!csrf_check_tokens($_POST['__csrf_magic'] ?? '')) throw new RuntimeException('Invalid request token.');
    $action=$_POST['action'] ?? '';
    if($action==='apply') {
        $token=nms_config_text($_POST['review_token'] ?? '',48,'Review token');
        $stored=$_SESSION['nms_node_config_reviews'][$token] ?? null;
        if(!$stored || $stored['node_id']!==$node_id) throw new RuntimeException('Preview is missing. Review the batch again.');
        // Consume before dispatch: retries may not resend any part of a batch.
        unset($_SESSION['nms_node_config_reviews'][$token]);
        $_SESSION['nms_node_config_results'][$node_id]=nms_config_node_apply($stored);
    } else {
        $key=nms_config_text($_POST['field_key'] ?? '',32,'Field');
        $selected=nms_config_node_selection($node_id,$_POST['members'] ?? []);
        $draft=['members'=>$selected,'field_key'=>$key,'requested'=>nms_config_text($_POST['requested'] ?? '',512,'Requested value',false)];
        $_SESSION['nms_node_config_drafts'][$node_id]=$draft;
        if($action==='read') $_SESSION['nms_node_config_results'][$node_id]=nms_config_node_reads($node_id,$selected,$key);
        elseif($action==='preview') {
            $review=nms_config_node_preview($node_id,$selected,$key,$draft['requested']);
            $token=bin2hex(random_bytes(24));
            $_SESSION['nms_node_config_reviews'][$token]=$review;
            $review['token']=$token;
        } else throw new InvalidArgumentException('Unsupported batch action.');
    }
    if(!$review) { header('Location: devices.php?tab=configuration&view=node&node_id='.$node_id,true,303); exit; }
} catch(Throwable $e) { $error=$e->getMessage(); }
$members=nms_node_members($node_id); $fields=[];
// A saved form is convenience only. Removed or inaccessible members cannot remain selected.
$draft['members']=array_values(array_intersect($draft['members'],array_column($members,'id')));
foreach($members as $member) try {
    $target=nms_config_target($member['id']);
    foreach($target['fields'] as $key=>$field) if($field['writable']) $fields[$key]=$field['label'].' ('.$key.')';
} catch(Throwable $e) {}
$results=$_SESSION['nms_node_config_results'][$node_id] ?? [];
foreach($results as $id=>&$result) {
    try {
        nms_require_device_access($id);
        if(isset($result['job_id'])) {
            $job=db_fetch_row_prepared('SELECT status,result_json FROM plugin_nms_config_jobs WHERE id=? AND host_id=? AND user_id=?',[$result['job_id'],$id,nms_current_user_id()]);
            if(!$job) throw new RuntimeException('Request is no longer available.');
            $result['status']=$job['status']; $result['details']=json_decode($job['result_json'],true) ?: [];
        }
    } catch(Throwable $e) { $result=['status'=>'unavailable','error'=>'Device or request is no longer accessible.']; }
} unset($result);
nms_prepare_page('devices','NMS · Node configuration','css/nms-devices.css,css/nms-nodes.css','');
require __DIR__.'/../../templates/app_header.php';
require __DIR__.'/../../templates/devices/node_configuration.php';
require __DIR__.'/../../templates/app_footer.php';
