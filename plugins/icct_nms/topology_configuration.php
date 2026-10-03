<?php
require __DIR__.'/../../include/auth.php';
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/inventory.php';
require_once __DIR__.'/includes/connection_service.php';
require_once __DIR__.'/includes/protocol_service.php';
require_once __DIR__.'/includes/topology_configuration_service.php';
require_once __DIR__.'/includes/network_configuration_service.php';
$error='';$tab=$_GET['tab']??'connections';if(!in_array($tab,['connections','networks','discovered'],true))$tab='connections';
try{
    icct_nms_backend();if($_SERVER['REQUEST_METHOD']==='GET'&&($_GET['tab']??'')==='diagnostics')icct_nms_redirect('topology_configuration.php?tab=connections');$management=is_realm_allowed(3);$automation=is_realm_allowed(23);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        try{icct_nms_post();$action=$_POST['action']??'';
            if(in_array($action,['save_network','run_network'],true)){
                if($action==='save_network')icct_nms_network_save($_POST);else icct_nms_network_run($_POST['network_id']??'');
            }elseif($action==='discover_device'){
                $id=icct_backend_topology_integer($_POST['host_id']??'',1,16777215,'Device');
                icct_backend_require_device_access($id);
                $host=db_fetch_row_prepared("SELECT id,site_id FROM host WHERE id=? AND deleted='' AND disabled=''",[$id]);
                if(!$host)throw new InvalidArgumentException('Select an enabled device.');
                $assignment=array_column(icct_backend_nd_hosts(),null,'id')[$id]??null;
                if(!$assignment || !icct_backend_nd_host_methods($assignment))throw new InvalidArgumentException('Enable SNMP and select discovery methods in this device’s Protocol Config.');
                icct_backend_nd_test_queue($id,(int)$host['site_id']);
            }elseif($action==='save_link')icct_nms_connection_save($_POST);
            elseif($action==='delete_link')icct_nms_connection_delete($_POST['link_id']??'');
            else throw new InvalidArgumentException('Unknown action.');
            $_SESSION['icct_nms_notice']=in_array($action,['run_network','discover_device'],true)?'Discovery requested for the assigned collector.':'Topology configuration saved.';
            icct_nms_redirect('topology_configuration.php?tab='.$tab);
        }catch(Throwable $failure){$error=$failure->getMessage();}
    }
    $devices=icct_nms_inventory();$byId=array_column($devices,null,'id');$profiles=icct_nms_connections();$links=array_filter(icct_nms_manual_links(),fn($link)=>isset($byId[$link['source']],$byId[$link['target']]));
    $deviceDiscovery=[];$deviceDiagnostics=[];
    if($tab==='connections'){foreach($devices as $device){$id=(int)$device['id'];$deviceDiscovery[$id]=icct_nms_device_discovery_summary($id);$deviceDiagnostics[$id]=$management?icct_backend_diag_selected_labels($id):[];}}
    $observations=icct_nms_topology_discovery_rows($devices);$networks=[];$coreDevices=[];
    if($automation){$networks=db_fetch_assoc('SELECT id,name,subnet_range,enabled,total_ips,up_hosts,snmp_hosts,last_started,last_status FROM automation_networks ORDER BY name');
        $coreDevices=db_fetch_assoc('SELECT d.id,d.network_id,d.hostname,d.ip,d.sysName,d.sysLocation,d.os,d.snmp,d.known,d.up,FROM_UNIXTIME(d.time) AS last_check,n.name AS network FROM automation_devices d LEFT JOIN automation_networks n ON n.id=d.network_id ORDER BY d.ip');}
    $networkOptions=[];$networkEdit=null;
    if($automation && $tab==='networks' && (isset($_GET['network_edit']) || ($error && ($_POST['action']??'')==='save_network'))){
        $networkOptions=icct_nms_network_options();$networkEdit=empty($_GET['network_edit'])?icct_nms_network_defaults($networkOptions):icct_nms_network_get($_GET['network_edit']);
        if($error && ($_POST['action']??'')==='save_network'){
            $networkEdit=array_replace($networkEdit,$_POST,['id'=>$_POST['network_id']??0]);
            foreach(['enabled','enable_netbios','add_to_cacti','same_sysname','rerun_data_queries','notification_enabled'] as $key)$networkEdit[$key]=empty($_POST[$key])?'':'on';
            foreach(['day_of_week','month','day_of_month','monthly_week','monthly_day'] as $key)$networkEdit[$key]=$_POST[$key]??[];
        }
    }
    $edit=$links[$_GET['edit']??'']??[];if($error && ($_POST['action']??'')==='save_link')$edit=$_POST;
}catch(Throwable $failure){icct_nms_failure($failure);}
$title='Topology Configuration';$notice=$_SESSION['icct_nms_notice']??'';unset($_SESSION['icct_nms_notice']);$topologyConfigurationPage=true;
require __DIR__.'/templates/header.php';require __DIR__.'/templates/topology_configuration.php';require __DIR__.'/templates/footer.php';
