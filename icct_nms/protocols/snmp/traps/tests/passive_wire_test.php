<?php

/** Run only in the disposable RHEL database and loopback receiver namespace. */
require '/var/www/html/cacti/include/cli_check.php';
if (!str_starts_with((string) db_fetch_cell('SELECT DATABASE()'), 'icct_discovery_qa_')) {
    throw new RuntimeException('Disposable passive event QA database required.');
}
api_plugin_load_realms();
require_once $config['base_path'] . '/lib/auth.php';
$_SESSION = ['sess_user_id' => 1];
require_once __DIR__ . '/../../../../shared/services/bootstrap.php';
icct_nms_backend();
require_once __DIR__ . '/../../../syslog/services/syslog_faults.php';

function passiveWireCheck($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$rules = [
    ['source' => 'syslog', 'program' => 'ICCT_PASSIVE', 'message' => 'port 7 down'],
    ['source' => 'trap', 'oid' => '1.3.6.1.6.3.1.1.5.3', 'varbind' => '1.3.6.1.2.1.2.2.1.1.7', 'value' => '7'],
];
foreach ($rules as &$rule) {
    $rule += ['group' => 'qa-port-7-down', 'name' => 'QA port 7 down', 'severity' => 'Major', 'enabled' => true];
}
unset($rule);
icct_nms_save_trap_sender(2, ['trap_enabled' => 1, 'trap_source_address' => '127.0.0.1']);
icct_nms_save_passive_faults(2, ['passive_rules' => json_encode($rules, JSON_THROW_ON_ERROR)]);
passiveWireCheck(count(icct_nms_passive_fault_rules(2)) === 2, 'Saved device event rules did not reload.');

$spool = $argv[1] ?? '';
$trap = icct_backend_syslog_import_spool_once($spool, 2000, 'icct_nms_trap_ingest_line');
passiveWireCheck($trap['inserted'] === 2 && $trap['repeated'] === 1 && $trap['errors'] === 0, 'Trap storage did not preserve two distinct notifications and one repeat.');
$socket = stream_socket_client('udp://127.0.0.1:1514');
$stamp = gmdate('Y-m-d\TH:i:s\Z');
fwrite($socket, '<132>1 ' . $stamp . ' arbitrary ICCT_PASSIVE - - - port 7 down');
fclose($socket);
$until = microtime(true) + 4;
do {
    icct_backend_syslog_import_spool_once('/var/log/icct-nms/syslog-qa.ndjson');
    $syslog = db_fetch_row("SELECT * FROM plugin_icct_nms_syslog_events WHERE program='ICCT_PASSIVE'");
    if ($syslog) {
        break;
    }
    usleep(20000);
} while (microtime(true) < $until);
passiveWireCheck((bool) $syslog, 'The received Syslog message was not stored.');
icct_backend_syslog_sync_faults();
icct_backend_syslog_sync_faults();
$total = 0;
$events = icct_backend_syslog_events([2], ['host_id' => 2, 'origin' => 'trap'], 25, 0, $total);
passiveWireCheck($total === 2, 'Trap history does not contain the two real trap records.');
$down = array_values(array_filter($events, static fn ($event) => $event['program'] === '1.3.6.1.6.3.1.1.5.3'))[0];
$up = array_values(array_filter($events, static fn ($event) => $event['program'] === '1.3.6.1.6.3.1.1.5.4'))[0];
$linked = db_fetch_cell_prepared('SELECT alarm_key FROM plugin_icct_nms_syslog_events WHERE id=?', [$syslog['id']]);
passiveWireCheck($down['alarm_key'] !== null && $linked === $down['alarm_key'] && $up['alarm_key'] === null, 'Grouping or history-only retention failed.');
$alarm = db_fetch_row_prepared('SELECT * FROM plugin_icct_nms_alarms WHERE alarm_key=?', [$linked]);
passiveWireCheck($alarm['source'] === 'Passive events' && (int) $alarm['occurrences'] === 3 && $alarm['severity'] === 'Major', 'Grouped alarm count or severity is incorrect.');
passiveWireCheck($down['severity_code'] === null && $down['facility_code'] === null, 'Trap history invented Syslog severity or facility.');
$trapPayload = json_decode($down['raw_message'], true, 32, JSON_THROW_ON_ERROR);
passiveWireCheck($trapPayload['1.3.6.1.2.1.2.2.1.1.7'] === ['7'], 'The reported interface index was lost.');
echo "PASS: real Syslog and authenticated SNMPv2c wire input, repeat-safe grouping, unmatched trap history and original interface evidence.\n";
