<?php
/** Shared equipment profiles, device setup, read/preview/apply and history screen. */
require_once __DIR__.'/monitoring.php';
if(($_GET['view'] ?? '')==='node') { require __DIR__.'/node_page.php'; return; }
try { nms_require_management(); } catch(Throwable $e) { http_response_code(403); die(nms_h($e->getMessage())); }
foreach(($_SESSION['nms_config_preview'] ?? []) as $key=>$review) if(($review['expires'] ?? 0)<time()) unset($_SESSION['nms_config_preview'][$key]);
$error=''; $notice=''; $preview=null; $target=null;
$view=($_GET['view'] ?? '')==='profiles'?'profiles':'devices';
$host_id=0; $profile_id=0; $node_id=0;
$values=['id'=>0,'revision'=>0,'name'=>'','manufacturer'=>'','model'=>'','manual_reference'=>'','protocol'=>'modbus_rtu','fields_json'=>'[]'];
try {
    $host_id=nms_config_integer($_GET['id'] ?? 0,0,16777215,'Device ID');
    $profile_id=nms_config_integer($_GET['profile_id'] ?? 0,0,2147483647,'Profile ID');
    $node_id=nms_config_integer($_GET['node_id'] ?? 0,0,2147483647,'Node ID');
    if($node_id) {
        require_once __DIR__.'/node.php';
        nms_node_get($node_id);
        // Check the selected container before any assignment, read or write action.
        if($host_id) nms_config_node_selection($node_id,[$host_id]);
    }
    if($host_id) nms_require_device_access($host_id);
    if($_SERVER['REQUEST_METHOD']==='POST') {
        if(!csrf_check_tokens($_POST['__csrf_magic'] ?? '')) throw new RuntimeException('Invalid request token.');
        $action=$_POST['action'] ?? '';
        if($action==='save_profile' && $view==='profiles') {
            $saved=nms_equipment_profile_save($_POST);
            header('Location: devices.php?tab=configuration&view=profiles&profile_id='.$saved.'&saved=1',true,303); exit;
        }
        if(!$host_id) throw new InvalidArgumentException('Select a device.');
        if($action==='assign_equipment') {
            nms_equipment_assign($host_id,$_POST);
            header('Location: devices.php?tab='.((($_POST['return_to'] ?? '')==='edit')?'edit':'configuration').'&id='.$host_id.'&saved=1#serial-setup',true,303); exit;
        }
        if($action==='create_graphs') {
            require_once __DIR__.'/graphs.php';
            nms_config_graphs_create($host_id);
            header('Location: devices.php?tab=configuration&id='.$host_id.'&graphs_created=1',true,303); exit;
        }
        if($action==='read') {
            nms_config_job_enqueue($host_id,$_POST['field_key'] ?? '');
            header('Location: devices.php?tab=configuration&id='.$host_id.'&queued=1',true,303); exit;
        }
        if($action==='preview_write') {
            $target=nms_config_target($host_id);
            $key=nms_config_text($_POST['field_key'] ?? '',32,'Field key');
            $field=$target['fields'][$key] ?? null;
            if(!$field || !$field['writable']) throw new InvalidArgumentException('Select a writable field.');
            $requested=nms_equipment_value($field,$_POST['requested'] ?? null);
            $read=db_fetch_row_prepared("SELECT * FROM plugin_nms_config_jobs WHERE id=? AND host_id=? AND field_key=? AND user_id=? AND operation='read' AND status='complete' AND finished_at>DATE_SUB(NOW(),INTERVAL 5 MINUTE)",[nms_config_integer($_POST['preview_id'] ?? 0,1,PHP_INT_MAX,'Read ID'),$host_id,$key,nms_current_user_id()]);
            if(!$read || !hash_equals($read['signature'],$target['signature'])) throw new RuntimeException('Read the current value again before previewing.');
            $old=json_decode($read['result_json'],true,32,JSON_THROW_ON_ERROR);
            $token=bin2hex(random_bytes(24));
            $preview=['token'=>$token,'read_id'=>$read['id'],'field'=>$field,'before'=>$old['value'],'requested'=>$requested];
            // Final form refers to server-side reviewed values, not editable hidden values.
            $_SESSION['nms_config_preview'][$token]=['host_id'=>$host_id,'read_id'=>$read['id'],'field_key'=>$key,'requested'=>$requested,'expires'=>time()+300];
        } elseif($action==='apply_write') {
            $token=nms_config_text($_POST['review_token'] ?? '',48,'Review token');
            $review=$_SESSION['nms_config_preview'][$token] ?? null;
            if(!$review || $review['host_id']!==$host_id || $review['expires']<time()) throw new RuntimeException('Preview expired. Review the change again.');
            nms_config_job_enqueue($host_id,$review['field_key'],'write',$review['requested'],$review['read_id']);
            unset($_SESSION['nms_config_preview'][$token]);
            header('Location: devices.php?tab=configuration&id='.$host_id.'&queued=1',true,303); exit;
        } else throw new InvalidArgumentException('Unsupported configuration action.');
    }
    if($view==='profiles' && $profile_id) {
        $row=db_fetch_row_prepared('SELECT * FROM plugin_nms_config_profiles WHERE id=?',[$profile_id]);
        if(!$row) throw new InvalidArgumentException('Equipment profile not found.');
        $values=array_replace($values,$row);
        $values['fields_json']=json_encode(json_decode($row['fields_json'],true),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    }
} catch(Throwable $e) {
    $error=$e->getMessage();
    if($view==='profiles' && $_SERVER['REQUEST_METHOD']==='POST') foreach($values as $key=>$default) if(isset($_POST[$key]) && is_scalar($_POST[$key])) $values[$key]=$_POST[$key];
}
$profiles=db_fetch_assoc('SELECT * FROM plugin_nms_config_profiles ORDER BY name');
$visible=nms_visible_host_sql();
$node_join=$node_id?' JOIN plugin_nms_node_devices nd ON nd.host_id=h.id AND nd.node_id='.(int)$node_id:'';
$devices=db_fetch_assoc("SELECT h.id,h.description,h.hostname FROM host h $node_join WHERE h.deleted='' AND $visible ORDER BY h.description");
$host=null; foreach($devices as $device) if((int)$device['id']===$host_id) $host=$device;
if($host_id && !$host) { $error='Device is not accessible in this selection.'; $host_id=0; }
$assignment=$host_id?db_fetch_row_prepared('SELECT * FROM plugin_nms_config_devices WHERE host_id=?',[$host_id]):[];
$history=$host_id?nms_config_job_history($host_id):[];
if($assignment) try { $target=nms_config_target($host_id); } catch(Throwable $e) { if(!$error) $error=$e->getMessage(); }
$reading_jobs=[];
if($target) foreach(db_fetch_assoc_prepared("SELECT * FROM plugin_nms_config_jobs WHERE host_id=? AND user_id=? AND operation='read' AND status='complete' AND finished_at>DATE_SUB(NOW(),INTERVAL 5 MINUTE) ORDER BY id DESC",[$host_id,nms_current_user_id()]) as $read) {
    if(hash_equals($target['signature'],$read['signature']) && !isset($reading_jobs[$read['field_key']])) $reading_jobs[$read['field_key']]=$read;
}
nms_prepare_page($view==='profiles'?'presets':'devices',$view==='profiles'?'NMS · Equipment profiles':'NMS · Configuration','css/nms-devices.css,css/nms-nodes.css','');
require __DIR__.'/../../templates/app_header.php';
require __DIR__.'/../../templates/devices/configuration.php';
require __DIR__.'/../../templates/app_footer.php';
