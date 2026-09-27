<?php
/** Device-scoped serial connection setup, dispatched by the authenticated device controller. */
require_once __DIR__.'/service.php';
try {
    nms_require_management();
    $host_id=nms_config_integer($_GET['id'] ?? 0,0,16777215,'Device ID');
    $pollers=db_fetch_assoc("SELECT id,name FROM poller WHERE disabled='' ORDER BY id");
    if($host_id) {
    nms_require_device_access($host_id);
    $host=db_fetch_row_prepared("SELECT id,description,poller_id FROM host WHERE id=? AND deleted=''",[$host_id]);
    if (!$host) throw new InvalidArgumentException('Device not found.');
    } else {
        $collector=nms_config_integer($_POST['poller_id'] ?? $_GET['poller_id'] ?? ($pollers[0]['id'] ?? 0),1,2147483647,'Collector');
        if(!db_fetch_cell_prepared("SELECT id FROM poller WHERE id=? AND disabled=''",[$collector])) throw new InvalidArgumentException('Select an enabled collector.');
        $host=['id'=>0,'description'=>'Add serial device','poller_id'=>$collector];
    }
} catch(Throwable $e) { http_response_code(403); die(nms_h($e->getMessage())); }
$error=''; $preview=null; $refresh_preview=null;
$values=['name'=>'','transport'=>'direct','endpoint'=>'','port'=>4001,'profile_id'=>0];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    foreach($values as $key=>$default) if(isset($_POST[$key]) && is_scalar($_POST[$key])) $values[$key]=$_POST[$key];
    try {
        if(!csrf_check_tokens($_POST['__csrf_magic'] ?? '')) throw new RuntimeException('Invalid request token.');
        $input=$_POST;
        $input['poller_id']=$host['poller_id'];
        switch($input['action'] ?? '') {
            case 'create_device':
                if($host_id) throw new InvalidArgumentException('Use Add serial device to create a device.');
                require_once __DIR__.'/device.php';
                $created=nms_serial_device_create($input);
                header('Location: devices.php?tab=edit&id='.$created.'&saved=1',true,303); exit;
            case 'preview_connection':
                nms_serial_endpoint($input);
                nms_config_text($input['name'] ?? '',150,'Connection name');
                $preview=nms_serial_profile_get($input['profile_id'] ?? 0);
                break;
            case 'create_connection':
                nms_serial_connection_create($input);
                header('Location: devices.php?tab=connection&id='.$host_id.'&poller_id='.$host['poller_id'].'&created=1',true,303); exit;
            case 'preview_refresh':
                $refresh_preview=nms_serial_refresh_preview($input['refresh_id'] ?? 0);
                break;
            case 'apply_refresh':
                nms_serial_refresh_apply($input['refresh_id'] ?? 0,$input['fingerprint'] ?? '');
                header('Location: devices.php?tab='.((($_POST['return_to'] ?? '')==='edit')?'edit':'connection').'&id='.$host_id.'&saved=1#serial-setup',true,303); exit;
            case 'assign':
                if(!$host_id) throw new InvalidArgumentException('Create a device before editing its assignment.');
                nms_serial_assign($host_id,$input);
                header('Location: devices.php?tab='.((($_POST['return_to'] ?? '')==='edit')?'edit':'connection').'&id='.$host_id.'&saved=1#serial-setup',true,303); exit;
            default: throw new InvalidArgumentException('Unsupported action.');
        }
    } catch(Throwable $e) { $error=$e->getMessage(); }
}
$assignment=$host_id?nms_serial_assignment($host_id):null;
$sites=$host_id?[]:db_fetch_assoc('SELECT id,name FROM sites ORDER BY name');
$nodes=$host_id?[]:nms_nodes_list();
$connections=nms_serial_connections($host['poller_id']);
$profiles=nms_serial_profiles();
nms_prepare_page('devices','NMS · Device connection','css/nms-devices.css,css/nms-nodes.css','');
require __DIR__.'/../../templates/app_header.php';
require __DIR__.'/../../templates/devices/connection.php';
require __DIR__.'/../../templates/app_footer.php';
