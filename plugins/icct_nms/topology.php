<?php
/** Dashboard geographic topology; Cacti controls page and per-device authorization. */
require __DIR__.'/../../include/auth.php';
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/inventory.php';
require_once __DIR__.'/includes/device_type_service.php';
require_once __DIR__.'/includes/graph_service.php';
require_once __DIR__.'/includes/map_service.php';
require_once __DIR__.'/includes/rack_view_service.php';
require_once __DIR__.'/includes/topology_view_service.php';
require_once __DIR__.'/includes/topology_summary_service.php';
require_once __DIR__.'/includes/topology_link_service.php';
require_once __DIR__.'/includes/dashboard_service.php';
try {
    icct_nms_backend();
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['dashboard_preferences'])){
        header('Content-Type: application/json');try{icct_nms_post();echo json_encode(icct_nms_dashboard_save(json_decode($_POST['dashboard_preferences'],true,512,JSON_THROW_ON_ERROR)));}catch(Throwable $e){http_response_code(400);echo json_encode(['error'=>'Unable to save dashboard layout.']);}exit;
    }
    if (isset($_GET['map_tile'])) { icct_nms_map_tile(); exit; }
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['topology_positions'])) {
        header('Content-Type: application/json; charset=utf-8');
        try {icct_nms_post();icct_nms_topology_save(json_decode($_POST['topology_positions'],true,512,JSON_THROW_ON_ERROR),$_POST['revision'] ?? '');echo json_encode(['ok'=>true,'revision'=>hash('sha256',json_encode(icct_nms_topology_layout()))]);}
        catch(Throwable $error){http_response_code(400);echo json_encode(['ok'=>false,'error'=>$error->getMessage()]);}
        exit;
    }
    if(isset($_GET['link_source'],$_GET['link_target'])) {
        $a=icct_nms_id($_GET['link_source']);$b=icct_nms_id($_GET['link_target']);
        icct_backend_require_device_access($a);icct_backend_require_device_access($b);
        $fresh=icct_nms_topology_data(icct_nms_map_data());$selected=null;
        foreach($fresh['links'] as $link)if((int)$link['source']===$a && (int)$link['target']===$b){$selected=$link;break;}
        header('Content-Type: application/json');header('Cache-Control: no-store');
        if(!$selected){http_response_code(404);echo json_encode(['error'=>'Link unavailable.']);exit;}
        $selected['readings']=[icct_nms_link_reading($a,$selected['source_port']??'',(int)($selected['source_ifindex']??0)),icct_nms_link_reading($b,$selected['target_port']??'')];
        echo json_encode($selected,JSON_THROW_ON_ERROR);exit;
    }
    if (isset($_GET['device_summary'])) {
        $summaryId=icct_nms_id($_GET['device_summary']);
        icct_backend_require_device_access($summaryId);
        $fresh=icct_nms_topology_data(icct_nms_map_data());
        $selected=null;foreach($fresh['devices'] as $device)if((int)$device['id']===$summaryId){$selected=$device;break;}
        if(!$selected)throw new RuntimeException('Device unavailable.');
        $hosts=array_column(icct_nms_inventory(),null,'id');
        $selected['capacity']=icct_nms_topology_capacity($hosts[$summaryId]);
        header('Content-Type: application/json');header('Cache-Control: no-store');
        echo json_encode(array_intersect_key($selected,array_flip(['name','address','status','network_asset','summary','capacity','fault_counts'])),JSON_THROW_ON_ERROR);exit;
    }
    $mapData=icct_nms_map_data();
    $dashboardReadings=icct_nms_dashboard_readings($mapData);
    $dashboardReadings['center']=icct_nms_dashboard_server();
    if(isset($_GET['dashboard_readings'])){header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode($dashboardReadings,JSON_THROW_ON_ERROR);exit;}
    $dashboardPreferences=icct_nms_dashboard_preferences();
    if(isset($_GET['node_summary'])) {
        $siteId=icct_nms_id($_GET['node_summary']);$node=null;
        foreach($mapData['sites'] as $site)if((int)$site['id']===$siteId){$node=icct_nms_map_node_summary($site);break;}
        if(!$node)throw new RuntimeException('Node unavailable.');
        header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode($node,JSON_THROW_ON_ERROR);exit;
    }
    if(isset($_GET['site_id'])) {
        $siteId=icct_nms_id($_GET['site_id']);
        $mapData['sites']=array_values(array_filter($mapData['sites'],static fn($site)=>(int)$site['id']===$siteId));
        if(!$mapData['sites'])throw new RuntimeException('Node unavailable.');
        $node=icct_nms_map_node_summary($mapData['sites'][0]);
        $mapData['unlocated']=[];$mapData['counts']=['total'=>$node['counts']['total'],'online'=>$node['counts']['online'],'offline'=>$node['counts']['offline'],'other'=>$node['counts']['disabled']+$node['counts']['other']];
    }
    $mapConfigured=!empty($config['nms_geoserver_wms_url']) && !empty($config['nms_geoserver_layer']);
} catch (Throwable $error) { icct_nms_failure($error); }
$dashboardView=in_array($_GET['view']??'', ['topology','rack','image','map'],true)?$_GET['view']:'topology';
$title='Dashboard'; $mapPage=true;
require __DIR__.'/templates/header.php';
require __DIR__.'/templates/map.php';
require __DIR__.'/templates/footer.php';
