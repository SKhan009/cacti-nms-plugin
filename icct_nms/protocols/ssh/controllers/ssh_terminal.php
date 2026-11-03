<?php

require dirname(__DIR__, 3) . '/../../include/auth.php';
require_once dirname(__DIR__, 3) . '/shared/services/bootstrap.php';
require_once __DIR__ . '/../services/console_service.php';
try {
    icct_nms_backend();
    icct_backend_ssh_https();
    icct_backend_require_management(3);
    $id = icct_nms_id($_GET['host_id'] ?? 0);
    $device = icct_nms_ssh_console_device($id);
    if (!$device['host_key'] || $device['verified_endpoint'] !== $device['endpoint']) {
        throw new RuntimeException('Approve the device host key before opening its terminal.');
    }
    $title = 'SSH Terminal';
    header('Cache-Control: no-store');
    require dirname(__DIR__, 3) . '/shared/templates/header.php';
    require __DIR__ . '/../templates/terminal.php';
    require dirname(__DIR__, 3) . '/shared/templates/footer.php';
} catch (Throwable $error) {
    icct_nms_failure($error);
}
