<?php

/** ICCT-owned NetFlow assignments and authorized read models. nfdump owns wire decoding. */
function icct_nms_netflow_defaults()
{
    return [
        'version' => 9,
        'collector' => '',
        'port' => 2055,
        'active_timeout' => 60,
        'inactive_timeout' => 15,
        'sampling_rate' => 1,
        'lan_cidrs' => '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16',
        'wan_interfaces' => '',
        'bandwidth_limit_mbps' => 100,
        'scan_threshold' => 100,
        'syn_pps_threshold' => 1000,
    ];
}
function icct_nms_netflow_fields()
{
    return array_keys(icct_nms_netflow_defaults());
}
function icct_nms_netflow_validate($input)
{
    $out = icct_nms_netflow_defaults();
    foreach ($out as $key => $default) {
        $value = $input[$key] ?? $default;
        if (!is_scalar($value)) {
            throw new InvalidArgumentException('Invalid NetFlow ' . $key);
        }
        $out[$key] = trim((string) $value);
    }
    if (!in_array($out['version'], ['5', '7', '9', '10'], true)) {
        throw new InvalidArgumentException('Choose NetFlow v5, v7, v9 or IPFIX.');
    }
    foreach (
        [
            'port' => [1, 65535],
            'active_timeout' => [1, 86400],
            'inactive_timeout' => [1, 86400],
            'sampling_rate' => [1, 1000000],
            'bandwidth_limit_mbps' => [1, 1000000],
            'scan_threshold' => [2, 1000000],
            'syn_pps_threshold' => [1, 100000000],
        ] as $key => $range
    ) {
        $value = filter_var($out[$key], FILTER_VALIDATE_INT);
        if ($value === false || $value < $range[0] || $value > $range[1]) {
            throw new InvalidArgumentException(
                'NetFlow ' . $key . ' must be between ' . $range[0] . ' and ' . $range[1] . '.',
            );
        }
        $out[$key] = $value;
    }
    if (
        $out['collector'] === '' ||
        strlen($out['collector']) > 253 ||
        (!filter_var($out['collector'], FILTER_VALIDATE_IP) &&
            !filter_var($out['collector'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))
    ) {
        throw new InvalidArgumentException(
            'Enter the collector IP address or hostname reachable from the exporter.',
        );
    }
    foreach (array_filter(explode(',', $out['lan_cidrs'])) as $cidr) {
        $parts = explode('/', trim($cidr));
        $ip = $parts[0];
        $bits = $parts[1] ?? '';
        if (
            count($parts) !== 2 ||
            !filter_var($ip, FILTER_VALIDATE_IP) ||
            !ctype_digit($bits) ||
            (int) $bits > (str_contains($ip, ':') ? 128 : 32)
        ) {
            throw new InvalidArgumentException(
                'Use comma-separated IPv4 or IPv6 LAN prefixes in CIDR format.',
            );
        }
    }
    if (
        strlen($out['lan_cidrs']) > 1000 ||
        strlen($out['wan_interfaces']) > 512 ||
        ($out['wan_interfaces'] !== '' &&
            !preg_match('/^\d+(\s*,\s*\d+)*$/D', $out['wan_interfaces']))
    ) {
        throw new InvalidArgumentException(
            'WAN interfaces must be comma-separated numeric ifIndex values.',
        );
    }
    return $out;
}
function icct_nms_netflow_assignment($id)
{
    $row = $id
        ? db_fetch_row_prepared('SELECT * FROM plugin_icct_nms_netflow_devices WHERE host_id=?', [
            (int) $id,
        ])
        : [];
    if (!$row) {
        return [];
    }
    $raw = db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?', [
        'netflow_config_' . (int) $id,
    ]);
    $extra = $raw ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : [];
    if (!is_array($extra)) {
        throw new RuntimeException(
            'Invalid saved NetFlow configuration. Review and save this device.',
        );
    }

    return array_replace(icct_nms_netflow_defaults(), $row, $extra);
}
function icct_nms_netflow_save($id, $host, $input)
{
    icct_backend_require_management(3);
    icct_backend_require_device_access($id);
    $v = icct_nms_netflow_validate($input);
    $source = trim((string) ($input['exporter_address'] ?? $host['hostname']));
    if (!filter_var($source, FILTER_VALIDATE_IP)) {
        throw new InvalidArgumentException(
            'Enter the exporter source IP address actually seen by this collector.',
        );
    }
    $packed = inet_pton($source);
    if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
        $packed = substr($packed, 12);
    }
    $source = inet_ntop($packed);
    if (
        db_fetch_cell_prepared(
            'SELECT host_id FROM plugin_icct_nms_netflow_devices WHERE exporter_address=? AND host_id<>?',
            [$source, (int) $id],
        )
    ) {
        throw new InvalidArgumentException(
            'That exporter IP is already assigned to another device.',
        );
    }
    icct_backend_category_execute('START TRANSACTION', []);
    try {
        icct_backend_category_execute(
            'INSERT INTO plugin_icct_nms_netflow_devices(host_id,enabled,exporter_address,version,sampling_rate,active_timeout,inactive_timeout,updated_by,updated_at) VALUES(?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),exporter_address=VALUES(exporter_address),version=VALUES(version),sampling_rate=VALUES(sampling_rate),active_timeout=VALUES(active_timeout),inactive_timeout=VALUES(inactive_timeout),updated_by=VALUES(updated_by),updated_at=NOW()',
            [
                (int) $id,
                icct_backend_protocol_enabled($id, 'netflow') ? 1 : 0,
                $source,
                (int) $v['version'],
                $v['sampling_rate'],
                $v['active_timeout'],
                $v['inactive_timeout'],
                icct_backend_current_user_id(),
            ],
        );
        icct_backend_category_execute(
            'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
            ['netflow_config_' . (int) $id, json_encode($v, JSON_THROW_ON_ERROR)],
        );
        icct_backend_category_execute('COMMIT', []);
    } catch (Throwable $e) {
        icct_backend_category_execute('ROLLBACK', []);
        throw $e;
    }
}
function icct_nms_netflow_remove($id)
{
    icct_backend_require_management(3);
    icct_backend_require_device_access($id);
    icct_backend_category_execute('DELETE FROM plugin_icct_nms_netflow_devices WHERE host_id=?', [
        (int) $id,
    ]);
    icct_backend_category_execute('DELETE FROM plugin_icct_nms_meta WHERE meta_key=?', [
        'netflow_config_' . (int) $id,
    ]);
}
function icct_nms_netflow_cidr($ip, $cidr)
{
    [$base, $bits] = explode('/', trim($cidr));
    $address = @inet_pton($ip);
    $network = @inet_pton($base);
    if (!$address || !$network || strlen($address) !== strlen($network)) {
        return false;
    }
    $bytes = intdiv((int) $bits, 8);
    $remainder = (int) $bits % 8;
    return substr($address, 0, $bytes) === substr($network, 0, $bytes) &&
        (!$remainder ||
            ((ord($address[$bytes]) ^ ord($network[$bytes])) & (255 << 8 - $remainder)) === 0);
}
function icct_nms_netflow_scope($record, $config)
{
    $wan = array_map('intval', array_filter(explode(',', $config['wan_interfaces'])));
    if (
        in_array((int) $record['input_if'], $wan, true) ||
        in_array((int) $record['output_if'], $wan, true)
    ) {
        return 'WAN';
    }
    $inside = static function ($ip) use ($config) {
        foreach (array_filter(explode(',', $config['lan_cidrs'])) as $cidr) {
            if (icct_nms_netflow_cidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    };
    $s = $inside($record['src_addr']);
    $d = $inside($record['dst_addr']);
    return $s && $d ? 'LAN' : ($s || $d ? 'WAN' : 'Unknown');
}
function icct_nms_netflow_application($record)
{
    foreach (['application_name', 'nbar_app_name'] as $key) {
        if (!empty($record[$key])) {
            return ['name' => mb_substr((string) $record[$key], 0, 120), 'basis' => 'exporter'];
        }
    }
    foreach (['application_id', 'nbar_app_id'] as $key) {
        if (!empty($record[$key])) {
            return ['name' => 'Application ' . $record[$key], 'basis' => 'exporter'];
        }
    }
    // Ports describe transport endpoints, not application identity.
    return ['name' => 'Not reported', 'basis' => 'unreported'];
}
function icct_nms_netflow_normalize($r, $version, $config)
{
    $source = $r['src4_addr'] ?? ($r['src6_addr'] ?? '');
    $dest = $r['dst4_addr'] ?? ($r['dst6_addr'] ?? '');
    if (!filter_var($source, FILTER_VALIDATE_IP) || !filter_var($dest, FILTER_VALIDATE_IP)) {
        throw new RuntimeException('Decoded flow has invalid addresses.');
    }
    $source = inet_ntop(inet_pton($source));
    $dest = inet_ntop(inet_pton($dest));
    $first = strtotime($r['first'] ?? '');
    $last = strtotime($r['last'] ?? '');
    if (!$first || !$last || $last < $first) {
        throw new RuntimeException('Decoded flow has invalid timestamps.');
    }
    $app = icct_nms_netflow_application($r);
    $out = [
        'version' => (int) $version,
        'src_addr' => $source,
        'dst_addr' => $dest,
        'src_port' => (int) ($r['src_port'] ?? 0),
        'dst_port' => (int) ($r['dst_port'] ?? 0),
        'protocol' => (int) ($r['proto'] ?? 0),
        'tcp_flags' => (string) ($r['tcp_flags'] ?? ''),
        'input_if' => (int) ($r['input_snmp'] ?? 0),
        'output_if' => (int) ($r['output_snmp'] ?? 0),
        'bytes' => (int) ($r['in_bytes'] ?? 0) + (int) ($r['out_bytes'] ?? 0),
        'packets' => (int) ($r['in_packets'] ?? 0) + (int) ($r['out_packets'] ?? 0),
        'first_seen' => date('Y-m-d H:i:s', $first),
        'last_seen' => date('Y-m-d H:i:s', $last),
        'application' => $app['name'],
        'application_basis' => $app['basis'],
        'sampled' => !empty($r['sampled']) ? 1 : 0,
    ];
    if ($out['bytes'] < 0 || $out['packets'] < 0) {
        throw new RuntimeException('Invalid decoded flow counters.');
    }
    // Scale only when no sampling was advertised; nfdump has already scaled advertised samples.
    if (!$out['sampled'] && (int) $config['sampling_rate'] > 1) {
        $out['bytes'] *= (int) $config['sampling_rate'];
        $out['packets'] *= (int) $config['sampling_rate'];
        $out['sampled'] = 1;
    }
    if (!is_int($out['bytes']) || !is_int($out['packets'])) {
        throw new RuntimeException('Sampled flow counters exceed the supported range.');
    }
    $out['traffic_scope'] = icct_nms_netflow_scope($out, $config);
    return $out;
}

/** One parameterized filter is reused for totals, charts, rankings, table pages and CSV. */
function icct_nms_netflow_filter($id, $input)
{
    icct_backend_require_device_access((int) $id);
    foreach (
        [
            'start',
            'end',
            'hours',
            'src_addr',
            'dst_addr',
            'protocol',
            'version',
            'dst_port',
            'traffic_scope',
            'application',
            'page',
            'size',
        ] as $key
    ) {
        if (isset($input[$key]) && !is_scalar($input[$key])) {
            throw new InvalidArgumentException('Use a single value for each flow filter.');
        }
    }
    $end =
        isset($input['end']) && $input['end'] !== '' ? strtotime((string) $input['end']) : time();
    $hours = (int) ($input['hours'] ?? 24);
    if (!in_array($hours, [1, 24, 168, 720], true)) {
        throw new InvalidArgumentException('Choose a supported flow time range.');
    }
    $start =
        isset($input['start']) && $input['start'] !== ''
            ? strtotime((string) $input['start'])
            : $end - $hours * 3600;
    if (!$end || !$start || $end < $start || $end - $start > 30 * 86400) {
        throw new InvalidArgumentException('Select a valid time range of up to 30 days.');
    }
    $where = ['host_id=?', 'last_seen>=?', 'last_seen<=?'];
    $args = [(int) $id, date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $end)];
    foreach (['src_addr', 'dst_addr'] as $key) {
        if (($input[$key] ?? '') !== '') {
            $value = $input[$key];
            if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_IP)) {
                throw new InvalidArgumentException(
                    'Enter an exact source or destination IP address.',
                );
            }
            $where[] = $key . '=?';
            $args[] = inet_ntop(inet_pton($value));
        }
    }
    foreach (['protocol' => 255, 'version' => 10, 'dst_port' => 65535] as $key => $maximum) {
        if (($input[$key] ?? '') !== '') {
            $value = filter_var($input[$key], FILTER_VALIDATE_INT);
            if (
                $value === false ||
                $value < 0 ||
                $value > $maximum ||
                ($key === 'version' && !in_array($value, [5, 7, 9, 10], true))
            ) {
                throw new InvalidArgumentException('Invalid flow ' . $key);
            }
            $where[] = $key . '=?';
            $args[] = $value;
        }
    }
    if (($input['traffic_scope'] ?? '') !== '') {
        if (!in_array($input['traffic_scope'], ['LAN', 'WAN', 'Unknown'], true)) {
            throw new InvalidArgumentException('Choose a valid traffic scope.');
        }
        $where[] = 'traffic_scope=?';
        $args[] = $input['traffic_scope'];
    }
    if (($input['application'] ?? '') !== '') {
        $value = $input['application'];
        if (!is_string($value) || strlen($value) > 120) {
            throw new InvalidArgumentException('Invalid application filter.');
        }
        $where[] = 'application LIKE ?';
        $args[] = '%' . strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $where[count($where) - 1] .= " ESCAPE '!'";
    }
    return ['where' => implode(' AND ', $where), 'args' => $args, 'start' => $start, 'end' => $end];
}
function icct_nms_netflow_read($id, $input)
{
    $filter = icct_nms_netflow_filter($id, $input);
    $where = $filter['where'];
    $args = $filter['args'];
    $size = (int) ($input['size'] ?? 25);
    if (!in_array($size, [10, 25, 50, 100], true)) {
        $size = 25;
    }
    $totals = db_fetch_row_prepared(
        'SELECT COUNT(*) AS flows,COALESCE(SUM(bytes),0) AS bytes,COALESCE(SUM(packets),0) AS packets,COUNT(DISTINCT src_addr) AS sources,MAX(last_seen) AS latest FROM plugin_icct_nms_netflow_flows WHERE ' .
            $where,
        $args,
    );
    $pages = max(1, (int) ceil($totals['flows'] / $size));
    $page = max(1, min($pages, (int) ($input['page'] ?? 1)));
    $offset = ($page - 1) * $size;
    $rows = db_fetch_assoc_prepared(
        'SELECT *,UNIX_TIMESTAMP(first_seen) AS first_epoch,UNIX_TIMESTAMP(last_seen) AS last_epoch FROM plugin_icct_nms_netflow_flows WHERE ' .
            $where .
            ' ORDER BY last_seen DESC,id DESC LIMIT ' .
            $size .
            ' OFFSET ' .
            $offset,
        $args,
    );
    $rankings = [];
    foreach (
        [
            'hosts' => 'src_addr',
            'destinations' => 'dst_addr',
            'protocols' => 'protocol',
            'applications' => "CONCAT(application,' (',application_basis,')')",
            'conversations' =>
                "CONCAT(src_addr,':',src_port,' → ',dst_addr,':',dst_port,' / ',protocol)",
        ] as $name => $expression
    ) {
        $rankings[$name] = db_fetch_assoc_prepared(
            'SELECT ' .
                $expression .
                ' AS label,SUM(bytes) AS bytes,SUM(packets) AS packets,COUNT(*) AS flows FROM plugin_icct_nms_netflow_flows WHERE ' .
                $where .
                ' GROUP BY ' .
                $expression .
                ' ORDER BY bytes DESC LIMIT 10',
            $args,
        );
    }
    $chartInterval = max(300, (int) ceil(($filter['end'] - $filter['start']) / 300 / 300) * 300);
    $chart = db_fetch_assoc_prepared(
        'SELECT FLOOR(UNIX_TIMESTAMP(last_seen)/' .
            $chartInterval .
            ')*' .
            $chartInterval .
            ' AS epoch,traffic_scope,SUM(bytes)*8/' .
            $chartInterval .
            ' AS bps FROM plugin_icct_nms_netflow_flows WHERE ' .
            $where .
            ' GROUP BY epoch,traffic_scope ORDER BY epoch',
        $args,
    );
    $events = db_fetch_assoc_prepared(
        'SELECT *,UNIX_TIMESTAMP(opened_at) AS opened_epoch,UNIX_TIMESTAMP(updated_at) AS updated_epoch FROM plugin_icct_nms_netflow_events WHERE host_id=? AND updated_at BETWEEN ? AND ? ORDER BY updated_at DESC LIMIT 100',
        [(int) $id, date('Y-m-d H:i:s', $filter['start']), date('Y-m-d H:i:s', $filter['end'])],
    );
    $worker =
        json_decode(
            (string) db_fetch_cell_prepared(
                'SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
                [
                    'netflow_worker_' .
                    (int) db_fetch_cell_prepared('SELECT poller_id FROM host WHERE id=?', [
                        (int) $id,
                    ]),
                ],
            ),
            true,
        ) ?:
        [];
    $assignment = icct_nms_netflow_assignment($id);
    $state = db_fetch_row_prepared(
        'SELECT *,UNIX_TIMESTAMP(last_data_at) AS last_data_epoch FROM plugin_icct_nms_netflow_state WHERE host_id=?',
        [(int) $id],
    );
    $enabled =
        $assignment && $assignment['enabled'] && icct_backend_protocol_enabled($id, 'netflow');
    $fresh = $worker && time() - (int) $worker['heartbeat'] < 90;
    $listenerFailed =
        $assignment &&
        in_array(
            (int) $assignment['port'],
            array_map('intval', $worker['failed_ports'] ?? []),
            true,
        );
    $health = !$assignment
        ? 'Not configured'
        : (!$enabled
            ? 'Disabled'
            : (!$fresh
                ? 'Collector unavailable'
                : ($listenerFailed
                    ? 'Collector listener error'
                    : (($state['status'] ?? '') === 'error'
                        ? 'Import error'
                        : (time() - (int) ($state['last_data_epoch'] ?? 0) <=
                        max(180, 2 * (int) $assignment['active_timeout'] + 120)
                            ? 'Receiving'
                            : 'Waiting for flows')))));
    return [
        'totals' => $totals,
        'rankings' => $rankings,
        'chart' => $chart,
        'chart_interval' => $chartInterval,
        'rows' => $rows,
        'events' => $events,
        'page' => $page,
        'pages' => $pages,
        'size' => $size,
        'range' => [$filter['start'], $filter['end']],
        'health' => $health,
        'last_data_epoch' => (int) ($state['last_data_epoch'] ?? 0),
        'error' => $state['last_error'] ?? '',
        'assignment' => $assignment
            ? array_intersect_key(
                $assignment,
                array_flip([
                    'exporter_address',
                    'version',
                    'collector',
                    'port',
                    'active_timeout',
                    'inactive_timeout',
                    'sampling_rate',
                    'lan_cidrs',
                    'wan_interfaces',
                ]),
            )
            : null,
    ];
}

function icct_nms_netflow_topology($id)
{
    $a = icct_nms_netflow_assignment($id);
    if (!$a) {
        return null;
    }
    $state = db_fetch_row_prepared(
        'SELECT status,last_data_at,total_flows,anomaly_status FROM plugin_icct_nms_netflow_state WHERE host_id=?',
        [(int) $id],
    );
    return [
        'enabled' => !empty($a['enabled']) && icct_backend_protocol_enabled($id, 'netflow'),
        'exporter' => $a['exporter_address'],
        'collector' => $a['collector'] . ':' . $a['port'],
        'last_data' => $state['last_data_at'] ?? 'No flows received',
        'flows' => (int) ($state['total_flows'] ?? 0),
        'anomalies' => (int) db_fetch_cell_prepared(
            "SELECT COUNT(*) FROM plugin_icct_nms_netflow_events WHERE host_id=? AND state='Active'",
            [(int) $id],
        ),
    ];
}
