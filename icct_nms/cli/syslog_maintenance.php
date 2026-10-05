#!/usr/bin/env php
<?php
/** Bounded retention cleanup for the plugin-owned Syslog event table. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require __DIR__ . '/../../../include/cli_check.php';
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$options = getopt('', ['days::', 'batch::']);
$days = isset($options['days']) ? (int) $options['days'] : 90;
$batch = isset($options['batch']) ? (int) $options['batch'] : 5000;
if ($days < 1 || $days > 3650) $days = 90;
if ($batch < 100 || $batch > 50000) $batch = 5000;
try {
    icct_nms_backend();
    $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));
    $total = 0;
    do {
        icct_backend_syslog_execute(
            'DELETE FROM plugin_icct_nms_syslog_events WHERE event_time<? ORDER BY id LIMIT ' . (int) $batch,
            [$cutoff]
        );
        $deleted = (int) db_fetch_cell('SELECT ROW_COUNT()');
        $total += $deleted;
        if ($deleted <= 0) break;
        usleep(100000);
    } while ($deleted === $batch);
    print "Syslog retention cleanup complete. Deleted {$total} event(s) older than {$days} days.\n";
} catch (Throwable $error) {
    cacti_log('ICCT NMS Syslog maintenance: ' . $error->getMessage(), false, 'ICCT NMS');
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
