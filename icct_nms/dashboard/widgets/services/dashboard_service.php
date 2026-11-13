<?php

/** Personal widget layouts; only known widgets, at most five dashboards. */
function icct_nms_dashboard_validate($value)
{
    if (
        !is_array($value) ||
        !isset($value['dashboards'], $value['selected']) ||
        !is_array($value['dashboards']) ||
        count($value['dashboards']) < 1 ||
        count($value['dashboards']) > 5
    ) {
        throw new InvalidArgumentException('Choose one to five dashboards.');
    }
    $refresh = $value['refresh_seconds'] ?? 30;
    if (!is_int($refresh) || $refresh < 5 || $refresh > 3600) {
        throw new InvalidArgumentException('Refresh interval must be 5–3600 seconds.');
    }
    $clean = [
        'refresh_seconds' => $refresh,
        'selected' => (int) $value['selected'],
        'dashboards' => [],
        'site_scope' => 'native',
    ];
    foreach (array_values($value['dashboards']) as $index => $dashboard) {
        if (
            !is_array($dashboard) ||
            !isset($dashboard['widgets']) ||
            !is_array($dashboard['widgets']) ||
            count($dashboard['widgets']) > 11
        ) {
            throw new InvalidArgumentException('Invalid dashboard widgets.');
        }
        $widgets = array_values($dashboard['widgets']);
        foreach ($widgets as $widget) {
            if (!is_string($widget)) {
                throw new InvalidArgumentException('Invalid widget.');
            }
        }
        if (
            count(array_unique($widgets)) !== count($widgets) ||
            array_diff($widgets, [
                'topology',
                'birds',
                'alarms',
                'ack',
                'escalation',
                'frequent',
                'recent',
                'ports',
                'problematic',
                'ntp',
                'netflow_talkers',
            ])
        ) {
            throw new InvalidArgumentException('Unknown or duplicate widget.');
        }
        $name = $dashboard['name'] ?? 'Dashboard #' . ($index + 1);
        if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 60) {
            throw new InvalidArgumentException('Enter a dashboard name of 1 to 60 characters.');
        }
        $columns = $dashboard['columns'] ?? 'auto';
        if (!in_array($columns, ['auto', 2, 3], true)) {
            throw new InvalidArgumentException('Choose automatic, two or three columns.');
        }
        $siteId = $dashboard['site_id'] ?? 0;
        if (!is_int($siteId) || $siteId < 0 || $siteId > 4294967295) {
            throw new InvalidArgumentException('Choose a valid site.');
        }
        $clean['dashboards'][] = [
            'name' => trim($name),
            'columns' => $columns,
            'site_id' => $siteId,
            'widgets' => array_merge(
                ['topology'],
                array_values(array_diff($widgets, ['topology'])),
            ),
        ];
    }
    if ($clean['selected'] < 0 || $clean['selected'] >= count($clean['dashboards'])) {
        throw new InvalidArgumentException('Dashboard unavailable.');
    }
    if (isset($value['deleted'])) {
        $deleted = $value['deleted'];
        if (
            !is_array($deleted) ||
            !isset($deleted['index'], $deleted['dashboard']) ||
            !is_int($deleted['index']) ||
            $deleted['index'] < 0 ||
            $deleted['index'] > 4
        ) {
            throw new InvalidArgumentException('Invalid deleted dashboard.');
        }
        $restored = icct_nms_dashboard_validate([
            'selected' => 0,
            'dashboards' => [$deleted['dashboard']],
        ]);
        $clean['deleted'] = [
            'index' => $deleted['index'],
            'dashboard' => $restored['dashboards'][0],
        ];
    }
    return $clean;
}
function icct_nms_dashboard_preferences()
{
    $raw = db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?', [
        'dashboard_user_' . icct_backend_current_user_id(),
    ]);
    if ($raw) {
        $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return icct_nms_dashboard_validate($value);
    }
    return [
        'refresh_seconds' => 30,
        'selected' => 0,
        'dashboards' => [
            [
                'widgets' => [
                    'topology',
                    'birds',
                    'alarms',
                    'ack',
                    'escalation',
                    'frequent',
                    'recent',
                    'ports',
                    'problematic',
                ],
            ],
        ],
    ];
}
function icct_nms_dashboard_save($value)
{
    $clean = icct_nms_dashboard_validate($value);
    icct_backend_category_execute(
        'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON ' .
            'DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
        [
            'dashboard_user_' . icct_backend_current_user_id(),
            json_encode($clean, JSON_THROW_ON_ERROR),
        ],
    );
    return $clean;
}
function icct_nms_dashboard_ntp($devices, $activeCounts)
{
    require_once __DIR__ . '/../../../protocols/ntp/services/ntp_service.php';
    $names = array_column($devices, 'name', 'id');
    if (!$names || !icct_nms_ntp_ready()) {
        return [];
    }
    $ids = array_map('intval', array_keys($names));
    $assigned = db_fetch_assoc(
        'SELECT host_id FROM plugin_icct_nms_ntp_monitors WHERE host_id IN (' .
            implode(',', $ids) .
            ')',
    );
    $rows = [];
    foreach ($assigned ?: [] as $assignment) {
        $id = (int) $assignment['host_id'];
        $current = icct_nms_ntp_current($id);
        $sample = $current['sample'] ?? [];
        $rows[] = [
            'id' => $id,
            'name' => $names[$id],
            'status' => $current['status'],
            'source' => $sample['source_address'] ?? null,
            'offset_ms' => $sample['offset_ms'] ?? null,
            'stratum' => $sample['stratum'] ?? null,
            'last_success' => $current['last_success'],
            'active_alarms' => $activeCounts[$id] ?? 0,
        ];
    }
    usort(
        $rows,
        static fn($a, $b) => $b['active_alarms'] <=> $a['active_alarms'] ?:
        strnatcasecmp($a['name'], $b['name']),
    );
    return $rows;
}

