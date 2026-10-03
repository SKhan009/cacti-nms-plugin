<?php
/** Dashboard geographic topology; Cacti controls page and per-device authorization. */
require __DIR__.'/../../include/auth.php';
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/inventory.php';
require_once __DIR__.'/includes/device_type_service.php';
require_once __DIR__.'/includes/graph_service.php';
require_once __DIR__.'/includes/map_service.php';
require_once __DIR__.'/includes/rack_view_service.php';
try {
    icct_nms_backend();
    if (isset($_GET['map_tile'])) { icct_nms_map_tile(); exit; }
    $mapData=icct_nms_map_data();
    $mapConfigured=!empty($config['nms_geoserver_wms_url']) && !empty($config['nms_geoserver_layer']);
} catch (Throwable $error) { icct_nms_failure($error); }
$title='Dashboard'; $mapPage=true;
require __DIR__.'/templates/header.php';
require __DIR__.'/templates/map.php';
require __DIR__.'/templates/footer.php';
