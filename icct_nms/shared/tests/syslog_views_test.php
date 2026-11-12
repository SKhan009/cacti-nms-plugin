<?php

/** Render the shared preset/device fields and device-scoped history without a live DB. */
set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$queries = [];
$binding = [
    'enabled' => 1,
    'source_address' => '192.0.2.85',
    'transport' => 'both',
    'severity_codes' => '[3,4]',
    'facility_codes' => '[16]',
    'match_strings' => '["link down"]',
];
$events = [
    [
        'event_time' => '2026-10-29 19:30:00',
        'severity_code' => 3,
        'source_ip' => '192.0.2.85',
        'transport' => 'tcp',
        'facility_code' => 16,
        'facility' => 'local0',
        'program' => 'sshd-session',
        'message' => 'pam_unix(sshd:session): session opened for user <script>unsafe</script>(uid=1000)',
        'timestamp_kind' => 'device',
        'alarm_key' => 'linked-alarm',
        'repeat_count' => 1,
        'last_seen' => '2026-10-29 19:30:00',
    ],
    [
        'event_time' => '2026-10-29 19:31:00',
        'severity_code' => 6,
        'source_ip' => '192.0.2.85',
        'transport' => 'tcp',
        'facility_code' => 3,
        'facility' => 'daemon',
        'program' => 'systemd',
        'event_origin' => 'syslog',
        'fingerprint' => 'restart-attempt-fingerprint',
        'message' => 'nms-diagnostic-runner.service: Scheduled restart job, restart counter is at 5938.',
        'timestamp_kind' => 'device',
        'repeat_count' => 1,
        'last_seen' => '2026-10-29 19:31:01',
    ],
];
function db_fetch_row_prepared($sql, $args)
{
    if (str_contains($sql, 'AS event_types')) {
        return ['event_types' => count($GLOBALS['events']), 'messages' => count($GLOBALS['events']), 'repeated' => 0];
    }
    return $GLOBALS['binding'];
}
function db_fetch_cell_prepared($sql, $args)
{
    $GLOBALS['queries'][] = [$sql, $args];
    return count($GLOBALS['events']);
}
function db_fetch_assoc_prepared($sql, $args)
{
    if (str_contains($sql, 'HAVING event_key IN')) {
        return [['event_key' => end($args), 'occurrences' => 27]];
    }
    if (str_contains($sql, 'AS matches_filter')) {
        return [];
    }
    $GLOBALS['queries'][] = [$sql, $args];
    return $GLOBALS['events'];
}
function icct_backend_protocol_enabled($id, $protocol)
{
    return true;
}
function icct_nms_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function syslog_view_check($ok, $message)
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
    echo "PASS: $message\n";
}
require __DIR__ . '/../../protocols/syslog/services/backend/syslog.php';
$template = __DIR__ . '/../../protocols/syslog/templates/syslog.php';
$syslog = $binding;
$presetMode = true;
ob_start();
require $template;
$html = ob_get_clean();
syslog_view_check(
    !str_contains($html, 'name="source_address"') && str_contains($html, 'name="transport"'),
    'Preset exposes transport without a device endpoint',
);
syslog_view_check(
    str_contains($html, 'value="[3,4]"') && str_contains($html, 'value="[16]"'),
    'Preset severity and facility selections render the saved values',
);
syslog_view_check(
    !str_contains($html, 'Syslog Console'),
    'Preset has no standalone console action',
);
// Attribute layout may change; assert the rendered field and its value across whitespace.
$presetMode = false;
$syslog = [];
$id = 0;
ob_start();
require $template;
$html = ob_get_clean();
syslog_view_check(
    preg_match('~name="source_address"\s+(?:required\s+)?value=""~', $html) === 1,
    'Unsaved device renders without a missing-hostname warning',
);
$host = ['hostname' => '192.0.2.85'];
ob_start();
require $template;
$html = ob_get_clean();
syslog_view_check(
    preg_match('~name="source_address"\s+(?:required\s+)?value="192\.0\.2\.85"~', $html) === 1,
    'A new device uses its saved hostname for source matching',
);
$syslog = $binding;
ob_start();
require $template;
$html = ob_get_clean();
syslog_view_check(
    str_contains($html, 'value="[3,4]"') && !str_contains($html, 'Syslog Console'),
    'Device overrides are retained without a console action',
);
$id = 85;
$old = $host;
$_GET = [
    'syslog_search' => 'link down',
    'syslog_ack' => 'invalid',
    'event_alarm' => str_repeat('a', 64),
];
ob_start();
require __DIR__ . '/../../protocols/syslog/templates/device_view_syslog.php';
$html = ob_get_clean();
syslog_view_check(
    !str_contains($html, 'Receiver settings') &&
        str_contains($html, 'name="syslog-event-records"') &&
        str_contains($html, 'SSH session opened') &&
        str_contains($html, 'recorded a session opening') &&
        str_contains($html, '2026-10-29 19:30:00 (Reported by the device)') &&
        str_contains($html, 'Local 0') &&
        str_contains($html, 'Syslog Event History') &&
        str_contains($html, 'What happened') &&
        str_contains($html, '>Occurrences</small>27') &&
        str_contains($html, 'All logs (2)') &&
        str_contains($html, 'Repeated events (0)') &&
        str_contains($html, 'Incidents (0)') &&
        str_contains($html, 'aria-label="Download Syslog CSV"') &&
        str_contains($html, 'syslog=1&amp;syslog_search=link+down') &&
        str_contains($html, 'class="inventory-pagination"') &&
        str_contains($html, 'name="syslog_size"') &&
        str_contains($html, 'name="syslog_page"') &&
        str_contains($html, 'value="link down"'),
    'History shows an expandable event explanation and keeps the active search',
);
syslog_view_check(
    str_contains($html, 'scheduled automatic restart attempt 5938 for the older NMS diagnostics listener') &&
        str_contains($html, 'The outcome of that attempt is unknown from this entry.') &&
        !str_contains($html, 'Fix or recovery status') &&
        str_contains($html, 'Scheduled restart job, restart counter is at 5938.'),
    'History explains a systemd retry without claiming recovery and preserves the raw message',
);
syslog_view_check(
    str_contains($html, 'device-syslog-severity severity-major'),
    'Received messages show the correct severity badge',
);
syslog_view_check(
    !str_contains($html, 'Saved collection settings') &&
        preg_match('~<dt>Received via</dt>\s*<dd>TCP</dd>~', $html) === 1 &&
        !str_contains($html, '<dt>Program</dt>') &&
        !str_contains($html, 'Alarm status') &&
        !str_contains($html, 'linked alarm') &&
        !str_contains($html, 'syslog_ack') &&
        !str_contains($html, 'event_alarm'),
    'History shows transport and omits program and alarm controls',
);
syslog_view_check(
    str_contains($html, '&lt;script&gt;unsafe&lt;/script&gt;') &&
        !str_contains($html, '<script>unsafe'),
    'Received messages are escaped',
);
syslog_view_check(
    !str_contains($html, 'method="post"') && !str_contains($html, 'Ingest Worker'),
    'Device history has no configuration writes or standalone worker panel',
);
foreach ($queries as [$sql, $args]) {
    syslog_view_check(
        $args === [85, 85, '%link down%', '%link down%'] &&
            !str_contains($sql, 'plugin_icct_nms_alarms') &&
            !str_contains($sql, 'e.program LIKE') &&
            !str_contains($sql, 'e.alarm_key'),
        'Event queries search only this device’s messages and sender IPs without alarm data',
    );
}
$binding = [];
$events = [];
$_GET = ['syslog_search' => ['invalid']];
ob_start();
require __DIR__ . '/../../protocols/syslog/templates/device_view_syslog.php';
$html = ob_get_clean();
syslog_view_check(
    str_contains($html, 'No Syslog or availability events'),
    'Unconfigured devices and malformed searches render safely',
);
echo "Syslog preset, device setup and read-only view rendering passed.\n";

