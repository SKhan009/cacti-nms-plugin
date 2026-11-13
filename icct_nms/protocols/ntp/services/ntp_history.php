<?php

function icct_nms_ntp_history_statuses(): array
{
    return ['Synchronized', 'Unsynchronized', 'Local reference', 'Unavailable'];
}

function icct_nms_ntp_history_filter(int $hostId, array $input): array
{
    $where = ['host_id=?'];
    $args = [$hostId];
    $dates = [];
    foreach (
        ['ntp_from' => ['>=', '00:00:00'], 'ntp_to' => ['<=', '23:59:59']]
        as $field => [$comparison, $time]
    ) {
        $date = $input[$field] ?? '';
        if ($date === '') {
            continue;
        }
        if (
            !is_string($date) ||
            !preg_match('/^([1-9][0-9]{3})-([0-9]{2})-([0-9]{2})$/D', $date, $parts) ||
            !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        ) {
            throw new InvalidArgumentException('Enter valid NTP history dates.');
        }
        $dates[$field] = $date;
        $where[] = 'observed_at' . $comparison . '?';
        $args[] = $date . ' ' . $time;
    }
    if (isset($dates['ntp_from'], $dates['ntp_to']) && $dates['ntp_from'] > $dates['ntp_to']) {
        throw new InvalidArgumentException('The NTP start date must be on or before the end date.');
    }
    $status = $input['ntp_status'] ?? '';
    if ($status !== '') {
        if (!in_array($status, icct_nms_ntp_history_statuses(), true)) {
            throw new InvalidArgumentException('Choose a valid NTP observation status.');
        }
        $where[] = 'status=?';
        $args[] = $status;
    }
    return [
        'where' => implode(' AND ', $where),
        'args' => $args,
        'dates' => $dates,
        'params' => $status === '' ? $dates : $dates + ['ntp_status' => $status],
    ];
}

function icct_nms_ntp_history_rows(
    array $filter,
    int $limit,
    int $offset = 0,
    ?int $before = null,
): array {
    $where = $filter['where'];
    $args = $filter['args'];
    if ($before !== null) {
        $where .= ' AND id<?';
        $args[] = $before;
    }
    $rows = db_fetch_assoc_prepared(
        'SELECT id,status,sample_json,error,observed_at FROM plugin_icct_nms_ntp_monitor_history WHERE ' .
            $where .
            ' ORDER BY id DESC LIMIT ' .
            $limit .
            ' OFFSET ' .
            $offset,
        $args,
    );
    if ($rows === false) {
        throw new RuntimeException('Could not read NTP history.');
    }
    foreach ($rows as &$row) {
        $row['sample'] = json_decode($row['sample_json'] ?? 'null', true, 16, JSON_THROW_ON_ERROR);
    }
    unset($row);
    return $rows;
}

function icct_nms_ntp_history_metrics(): array
{
    return [
        'offset_ms' => 'Offset (ms)',
        'jitter_ms' => 'Jitter (ms)',
        'peer_delay_ms' => 'Peer delay (ms)',
    ];
}

/** Plot only recorded values and leave missing intervals and backward clock steps visible. */
function icct_nms_ntp_history_trend(array $rows, string $metric, int $interval): array
{
    $times = array_map(static fn($row) => strtotime($row['observed_at']), $rows);
    $values = array_column(array_column($rows, 'sample'), $metric);
    $values = array_filter($values, static fn($value) => $value !== null);
    if (!$values) {
        return [];
    }
    $low = min($values);
    $high = max($values);
    $start = min($times);
    $duration = max($times) - $start;
    $segments = [[]];
    $previousTime = null;
    foreach ($rows as $i => $row) {
        $time = $times[$i];
        $value = $row['sample'][$metric] ?? null;
        $gap =
            $previousTime !== null &&
            ($time <= $previousTime || $time - $previousTime > $interval * 1.5);
        if ($value === null || $gap) {
            $segments[] = [];
        }
        if ($value !== null) {
            $x = $duration ? 5 + (390 * ($time - $start)) / $duration : 200;
            $y = $high > $low ? 55 - (50 * ($value - $low)) / ($high - $low) : 30;
            $segments[array_key_last($segments)][] = [round($x, 2), round($y, 2)];
        }
        $previousTime = $time;
    }
    return ['min' => $low, 'max' => $high, 'segments' => array_filter($segments)];
}

function icct_nms_ntp_export_history(int $hostId, array $input): void
{
    $filter = icct_nms_ntp_history_filter($hostId, $input);
    $limit = 500;
    $rows = icct_nms_ntp_history_rows($filter, $limit);
    $metrics = icct_nms_ntp_history_metrics();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ntp-device-' . $hostId . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fputcsv(
        $out,
        ['Observed at', 'Status', 'Source IP', ...array_values($metrics), 'Collection detail'],
        ',',
        '"',
        '',
    );
    do {
        foreach ($rows as $row) {
            $sample = $row['sample'];
            $error = $row['error'];
            if (preg_match('/^[\s]*[=+@-]/', $error)) {
                $error = "'" . $error;
            }
            $values = array_map(
                static fn($metric) => $sample[$metric] ?? null,
                array_keys($metrics),
            );
            fputcsv(
                $out,
                [
                    $row['observed_at'],
                    $row['status'],
                    $sample['source_address'] ?? '',
                    ...$values,
                    $error,
                ],
                ',',
                '"',
                '',
            );
        }
        if (count($rows) < $limit || connection_aborted()) {
            break;
        }
        $rows = icct_nms_ntp_history_rows($filter, $limit, 0, (int) end($rows)['id']);
    } while ($rows);
    fclose($out);
}
