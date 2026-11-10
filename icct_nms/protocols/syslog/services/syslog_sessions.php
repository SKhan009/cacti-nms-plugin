<?php

/** Interpret only explicit systemd-logind session lifecycle messages. */
function icct_nms_syslog_session(array $event): ?array
{
    if (($event['event_origin'] ?? '') !== 'syslog' || $event['program'] !== 'systemd-logind') {
        return null;
    }
    $message = $event['message'];
    if (preg_match('/^New session ([a-zA-Z0-9]{1,32}) of user (\S+)\.$/D', $message, $matches)) {
        return ['session' => $matches[1], 'state' => 'opened', 'account' => $matches[2]];
    }
    if (preg_match('/^Session ([a-zA-Z0-9]{1,32}) logged out\.(?: Waiting for processes to exit\.)?$/D', $message, $matches)) {
        return ['session' => $matches[1], 'state' => 'logged_out'];
    }
    if (preg_match('/^Removed session ([a-zA-Z0-9]{1,32})\.$/D', $message, $matches)) {
        return ['session' => $matches[1], 'state' => 'removed'];
    }
    return null;
}

/** Find earlier evidence outside the current page or search, scoped to this device and sender. */
function icct_nms_syslog_session_evidence(int $hostId, array $events, array $sessions): array
{
    $requests = [];
    $args = [];
    foreach ($events as $event) {
        $eventId = (int) ($event['id'] ?? 0);
        $session = $sessions[$eventId] ?? null;
        if ($session && $session['state'] !== 'opened' && (int) $event['repeat_count'] === 1) {
            $requests[] = 'SELECT ? AS event_id, ? AS session_id';
            array_push($args, $eventId, $session['session']);
        }
    }
    if (!$requests) {
        return [];
    }
    // A prior removal ends the candidate session. Limit correlation to recent, individually stored rows.
    $rows = db_fetch_assoc_prepared(
        'SELECT e.id, s.message AS opening_message, s.event_time AS opening_time,
            l.message AS logout_message, l.event_time AS logout_time
        FROM (' . implode(' UNION ALL ', $requests) . ') AS wanted
        JOIN plugin_icct_nms_syslog_events e ON e.id=wanted.event_id AND e.host_id=?
        JOIN plugin_icct_nms_syslog_events s ON s.id=(
            SELECT p.id FROM plugin_icct_nms_syslog_events p
            WHERE p.host_id=e.host_id AND p.source_ip=e.source_ip AND p.event_origin=e.event_origin
                AND p.program=e.program AND p.id<e.id
                AND p.event_time BETWEEN DATE_SUB(e.event_time, INTERVAL 1 DAY) AND e.event_time
                AND (p.message LIKE CONCAT(\'New session \', wanted.session_id, \' of user %.\')
                    OR p.message=CONCAT(\'Removed session \', wanted.session_id, \'.\'))
            ORDER BY p.event_time DESC,p.id DESC LIMIT 1
        ) AND s.message LIKE \'New session %\' AND s.repeat_count=1
        LEFT JOIN plugin_icct_nms_syslog_events l ON l.id=(
            SELECT p.id FROM plugin_icct_nms_syslog_events p
            WHERE p.host_id=e.host_id AND p.source_ip=e.source_ip AND p.event_origin=e.event_origin
                AND p.program=e.program AND p.id>s.id AND p.id<e.id AND p.repeat_count=1
                AND p.event_time BETWEEN s.event_time AND e.event_time
                AND p.message IN (
                    CONCAT(\'Session \', wanted.session_id, \' logged out.\'),
                    CONCAT(\'Session \', wanted.session_id, \' logged out. Waiting for processes to exit.\')
                )
            ORDER BY p.event_time DESC,p.id DESC LIMIT 1
        )',
        [...$args, $hostId],
    );
    return array_column($rows ?: [], null, 'id');
}
