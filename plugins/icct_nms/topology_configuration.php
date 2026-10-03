<?php
require __DIR__.'/../../include/auth.php';
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/inventory.php';
require_once __DIR__.'/includes/connection_service.php';
require_once __DIR__.'/includes/protocol_service.php';
require_once __DIR__.'/includes/topology_configuration_service.php';
$error='';$tab=$_GET['tab']??'connections';if(!in_array($tab,['connections','networks','discovered'],true))$tab='connections';
try{
    icct_nms_backend();$management=is_realm_allowed(3);$automation=is_realm_allowed(23);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        try{icct_nms_post();$action=$_POST['action']??'';
            if($action==='save_link')icct_nms_connection_save($_POST);
            elseif($action==='delete_link')icct_nms_connection_delete($_POST['link_id']??'');
            elseif(in_array($action,['save_discovery','discover_now'],true)){
                icct_backend_require_management(3);
                $id=icct_nms_id($_POST['host_id']??'');icct_backend_require_device_access($id);$device=icct_nms_device($id);
                if($action==='save_discovery')icct_nms_save_discovery($id,$_POST);
                else icct_backend_nd_test_queue($id,$device['site_id']);
            }else throw new InvalidArgumentException('Unknown action.');
            $_SESSION['icct_nms_notice']=$action==='discover_now'?'Discovery queued for the assigned collector. Refresh after the next poll cycle.':'Topology configuration saved.';
            icct_nms_redirect('topology_configuration.php?tab='.$tab);
        }catch(Throwable $failure){$error=$failure->getMessage();}
    }
    $devices=icct_nms_inventory();$byId=array_column($devices,null,'id');$profiles=icct_nms_connections();$links=array_filter(icct_nms_manual_links(),fn($link)=>isset($byId[$link['source']],$byId[$link['target']]));
    $observations=icct_nms_topology_discovery_rows($devices);$networks=[];$coreDevices=[];
    if($automation){$networks=db_fetch_assoc('SELECT id,name,subnet_range,enabled,total_ips,up_hosts,snmp_hosts,last_started,last_status FROM automation_networks ORDER BY name');
        $coreDevices=db_fetch_assoc('SELECT d.id,d.network_id,d.hostname,d.ip,d.sysName,d.sysLocation,d.os,d.snmp,d.known,d.up,FROM_UNIXTIME(d.time) AS last_check,n.name AS network FROM automation_devices d LEFT JOIN automation_networks n ON n.id=d.network_id ORDER BY d.ip');}
    $edit=$links[$_GET['edit']??'']??[];if($error && ($_POST['action']??'')==='save_link')$edit=$_POST;
}catch(Throwable $failure){icct_nms_failure($failure);}
$title='Topology Configuration';$notice=$_SESSION['icct_nms_notice']??'';unset($_SESSION['icct_nms_notice']);$topologyConfigurationPage=true;
require __DIR__.'/templates/header.php';require __DIR__.'/templates/topology_configuration.php';require __DIR__.'/templates/footer.php';
