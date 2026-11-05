<?php

require_once __DIR__ . '/port_monitoring_service.php';

/** Latest evidence belongs to the same incident; event evidence itself remains immutable. */
function icct_nms_port_history_context(array $event, array $snapshot)
{
    $recorded =
        $event['details_json'] === null
            ? []
            : json_decode($event['details_json'], true, 32, JSON_THROW_ON_ERROR);
    $latest = empty($event['incident_details'])
        ? $recorded
        : json_decode($event['incident_details'], true, 32, JSON_THROW_ON_ERROR);
    foreach ($snapshot['ports'] ?? [] as $port) {
        if (
            !empty($event['incident_key']) &&
            ($port['details']['incident_key'] ?? null) === $event['incident_key']
        ) {
            $latest = $port['details'];
            break;
        }
    }
    return ['recorded' => $recorded, 'latest' => $latest];
}

function icct_nms_port_history_story(array $event, array $context)
{
    if ($event['event'] === 'Cleared') {
        return 'A fresh observation reported the interface UP. The monitor cleared the alarm automatically; the physical repair action and actor were not reported.';
    }
    if ($event['state_after'] === 'Unknown') {
        return 'The current interface observation was unavailable. A retained alarm does not confirm continued downtime or recovery.';
    }
    if ($event['state_after'] === 'Disabled') {
        return 'Monitoring or device configuration was disabled. This does not confirm link recovery.';
    }
    if ($event['state_after'] === 'Normal') {
        return 'The saved observation reports the interface UP, with no active port alarm severity.';
    }
    if (!empty($context['issue'])) {
        return $context['issue'];
    }
    if (($event['admin_status'] ?? null) !== null && (int) $event['admin_status'] === 2) {
        return 'The device reported this interface administratively DOWN. The initiating user or system was not recorded.';
    }
    if (in_array((int) ($event['oper_status'] ?? 0), [2, 7], true)) {
        return 'The device reported the operational link DOWN. The physical root cause was not recorded.';
    }
    return 'The interface observation was unavailable. Recovery cannot be confirmed from this record.';
}

function icct_nms_port_history_ack(array $event, $hasIncident)
{
    if (
        ($event['ack_event'] ?? null) === 'Automatically acknowledged' &&
        (int) $event['ack_actor_id'] === 0
    ) {
        return 'Automatic (port monitor)';
    }
    if (!empty($event['ack_event'])) {
        return !empty($event['ack_username'])
            ? $event['ack_username']
            : 'User #' . (int) $event['ack_actor_id'] . ' (account unavailable)';
    }
    return $hasIncident ? 'Not recorded' : 'Not applicable';
}
