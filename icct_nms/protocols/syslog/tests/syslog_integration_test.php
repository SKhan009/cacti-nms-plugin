<?php

/** Actual UDP/TCP/IPv6 wire tests, DB-failure recovery and common workflow in a private QA DB. */
require '/var/www/html/cacti/include/cli_check.php';
if (!str_starts_with((string) db_fetch_cell('SELECT DATABASE()'), 'icct_discovery_qa_')) {
    throw new RuntimeException('Disposable Syslog QA database required.');
}
api_plugin_load_realms();
require_once $config['base_path'] . '/lib/auth.php';
$_SESSION = ['sess_user_id' => 1];
require_once __DIR__ . '/../../../shared/services/bootstrap.php';
icct_nms_backend();
require_once __DIR__ . '/../services/syslog_service.php';
$spool = '/var/log/icct-nms/syslog-qa.ndjson';
// Reset only the explicitly guarded private acceptance fixture.
db_execute('DROP TRIGGER IF EXISTS qa_syslog_fail');
db_execute('DROP TRIGGER IF EXISTS qa_fault_fail');
db_execute(
    "DELETE h FROM plugin_icct_nms_alarm_history h JOIN plugin_icct_nms_alarms a ON a.alarm_key=h.alarm_key WHERE a.source='Syslog'",
);
db_execute("DELETE FROM plugin_icct_nms_alarms WHERE source='Syslog'");
db_execute('DELETE FROM plugin_icct_nms_syslog_events');
db_execute('DELETE FROM plugin_icct_nms_syslog_ingest_state');

