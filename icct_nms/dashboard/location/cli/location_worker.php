#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    exit(1);
}
try {
    require __DIR__ . '/../../../../../include/cli_check.php';
    require_once __DIR__ . '/../../../shared/services/bootstrap.php';
    icct_nms_backend();
    icct_backend_diag_worker_database();
    require_once __DIR__ . '/../services/location_service.php';
    $options = getopt('', ['once']);
    do {
        if (
            (int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory=?', [
                'icct_nms',
            ]) === 1
        ) {
            icct_nms_gnss_collect((int) $config['poller_id']);
        }
        if (isset($options['once'])) {
            break;
        }
        sleep(5);
    } while (true);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
