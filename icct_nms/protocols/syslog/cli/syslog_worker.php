#!/usr/bin/env php
<?php
/** Receive only from the configured rsyslog spool; faults reuse the common alarm journal. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require __DIR__ . '/../../../../../include/cli_check.php';
require_once __DIR__ . '/../../../shared/services/bootstrap.php';
require_once __DIR__ . '/../services/syslog_faults.php';
$options = getopt('', ['file:', 'traps', 'once', 'follow', 'sleep::']);
$file = $options['file'] ?? '';
$sleep = filter_var($options['sleep'] ?? 0.5, FILTER_VALIDATE_FLOAT);
if (!is_string($file) || $file === '' || $sleep === false || $sleep < 0.1 || $sleep > 10) {
    fwrite(STDERR, "Supply --file and a sleep interval between 0.1 and 10 seconds.\n");
    exit(2);
}
$traps = isset($options['traps']);
$ingestLine = $traps ? 'icct_nms_trap_ingest_line' : null;
$once = isset($options['once']) || !isset($options['follow']);
try {
    icct_nms_backend();
    do {
        if (
            (int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory=?', [
                'icct_nms',
            ]) !== 1
        ) {
            break;
        }
        clearstatcache(true, $file);
        $result = is_file($file)
            ? icct_backend_syslog_import_spool_once($file, 2000, $ingestLine)
            : null;
        // The durable outbox is drained even when no new packets arrive after an upgrade/restart.
        $faults = icct_backend_syslog_sync_faults();
        [, $state] = icct_backend_syslog_worker_state($file);
        icct_backend_syslog_heartbeat(
            [
                'pid' => getmypid(),
                'file' => $file,
                'status' => $result === null ? 'waiting-for-spool' : 'running',
                'offset' => (int) ($state['source_offset'] ?? 0),
                'ingested' => (int) ($state['records_ingested'] ?? 0),
                'dropped' => (int) ($state['records_dropped'] ?? 0),
                'errors' => (int) ($state['errors'] ?? 0),
                'faults_normalized' => $faults,
                'last_record_at' => $state['last_record_at'] ?? null,
                'drop_reasons' => $result['drop_reasons'] ?? [],
                'last_error' => implode('; ', $result['error_messages'] ?? []),
            ],
            $traps ? 'trap_worker_status' : 'syslog_worker_status',
        );
        if (!$once && ($result['processed'] ?? 0) < 2000 && $faults < 200) {
            usleep((int) ($sleep * 1000000));
        }
    } while (!$once);
} catch (Throwable $error) {
    cacti_log('ICCT NMS Syslog worker: ' . $error->getMessage(), false, 'ICCT NMS');
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
