<?php

require_once __DIR__ . '/../services/ntp_collector.php';
require_once __DIR__ . '/../../../dashboard/topology/services/backend/topology_config.php';

function read_config_option($key)
{
    return 60;
}
function checkNtp($ok, $message)
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
function rejectsNtp($operation)
{
    try {
        $operation();
    } catch (InvalidArgumentException | RuntimeException $error) {
        return;
    }
    throw new LogicException('Invalid NTP input was accepted.');
}

$values = [
    'method' => 'local',
    'timeout' => 5,
    'interval_seconds' => 60,
    'stale_seconds' => 180,
];
checkNtp(icct_nms_ntp_validate($values)['timeout'] === 5, 'Collection settings conversion failed.');
foreach (
    [
        'method' => 'invalid',
        'timeout' => 0,
        'interval_seconds' => 61,
        'stale_seconds' => 60,
    ]
    as $field => $invalid
) {
    rejectsNtp(fn() => icct_nms_ntp_validate(array_replace($values, [$field => $invalid])));
}
rejectsNtp(fn() => icct_nms_ntp_validate(array_diff_key($values, ['method' => true])));
rejectsNtp(fn() => icct_nms_ntp_validate(array_replace($values, ['timeout' => []])));
checkNtp(icct_nms_ntp_local_device(['hostname' => '::1']), 'IPv6 localhost rejected.');
checkNtp(
    !icct_nms_ntp_local_device(['hostname' => '192.0.2.4']),
    'Remote clock relabelled as collector.',
);

foreach (['auto', 'udp'] as $method) {
    checkNtp(icct_nms_ntp_validate(array_replace($values, ['method' => $method]))['method'] === $method,
        'Direct monitoring method was rejected.');
}
$sent = 2085978495.875; // The response crosses the 2036 NTP timestamp rollover.
$origin = icct_nms_ntp_timestamp($sent);
$reply = "\x24\x02" . str_repeat("\0", 10) . "GPS\0" .
    icct_nms_ntp_timestamp($sent - 1) . $origin .
    icct_nms_ntp_timestamp($sent + 0.25) . icct_nms_ntp_timestamp($sent + 0.375);
$direct = icct_nms_ntp_udp_sample($reply, $origin, $sent, $sent + 0.5, 'ntp.lan');
checkNtp(
    $direct['synchronized'] && $direct['offset_ms'] === 62.5 &&
        $direct['peer_delay_ms'] === 375.0 && $direct['server_address'] === 'ntp.lan' &&
        $direct['source_address'] === null && $direct['jitter_ms'] === null,
    'Direct NTP timestamps or source semantics are incorrect.',
);
$unsynced = icct_nms_ntp_udp_sample("\xe4" . substr($reply, 1), $origin, $sent, $sent + 0.5, 'ntp.lan');
checkNtp(!$unsynced['synchronized'] && $unsynced['offset_ms'] === null, 'Unsynchronized reply produced a clock offset.');
rejectsNtp(fn() => icct_nms_ntp_udp_sample($reply, str_repeat("\0", 8), $sent, $sent + 0.5, 'ntp.lan'));
rejectsNtp(fn() => icct_nms_ntp_udp_sample(substr($reply, 0, 47), $origin, $sent, $sent + 0.5, 'ntp.lan'));

