#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') {
    exit(1);
}
try {
    require dirname(__DIR__, 2) . '/../../include/cli_check.php';
    require_once dirname(__DIR__, 2) . '/shared/services/backend.php';
    require_once dirname(__DIR__) . '/services/checks.php';
    if (in_array('--save', $argv, true)) {
        icct_nms_installation_save_services();
    }
    echo json_encode(
        icct_nms_installation_checks(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