function wireCheck($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function sendSyslog($message, $transport = 'udp', $address = '127.0.0.1', $bind = null)
{
    $context = stream_context_create(
        $bind === null ? [] : ['socket' => ['bindto' => $bind . ':0']],
    );
    $target =
        $transport .
        '://' .
        (str_contains($address, ':') ? '[' . $address . ']' : $address) .
        ':1514';
    $connection = stream_socket_client($target, $errno, $error, 2, STREAM_CLIENT_CONNECT, $context);
    wireCheck(is_resource($connection), 'Private Syslog socket failed: ' . $error);
    fwrite($connection, $message . ($transport === 'tcp' ? "\n" : ''));
    fclose($connection);
}
function waitSyslog($minimum)
{
    global $spool;
    $until = microtime(true) + 4;
    do {
        clearstatcache(true, $spool);
        $lines = is_file($spool) ? file($spool) : [];
        if (count($lines) >= $minimum) {
            return;
        }
        usleep(20000);
    } while (microtime(true) < $until);
    throw new RuntimeException('Private rsyslog receiver did not flush packets.');
}
function runSyslog($success = true)
{
    global $spool;
    $result = icct_backend_diag_run_command(
        [
            '/usr/sbin/runuser',
            '-u',
            'apache',
            '--',
            '/usr/bin/php',
            __DIR__ . '/../cli/syslog_worker.php',
            '--file=' . $spool,
            '--once',
        ],
        15,
    );
    wireCheck(
        ($result['exit'] === 0) === $success,
        'Unexpected worker result: ' . json_encode($result, JSON_THROW_ON_ERROR),
    );
}
$base = [
    'transport' => 'both',
    'severity_codes' => range(0, 7),
    'facility_codes' => range(0, 23),
    'match_strings' => [],
];
// Reset collector eligibility in the guarded private database, independent of earlier suites.
db_execute("UPDATE host SET disabled='' WHERE id IN (1,2)");
foreach ([2 => '127.0.0.1', 1 => '::1'] as $id => $address) {
    icct_backend_protocol_state_write($id, 'syslog', true);
    icct_nms_save_syslog($id, [], $base + ['source_address' => $address]);
}
foreach ([2 => ['ICCT_DUP', 'ICCT_OUTBOX'], 1 => ['ICCT_V6']] as $id => $programs) {
    $rules = array_map(
        static fn($program) => [
            'source' => 'syslog',
            'name' => $program,
            'program' => $program,
            'severity' => 'Critical',
            'enabled' => true,
        ],
        $programs,
    );
    icct_nms_save_passive_faults($id, [
        'passive_rules' => json_encode($rules, JSON_THROW_ON_ERROR),
    ]);
}
$stamp = gmdate('Y-m-d\TH:i:s\Z');
$duplicate = '<0>1 ' . $stamp . ' claimed-other-device ICCT_DUP - - - emergency wire event';
sendSyslog($duplicate);
sendSyslog($duplicate);
sendSyslog('<132>1 - arbitrary-header ICCT_NIL - - - timestamp omitted', 'tcp');
sendSyslog('<134>1 ' . $stamp . ' ipv6-device ICCT_V6 - - - ipv6 wire event', 'udp', '::1');
sendSyslog(
    '<132>1 ' . $stamp . ' claimed-other-device ICCT_UNKNOWN - - - unmapped source',
    'udp',
    '127.0.0.1',
    '127.0.0.2',
);
sendSyslog('<999>broken priority');
waitSyslog(6);
runSyslog();
$dup = db_fetch_row("SELECT * FROM plugin_icct_nms_syslog_events WHERE program='ICCT_DUP'");
wireCheck(
    (int) $dup['host_id'] === 2 &&
        (int) $dup['severity_code'] === 0 &&
        (int) $dup['repeat_count'] === 2,
    'Source binding, emergency zero or deduplication lost.',
);
$alarm = db_fetch_row_prepared('SELECT * FROM plugin_icct_nms_alarms WHERE alarm_key=?', [
    $dup['alarm_key'],
]);
wireCheck(
    $alarm['source'] === 'Syslog' &&
        (int) $alarm['occurrences'] === 2 &&
        $alarm['source_address'] === '127.0.0.1',
    'Common fault normalization failed.',
);
$nil = db_fetch_row("SELECT * FROM plugin_icct_nms_syslog_events WHERE program='ICCT_NIL'");
wireCheck(
    $nil['timestamp_kind'] === 'receiver' && $nil['receiver_time'] === $nil['event_time'],
    'NIL timestamp was presented as a sender time.',
);
wireCheck($nil['alarm_key'] === null, 'Unmatched history must create no alarm.');
wireCheck(
    (int) db_fetch_cell(
        "SELECT host_id FROM plugin_icct_nms_syslog_events WHERE program='ICCT_V6'",
    ) === 1,
    'IPv6 sender identity lost.',
);
wireCheck(
    (int) db_fetch_cell(
        "SELECT COUNT(*) FROM plugin_icct_nms_syslog_events WHERE program='ICCT_UNKNOWN'",
    ) === 0,
    'Unmapped sender accepted.',
);
icct_nms_alarm_action([
    'alarm_key' => $alarm['alarm_key'],
    'alarm_action' => 'ack',
    'revision' => $alarm['revision'],
    'note' => 'Private QA acknowledgement',
]);
$total = 0;
$rows = icct_backend_syslog_events(
    [1, 2],
    [
        'host_id' => 2,
        'severity_codes' => [0],
        'ack' => 'yes',
        'since' => $dup['event_time'],
        'until' => $dup['event_time'],
    ],
    50,
    0,
    $total,
);
wireCheck(
    $total === 1 && (int) $rows[0]['acknowledged'] === 1,
    'Shared acknowledgement or combined filters failed.',
);
$before = (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_icct_nms_syslog_events');
runSyslog();
wireCheck(
    (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_icct_nms_syslog_events') === $before,
    'Restart replayed received messages.',
);
// A write failure must roll back both a prior successful message and the cursor in this chunk.
$cursor = db_fetch_cell('SELECT source_offset FROM plugin_icct_nms_syslog_ingest_state LIMIT 1');
db_execute(
    "CREATE TRIGGER qa_syslog_fail BEFORE INSERT ON plugin_icct_nms_syslog_events FOR EACH ROW BEGIN IF NEW.program='ICCT_FAIL' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Private storage failure'; END IF; END",
);
sendSyslog('<132>1 ' . $stamp . ' host ICCT_BEFORE - - - before storage failure');
sendSyslog('<132>1 ' . $stamp . ' host ICCT_FAIL - - - forced storage failure');
waitSyslog(8);
runSyslog(false);
wireCheck(
    (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_icct_nms_syslog_events') === $before &&
        db_fetch_cell('SELECT source_offset FROM plugin_icct_nms_syslog_ingest_state LIMIT 1') ===
            $cursor,
    'Storage failure advanced cursor or left a partial chunk.',
);
db_execute('DROP TRIGGER qa_syslog_fail');
runSyslog();
wireCheck(
    (int) db_fetch_cell(
        "SELECT COUNT(*) FROM plugin_icct_nms_syslog_events WHERE program IN ('ICCT_BEFORE','ICCT_FAIL')",
    ) === 2,
    'Storage retry lost actual messages.',
);
// Projection can fail independently after raw storage; the outbox repairs it without replay.
db_execute(
    "CREATE TRIGGER qa_fault_fail BEFORE INSERT ON plugin_icct_nms_alarms FOR EACH ROW BEGIN IF NEW.source='Syslog' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Private journal failure'; END IF; END",
);
sendSyslog('<132>1 ' . $stamp . ' host ICCT_OUTBOX - - - journal recovery');
waitSyslog(9);
runSyslog(false);
wireCheck(
    (int) db_fetch_cell(
        "SELECT COUNT(*) FROM plugin_icct_nms_syslog_events WHERE program='ICCT_OUTBOX'",
    ) === 1,
    'Outbox lost a received message.',
);
db_execute('DROP TRIGGER qa_fault_fail');
runSyslog();
$outbox = db_fetch_row("SELECT * FROM plugin_icct_nms_syslog_events WHERE program='ICCT_OUTBOX'");
wireCheck(
    (int) $outbox['repeat_count'] === 1 &&
        (int) $outbox['synced_count'] === 1 &&
        (int) db_fetch_cell_prepared(
            'SELECT COUNT(*) FROM plugin_icct_nms_alarms WHERE alarm_key=?',
            [$outbox['alarm_key']],
        ) === 1,
    'Journal retry duplicated an alarm or inflated counts.',
);
echo "PASS: actual UDP/TCP/IPv6, priority zero, source-bound identity, NIL receiver-time provenance, exact counts, shared acknowledgements/filters, atomic cursor rollback and outbox recovery without duplicate alarms.\n";
