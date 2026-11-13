#!/usr/bin/env php
<?php
// Stop the collector first and back up flow/file/bucket/state tables before running.
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../../../../../include/cli_check.php';
require_once __DIR__ . '/../../../shared/services/bootstrap.php';
require_once __DIR__ . '/../services/netflow_service.php';
require_once __DIR__ . '/../services/netflow_collector.php';
icct_nms_backend();
$lock = 'icct_netflow_worker_' . icct_backend_inventory_collector_id();
if ((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,0)', [$lock]) !== 1) {
    throw new RuntimeException('Stop the NetFlow collector before upgrading capture identities.');
}
icct_backend_category_execute('ALTER TABLE plugin_icct_nms_netflow_files ADD COLUMN IF NOT EXISTS payload_key char(64) DEFAULT NULL, ADD UNIQUE INDEX IF NOT EXISTS capture_payload(payload_key)', []);
$removed = 0;
icct_backend_category_execute('START TRANSACTION', []);
try {
    $files = db_fetch_assoc_prepared('SELECT * FROM plugin_icct_nms_netflow_files WHERE payload_key IS NULL ORDER BY imported_at,capture_key', []);
    foreach ($files as $file) {
        $flows = db_fetch_assoc_prepared('SELECT * FROM plugin_icct_nms_netflow_flows WHERE capture_key=? ORDER BY record_number', [$file['capture_key']]);
        // A partially expired capture cannot be safely matched to the original payload.
        if (!$flows || count($flows) !== (int)$file['records_count']) continue;
        $payload = icct_nms_netflow_payload_key((int)$file['host_id'], $flows);
        $existing = db_fetch_cell_prepared('SELECT capture_key FROM plugin_icct_nms_netflow_files WHERE payload_key=?', [$payload]);
        if (!$existing) {
            icct_backend_category_execute('UPDATE plugin_icct_nms_netflow_files SET payload_key=? WHERE capture_key=?', [$payload,$file['capture_key']]);
            continue;
        }
        foreach ($flows as $flow) {
            icct_backend_category_execute('UPDATE plugin_icct_nms_netflow_buckets SET total_bytes=GREATEST(0,CAST(total_bytes AS SIGNED)-?),total_packets=GREATEST(0,CAST(total_packets AS SIGNED)-?),total_flows=GREATEST(0,CAST(total_flows AS SIGNED)-1) WHERE host_id=? AND bucket_start=FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(?)/300)*300)', [$flow['bytes'],$flow['packets'],$file['host_id'],$flow['last_seen']]);
        }
        icct_backend_category_execute('UPDATE plugin_icct_nms_netflow_state SET total_bytes=GREATEST(0,CAST(total_bytes AS SIGNED)-?),total_packets=GREATEST(0,CAST(total_packets AS SIGNED)-?),total_flows=GREATEST(0,CAST(total_flows AS SIGNED)-?) WHERE host_id=?', [array_sum(array_column($flows,'bytes')),array_sum(array_column($flows,'packets')),count($flows),$file['host_id']]);
        icct_backend_category_execute('DELETE FROM plugin_icct_nms_netflow_flows WHERE capture_key=?', [$file['capture_key']]);
        icct_backend_category_execute('UPDATE plugin_icct_nms_netflow_files SET records_count=0 WHERE capture_key=?', [$file['capture_key']]);
        $removed += count($flows);
    }
    icct_backend_category_execute('COMMIT', []);
    echo "Removed $removed rows from confirmed duplicate captures; corrected counters.\n";
} catch (Throwable $e) {
    icct_backend_category_execute('ROLLBACK', []);
    throw $e;
} finally {
    db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
}
