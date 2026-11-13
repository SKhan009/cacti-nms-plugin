#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../../../../../include/cli_check.php';
require_once __DIR__ . '/../../../shared/services/bootstrap.php';
require_once __DIR__ . '/../services/netflow_service.php';
icct_nms_backend();
$last = 0;
$updated = 0;
do {
    $rows = db_fetch_assoc_prepared("SELECT id,protocol,src_port,dst_port FROM plugin_icct_nms_netflow_flows WHERE application_basis='unreported' AND id>? ORDER BY id LIMIT 1000", [$last]);
    foreach ($rows as $row) {
        $last = (int) $row['id'];
        $app = icct_nms_netflow_application($row);
        if ($app['basis'] === 'unreported') continue;
        db_execute_prepared("UPDATE plugin_icct_nms_netflow_flows SET application=?,application_basis=? WHERE id=? AND application_basis='unreported'", [$app['name'], $app['basis'], $last]);
        $updated++;
    }
} while (count($rows) === 1000);
echo "Enriched $updated flow records with explicitly labelled port-based services.\n";
