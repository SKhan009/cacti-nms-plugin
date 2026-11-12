<?php

require_once __DIR__ . '/syslog_systemd.php';

function icct_nms_syslog_history_request(int $id, array $input): array
{
    $syslogSearch = mb_substr(
        trim(is_scalar($input['syslog_search'] ?? '') ? (string) ($input['syslog_search'] ?? '') : ''),
        0,
        200,
    );
    $syslogFiltersView = ['host_id' => (int) $id, 'search' => $syslogSearch];
    $syslogSeverityFilter = $input['syslog_severity'] ?? [];
    if (!is_array($syslogSeverityFilter)) {
        throw new InvalidArgumentException('Choose Syslog severity levels.');
    }
    $syslogSeverityFilter = icct_backend_syslog_filters(['severity_codes' => $syslogSeverityFilter])['severity_codes'];
    if ($syslogSeverityFilter) {
        $syslogFiltersView['severity_codes'] = $syslogSeverityFilter;
    }
    $syslogFacility = $input['syslog_facility'] ?? '';
    if (!is_string($syslogFacility) || ($syslogFacility !== '' && !array_key_exists($syslogFacility, icct_backend_syslog_facility_labels()))) {
        throw new InvalidArgumentException('Choose a Syslog facility.');
    }
    if ($syslogFacility !== '') {
        $syslogFiltersView['facility_codes'] = [(int) $syslogFacility];
    }
    $syslogTab = $input['syslog_tab'] ?? 'all';
    if (!in_array($syslogTab, ['all', 'repeated', 'incidents'], true)) {
        $syslogTab = 'all';
    }
    foreach (['since', 'until'] as $bound) {
        $value = $input['syslog_' . $bound] ?? '';
        if (!is_string($value) || ($value !== '' && !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d$/D', $value))) {
            throw new InvalidArgumentException('Choose a valid Syslog time window.');
        }
        if ($value !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value);
            if (!$parsed || $parsed->format('Y-m-d\\TH:i') !== $value) {
                throw new InvalidArgumentException('Choose a valid Syslog time window.');
            }
        }
        $syslogFiltersView[$bound] = $value === '' ? '' : str_replace('T', ' ', $value) . ($bound === 'until' ? ':59' : ':00');
    }
    if ($syslogFiltersView['since'] !== '' && $syslogFiltersView['until'] !== '' && $syslogFiltersView['since'] > $syslogFiltersView['until']) {
        throw new InvalidArgumentException('Syslog From must not be later than Until.');
    }
    return [$syslogFiltersView, $syslogTab];
}

/** Report filters apply to the starting event. Recovery can have a different severity or text. */
function icct_nms_syslog_report_filter(array $filters): array
{
    $where = [];
    $params = [];
    foreach (['severity_codes' => 'severity_code', 'facility_codes' => 'facility_code'] as $key => $column) {
        if (!empty($filters[$key])) {
            $where[] = 'e.' . $column . ' IN (' . implode(',', array_fill(0, count($filters[$key]), '?')) . ')';
            array_push($params, ...$filters[$key]);
        }
    }
    if (($filters['search'] ?? '') !== '') {
        $where[] = '(e.message LIKE ? OR e.source_ip LIKE ?)';
        $like = '%' . $filters['search'] . '%';
        array_push($params, $like, $like);
    }
    foreach (['since' => '>=', 'until' => '<='] as $bound => $operator) {
        if (($filters[$bound] ?? '') !== '') {
            $where[] = 'e.event_time' . $operator . '?';
            $params[] = $filters[$bound];
        }
    }
    return [$where ? implode(' AND ', $where) : '1=1', $params];
}

function icct_nms_syslog_link_transition(string $message): ?array
{
    $patterns = [
        '/^(?:%LINK-\d+-[A-Z_]+:\s*)?(?:Interface|Port)\s+(?<port>[a-z0-9][a-z0-9.\/:-]*)\s*,?\s+(?:changed state to|link is|link)\s+(?<state>up|down)\b/i',
        '/^(?<port>[a-z0-9][a-z0-9.\/:-]*):\s+(?:NIC\s+)?Link is (?<state>Up|Down)\b/i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, trim($message), $match)) {
            return [$match['port'], strtolower($match['state'])];
        }
    }
    return null;
}

function icct_nms_syslog_link_incidents(array $events): array
{
    $open = [];
    $incidents = [];
    foreach ($events as $event) {
        $transition = icct_nms_syslog_link_transition($event['message']);
        if ($transition === null) {
            continue;
        }
        [$port, $state] = $transition;
        $key = $event['source_ip'] . '|' . strtolower($port);
        if ($state === 'down') {
            if (!isset($open[$key])) {
                $open[$key] = [
                    'start' => $event,
                    'port' => $port,
                    'recovery' => null,
                    'seconds' => null,
                    'occurrences' => (int) $event['repeat_count'],
                ];
            } else {
                $open[$key]['occurrences'] += (int) $event['repeat_count'];
            }
        } elseif (isset($open[$key])) {
            $incident = $open[$key];
            unset($open[$key]);
            if ($incident['start']['matches_filter']) {
                $incident['recovery'] = $event['event_time'];
                $incident['seconds'] = max(0, strtotime($event['event_time']) - strtotime($incident['start']['event_time']));
                $incidents[] = $incident;
            }
        }
    }
    foreach ($open as $incident) {
        if ($incident['start']['matches_filter']) {
            $incidents[] = $incident;
        }
    }
    usort($incidents, static fn ($a, $b) =>
        strcmp($b['start']['event_time'], $a['start']['event_time']) ?: $b['start']['id'] <=> $a['start']['id']);
    return $incidents;
}