function icct_nms_dashboard_netflow_talkers($devices)
{
    $names = [];
    foreach ($devices as $device) {
        if ((int) ($device['id'] ?? 0) > 0) {
            $names[(int) $device['id']] = $device['name'] ?? 'Device ' . $device['id'];
        }
    }
    if (!$names) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $rows = db_fetch_assoc_prepared(
        'SELECT f.host_id,f.src_addr,SUM(f.bytes) AS bytes,COUNT(*) AS flows ' .
            'FROM plugin_icct_nms_netflow_flows f ' .
            'JOIN plugin_icct_nms_netflow_devices d ON d.host_id=f.host_id AND d.enabled=1 ' .
            'JOIN host h ON h.id=f.host_id AND h.deleted=\'\' AND h.disabled=\'\' ' .
            "LEFT JOIN plugin_icct_nms_meta p ON p.meta_key=CONCAT('protocol_disabled_netflow_',f.host_id) " .
            "WHERE f.host_id IN ($placeholders) AND COALESCE(p.meta_value,'0')<>'1' " .
            'AND f.last_seen BETWEEN DATE_SUB(NOW(),INTERVAL 5 MINUTE) AND NOW() ' .
            'GROUP BY f.host_id,f.src_addr ORDER BY bytes DESC,f.src_addr,f.host_id LIMIT 10',
        array_keys($names),
    );
    if ($rows === false) {
        throw new RuntimeException('Unable to read NetFlow top talkers.');
    }
    return array_map(static fn($row) => $row + ['device' => $names[(int) $row['host_id']]], $rows);
}

