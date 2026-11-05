<?php
require_once __DIR__ . '/../services/backend/syslog.php';
function syslogCheck($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function syslogReject($record)
{
    try {
        icct_nms_syslog_normalize($record);
    } catch (InvalidArgumentException | Exception $expected) {
        return;
    }
    throw new LogicException('Malformed Syslog metadata accepted.');
}
$record = [
    'source_ip' => '::ffff:192.0.2.1',
    'hostname' => 'claimed-name',
    'input_name' => 'icct-udp',
    'severity_code' => '0',
    'facility_code' => '0',
    'program' => 'test',
    'message' => 'Actual test payload',
    'timestamp' => '2026-11-04T12:00:00Z',
    'received_timestamp' => '2026-11-04T12:00:02Z',
    'raw_message' => '<0>1 2026-11-04T12:00:00Z claimed-name test - - - Actual test payload',
];
$n = icct_nms_syslog_normalize($record);
syslogCheck(
    $n['source_ip'] === '192.0.2.1' && $n['severity_code'] === 0 && $n['facility_code'] === 0,
    'Zero priority or canonical peer lost.',
);
syslogCheck(
    $n['timestamp_kind'] === 'device' && $n['event_time'] !== $n['receiver_time'],
    'Sender and receiver times mixed.',
);
$nil = icct_nms_syslog_normalize(
    array_replace($record, ['raw_message' => '<0>1 - claimed-name test - - - Actual test payload']),
);
syslogCheck(
    $nil['timestamp_kind'] === 'receiver' && $nil['event_time'] === $nil['receiver_time'],
    'NIL timestamp invented as a device time.',
);
foreach (
    [
        ['severity_code' => -1],
        ['severity_code' => 'bad'],
        ['facility_code' => 24],
        ['raw_message' => '<999>invalid'],
        ['raw_message' => '<0>1 2026-02-31T12:00:00Z host test - - - bad'],
        ['received_timestamp' => 'yesterday'],
        ['source_ip' => 'example.net'],
        ['message' => str_repeat('x', 16385)],
        ['input_name' => 'spoof-tcp-wrapper'],
        ['received_timestamp' => null],
    ]
    as $bad
) {
    syslogReject(array_replace($record, $bad));
}
echo "PASS: actual priority including zero, canonical peers, timestamp provenance, RFC NIL time, malformed/oversized data and fixed transport identities.\n";
