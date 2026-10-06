<?php
/** CLI-only worker rechecks the requesting account, device and saved profile before execution. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}
require dirname(__DIR__, 3) . '/../../include/cli_check.php';
require_once dirname(__DIR__, 3) . '/shared/services/bootstrap.php';
try {
    icct_backend_worker_bootstrap();
    icct_backend_diag_worker_database();
    icct_backend_diag_poll();
} catch (Throwable $error) {
    cacti_log('ICCT NMS diagnostic worker: ' . $error->getMessage(), false, 'ICCT NMS');
    exit(1);
}
function icct_backend_worker_bootstrap()
{
    icct_nms_backend();
}
