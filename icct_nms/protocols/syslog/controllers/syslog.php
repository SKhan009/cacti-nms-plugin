<?php
/** Compatibility route: Syslog history now belongs to the device's read-only view. */
require dirname(__DIR__, 3) . '/../../include/auth.php';
require_once dirname(__DIR__, 3) . '/shared/services/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}
$hostId=filter_var($_GET['host_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>16777215]]);
icct_nms_redirect($hostId?'inventory/controllers/device.php?id='.$hostId.'&view=1#view-syslog':'inventory/controllers/inventory.php');
