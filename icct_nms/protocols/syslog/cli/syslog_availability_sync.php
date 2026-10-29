#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require __DIR__ . '/../../../../../include/cli_check.php';
require_once __DIR__ . '/../../../shared/services/bootstrap.php';
try {
    icct_nms_backend();
    $summary = icct_backend_syslog_collect_availability_events();
    echo json_encode($summary, JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $e) {
    cacti_log('ICCT NMS availability event sync: ' . $e->getMessage(), false, 'ICCT NMS');
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