$event = static fn ($id, $time, $source, $message, $matches = 1, $repeats = 1) => [
    'id' => $id, 'event_time' => '2026-11-12 ' . $time, 'source_ip' => $source,
    'message' => $message, 'matches_filter' => $matches, 'repeat_count' => $repeats,
];
$incidents = icct_nms_syslog_link_incidents([
    $event(1, '09:00:00', '192.0.2.1', 'Interface Gi1/0/24 changed state to down'),
    $event(2, '09:00:10', '192.0.2.1', '%LINK-3-UPDOWN: Interface Gi1/0/24, changed state to down', 1, 2),
    $event(3, '09:00:20', '192.0.2.2', 'Interface Gi1/0/24 changed state to up', 0),
    $event(4, '09:00:30', '192.0.2.1', 'Interface Gi1/0/25 changed state to up', 0),
    $event(5, '09:02:00', '192.0.2.1', 'Interface Gi1/0/24 changed state to up', 0),
    $event(6, '09:03:00', '192.0.2.1', 'eth0: NIC Link is Down'),
    $event(7, '09:03:10', '192.0.2.1', 'The remote link went down'),
]);
syslog_view_check(
    count($incidents) === 2 && $incidents[0]['recovery'] === null &&
        $incidents[1]['seconds'] === 120 && $incidents[1]['occurrences'] === 3,
    'Incident correlation matches sender and interface, counts repeated downs once and preserves open incidents',
);

[$parsedFilters, $parsedTab] = icct_nms_syslog_history_request(85, [
    'syslog_tab' => 'incidents', 'syslog_search' => ' icctqa ',
    'syslog_severity' => ['4'], 'syslog_facility' => '22',
    'syslog_since' => '2026-11-12T19:56', 'syslog_until' => '2026-11-12T19:57',
]);
syslog_view_check(
    $parsedTab === 'incidents' && $parsedFilters === [
        'host_id' => 85, 'search' => 'icctqa', 'severity_codes' => [4], 'facility_codes' => [22],
        'since' => '2026-11-12 19:56:00', 'until' => '2026-11-12 19:57:59',
    ],
    'History and CSV share normalized filters and include the selected end minute',
);

$restartEvent = [
    'event_origin' => 'syslog', 'program' => 'systemd', 'source_ip' => '192.0.2.85',
    'facility_code' => 3, 'severity_code' => 6, 'fingerprint' => 'original',
    'message' => 'my-service.service: Scheduled restart job, restart counter is at 1.',
];
$restartKey = icct_nms_syslog_history_key($restartEvent);
syslog_view_check(
    icct_nms_syslog_history_key(array_replace($restartEvent, [
        'message' => 'my-service.service: Scheduled restart job, restart counter is at 2.',
        'fingerprint' => 'different',
    ])) === $restartKey &&
        icct_nms_syslog_history_key(array_replace($restartEvent, ['severity_code' => 4])) !== $restartKey &&
        icct_nms_syslog_history_key(array_replace($restartEvent, ['source_ip' => '192.0.2.86'])) !== $restartKey &&
        icct_nms_syslog_history_key(array_replace($restartEvent, ['program' => 'other'])) === 'original',
    'Restart attempts group by service and retain sender and severity boundaries',
);
