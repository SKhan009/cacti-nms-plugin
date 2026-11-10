<?php

/** CLI-only collection adapter. No collector processes or imports run in web requests. */
function icct_nms_netflow_command($args, $timeout = 20)
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Collector commands are CLI-only.');
    }
    $out = tmpfile();
    $err = tmpfile();
    $pipes = [];
    $proc = proc_open($args, [0 => ['pipe', 'r'], 1 => $out, 2 => $err], $pipes, null, [
        'TZ' => 'Asia/Kolkata',
        'PATH' => '/usr/bin:/usr/sbin:/bin',
    ]);
    if (!is_resource($proc)) {
        throw new RuntimeException('Cannot start nfdump.');
    }
    fclose($pipes[0]);
    $start = microtime(true);
    $exit = -1;
    while (true) {
        $state = proc_get_status($proc);
        if (!$state['running']) {
            $exit = $state['exitcode'];
            break;
        }
        if (microtime(true) - $start > $timeout || fstat($out)['size'] > 128 * 1024 * 1024) {
            proc_terminate($proc, 9);
            proc_close($proc);
            fclose($out);
            fclose($err);
            throw new RuntimeException('Capture decoding exceeded its resource limit.');
        }
        usleep(20000);
    }
    proc_close($proc);
    rewind($out);
    rewind($err);
    $text = stream_get_contents($out);
    $error = stream_get_contents($err);
    fclose($out);
    fclose($err);
    if ($exit !== 0) {
        throw new RuntimeException('nfdump decoding failed: ' . substr($error, 0, 300));
    }
    return $text;
}
function icct_nms_netflow_assignments()
{
    return db_fetch_assoc_prepared(
        'SELECT d.*,h.poller_id,h.disabled FROM plugin_icct_nms_netflow_devices d JOIN host h ON ' .
            'h.id=d.host_id WHERE h.deleted=\'\' AND h.disabled=\'\' AND d.enabled=1 AND h.poller_id=?',
        [icct_backend_inventory_collector_id()],
    );
}
function icct_nms_netflow_import_file($assignment, $file)
{
    $id = (int) $assignment['host_id'];
    $config = icct_nms_netflow_assignment($id);
    if (!$config || !icct_backend_protocol_enabled($id, 'netflow')) {
        return 0;
    }
    $key = hash('sha256', $id . '|' . basename($file) . '|' . hash_file('sha256', $file));
    if (
        db_fetch_cell_prepared(
            'SELECT capture_key FROM plugin_icct_nms_netflow_files WHERE capture_key=?',
            [$key],
        )
    ) {
        return 0;
    }
    $versions = [];
    $exporters = icct_nms_netflow_command(['/usr/bin/nfdump', '-E', $file]);
    preg_match_all(
        '/SysID:\s*(\d+),\s*IP:.*?version:\s*(\d+)/',
        $exporters,
        $matches,
        PREG_SET_ORDER,
    );
    foreach ($matches as $m) {
        $versions[(int) $m[1]] = (int) $m[2];
    }
    $decoded = icct_nms_netflow_command(['/usr/bin/nfdump', '-r', $file, '-o', 'json']);
    $records =
        trim($decoded) === '' || preg_match('/^\[\s*No matching flows\s*\]$/D', trim($decoded))
            ? []
            : json_decode($decoded, true, 128, JSON_THROW_ON_ERROR);
    if (!is_array($records)) {
        throw new RuntimeException('Invalid decoded capture.');
    }
    // nfdump 1.7.3 omits NBAR in JSON; its numeric output preserves exporter application IDs.
    $appLines = [];
    if ($records) {
        $text = icct_nms_netflow_command([
            '/usr/bin/nfdump',
            '-r',
            $file,
            '-q',
            '-o',
            'fmt:%nbid|%nbnam',
        ]);
        $appLines = preg_split('/\r?\n/', trim($text));
        if (count($appLines) !== count($records)) {
            throw new RuntimeException('Application metadata does not align with decoded records.');
        }
    }
    foreach ($records as $index => &$record) {
        $parts = explode('|', $appLines[$index] ?? '', 2);
        if (
            preg_match('/^\s*(\d+)\.\.(\d+)\s*$/D', $parts[0], $app) &&
            ((int) $app[1] || (int) $app[2])
        ) {
            $record['application_id'] = $app[1] . '..' . $app[2];
            $name = trim($parts[1] ?? '');
            if ($name !== '' && $name !== '<no info>' && !preg_match('/[\x00-\x1f]/', $name)) {
                $record['application_name'] = $name;
            }
        }
    }
    unset($record);
    $flows = [];
    foreach ($records as $r) {
        if (($r['type'] ?? '') !== 'FLOW') {
            continue;
        }
        $version = $versions[(int) ($r['export_sysid'] ?? 0)] ?? 0;
        if (!in_array($version, [5, 7, 9, 10], true)) {
            throw new RuntimeException('Unsupported or unidentified flow version.');
        }
        $flows[] = icct_nms_netflow_normalize($r, $version, $config);
    }
    icct_backend_category_execute('START TRANSACTION', []);
    try {
        // The primary key also serializes concurrent retries; roll back all counters on a duplicate.
        icct_backend_category_execute(
            'INSERT INTO plugin_icct_nms_netflow_files(capture_key,host_id,source_file,records_count,imported_at) VALUES(?,?,?,?,NOW())',
            [$key, $id, $file, count($flows)],
        );
        foreach ($flows as $i => $flow) {
            $columns = array_keys($flow);
            $params = array_values($flow);
            icct_backend_category_execute(
                'INSERT INTO plugin_icct_nms_netflow_flows(host_id,capture_key,record_number,' .
                    implode(',', $columns) .
                    ',received_at) VALUES(?,?,?,' .
                    implode(',', array_fill(0, count($columns), '?')) .
                    ',NOW())',
                array_merge([$id, $key, $i], $params),
            );
            $bucket = date(
                'Y-m-d H:i:00',
                strtotime($flow['last_seen']) -
                    ((int) date('i', strtotime($flow['last_seen'])) % 5) * 60,
            );
            icct_backend_category_execute(
                'INSERT INTO ' .
                    'plugin_icct_nms_netflow_buckets(host_id,bucket_start,total_bytes,total_packets,total_flows,updated_at) ' .
                    'VALUES(?,?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE ' .
                    'total_bytes=total_bytes+VALUES(total_bytes),total_packets=total_packets+VALUES(total_packets),total_flows=total_flows+1,updated_at=NOW()',
                [$id, $bucket, $flow['bytes'], $flow['packets']],
            );
        }
        $bytes = array_sum(array_column($flows, 'bytes'));
        $packets = array_sum(array_column($flows, 'packets'));
        icct_backend_category_execute(
            'INSERT INTO ' .
                ('plugin_icct_nms_netflow_state(host_id,status,last_attempt,last_success,last_data_at,' .
                    'last_error,collector_path,total_bytes,total_packets,total_flows,top_json,updated_at) ') .
                'VALUES(?,\'receiving\',NOW(),NOW(),IF(? > 0,NOW(),NULL),\'\',?,?,?,?,?,NOW()) ON DUPLICATE ' .
                'KEY UPDATE status=IF(? > ' .
                '0,\'receiving\',status),last_attempt=NOW(),last_success=NOW(),last_data_at=IF(? > ' .
                ("0,NOW(),last_data_at),last_error='',collector_path=VALUES(collector_path)," .
                    'total_bytes=total_bytes+VALUES(total_bytes),' .
                    'total_packets=total_packets+VALUES(total_packets),' .
                    'total_flows=total_flows+VALUES(total_flows),updated_at=NOW()'),
            [
                $id,
                count($flows),
                dirname($file),
                $bytes,
                $packets,
                count($flows),
                '{}',
                count($flows),
                count($flows),
            ],
        );
        icct_backend_category_execute('COMMIT', []);
        return count($flows);
    } catch (Throwable $e) {
        icct_backend_category_execute('ROLLBACK', []);
        throw $e;
    }
}
function icct_nms_netflow_anomalies($id)
{
    icct_nms_netflow_rule_lock($id, static fn () => icct_nms_netflow_evaluate_anomalies($id));
}

