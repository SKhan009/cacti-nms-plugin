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
        'method' => 'auto',
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
echo "PASS: strict presets, unit conversion, real zero, unknown measurements, malformed responses and fixed read-only command.\n";