function icct_nms_dashboard_readings($mapData, $includeNtp = false, $includeNetflowTalkers = false)
{
    $severities = array_fill_keys(['Critical', 'Major', 'Minor', 'Warning', 'Information'], 0);
    $segments = [];
    $devices = $mapData['unlocated'];
    $sites = [];
    $frequent = [];
    $recent = [];
    $ports = [];
    $problematic = [];
    foreach ($mapData['sites'] as $site) {
        $devices = array_merge($devices, $site['devices']);
        $summary = icct_nms_map_site_summary($site);
        $sites[] = [
            'id' => $site['id'],
            'name' => $site['name'],
            'coordinates' => $site['coordinates'],
            'status' => $summary['status'],
        ];
    }
    $visibleIds = array_fill_keys(array_map('intval', array_column($devices, 'id')), true);
    $workflow = [
        'ack' => ['Ack' => 0, 'Not_Ack' => 0],
        'escalation' => ['Esc' => 0, 'Not_Esc' => 0],
    ];
    $ntpCounts = [];
    foreach (icct_nms_alarm_rows() as $alarm) {
        $id = (int) $alarm['host_id'];
        if ($alarm['state'] !== 'Active' || !isset($visibleIds[$id])) {
            continue;
        }
        $workflow['ack'][(int) $alarm['acknowledged'] ? 'Ack' : 'Not_Ack']++;
        $workflow['escalation'][(int) $alarm['escalated'] ? 'Esc' : 'Not_Esc']++;
        $source = $alarm['source'] ?? '';
        if ($source === 'NTP') {
            $ntpCounts[$id] = ($ntpCounts[$id] ?? 0) + 1;
        }
    }

    foreach ($devices as &$device) {
        if (!empty($device['ports']['items'])) {
            $ports[] = [
                'id' => $device['id'],
                'name' => $device['name'],
                'ports' => $device['ports'],
            ];
        }
        foreach ($device['fault_alarms'] ?? [] as $alarm) {
            $recent[] = $alarm + [
                'device' => $device['name'] ?? 'Device',
                'device_id' => (int) ($device['id'] ?? 0),
            ];
            $key = json_encode([$alarm['name'], $alarm['severity']]);
            if (!isset($frequent[$key])) {
                $frequent[$key] = $alarm + ['count' => 0];
            }
            $frequent[$key]['count']++;
        }
        $total = 0;
        foreach ($device['fault_counts'] ?? [] as $key => $count) {
            if (isset($severities[$key])) {
                $severities[$key] += (int) $count;
                $total += (int) $count;
            }
        }
        $segment = trim($device['category'] ?? '') ?: 'Unassigned';
        $segments[$segment] = ($segments[$segment] ?? 0) + $total;
    }
    unset($device);
    foreach ($devices as $device) {
        $counts = array_intersect_key($device['fault_counts'] ?? [], $severities);
        if (array_sum($counts) > 0) {
            $problematic[] = [
                'id' => (int) ($device['id'] ?? 0),
                'name' => $device['name'] ?? 'Device',
                'counts' => $counts,
            ];
        }
    }
    arsort($segments);
    $frequent = array_values($frequent);
    usort(
        $frequent,
        static fn($a, $b) => $b['count'] <=> $a['count'] ?:
        strcmp($a['name'], $b['name']) ?:
        strcmp($a['severity'], $b['severity']),
    );
    usort(
        $recent,
        static fn($a, $b) => ($b['time'] ?? 0) <=> ($a['time'] ?? 0) ?:
        strcmp($a['name'], $b['name']),
    );
    require_once __DIR__ . '/../../../inventory/discovery/services/inventory_events.php';
    $inventoryEvents = icct_nms_inventory_recent_events(array_column($devices, 'id'));
    usort($ports, static fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return [
        'device_counts' => $mapData['counts'] ?? null,
        'inventory_changes' => $inventoryEvents,
        'device_alarms' => array_map(
            static fn($device) => [
                'id' => (int) ($device['id'] ?? 0),
                'counts' => $device['fault_counts'] ?? [],
                'alarm' => $device['alarm'] ?? null,
            ],
            $devices,
        ),
        'problematic' => ['total_devices' => count($devices), 'devices' => $problematic],
        'ports' => $ports,
        'ntp' => $includeNtp ? icct_nms_dashboard_ntp($devices, $ntpCounts) : [],
        'netflow_talkers' => $includeNetflowTalkers
            ? icct_nms_dashboard_netflow_talkers($devices)
            : [],
        'recent' => array_slice($recent, 0, 25),
        'frequent' => array_slice($frequent, 0, 10),
        'ack' => $workflow['ack'],
        'escalation' => $workflow['escalation'],
        'sites' => $sites,
        'severity' => $severities,
        'segments' => $segments,
        'total' => array_sum($severities),
        'updated' => date(DATE_ATOM),
    ];
}

/** Site choices come from the native site catalogue. */
function icct_nms_dashboard_sites($map)
{
    return array_map(
        static fn($site) => ['id' => (int) $site['id'], 'name' => $site['name']],
        db_fetch_assoc('SELECT id,name FROM sites ORDER BY name,id'),
    );
}
function icct_nms_dashboard_scope($map, $siteId)
{
    if ($siteId === 0) {
        return $map;
    }
    if (!in_array($siteId, array_column(icct_nms_dashboard_sites($map), 'id'), true)) {
        throw new RuntimeException('Site unavailable.');
    }
    $map['sites'] = array_values(
        array_filter($map['sites'], static fn($site) => (int) $site['id'] === $siteId),
    );
    $map['unlocated'] = array_values(
        array_filter($map['unlocated'], static fn($device) => (int) $device['site_id'] === $siteId),
    );
    return icct_nms_dashboard_recount($map);
}
function icct_nms_dashboard_recount($map)
{
    $devices = $map['unlocated'];
    foreach ($map['sites'] as $site) {
        $devices = array_merge($devices, $site['devices']);
    }
    $map['counts'] = [
        'total' => count($devices),
        'online' => 0,
        'offline' => 0,
        'disabled' => 0,
        'other' => 0,
    ];
    foreach ($devices as $device) {
        $map['counts'][
            $device['status'] === 'Up'
                ? 'online'
                : ($device['status'] === 'Down'
                    ? 'offline'
                    : ($device['status'] === 'Disabled'
                        ? 'disabled'
                        : 'other'))
        ]++;
    }
    return $map;
}

/** Collector membership is always the current native host.poller_id. */
function icct_nms_dashboard_poller_scope($map, $pollerId)
{
    if (!$pollerId) {
        return $map;
    }
    $matches = static fn($device) => (int) $device['poller_id'] === $pollerId;
    $map['unlocated'] = array_values(array_filter($map['unlocated'], $matches));
    foreach ($map['sites'] as &$site) {
        $site['devices'] = array_values(array_filter($site['devices'], $matches));
    }
    unset($site);
    return icct_nms_dashboard_recount($map);
}
