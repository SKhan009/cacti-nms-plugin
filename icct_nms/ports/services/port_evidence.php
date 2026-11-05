<?php

/** Classify reported interfaces without guessing from their names. */
function icct_nms_port_kind(array $port)
{
    if (
        in_array($port['type'] ?? null, [24, 53, 131, 135, 136, 161], true) ||
        ($port['connector'] ?? null) === 2
    ) {
        return 'Software';
    }
    if (($port['connector'] ?? null) === 1 || ($port['type'] ?? null) === 6) {
        return 'Physical';
    }
    return 'Unclassified';
}

function icct_nms_port_state_label($value)
{
    return [
        1 => 'UP',
        2 => 'DOWN',
        3 => 'TESTING',
        4 => 'UNKNOWN',
        5 => 'DORMANT',
        6 => 'NOT PRESENT',
        7 => 'LOWER LAYER DOWN',
    ][$value ?? 0] ?? 'Not recorded';
}

/** Capture evidence at collection time; never manufacture an earlier outage or a repair actor. */
function icct_nms_port_evidence(
    array $previous,
    array $port,
    array $view,
    array $transition,
    $contextChanged,
) {
    $fresh = !empty($view['fresh']) && ($port['name'] ?? '') === $transition['name'];
    $sample = $fresh ? (int) $view['collected'] : null;
    $old = !$contextChanged ? $previous['details'] ?? [] : [];
    $active = icct_nms_port_alarm_severity($transition['active']);
    $incident = $old['incident_key'] ?? null;
    $start = $old['down_since'] ?? null;
    $end = $old['recovered_at'] ?? null;
    if (($active || $transition['event'] === 'Cleared') && $incident === null) {
        $incident = bin2hex(random_bytes(16));
        // An already-active legacy alarm has no trustworthy historical start timestamp.
        $start =
            $fresh && $active && ($contextChanged || empty($previous['active'])) ? $sample : null;
        $end = null;
    } elseif ($transition['event'] === 'Raised') {
        $incident = bin2hex(random_bytes(16));
        $start = $sample;
        $end = null;
    }
    $issue = $old['issue'] ?? null;
    if ($fresh && $active && ($transition['event'] === 'Raised' || $issue === null)) {
        $issue =
            ($port['admin'] ?? null) === 2
                ? 'The device reports the interface administratively DOWN. The user or system that disabled it is not reported.'
                : (($port['oper'] ?? null) === 7
                    ? 'The device reports a lower-layer interface DOWN. The underlying link has not become operational.'
                    : 'The interface is enabled but reports its operational link DOWN. The device does not report the physical root cause.');
    }
    $recovery = $transition['event'] === 'Raised' ? null : $old['recovery'] ?? null;
    if (
        $fresh &&
        $transition['event'] === 'Cleared' &&
        ($port['admin'] ?? null) === 1 &&
        ($port['oper'] ?? null) === 1
    ) {
        $end = $sample;
        $recovery =
            'Automatic recovery detection: the collector observed administrative and operational status return to UP. No repair command was issued; the actual repair actor is not reported.';
    }
    $last = $sample ?? ($old['last_sample'] ?? null);
    $duration =
        $start !== null && $last !== null && ($end ?? $last) >= $start
            ? ($end ?? $last) - $start
            : null;
    return [
        'version' => 1,
        'previous_admin_status' => $previous['admin'] ?? null,
        'previous_oper_status' => $previous['oper'] ?? null,
        'kind' => $fresh ? icct_nms_port_kind($port) : $old['kind'] ?? null,
        'source' => $fresh ? $view['source'] ?? null : $old['source'] ?? null,
        'if_last_change_ticks' => $fresh
            ? $port['last_change_ticks'] ?? null
            : $old['if_last_change_ticks'] ?? null,
        'uptime_ticks' => $fresh ? $view['uptime_ticks'] ?? null : $old['uptime_ticks'] ?? null,
        'if_type' => $fresh ? $port['type'] ?? null : $old['if_type'] ?? null,
        'connector_present' => $fresh
            ? $port['connector'] ?? null
            : $old['connector_present'] ?? null,
        'incident_key' => $incident,
        'down_since' => $start,
        'last_sample' => $last,
        'recovered_at' => $end,
        'observed_down_seconds' => $duration,
        'issue' => $issue,
        'recovery' => $recovery,
        'auto_acknowledge' => $active && $fresh,
        'observation_gap' =>
            ($transition['event'] !== 'Raised' && !empty($old['observation_gap'])) ||
            (!$fresh && $incident !== null && $end === null),
    ];
}

function icct_nms_port_duration_text($seconds)
{
    if ($seconds === null) {
        return 'Not recorded';
    }
    $seconds = (int) $seconds;
    return sprintf(
        '%dd %02dh %02dm %02ds',
        intdiv($seconds, 86400),
        intdiv($seconds % 86400, 3600),
        intdiv($seconds % 3600, 60),
        $seconds % 60,
    );
}

/** An absent severity is not a second status label; retain only the recorded state. */
function icct_nms_port_history_severity($severity, $state)
{
    if ($severity !== null && $severity !== '' && $severity !== 'None') {
        return $severity;
    }
    return $state !== null && $state !== '' ? $state : 'Not recorded';
}
