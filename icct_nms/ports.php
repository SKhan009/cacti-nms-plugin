<?php
require __DIR__ . '/../../include/auth.php';
require_once __DIR__ . '/shared/services/bootstrap.php';
require_once __DIR__ . '/inventory/services/inventory.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    icct_nms_backend();
    $host=icct_nms_device(icct_nms_id($_GET['id'] ?? 0));
    echo json_encode(['ok'=>true,'data'=>icct_backend_ports_view($host)],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
} catch(Throwable $failure) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>$failure->getMessage()]); }
