#!/usr/bin/env php
<?php
/** Bounded retention cleanup for the plugin-owned Syslog event table. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require __DIR__ . '/../../../../../include/cli_check.php';
require_once __DIR__ . '/../../../shared/services/bootstrap.php';
$options = getopt('', ['days::', 'batch::']);
try {
    // These are explicit retention presets; invalid operator input must fail visibly.
    $days = filter_var($options['days'] ?? 90, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 3650],
    ]);
    $batch = filter_var($options['batch'] ?? 5000, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 100, 'max_range' => 50000],
    ]);
    if ($days === false || $batch === false) {
        throw new InvalidArgumentException('Days must be 1–3650 and batch must be 100–50000.');
    }
    icct_nms_backend();
    $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
    $total = 0;
    do {
        icct_backend_syslog_execute(
            'DELETE FROM plugin_icct_nms_syslog_events WHERE event_time<? ORDER BY id LIMIT ' .
                (int) $batch,
            [$cutoff],
        );
        $deleted = (int) db_fetch_cell('SELECT ROW_COUNT()');
        $total += $deleted;
        if ($deleted <= 0) {
            break;
        }
        usleep(100000);
    } while ($deleted === $batch);
    print "Syslog retention cleanup complete. Deleted {$total} event(s) older than {$days} days.\n";
} catch (Throwable $error) {
    cacti_log('ICCT NMS Syslog maintenance: ' . $error->getMessage(), false, 'ICCT NMS');
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