$csv = 'C0000201,192.0.2.1,2,1793799702.492115327,0.0001,-0.002,0.003,1,0,2,0.004,0.005,64,Normal';
$sample = icct_nms_ntp_parse($csv);
checkNtp(
    $sample['offset_ms'] === -2.0 &&
        $sample['root_delay_ms'] === 4.0 &&
        $sample['rms_offset_ms'] === 3.0 &&
        $sample['synchronized'],
    'Unit conversion or synchronization failed.',
);
checkNtp(
    icct_nms_ntp_evaluate($sample, $values)[0] === 'Synchronized',
    'Valid measurement rejected.',
);
$zero = icct_nms_ntp_parse(str_replace('-0.002', '0', $csv));
checkNtp($zero['offset_ms'] === 0.0, 'Measured zero became missing.');
$unsynchronized = icct_nms_ntp_parse('00000000,,0,0,0,0,0,0,0,0,0,0,0,Not synchronised');
checkNtp(
    !$unsynchronized['synchronized'] && $unsynchronized['offset_ms'] === null,
    'Unsynchronized zero presented as measured offset.',
);
$local = icct_nms_ntp_parse(
    str_replace(['C0000201', '192.0.2.1'], ['7F7F0101', '127.127.1.1'], $csv),
);
checkNtp(
    !$local['synchronized'] && $local['local_reference'] && $local['offset_ms'] === null,
    'Local reference falsely marked synchronized.',
);
foreach (
    [
        '',
        '506 Cannot talk to daemon',
        $csv . "\n" . $csv,
        str_replace('-0.002', 'INF', $csv),
        str_replace('-0.002', '1e308', $csv),
        str_replace(',2,179379', ',17,179379', $csv),
        str_replace(',Normal', ',Unknown', $csv),
    ]
    as $bad
) {
    rejectsNtp(fn() => icct_nms_ntp_parse($bad));
}
checkNtp(
    icct_nms_ntp_command() === [
        '/usr/bin/sudo',
        '-n',
        '-u',
        'chrony',
        '--',
        '/usr/bin/chronyc',
        '-n',
        '-c',
        '-h',
        '/run/chrony/chronyd.sock',
        'tracking',
    ],
    'Clock-control command exposed.',
);
require_once __DIR__ . '/../services/ntp_history.php';
$filter = icct_nms_ntp_history_filter(2, ['ntp_from' => '2026-11-04', 'ntp_to' => '2026-11-04']);
checkNtp(
    $filter['args'] === [2, '2026-11-04 00:00:00', '2026-11-04 23:59:59'],
    'History filter must include the whole selected day.',
);
rejectsNtp(fn() => icct_nms_ntp_history_filter(2, ['ntp_from' => '2026-02-29']));
rejectsNtp(fn() => icct_nms_ntp_history_filter(2, ['ntp_from' => '2026-11-05', 'ntp_to' => '2026-11-04']));
$statusFilter = icct_nms_ntp_history_filter(2, ['ntp_from' => '2026-11-04', 'ntp_status' => 'Unavailable']);
checkNtp(
    $statusFilter['where'] === 'host_id=? AND observed_at>=? AND status=?' &&
        $statusFilter['args'] === [2, '2026-11-04 00:00:00', 'Unavailable'] &&
        $statusFilter['params'] === ['ntp_from' => '2026-11-04', 'ntp_status' => 'Unavailable'],
    'History status must be bound with the selected date and preserved in pagination and export.',
);
rejectsNtp(fn() => icct_nms_ntp_history_filter(2, ['ntp_status' => ['Unavailable']]));
$rows = [
    ['observed_at' => '2026-11-04 12:00:00', 'sample' => ['jitter_ms' => 0.0]],
    ['observed_at' => '2026-11-04 12:05:00', 'sample' => null],
    ['observed_at' => '2026-11-04 12:10:00', 'sample' => ['jitter_ms' => 5.0]],
    ['observed_at' => '2026-11-04 12:30:00', 'sample' => ['jitter_ms' => 6.0]],
    ['observed_at' => '2026-11-04 12:20:00', 'sample' => ['jitter_ms' => 2.0]],
];
$trend = icct_nms_ntp_history_trend($rows, 'jitter_ms', 300);
checkNtp(
    $trend['min'] === 0.0 && array_values(array_map('count', $trend['segments'])) === [1, 1, 1, 1],
    'The trend must show real zero and break at unknown readings, missed cycles and backward clock steps.',
);
echo "PASS: NTP parsing, read-only commands, date filters and trend gaps.\n";
