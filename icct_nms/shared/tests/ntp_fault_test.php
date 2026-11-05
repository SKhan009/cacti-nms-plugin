<?php

require __DIR__ . '/../../fcaps/services/fcaps_service.php';
function ntpFaultCheck($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function icct_nms_id($value)
{
    return (int) $value;
}
$sample = [
    'synchronized' => true,
    'stratum' => 2,
    'offset_ms' => 0.0,
    'rms_offset_ms' => 3.0,
    'root_delay_ms' => 4.0,
    'jitter_ms' => 0.25,
    'peer_delay_ms' => 3.5,
];
$current = ['status' => 'Synchronized', 'attempted_epoch' => time() - 2, 'sample' => $sample];
$values = icct_nms_ntp_fault_values($current);
ntpFaultCheck(
    $values['absolute_offset_ms'] === 0.0 &&
        $values['synchronized'] === 1 &&
        $values['collection_available'] === 1,
    'Actual zero or synchronization lost.',
);
foreach (['Not configured', 'Not collected', 'Disabled', 'Settings changed'] as $status) {
    $missing = icct_nms_ntp_fault_values(
        array_replace($current, ['status' => $status, 'sample' => null]),
    );
    ntpFaultCheck(
        count(array_filter($missing, fn($v) => $v !== null)) === 0,
        'Invalidated observation reused.',
    );
}
foreach (['Stale', 'Unavailable'] as $status) {
    $missing = icct_nms_ntp_fault_values(
        array_replace($current, ['status' => $status, 'sample' => null]),
    );
    ntpFaultCheck(
        $missing['collection_available'] === 0 &&
            $missing['offset_ms'] === null &&
            $missing['synchronized'] === null,
        'Missing clock data replaced.',
    );
}
ntpFaultCheck(
    $values['jitter_ms'] === 0.25 && $values['peer_delay_ms'] === 3.5,
    'Actual jitter/delay not exposed to FCAPS.',
);
$legacy = $current;
unset($legacy['sample']['jitter_ms'], $legacy['sample']['peer_delay_ms']);
$legacyValues = icct_nms_ntp_fault_values($legacy);
ntpFaultCheck(
    $legacyValues['jitter_ms'] === null &&
        $legacyValues['peer_delay_ms'] === null &&
        $legacyValues['offset_ms'] === 0.0,
    'Older observations were backfilled with substitute measurements.',
);
$rules = icct_nms_fault_validate(icct_nms_ntp_fault_starters(), [], []);
foreach (['Information', 'Minor', 'Warning', 'Major', 'Critical'] as $severity) {
    $rule = array_replace($rules[0], ['severity' => $severity, 'maximum' => 5]);
    ntpFaultCheck(icct_nms_fault_state($rule, 6) === $severity, 'Configured severity ignored.');
    ntpFaultCheck(icct_nms_fault_state($rule, null) === 'Unknown', 'Unknown threshold fabricated.');
    ntpFaultCheck(icct_nms_fault_state($rule, 5) === 'Normal', 'Strict comparison changed.');
}
foreach (
    [
        [$rules[0], $rules[0]],
        [array_replace($rules[0], ['parameter' => 'arbitrary'])],
        [
            array_replace($rules[0], [
                'parameter' => 'source_matches',
                'expected_source' => 'invalid',
            ]),
        ],
    ]
    as $invalid
) {
    try {
        icct_nms_fault_validate($invalid, [], []);
        throw new LogicException('Invalid NTP policy accepted.');
    } catch (InvalidArgumentException $expected) {
    }
}
echo "PASS: NTP actual zero, unknown/stale/invalidated samples, configurable severity, bounds, source validation and duplicate prevention.\n";
