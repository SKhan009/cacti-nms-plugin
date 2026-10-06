<?php
/** Dashboard geographic topology; Cacti controls page and per-device authorization. */
require dirname(__DIR__, 2) . '/../../include/auth.php';
require_once dirname(__DIR__, 2) . '/shared/services/bootstrap.php';
require_once dirname(__DIR__, 2) . '/inventory/services/inventory.php';
require_once dirname(__DIR__, 2) . '/presets/services/device_type_service.php';
require_once dirname(__DIR__, 2) . '/graphs/services/graph_service.php';
require_once dirname(__DIR__, 2) . '/dashboard/map/services/map_service.php';
require_once dirname(__DIR__, 2) . '/dashboard/rack-view/services/rack_view_service.php';
require_once dirname(__DIR__, 2) . '/dashboard/topology/services/topology_view_service.php';
require_once dirname(__DIR__, 2) . '/dashboard/topology/services/topology_summary_service.php';
require_once dirname(__DIR__, 2) . '/dashboard/topology/services/topology_link_service.php';
require_once dirname(__DIR__, 2) . '/dashboard/topology/services/topology_mtr_service.php';
require_once dirname(__DIR__, 2) . '/dashboard/widgets/services/dashboard_service.php';
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
        $selected['mtr']=[icct_nms_link_mtr($a),icct_nms_link_mtr($b)];
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
    $dashboardPreferences=icct_nms_dashboard_preferences();
    $dashboardSites=icct_nms_dashboard_sites($mapData);
    $availableSiteIds=array_column($dashboardSites,'id');
    foreach($dashboardPreferences['dashboards'] as &$dashboard)if(!in_array($dashboard['site_id'] ?? 0,array_merge([0],$availableSiteIds),true))$dashboard['site_id']=0;unset($dashboard);
    if(isset($_GET['site_summary'])) {
        $siteId=icct_nms_id($_GET['site_summary']);$summary=null;
        foreach($mapData['sites'] as $site)if((int)$site['id']===$siteId){$summary=icct_nms_map_site_summary($site);break;}
        if(!$summary)throw new RuntimeException('Site unavailable.');
        header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode($summary,JSON_THROW_ON_ERROR);exit;
    }
    $savedSite=$dashboardPreferences['dashboards'][$dashboardPreferences['selected']]['site_id'] ?? 0;
    $dashboardSiteId=isset($_GET['site_id'])?icct_backend_topology_integer($_GET['site_id'],0,4294967295,'Site ID'):$savedSite;
    $mapData=icct_nms_dashboard_scope($mapData,$dashboardSiteId);
    $dashboardPreferences['dashboards'][$dashboardPreferences['selected']]['site_id']=$dashboardSiteId;
    $dashboardReadings=icct_nms_dashboard_readings($mapData);
    $dashboardReadings['center']=icct_nms_dashboard_server();
    if(isset($_GET['dashboard_readings'])){header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode($dashboardReadings,JSON_THROW_ON_ERROR);exit;}
    $mapConfigured=!empty($config['nms_geoserver_wms_url']) && !empty($config['nms_geoserver_layer']);
} catch (Throwable $error) { icct_nms_failure($error); }
$dashboardView=in_array($_GET['view']??'', ['topology','rack','image','map'],true)?$_GET['view']:'topology';
$title='Dashboard'; $mapPage=true;
require dirname(__DIR__, 2) . '/shared/templates/header.php';
require dirname(__DIR__, 2) . '/dashboard/map/templates/map.php';
require dirname(__DIR__, 2) . '/shared/templates/footer.php';