function icct_nms_netflow_evaluate_anomalies($id)
{
    $config = icct_nms_netflow_assignment($id);
    if (!$config) {
        return;
    }
    $window = 'host_id=? AND last_seen BETWEEN DATE_SUB(NOW(),INTERVAL 5 MINUTE) AND NOW()';
    $synOnly = "protocol=6 AND tcp_flags LIKE '%S%' AND tcp_flags NOT LIKE '%A%'";
    $rows = db_fetch_assoc_prepared(
        'SELECT src_addr,COUNT(DISTINCT dst_addr) AS peers,COUNT(DISTINCT dst_port) AS ' .
            'ports,SUM(bytes) AS bytes,SUM(CASE WHEN ' . $synOnly . ' THEN packets ELSE 0 END) AS syn_packets,' .
            'COUNT(DISTINCT CASE WHEN ' . $synOnly . ' THEN dst_addr END) AS syn_targets,' .
            'MIN(CASE WHEN ' . $synOnly . ' THEN dst_addr END) AS syn_target,' .
            'COUNT(DISTINCT CASE WHEN protocol IN(6,17) AND dst_port IN(135,139,445) THEN dst_addr END) AS smb_peers ' .
            'FROM plugin_icct_nms_netflow_flows WHERE ' . $window . ' GROUP BY src_addr ORDER BY bytes DESC,src_addr',
        [(int) $id],
    );
    if ($rows === false) {
        throw new RuntimeException('Unable to read the NetFlow anomaly window.');
    }
    $findings = [];
    foreach ($rows as $r) {
        $ip = $r['src_addr'];
        if (
            (int) $r['peers'] >= $config['scan_threshold'] ||
            (int) $r['ports'] >= $config['scan_threshold']
        ) {
            $findings['scan_' . $ip] = [
                'Major',
                'Possible network scan: ' .
                $ip .
                ' contacted ' .
                $r['peers'] .
                ' destinations and ' .
                $r['ports'] .
                ' ports in 5 minutes.',
            ];
        }
        if ((int) $r['syn_packets'] / 300 >= $config['syn_pps_threshold']) {
            $findings['dos_' . $ip] = [
                'Critical',
                'Possible SYN flood / DoS from ' . $ip . ' to ' .
                ((int) $r['syn_targets'] === 1 ? $r['syn_target'] : $r['syn_targets'] . ' destination IPs') .
                ': ' .
                round($r['syn_packets'] / 300) .
                ' SYN packets/s over 5 minutes.',
            ];
        }
        if ((int) $r['smb_peers'] >= $config['scan_threshold']) {
            $findings['worm_' . $ip] = [
                'Major',
                'Possible worm-like lateral scanning from ' .
                $ip . ' reached ' . $r['smb_peers'] .
                ' destination IPs on SMB/RPC ports in 5 minutes. Check the endpoint for signs of infection.',
            ];
        }
    }
    if ($rows) {
        $packetLimit = (int) $config['syn_pps_threshold'] * 300;
        $targets = db_fetch_assoc_prepared(
            'SELECT dst_addr,COUNT(*) AS sources,SUM(packets) AS packets FROM (' .
                'SELECT dst_addr,src_addr,SUM(packets) AS packets FROM plugin_icct_nms_netflow_flows WHERE ' .
                $window . ' AND ' . $synOnly . ' GROUP BY dst_addr,src_addr) AS senders ' .
                'GROUP BY dst_addr HAVING COUNT(*)>1 AND SUM(packets)>=? AND MAX(packets)<?',
            [(int) $id, $packetLimit, $packetLimit],
        );
        if ($targets === false) {
            throw new RuntimeException('Unable to read NetFlow destination rates.');
        }
        foreach ($targets as $target) {
            $findings['ddos_' . $target['dst_addr']] = [
                'Critical',
                'Possible distributed SYN flood / DoS targeting ' . $target['dst_addr'] . ': ' .
                round($target['packets'] / 300) . ' SYN packets/s from ' . $target['sources'] .
                ' source IPs over 5 minutes.',
            ];
        }
    }
    $total = array_sum(array_column($rows, 'bytes'));
    $baseline = (float) db_fetch_cell_prepared(
        'SELECT AVG(total_bytes) FROM plugin_icct_nms_netflow_buckets WHERE host_id=? AND ' .
            'bucket_start BETWEEN DATE_SUB(NOW(),INTERVAL 65 MINUTE) AND DATE_SUB(NOW(),INTERVAL 10 ' .
            'MINUTE)',
        [(int) $id],
    );
    if (
        ($total * 8) / 300 >= $config['bandwidth_limit_mbps'] * 1000000 ||
        ($baseline > 1000000 && $total > $baseline * 3)
    ) {
        $topTalkers = array_map(
            static fn ($row) => sprintf('%s (%.2f Mbps, %.1f%%)', $row['src_addr'],
                $row['bytes'] * 8 / 300 / 1000000, $row['bytes'] * 100 / $total),
            array_slice($rows, 0, 3),
        );
        $findings['overuse'] = [
            'Major',
            'Unusually high bandwidth: ' .
            round(($total * 8) / 300 / 1000000, 2) .
            ' Mbps in 5 minutes. Top source IPs by traffic share: ' .
            implode(', ', $topTalkers) .
            '.',
        ];
    }
    $findings += icct_nms_netflow_custom_findings($id, $window);
    $keys = [];
    foreach ($findings as $key => [$severity, $message]) {
        $key = str_starts_with($key, 'rule_') ? $key : substr(hash('sha256', $key), 0, 32);
        $keys[] = $key;
        $existing = db_fetch_cell_prepared(
            "SELECT id FROM plugin_icct_nms_netflow_events WHERE host_id=? AND event_key=? AND state='Active'",
            [(int) $id, $key],
        );
        if ($existing) {
            icct_backend_category_execute(
                'UPDATE plugin_icct_nms_netflow_events SET message=?,severity=?,updated_at=NOW() WHERE id=?',
                [$message, $severity, (int) $existing],
            );
        } else {
            icct_backend_category_execute(
                'INSERT INTO ' .
                    'plugin_icct_nms_netflow_events(host_id,event_key,severity,state,message,opened_at,updated_at) ' .
                    'VALUES(?,?,?,\'Active\',?,NOW(),NOW())',
                [(int) $id, $key, $severity, $message],
            );
        }
    }
    // Resolve only with recent data: collector outages must never clear anomalies.
    $recent = db_fetch_cell_prepared(
        'SELECT 1 FROM plugin_icct_nms_netflow_flows WHERE ' . $window .
            ' AND received_at>=DATE_SUB(NOW(),INTERVAL 2 MINUTE) LIMIT 1',
        [(int) $id],
    );
    if ($recent) {
        foreach (
            db_fetch_assoc_prepared(
                "SELECT id,event_key FROM plugin_icct_nms_netflow_events WHERE host_id=? AND state='Active'",
                [(int) $id],
            ) as $event
        ) {
            if (!in_array($event['event_key'], $keys, true)) {
                icct_backend_category_execute(
                    "UPDATE plugin_icct_nms_netflow_events SET state='Resolved',closed_at=NOW(),updated_at=NOW() WHERE id=?",
                    [(int) $event['id']],
                );
            }
        }
    }
}
