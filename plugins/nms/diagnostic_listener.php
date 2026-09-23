<?php
/** Plugin-owned collector runner. Started/recovered by the existing poller; no OS service. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../../include/cli_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/diagnostics_queue.php';
require_once __DIR__ . '/includes/inventory.php';
try {
    nms_diag_worker_database();
    if (!nms_database_ready()) exit(1);
    $collector = nms_inventory_collector_id();
    $lock = 'nms_diag_listener_' . $collector;
    if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?,0)', [$lock]) !== 1) exit;
    $connection = (int) db_fetch_cell('SELECT CONNECTION_ID()');
    $key = 'diagnostic_runner_' . $collector;
    $revision = hash_file('sha256', __FILE__);
    $tools = []; $lastTools = 0;
    try {
        while ((int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory=?', ['nms']) === 1) {
            // A reconnect loses named locks. Never advertise a runner that no longer owns its lock.
            if ((int) db_fetch_cell_prepared('SELECT IS_USED_LOCK(?)', [$lock]) !== $connection) break;
            clearstatcache(true, __FILE__);
            if (hash_file('sha256', __FILE__) !== $revision) break;
            if (time() - $lastTools >= 60) {
                foreach (nms_diag_labels() as $tool => $label) {
                    $program = $tool === 'arp' ? 'ip' : $tool;
                    $tools[$tool] = (bool) nms_diag_program($program);
                    if ($tool === 'traceroute' && !$tools[$tool]) $tools[$tool] = (bool) nms_diag_program('tracepath');
                }
                $tools['pathchar'] = $tools['pathchar'] || (bool) nms_diag_program('pchar');
                $lastTools = time();
            }
            nms_category_execute("INSERT INTO plugin_nms_meta(meta_key,meta_value,updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()", [$key, json_encode(['tools'=>$tools])]);
            if (db_fetch_cell_prepared("SELECT id FROM plugin_nms_diagnostic_jobs WHERE poller_id=? AND status IN ('queued','running') LIMIT 1", [$collector])) {
                // Fresh PHP process rechecks the current account, device and profile permissions.
                $child = nms_diag_run_command([PHP_BINARY, '-q', __DIR__ . '/diagnostic_worker.php'], 75);
                if ($child['exit'] !== 0) cacti_log('NMS diagnostic runner: worker exited unsuccessfully.', false, 'NMS');
            }
            sleep(1);
        }
    } finally {
        if ((int) db_fetch_cell_prepared('SELECT IS_USED_LOCK(?)', [$lock]) === $connection) {
            nms_category_execute('DELETE FROM plugin_nms_meta WHERE meta_key=?', [$key]);
            db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
} catch (Throwable $error) {
    cacti_log('NMS diagnostic runner: ' . $error->getMessage(), false, 'NMS');
    exit(1);
}
