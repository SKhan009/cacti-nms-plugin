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
        'message' => 'nms-diagnostic-runner.service: Scheduled restart job, restart counter is at 5938.',
        'timestamp_kind' => 'device',
        'repeat_count' => 1,
        'last_seen' => '2026-10-29 19:31:01',
    ],
];
function db_fetch_row_prepared($sql, $args)
{
    return $GLOBALS['binding'];
}
function db_fetch_cell_prepared($sql, $args)
{
    $GLOBALS['queries'][] = [$sql, $args];
    return count($GLOBALS['events']);
}
function db_fetch_assoc_prepared($sql, $args)
{
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
$_GET = ['syslog_search' => 'link down'];
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
        preg_match('~<a class="button" href="[^"]+view=linked-alarm">View linked alarm</a>~', $html) === 1 &&
        str_contains($html, 'value="link down"'),
    'History shows an expandable event explanation and keeps the active search',
);
syslog_view_check(
    str_contains($html, 'scheduled automatic restart attempt 5938 for the older NMS diagnostics listener') &&
        str_contains($html, 'The outcome of that attempt is unknown from this entry.') &&
        str_contains($html, 'Fix or recovery information is unavailable for this event.') &&
        str_contains($html, 'Scheduled restart job, restart counter is at 5938.'),
    'History explains a systemd retry without claiming recovery and preserves the raw message',
);
syslog_view_check(
    str_contains($html, 'device-syslog-severity severity-major'),
    'Received messages show the correct severity badge',
);
syslog_view_check(
    !str_contains($html, 'Saved collection settings') &&
        preg_match('~<dt>Received via</dt>\s*<dd>TCP</dd>~', $html) === 1,
    'History shows the message transport inside its record',
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
        $args[0] === 85 && $args[1] === 85,
        'Event queries remain scoped to the current device',
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
