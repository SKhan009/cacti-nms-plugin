<?php
/** The journal is the common alarm source, including passive and NTP alarms. */
function icct_nms_alarm_rows()
{
    return [
        [
            'host_id' => 2,
            'state' => 'Active',
            'severity' => 'Critical',
            'name' => 'Temperature',
            'source' => 'Syslog',
            'occurred_epoch' => 1,
            'description' => 'Hot',
            'alarm_key' => 'a',
            'fix' => '',
        ],
        [
            'host_id' => 2,
            'state' => 'Active',
            'severity' => 'Warning',
            'name' => 'Clock',
            'source' => 'NTP',
            'occurred_epoch' => 2,
            'description' => 'Offset',
            'alarm_key' => 'b',
            'fix' => '',
        ],
        ['host_id' => 2, 'state' => 'Resolved', 'severity' => 'Major', 'name' => 'Old'],
        [
            'host_id' => 3,
            'state' => 'Active',
            'severity' => 'Minor',
            'name' => 'Other device',
            'source' => 'Port',
            'occurred_epoch' => 3,
            'description' => 'Down',
            'alarm_key' => 'c',
            'fix' => '',
        ],
    ];
}
require __DIR__ . '/../../dashboard/map/services/map_service.php';
$device = icct_nms_dashboard_alarm_data(2);
if (
    array_sum($device['fault_counts']) !== 2 ||
    $device['fault_counts']['Critical'] !== 1 ||
    $device['fault_counts']['Warning'] !== 1 ||
    $device['alarm']['severity'] !== 'Critical' ||
    count($device['fault_alarms']) !== 2 ||
    array_sum(icct_nms_dashboard_alarm_data(99)['fault_counts']) !== 0
) {
    throw new RuntimeException('Dashboard journal projection mismatch.');
}
echo "PASS journal counts, highest severity, source inclusion and host isolation\n";

require __DIR__ . '/../../dashboard/widgets/services/dashboard_service.php';
$map = [
    'unlocated' => [
        ['id' => 1, 'poller_id' => 1, 'status' => 'Up'],
        ['id' => 2, 'poller_id' => 2, 'status' => 'Disabled'],
    ],
    'sites' => [
        [
            'devices' => [
                ['id' => 3, 'poller_id' => 1, 'status' => 'Down'],
                ['id' => 4, 'poller_id' => 2, 'status' => 'Up'],
            ],
        ],
    ],
];
$scoped = icct_nms_dashboard_poller_scope($map, 1);
if (
    array_column($scoped['unlocated'], 'id') !== [1] ||
    array_column($scoped['sites'][0]['devices'], 'id') !== [3] ||
    $scoped['counts']['total'] !== 2 ||
    $scoped['counts']['online'] !== 1 ||
    $scoped['counts']['offline'] !== 1 ||
    icct_nms_dashboard_poller_scope($map, 99)['counts']['total'] !== 0 ||
    icct_nms_dashboard_poller_scope($map, 0) !== $map
) {
    throw new RuntimeException('Native collector scope mismatch.');
}
echo "PASS collector filtering of located/unlocated devices and counts\n";
