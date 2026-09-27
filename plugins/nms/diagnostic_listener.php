<?php
/** Collector runner. Startup/recovery belongs to the configured poller or managed service. */
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
    $tools = []; $lastTools = 0; $lastConfiguration = 0;
    $heartbeat = function () use ($lock, $connection, $key, &$tools) {
        if ((int) db_fetch_cell_prepared('SELECT IS_USED_LOCK(?)', [$lock]) !== $connection ||
            (int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory=?', ['nms']) !== 1) {
            throw new RuntimeException('Collector runner no longer owns its lock or NMS was disabled.');
        }
        nms_category_execute("INSERT INTO plugin_nms_meta(meta_key,meta_value,updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()", [$key, json_encode(['tools'=>$tools])]);
    };
    try {
        while ((int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory=?', ['nms']) === 1) {
            // A reconnect loses named locks. Never advertise a runner that no longer owns its lock.
            if ((int) db_fetch_cell_prepared('SELECT IS_USED_LOCK(?)', [$lock]) !== $connection) break;
            clearstatcache(true, __FILE__);
            if (hash_file('sha256', __FILE__) !== $revision) break;
            if (time() - $lastTools >= 60) {
                foreach (nms_diag_labels() as $tool => $label) {
                    [, $binary] = nms_diag_executable($tool);
                    $tools[$tool] = (bool) $binary;
                }
                $lastTools = time();
            }
            $heartbeat();
            if (db_fetch_cell_prepared("SELECT id FROM plugin_nms_diagnostic_jobs WHERE poller_id=? AND status IN ('queued','running') LIMIT 1", [$collector])) {
                // Fresh PHP process rechecks the current account, device and profile permissions.
                $child = nms_diag_run_command([PHP_BINARY, '-q', __DIR__ . '/diagnostic_worker.php'], 75, $heartbeat);
                if ($child['exit'] !== 0) cacti_log('NMS diagnostic runner: worker exited unsuccessfully.', false, 'NMS');
            }
            if (time() - $lastConfiguration >= 10 || db_fetch_cell_prepared("SELECT id FROM plugin_nms_config_jobs WHERE poller_id=? AND status IN ('queued','running') LIMIT 1", [$collector])) {
                $child = nms_diag_run_command([PHP_BINARY, '-q', __DIR__ . '/configuration_worker.php'], 75, $heartbeat);
                $lastConfiguration = time();
                if ($child['exit'] !== 0) cacti_log('NMS configuration worker stopped; interrupted writes will not be replayed.', false, 'NMS');
            }
            if (db_fetch_cell_prepared("SELECT id FROM plugin_nms_scan_runs WHERE poller_id=? AND status IN ('queued','dispatching','running') LIMIT 1", [$collector])) {
                $child = nms_diag_run_command([PHP_BINARY, '-q', __DIR__ . '/workspace_worker.php'], 30, $heartbeat);
                if ($child['exit'] !== 0) cacti_log('NMS scan worker stopped; resumable probe progress is retained.', false, 'NMS');
            }
            if (db_fetch_cell_prepared("SELECT id FROM plugin_nms_candidate_checks WHERE poller_id=? AND status IN ('queued','running') LIMIT 1", [$collector])) {
                $child = nms_diag_run_command([PHP_BINARY, '-q', __DIR__ . '/workspace_candidate.php'], 30, $heartbeat);
                if ($child['exit'] !== 0) cacti_log('NMS candidate worker stopped; interrupted verification is not replayed.', false, 'NMS');
            }
            if (db_fetch_cell_prepared("SELECT id FROM plugin_nms_onboarding_requests WHERE poller_id=? AND status IN ('queued','applying') LIMIT 1", [$collector])) {
                $child = nms_diag_run_command([PHP_BINARY, '-q', __DIR__ . '/workspace_onboarding.php'], 75, $heartbeat);
                if ($child['exit'] !== 0) cacti_log('NMS onboarding worker stopped; interrupted native creation requires review.', false, 'NMS');
            }
            if (db_fetch_cell_prepared("SELECT id FROM plugin_nms_management_changes WHERE poller_id=? AND status IN ('queued_verify','verifying','queued_apply','applying') LIMIT 1", [$collector])) {
                $child = nms_diag_run_command([PHP_BINARY, '-q', __DIR__ . '/workspace_management.php'], 75, $heartbeat);
                if ($child['exit'] !== 0) cacti_log('NMS management-IP worker stopped; interrupted native updates require review.', false, 'NMS');
            }
            if (db_fetch_cell_prepared("SELECT id FROM plugin_nms_service_jobs WHERE poller_id=? AND status IN ('queued','running') LIMIT 1", [$collector])) {
                $child = nms_diag_run_command([PHP_BINARY, '-q', __DIR__ . '/workspace_service.php'], 15, $heartbeat);
                if ($child['exit'] !== 0) cacti_log('NMS service-check worker stopped; interrupted requests are not replayed.', false, 'NMS');
            }
            if (db_fetch_cell_prepared("SELECT id FROM plugin_nms_consolidation_jobs WHERE poller_id=? AND status IN ('queued','verifying','applying','queued_recovery') LIMIT 1", [$collector])) {
                $child = nms_diag_run_command([PHP_BINARY, '-q', __DIR__ . '/workspace_consolidation.php'], 75, $heartbeat);
                if ($child['exit'] !== 0) cacti_log('NMS consolidation worker stopped; possible partial transfers require recovery review.', false, 'NMS');
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
