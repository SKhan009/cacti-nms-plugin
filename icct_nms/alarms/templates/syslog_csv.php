<?php

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="syslog-events.csv"');
header('Cache-Control: no-store');
$out = fopen('php://output', 'w');
$severities = icct_backend_syslog_severity_labels();
$facilities = icct_backend_syslog_facility_labels();
fputcsv($out, ['Event time', 'Device', 'Short name', 'Source IP', 'Transport', 'Severity', 'Facility', 'Program', 'Message', 'Count', 'Last received'], ',', '"', '');
foreach ($syslogConsole['events'] as $event) {
    $device = $syslogConsole['devices'][$event['host_id']];
    $row = [
        $event['event_time'], $device['description'], $device['short_name'] ?? '',
        $event['source_ip'], strtoupper($event['transport']), $severities[(int) $event['severity_code']] ?? 'Unknown',
        $facilities[(int) $event['facility_code']] ?? $event['facility'], $event['program'],
        $event['message'], $event['repeat_count'], $event['last_seen'],
    ];
    fputcsv($out, array_map(static fn($value) => preg_match('/^[\s]*[=+@-]/', (string) $value)
        ? "'" . $value : $value, $row), ',', '"', '');
}
fclose($out);
