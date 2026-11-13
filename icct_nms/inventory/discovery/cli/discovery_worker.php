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
    require_once $config['base_path'] . '/lib/auth.php';
    require_once __DIR__ . '/../services/discovery_workflow.php';
    require_once __DIR__ . '/../services/discovery_collector.php';
    require_once __DIR__ . '/../services/inventory_events.php';
    $options = getopt('', ['once']);
    do {
        if (
            (int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory=?', [
                'icct_nms',
            ]) !== 1
        ) {
            if (isset($options['once'])) {
                break;
            }
            sleep(5);
            continue;
        }
        icct_nms_scan_native_runs((int) $config['poller_id']);
        icct_nms_inventory_observe((int) $config['poller_id']);
        $id = icct_nms_scan_work((int) $config['poller_id']);
        if (isset($options['once'])) {
            echo json_encode(['job' => $id]) . PHP_EOL;
            break;
        }
        sleep(5);
    } while (true);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
