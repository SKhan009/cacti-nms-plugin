<?php

require_once __DIR__ . '/../services/ntp_collector.php';
function detailsCheck($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function detailsReject($operation)
{
    try {
        $operation();
    } catch (RuntimeException | InvalidArgumentException $expected) {
        return;
    }
    throw new LogicException('Invalid detail observation accepted.');
}
// Parser fixtures are confined to this standalone test; live collectors always invoke chrony.
$tracking =
    'C0000201,192.0.2.1,2,1793799702.492115327,0.0001,-0.002,0.003,1,0,2,0.004,0.005,64,Normal';
$stats = '192.0.2.1,8,5,64,-0.158,3.551,-0.001407226,0.00025';
$peer =
    "Remote address : 192.0.2.1 (C0000201)\nPeer delay : 0.0035 seconds\nTotal valid RX : 8\nTotal good RX : 7\n";
$read = static fn($kind) => ['tracking' => $tracking, 'jitter' => $stats, 'delay' => $peer][$kind];
$observed = icct_nms_ntp_observe($read);
detailsCheck(
    $observed['jitter_ms'] === 0.25 && $observed['peer_delay_ms'] === 3.5,
    'Wrong measured units.',
);
detailsCheck(
    $observed['rms_offset_ms'] === 3.0 && $observed['jitter_samples'] === 8,
    'Jitter replaced by RMS or sample provenance lost.',
);
detailsCheck(
    icct_nms_ntp_peer_delay(str_replace('0.0035', '0', $peer), '192.0.2.1') === 0.0,
    'Actual zero delay lost.',
);
detailsCheck(
    icct_nms_ntp_jitter(str_replace('0.00025', '0', $stats), '192.0.2.1')['jitter_ms'] === 0.0,
    'Actual zero jitter lost.',
);
detailsCheck(
    icct_nms_ntp_jitter(str_replace('192.0.2.1', '2001:db8::1', $stats), '2001:db8:0:0::1')[
        'jitter_ms'
    ] === 0.25,
    'IPv6 source matching failed.',
);
foreach (['NaN', '-1', 'INF', '1e308'] as $duration) {
    detailsReject(fn() => icct_nms_ntp_milliseconds($duration));
}
foreach (
    [
        '',
        '501 Not authorised',
        str_replace('192.0.2.1', '192.0.2.2', $peer),
        $peer . $peer,
        $peer . "Peer delay : 1 seconds\n",
        str_replace('RX : 7', 'RX : 0', $peer),
    ]
    as $bad
) {
    detailsReject(fn() => icct_nms_ntp_peer_delay($bad, '192.0.2.1'));
}
foreach (
    [
        '',
        '501 Not authorised',
        str_replace(',8,5,', ',2,1,', $stats),
        $stats . "\n" . $stats,
        str_replace('192.0.2.1', '192.0.2.2', $stats),
        str_replace('0.00025', 'NaN', $stats),
    ]
    as $bad
) {
    detailsReject(fn() => icct_nms_ntp_jitter($bad, '192.0.2.1'));
}
detailsCheck(
    icct_nms_ntp_jitter('PPS0,8,5,64,0,0,0,0' . "\n" . $stats, '192.0.2.1')['jitter_ms'] === 0.25,
    'Unrelated reference clock blocked selected NTP peer.',
);
$count = 0;
detailsReject(function () use (&$count, $tracking, $stats, $peer) {
    icct_nms_ntp_observe(function ($kind) use (&$count, $tracking, $stats, $peer) {
        if ($kind !== 'tracking') {
            return $kind === 'jitter' ? $stats : $peer;
        }
        return ++$count === 1 ? $tracking : str_replace('192.0.2.1', '192.0.2.2', $tracking);
    });
});
$unsynced = icct_nms_ntp_observe(static function ($kind) {
    detailsCheck($kind === 'tracking', 'Unsynchronized clock queried as a selected peer.');
    return '00000000,,0,0,0,0,0,0,0,0,0,0,0,Not synchronised';
});
detailsCheck(
    $unsynced['jitter_ms'] === null && $unsynced['peer_delay_ms'] === null,
    'Missing peer measurements invented.',
);
detailsReject(fn() => icct_nms_ntp_command('makestep'));
echo "PASS: coherent selected-source jitter/delay, real zero, IPv6, malformed/ambiguous/insufficient observations, source changes and no clock-control commands.\n";