function icct_nms_syslog_history_key(array $event): string
{
    if (($event['event_origin'] ?? '') === 'syslog' && $event['program'] === 'systemd' &&
        ($restart = icct_nms_syslog_restart($event['message']))) {
        return 'restart:' . hash('sha256', implode('|', [
            $event['source_ip'], $event['facility_code'], $event['severity_code'], $restart['unit'],
        ]));
    }
    return $event['fingerprint'] ?? '';
}

/** Count occurrences over the filtered history, including records outside the visible page. */
function icct_nms_syslog_history_occurrences(int $host, array $filters, array $events): array
{
    $keys = array_values(array_unique(array_filter(array_map('icct_nms_syslog_history_key', $events))));
    if (!$keys) {
        return [];
    }
    [$groups, $params] = icct_nms_syslog_history_groups($host, $filters);
    $rows = db_fetch_assoc_prepared(
        $groups . ' HAVING event_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')',
        [...$params, ...$keys],
    );
    return array_column($rows ?: [], 'occurrences', 'event_key');
}

function icct_nms_syslog_history_groups(int $host, array $filters): array
{
    [$filter, $params] = icct_nms_syslog_report_filter($filters);
    $pattern = ICCT_NMS_SYSLOG_RESTART_PATTERN;
    $eventKey = "CASE WHEN e.event_origin='syslog' AND BINARY e.program='systemd'
        AND BINARY e.message REGEXP '$pattern'
        THEN CONCAT('restart:',SHA2(CONCAT_WS('|',e.source_ip,e.facility_code,e.severity_code,
            SUBSTRING_INDEX(e.message, ': Scheduled restart job, restart counter is at ',1)),256))
        ELSE e.fingerprint END";
    $groups = "SELECT $eventKey AS event_key, MIN(e.event_time) AS first_time, MAX(e.event_time) AS last_time,
        SUM(e.repeat_count) AS occurrences, MAX(e.id) AS latest_id
        FROM plugin_icct_nms_syslog_events e WHERE e.host_id=? AND e.event_origin IN ('syslog','availability')
        AND ($filter) GROUP BY event_key";
    return [$groups, [$host, ...$params]];
}

function icct_nms_syslog_repeated_rows(string $groups, array $params, int $limit, int $offset): array
{
    return db_fetch_assoc_prepared(
        "SELECT g.event_key,g.first_time,g.last_time,g.occurrences,e.message,e.source_ip,e.severity_code,e.facility_code
            FROM ($groups HAVING SUM(e.repeat_count)>1 ORDER BY last_time DESC, latest_id DESC LIMIT $limit OFFSET $offset) g
            JOIN plugin_icct_nms_syslog_events e ON e.id=g.latest_id ORDER BY g.last_time DESC,g.latest_id DESC",
        $params,
    ) ?: [];
}

function icct_nms_syslog_incident_rows(int $host, array $filters): array
{
    [$filter, $params] = icct_nms_syslog_report_filter($filters);
    $candidateSql = "SELECT e.id,e.source_ip,e.event_time,e.message,e.repeat_count,e.severity_code,e.facility_code,
        CASE WHEN ($filter) THEN 1 ELSE 0 END AS matches_filter
        FROM plugin_icct_nms_syslog_events e WHERE e.host_id=? AND e.event_origin='syslog'
        AND (e.message LIKE '%link%up%' OR e.message LIKE '%link%down%'
            OR e.message LIKE '%changed state to up%' OR e.message LIKE '%changed state to down%')";
    $candidateParams = [...$params, $host];
    if (!empty($filters['until'])) {
        $candidateSql .= ' AND e.event_time<=?';
        $candidateParams[] = $filters['until'];
    }
    $candidates = db_fetch_assoc_prepared($candidateSql . ' ORDER BY e.event_time,e.id', $candidateParams);
    return icct_nms_syslog_link_incidents($candidates ?: []);
}

function icct_nms_syslog_history_report(int $host, array $filters, string $tab, int $limit, int $page): array
{
    [$groups, $groupParams] = icct_nms_syslog_history_groups($host, $filters);
    $summary = db_fetch_row_prepared(
        "SELECT COUNT(*) AS event_types, COALESCE(SUM(occurrences),0) AS messages,
            COALESCE(SUM(occurrences>1),0) AS repeated FROM ($groups) grouped",
        $groupParams,
    );
    $incidents = icct_nms_syslog_incident_rows($host, $filters);
    $summary['incidents'] = count($incidents);
    $summary['open'] = count(array_filter($incidents, static fn ($incident) => $incident['recovery'] === null));
    $summary['downtime'] = array_sum(array_column($incidents, 'seconds'));
    $total = (int) ($summary[$tab] ?? 0);
    $page = min($page, max(1, (int) ceil($total / $limit)));
    $offset = ($page - 1) * $limit;
    $rows = [];
    if ($tab === 'repeated' && $total) {
        $rows = icct_nms_syslog_repeated_rows($groups, $groupParams, $limit, $offset);
    } elseif ($tab === 'incidents') {
        $rows = array_slice($incidents, $offset, $limit);
    }
    return [$summary, $rows, $total, $page];
}

function icct_nms_syslog_duration(int $seconds): string
{
    $parts = [];
    foreach ([86400 => 'd', 3600 => 'h', 60 => 'm', 1 => 's'] as $unit => $label) {
        if ($seconds >= $unit) {
            $parts[] = intdiv($seconds, $unit) . $label;
            $seconds %= $unit;
        }
    }
    return $parts ? implode(' ', $parts) : '0s';
}
