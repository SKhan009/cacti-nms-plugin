<?php

require_once __DIR__ . '/syslog_history_report.php';

function icct_nms_syslog_csv_rows(int $host, array $filters, string $tab): Generator
{
    if ($tab === 'incidents') {
        yield from icct_nms_syslog_incident_rows($host, $filters);
        return;
    }
    $limit = 500;
    if ($tab === 'repeated') {
        [$groups, $params] = icct_nms_syslog_history_groups($host, $filters);
        for ($offset = 0; ; $offset += $limit) {
            $rows = icct_nms_syslog_repeated_rows($groups, $params, $limit, $offset);
            yield from $rows;
            if (count($rows) < $limit) {
                return;
            }
        }
    }
    [$filter, $params] = icct_nms_syslog_report_filter($filters);
    $query = "SELECT e.id,e.event_time,e.last_seen,e.event_origin,e.source_ip,e.transport,e.facility_code,
        e.severity_code,e.repeat_count,e.message,e.raw_message,e.timestamp_kind
        FROM plugin_icct_nms_syslog_events e WHERE e.host_id=? AND e.event_origin IN ('syslog','availability') AND ($filter)";
    $cursor = [];
    do {
        $boundary = $cursor ? ' AND (e.event_time<? OR (e.event_time=? AND e.id<?))' : '';
        $rows =
            db_fetch_assoc_prepared(
                $query . $boundary . ' ORDER BY e.event_time DESC,e.id DESC LIMIT ' . $limit,
                [$host, ...$params, ...$cursor],
            ) ?:
            [];
        yield from $rows;
        if (count($rows) === $limit) {
            $last = end($rows);
            $cursor = [$last['event_time'], $last['event_time'], (int) $last['id']];
        }
    } while (count($rows) === $limit);
}

function icct_nms_syslog_export_history(int $id, array $input): void
{
    [$filters, $tab] = icct_nms_syslog_history_request($id, $input);
    $rows = icct_nms_syslog_csv_rows($id, $filters, $tab);
    $rows->rewind();
    $severities = icct_backend_syslog_severity_labels();
    $facilities = icct_backend_syslog_facility_labels();
    $columns = match ($tab) {
        'incidents' => [
            'Started',
            'Interface',
            'Event',
            'Sender IP',
            'Severity',
            'Facility',
            'Occurrences',
            'Recovery',
            'Downtime (seconds)',
            'Status',
        ],
        'repeated' => [
            'First occurrence',
            'Last occurrence',
            'Event',
            'Sender IP',
            'Severity',
            'Facility',
            'Occurrences',
        ],
        default => [
            'Event time',
            'Last received',
            'Event source',
            'Sender IP',
            'Transport',
            'Severity',
            'Facility',
            'Occurrences',
            'Timestamp source',
            'Original message',
            'Raw Syslog',
        ],
    };
    header('Content-Type: text/csv; charset=utf-8');
    header(
        'Content-Disposition: attachment; filename="syslog-device-' . $id . '-' . $tab . '.csv"',
    );
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fputcsv($out, $columns, ',', '"', '');
    foreach ($rows as $row) {
        $event = $tab === 'incidents' ? $row['start'] : $row;
        $severity = $severities[(int) $event['severity_code']] ?? 'Unknown';
        $facility = $facilities[$event['facility_code']] ?? '';
        $values = match ($tab) {
            'incidents' => [
                $event['event_time'],
                $row['port'],
                $event['message'],
                $event['source_ip'],
                $severity,
                $facility,
                $row['occurrences'],
                $row['recovery'],
                $row['seconds'],
                $row['recovery'] === null ? 'Open' : 'Recovered',
            ],
            'repeated' => [
                $row['first_time'],
                $row['last_time'],
                $event['message'],
                $event['source_ip'],
                $severity,
                $facility,
                $row['occurrences'],
            ],
            default => [
                $event['event_time'],
                $event['last_seen'],
                $event['event_origin'],
                $event['source_ip'],
                $event['transport'],
                $severity,
                $facility,
                $event['repeat_count'],
                $event['timestamp_kind'],
                $event['message'],
                $event['raw_message'],
            ],
        };
        $values = array_map(static function ($value) {
            $value = (string) $value;
            return preg_match('/^\s*[=+@-]|^[\t\r\n]/u', $value) ? "'" . $value : $value;
        }, $values);
        fputcsv($out, $values, ',', '"', '');
        if (connection_aborted()) {
            break;
        }
    }
    fclose($out);
}
