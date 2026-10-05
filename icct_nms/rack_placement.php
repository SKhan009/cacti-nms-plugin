<?php
require __DIR__.'/../../include/auth.php';
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/inventory.php';
require_once __DIR__.'/includes/configuration_history.php';
require_once __DIR__.'/includes/graph_service.php';
require_once __DIR__.'/includes/protocol_service.php';
require_once __DIR__.'/includes/rack_view_service.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
try {
    icct_nms_backend();
    if ($_SERVER['REQUEST_METHOD']==='GET') {echo json_encode(['ok'=>true,'data'=>icct_nms_rack_view_data()],JSON_THROW_ON_ERROR);exit;}
    icct_nms_post();
    if (isset($_POST['moves'])) {
        $moves=json_decode($_POST['moves'],true,512,JSON_THROW_ON_ERROR);
        foreach (icct_nms_rack_save_draft($moves,json_decode($_POST['reservations'] ?? '[]',true,512,JSON_THROW_ON_ERROR)) as $id) icct_nms_configuration_record($id,'Rack placement changed');
    } else {
        $id=icct_nms_id($_POST['host_id'] ?? 0); $host=icct_nms_device($id);
        $rack=icct_backend_topology_integer($_POST['rack_id'] ?? 0,0,2147483647,'Rack');
        icct_nms_rack_place($id,(int)$host['site_id'],$rack,$_POST['units'] ?? [],($_POST['peripheral'] ?? '')==='1',$_POST['revision'] ?? '',$rack===0);
        icct_nms_configuration_record($id,'Rack placement changed');
    }
    echo json_encode(['ok'=>true,'data'=>icct_nms_rack_view_data()],JSON_THROW_ON_ERROR);
} catch (Throwable $error) {http_response_code(400);echo json_encode(['ok'=>false,'error'=>$error->getMessage()]);}
