<?php

require __DIR__ . '/../../ports/services/port_history_service.php';
function evidenceCheck($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$port = ['name' => 'eth0', 'index' => 2, 'admin' => 1, 'oper' => 1, 'type' => 6, 'connector' => 1];
$view = ['fresh' => true, 'collected' => 1000, 'source' => 'SNMP IF-MIB / IF-X-MIB'];
$previous = ['name' => 'eth0', 'state' => 'Normal', 'active' => ''];
$port['oper'] = 2;
$transition = icct_nms_port_alarm_transition($previous, 'Major', 'eth0');
$down = icct_nms_port_evidence($previous, $port, $view, $transition, false);
evidenceCheck(
    $down['down_since'] === 1000 &&
        $down['observed_down_seconds'] === 0 &&
        $down['auto_acknowledge'],
    'Fresh down observation starts a measured outage and auto-ack request',
);
$previous = $transition + ['details' => $down];
$view['collected'] = 1060;
$steady = icct_nms_port_evidence(
    $previous,
    $port,
    $view,
    icct_nms_port_alarm_transition($previous, 'Major', 'eth0'),
    false,
);
evidenceCheck(
    $steady['observed_down_seconds'] === 60 && $steady['incident_key'] === $down['incident_key'],
    'Repeated observations retain one incident and update measured duration',
);
$previous['details'] = $steady;
$view['fresh'] = false;
$missing = icct_nms_port_evidence(
    $previous,
    [],
    $view,
    icct_nms_port_alarm_transition($previous, 'Unknown', 'eth0'),
    false,
);
evidenceCheck(
    $missing['observed_down_seconds'] === 60 &&
        $missing['recovered_at'] === null &&
        !$missing['auto_acknowledge'],
    'Missing observations do not extend measured duration or invent recovery',
);
$previous['details'] = $missing;
$view['fresh'] = true;
$view['collected'] = 1120;
$port['oper'] = 1;
$transition = icct_nms_port_alarm_transition($previous, 'Normal', 'eth0');
$up = icct_nms_port_evidence($previous, $port, $view, $transition, false);
evidenceCheck(
    $up['observed_down_seconds'] === 120 &&
        $up['recovered_at'] === 1120 &&
        str_contains($up['recovery'], 'No repair command'),
    'Recovery uses the first confirmed UP sample without claiming a repair command',
);
$previous = $transition + ['details' => $up];
$view['collected'] = 1200;
$port['oper'] = 2;
$new = icct_nms_port_evidence(
    $previous,
    $port,
    $view,
    icct_nms_port_alarm_transition($previous, 'Major', 'eth0'),
    false,
);
evidenceCheck(
    $new['incident_key'] !== $down['incident_key'] &&
        $new['recovery'] === null &&
        $new['down_since'] === 1200 &&
        !$new['observation_gap'],
    'Recurrence must not inherit the previous recovery',
);
$legacy = ['name' => 'eth0', 'state' => 'Major', 'active' => 'Major'];
$legacyEvidence = icct_nms_port_evidence(
    $legacy,
    $port,
    $view,
    icct_nms_port_alarm_transition($legacy, 'Major', 'eth0'),
    false,
);
evidenceCheck(
    $legacyEvidence['down_since'] === null && $legacyEvidence['observed_down_seconds'] === null,
    'Legacy outage start and duration must stay unrecorded',
);
evidenceCheck(
    icct_nms_port_kind($port) === 'Physical' &&
        icct_nms_port_kind(['type' => 24]) === 'Software' &&
        icct_nms_port_kind(['type' => 53, 'connector' => 1]) === 'Software' &&
        icct_nms_port_kind(['name' => 'lo']) === 'Unclassified',
    'Interface grouping must use observed type/connector evidence, not names',
);
evidenceCheck(
    icct_nms_port_history_severity('', 'Normal') === 'Normal' &&
        icct_nms_port_history_severity('None', 'Normal') === 'Normal' &&
        icct_nms_port_history_severity(null, 'Normal') === 'Normal' &&
        icct_nms_port_history_severity('', '') === 'Not recorded',
    'Severity must not concatenate None and Normal',
);
evidenceCheck(
    icct_nms_port_history_ack(
        ['ack_event' => 'Automatically acknowledged', 'ack_actor_id' => 0],
        true,
    ) === 'Automatic (port monitor)' &&
        icct_nms_port_history_ack(
            ['ack_event' => 'Acknowledged', 'ack_actor_id' => 1, 'ack_username' => 'admin'],
            true,
        ) === 'admin' &&
        icct_nms_port_history_ack([], true) === 'Not recorded',
    'Acknowledgement labels must match actual recorded actors',
);
echo "Port evidence: observed downtime, fresh recovery, recurrence, legacy unknowns, truthful causes, grouping and acknowledgement actors passed.\n";
