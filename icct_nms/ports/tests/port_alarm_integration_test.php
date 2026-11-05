<?php

/** Acceptance stages require a disposable DB and a real veth pair in a private network namespace. */
require '/var/www/html/cacti/include/cli_check.php';
if (!str_starts_with((string) db_fetch_cell('SELECT DATABASE()'), 'icct_discovery_qa_')) {
    throw new RuntimeException('Private port QA database required.');
}
api_plugin_load_realms();
require_once $config['base_path'] . '/lib/auth.php';
$_SESSION = ['sess_user_id' => 1];
require_once __DIR__ . '/../../shared/services/bootstrap.php';
icct_nms_backend();
require_once __DIR__ . '/../../inventory/services/inventory.php';
$stage = getopt('', ['stage:'])['stage'] ?? '';
function portQaCheck($value, $message)
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}
if ($stage === 'initial') {
    db_execute(
        "DELETE hist FROM plugin_icct_nms_alarm_history hist JOIN plugin_icct_nms_alarms a ON a.alarm_key=hist.alarm_key WHERE a.host_id=2 AND a.source='Port'",
    );
    db_execute("DELETE FROM plugin_icct_nms_alarms WHERE host_id=2 AND source='Port'");
    db_execute('DELETE FROM plugin_icct_nms_port_alarm_events WHERE host_id=2');
    db_execute(
        "DELETE FROM plugin_icct_nms_meta WHERE meta_key IN ('port_alarm_state_2','fault_status_2')",
    );
    db_execute(
        "UPDATE host SET snmp_version=2,snmp_community='qa-port',snmp_port=161,snmp_timeout=100,disabled='' WHERE id=2",
    );
    db_execute("UPDATE host SET disabled='on' WHERE id=1");
    icct_backend_protocol_state_write(2, 'snmp', true);
    icct_backend_collect_ports();
    $host = icct_nms_device(2);
    $ports = icct_backend_ports_view($host);
    $target = current(
        array_filter($ports['ports'], static fn($port) => $port['name'] === 'qa-port'),
    );
    portQaCheck(
        $target && $ports['fresh'] && $target['oper'] === 1,
        'Real QA veth must initially be UP.',
    );
    $settings = [
        'enabled' => true,
        'ports' => [
            $target['index'] => [
                'name' => 'qa-port',
                'alarm' => true,
                'cnms' => false,
                'oper_severity' => 'Major',
                'admin_severity' => 'Warning',
            ],
        ],
    ];
    icct_nms_save_port_monitoring(2, [
        'port_settings' => json_encode($settings, JSON_THROW_ON_ERROR),
    ]);
} elseif ($stage === 'missing') {
    // A genuinely stopped SNMP agent must leave the last measured outage open.
    icct_backend_collect_ports();
} else {
    icct_backend_collect_ports();
}
$host = icct_nms_device(2);
icct_backend_collect_port_alarms();
icct_nms_collect_alarms();
$snapshot = icct_nms_port_alarm_snapshot(2);
$port = current(array_filter($snapshot['ports'], static fn($port) => $port['name'] === 'qa-port'));
$events = db_fetch_assoc(
    'SELECT * FROM plugin_icct_nms_port_alarm_events WHERE host_id=2 ORDER BY id',
);
$raised = current(array_filter($events, static fn($event) => $event['event'] === 'Raised'));
$alarm = db_fetch_row(
    "SELECT * FROM plugin_icct_nms_alarms WHERE host_id=2 AND source='Port' AND name='qa-port link status'",
);
if ($stage === 'down' || $stage === 'steady') {
    portQaCheck(
        $port['state'] === 'Major' &&
            $alarm['state'] === 'Active' &&
            (int) $alarm['acknowledged'] === 1,
        'Actual link-down fault must be active and automatically acknowledged.',
    );
    portQaCheck(
        (int) db_fetch_cell_prepared(
            "SELECT COUNT(*) FROM plugin_icct_nms_alarm_history WHERE alarm_key=? AND event='Automatically acknowledged'",
            [$alarm['alarm_key']],
        ) === 1,
        'One automatic acknowledgement per episode is required.',
    );
    portQaCheck(
        count(array_filter($events, static fn($event) => $event['event'] === 'Raised')) === 1,
        'Repeated polls duplicated the raised event.',
    );
    portQaCheck(
        $raised['alarm_key'] === $alarm['alarm_key'] && (int) $raised['alarm_occurrence'] === 1,
        'Raw history must link to the correct shared alarm episode.',
    );
} elseif ($stage === 'recurrence') {
    portQaCheck(
        $port['state'] === 'Major' &&
            $alarm['state'] === 'Active' &&
            (int) $alarm['occurrences'] === 2 &&
            (int) $alarm['acknowledged'] === 1,
        'A second observed outage must reuse the alarm with a new activation.',
    );
    portQaCheck(
        (int) db_fetch_cell_prepared(
            "SELECT COUNT(*) FROM plugin_icct_nms_alarm_history WHERE alarm_key=? AND event='Automatically acknowledged'",
            [$alarm['alarm_key']],
        ) === 2,
        'Each activation must retain its own automatic acknowledgement.',
    );
    portQaCheck(
        !$port['details']['observation_gap'] && $port['details']['recovery'] === null,
        'A new outage inherited the previous episode recovery or missing-data flag.',
    );
} elseif ($stage === 'recovered_again') {
    portQaCheck(
        $port['state'] === 'Normal' &&
            $alarm['state'] === 'Resolved' &&
            (int) $alarm['occurrences'] === 2,
        'Second recovery did not resolve the existing alarm.',
    );
    $episodes = db_fetch_assoc(
        'SELECT DISTINCT incident_key,alarm_occurrence FROM plugin_icct_nms_port_alarm_events WHERE host_id=2 AND incident_key IS NOT NULL ORDER BY alarm_occurrence',
    );
    portQaCheck(
        count($episodes) === 2 &&
            (int) $episodes[0]['alarm_occurrence'] === 1 &&
            (int) $episodes[1]['alarm_occurrence'] === 2,
        'History or acknowledgement linkage crossed outage episodes.',
    );
} elseif ($stage === 'missing') {
    portQaCheck(
        $port['state'] === 'Unknown' &&
            $port['active'] === 'Major' &&
            $alarm['state'] === 'Active' &&
            $port['details']['recovered_at'] === null,
        'Missing data falsely resolved the alarm.',
    );
} elseif ($stage === 'recovered') {
    portQaCheck(
        $port['state'] === 'Normal' && $alarm['state'] === 'Resolved',
        'Actual link recovery must resolve both histories.',
    );
    portQaCheck(
        $port['details']['observed_down_seconds'] >= 2 && $port['details']['recovered_at'] !== null,
        'Measured downtime/recovery evidence missing.',
    );
    $detail = icct_nms_alarm_detail($alarm['alarm_key']);
    portQaCheck(
        $detail['acknowledgement']['actor_username'] === 'Automatic',
        'Shared alarm must show the actual automatic acknowledgement actor.',
    );
    require_once __DIR__ . '/../services/port_history_service.php';
    portQaCheck(
        str_contains($port['details']['recovery'], 'No repair command'),
        'Recovery must not claim an unobserved repair action.',
    );
}
// Exercise the real view query too: legacy port and shared Fault tables use different collations.
$id = 2;
$_GET = [];
ob_start();
require __DIR__ . '/../templates/device_port_history.php';
$historyHtml = ob_get_clean();
portQaCheck(
    str_contains($historyHtml, 'Port alarm history') && !str_contains($historyHtml, 'NoneNormal'),
    'Port history rendering failed.',
);
if ($stage !== 'initial') {
    portQaCheck(
        str_contains($historyHtml, 'Automatic (port monitor)'),
        'History lost its recorded acknowledgement identity.',
    );
}
echo json_encode(
    [
        'stage' => $stage,
        'if_index' => (int) array_key_first($snapshot['ports']),
        'port' => $port,
        'raw_events' => array_column($events, 'event'),
        'alarm' => $alarm
            ? [
                'key' => $alarm['alarm_key'],
                'state' => $alarm['state'],
                'acknowledged' => (int) $alarm['acknowledged'],
                'occurrences' => (int) $alarm['occurrences'],
            ]
            : null,
    ],
    JSON_THROW_ON_ERROR,
) . "\n";
