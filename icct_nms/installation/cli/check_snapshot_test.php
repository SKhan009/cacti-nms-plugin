<?php

require dirname(__DIR__) . '/services/checks.php';
$snapshot = json_encode([
    'hostname' => 'collector-a',
    'time' => 1000,
    'services' => ['rsyslog.service' => 'active'],
]);
if (!icct_nms_installation_snapshot($snapshot, 'collector-a', 1100, 600)) {
    throw new RuntimeException('Fresh local service state lost.');
}
foreach (
    [['collector-b', 1100, 600], ['collector-a', 1601, 600], ['collector-a', 999, 600]]
    as $args
) {
    if (icct_nms_installation_snapshot($snapshot, ...$args)) {
        throw new RuntimeException('Foreign, stale or future service state accepted.');
    }
}
if (icct_nms_installation_snapshot('invalid', 'collector-a', 1100, 600)) {
    throw new RuntimeException('Corrupt report accepted.');
}
echo "PASS: service checks reject another collector, expired observations, future timestamps and corrupt reports.\n";
