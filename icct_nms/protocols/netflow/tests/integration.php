<?php

/** Run using native Cacti APIs with connection-local tables. Never writes saved device settings. */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
try {
    require '/var/www/html/cacti/include/cli_check.php';
    api_plugin_load_realms();
    require_once $config['base_path'] . '/lib/auth.php';
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION = ['sess_user_id' => 1];
    require_once __DIR__ . '/../../../shared/services/bootstrap.php';
    require_once __DIR__ . '/../services/netflow_collector.php';
    require_once __DIR__ . '/../services/netflow_faults.php';
    require_once __DIR__ . '/../../../dashboard/widgets/services/dashboard_service.php';
    icct_nms_backend();
    date_default_timezone_set('Asia/Kolkata');
    $tables = [
        'meta',
        'alarms',
        'alarm_history',
        'netflow_devices',
        'netflow_state',
        'netflow_buckets',
        'netflow_metrics',
        'netflow_events',
        'netflow_flows',
        'netflow_files',
    ];
    foreach ($tables as $short) {
        $table = 'plugin_icct_nms_' . $short;
        if (!db_execute('CREATE TEMPORARY TABLE qa_nf_copy LIKE ' . $table)) {
            throw new RuntimeException('Temporary isolation failed');
        }
        if ($short === 'meta' && !db_execute('INSERT INTO qa_nf_copy SELECT * FROM ' . $table)) {
            throw new RuntimeException('Metadata isolation failed');
        }
        if (!db_execute('CREATE TEMPORARY TABLE ' . $table . ' LIKE qa_nf_copy')) {
            throw new RuntimeException('Temporary isolation failed');
        }
        if ($short === 'meta') {
            db_execute('INSERT INTO ' . $table . ' SELECT * FROM qa_nf_copy');
        }
        db_execute('DROP TEMPORARY TABLE qa_nf_copy');
    }
    function nfAssert($condition, $message)
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
    $host = db_fetch_row_prepared(
        "SELECT * FROM host WHERE deleted='' AND disabled='' AND poller_id=? ORDER BY id LIMIT 1",
        [icct_backend_inventory_collector_id()],
    );
    nfAssert(!empty($host), 'An enabled device on this collector is required.');
    $id = (int) $host['id'];
    $nfSettings = icct_nms_netflow_defaults();
    $nfSettings['collector'] = '127.0.0.1';
    $nfSettings['port'] = 22055;
    $nfSettings['exporter_address'] = '127.0.0.77';
    foreach ([5, 7, 9, 10] as $version) {
        $nfSettings['version'] = $version;
        icct_nms_netflow_save($id, $host, $nfSettings);
        nfAssert(
            (int) icct_nms_netflow_assignment($id)['version'] === $version,
            'Version save failed',
        );
    }
    try {
        icct_nms_netflow_save($id, $host, array_replace($nfSettings, ['version' => 8]));
        throw new LogicException('Bad version accepted');
    } catch (InvalidArgumentException $e) {
    }
    nfAssert(
        icct_nms_netflow_cidr('10.1.2.3', '10.0.0.0/8') &&
            !icct_nms_netflow_cidr('11.1.2.3', '10.0.0.0/8') &&
            icct_nms_netflow_cidr('2001:db8::1', '2001:db8::/32'),
        'CIDR matching failed',
    );
    $assignment = icct_nms_netflow_assignment($id);
    $count = 0;
    $nfOptions = getopt('', ['captures:']);
    $nfCapturePath = $nfOptions['captures'] ?? '/tmp/icct-netflow-qa/127-0-0-77';
    $nfRecord = [
        'src4_addr' => '10.0.0.1',
        'dst4_addr' => '10.0.0.2',
        'first' => date('c', time() - 1),
        'last' => date('c'),
        'in_bytes' => 100,
        'in_packets' => 10,
    ];
    $nfNormalized = icct_nms_netflow_normalize(
        $nfRecord,
        5,
        array_replace($assignment, ['sampling_rate' => 100]),
    );
    nfAssert(
        $nfNormalized['bytes'] === 10000 && $nfNormalized['traffic_scope'] === 'LAN',
        'Configured sampling fallback or LAN classification failed',
    );
    $nfNormalized = icct_nms_netflow_normalize(
        $nfRecord + ['sampled' => 1],
        5,
        array_replace($assignment, ['sampling_rate' => 100]),
    );
    nfAssert($nfNormalized['bytes'] === 100, 'Advertised sampling was multiplied twice');
    $nfNormalized = icct_nms_netflow_normalize(
        $nfRecord + ['input_snmp' => 2],
        9,
        array_replace($assignment, ['wan_interfaces' => '2']),
    );
    nfAssert(
        $nfNormalized['traffic_scope'] === 'WAN',
        'Explicit WAN interface classification did not override LAN prefixes',
    );

    foreach (glob($nfCapturePath . '/nfcapd.20*') as $file) {
        $count += icct_nms_netflow_import_file($assignment, $file);
    }
    nfAssert($count === 4, 'All four wire fixtures must import exactly once, got ' . $count);
    nfAssert(
        array_map(
            'intval',
            array_column(
                db_fetch_assoc(
                    'SELECT DISTINCT version FROM plugin_icct_nms_netflow_flows ORDER BY version',
                ),
                'version',
            ),
        ) === [5, 7, 9, 10],
        'Actual decoded versions incorrect',
    );
    $totals = db_fetch_row(
        'SELECT SUM(bytes) AS bytes,SUM(packets) AS packets,MIN(first_seen) AS ' .
            'first_seen,MAX(last_seen) AS last_seen FROM plugin_icct_nms_netflow_flows',
    );
    nfAssert(
        (int) $totals['bytes'] === 49360 && (int) $totals['packets'] === 40,
        'Counters changed while decoding',
    );
    foreach (glob($nfCapturePath . '/nfcapd.20*') as $file) {
        nfAssert(
            icct_nms_netflow_import_file($assignment, $file) === 0,
            'Capture retry duplicated counters',
        );
    }
    nfAssert(
        strtotime($totals['last_seen']) - strtotime($totals['first_seen']) === 1,
        'Wire timestamps were not preserved',
    );
    $read = icct_nms_netflow_read($id, ['hours' => 24, 'live' => '1']);
    nfAssert(
        (int) $read['totals']['flows'] === 4 && count($read['rankings']['conversations']) === 1,
        'Read model totals/conversations wrong',
    );
    $live = $read['live_readings'];
    nfAssert(
        $live['flows'] === 4 && $live['latest_epoch'] > 0 &&
        abs($live['values']['bandwidth'] - 49360 * 8 / 300000000) < 0.000001 &&
        $live['values']['destinations'] === 1.0 && $live['values']['ports'] === 1.0 &&
        $live['values']['syn_rate'] === 0.0 && $live['values']['distributed_syn_rate'] === 0.0,
        'Live measurements did not match the decoded flow records.',
    );
    $talkers = icct_nms_dashboard_netflow_talkers([['id' => $id, 'name' => 'QA exporter']]);
    nfAssert(count($talkers) === 1 && $talkers[0]['src_addr'] === '10.21.1.10' &&
        (int) $talkers[0]['bytes'] === 49360 && $talkers[0]['device'] === 'QA exporter' &&
        icct_nms_dashboard_netflow_talkers([['id' => 16777215]]) === [],
        'Top Talkers totals or device scope were incorrect.');
    $filteredRankings = icct_nms_netflow_rankings(icct_nms_netflow_filter($id, ['protocol' => '17']));
    nfAssert($filteredRankings['hosts'] === [] && $filteredRankings['conversations'] === [],
        'Breakdown report did not apply its protocol filter.');
    foreach ([5, 7, 9, 10] as $version) {
        nfAssert(
            (int) icct_nms_netflow_read($id, ['version' => $version])['totals']['flows'] === 1,
            'Version filtering failed',
        );
    }
    nfAssert(
        (int) icct_nms_netflow_read($id, ['src_addr' => '192.0.2.99'])['totals']['flows'] === 0,
        'Source filter leaks unrelated records',
    );
    try {
        icct_nms_netflow_read(16777215, []);
        throw new LogicException('Forbidden device read accepted');
    } catch (RuntimeException $e) {
    }
    try {
        icct_nms_netflow_read($id, ['start' => '2020-01-01', 'end' => '2026-01-01']);
        throw new LogicException('Unbounded date filter accepted');
    } catch (InvalidArgumentException $e) {
    }
    $nfSettings = array_replace($nfSettings, [
        'scan_threshold' => 2,
        'syn_pps_threshold' => 1,
        'bandwidth_limit_mbps' => 1,
    ]);
    icct_nms_netflow_save($id, $host, $nfSettings);
    db_execute(
        'UPDATE plugin_icct_nms_netflow_flows SET last_seen=DATE_SUB(NOW(),INTERVAL 10 MINUTE),received_at=DATE_SUB(NOW(),INTERVAL 10 MINUTE)',
    );
    $live = icct_nms_netflow_live_readings($id);
    nfAssert($live['flows'] === 0 && $live['values'] === [], 'Stale records were shown as live readings.');
    $sample = db_fetch_row('SELECT * FROM plugin_icct_nms_netflow_flows LIMIT 1');
    unset($sample['id']);
    $fixtures = [
        [$sample['src_addr'], '10.22.1.1', 135, 10000, 50000000, '......S.'],
        [$sample['src_addr'], '10.22.1.2', 445, 10000, 50000000, '......S.'],
        ['192.0.2.40', '10.22.2.1', 445, 200, 1000, '......S.'],
        ['192.0.2.41', '10.22.2.1', 443, 200, 1000, '......S.'],
        ['192.0.2.40', '10.22.2.2', 443, 10000, 1000, '...A..S.'],
    ];
    foreach ($fixtures as $i => [$src, $dst, $port, $packets, $bytes, $flags]) {
        $row = array_replace($sample, [
            'capture_key' => hash('sha256', 'anomaly-fixture-' . $i),
            'src_addr' => $src,
            'dst_addr' => $dst,
            'dst_port' => $port,
            'protocol' => 6,
            'tcp_flags' => $flags,
            'bytes' => $bytes,
            'packets' => $packets,
            'last_seen' => date('Y-m-d H:i:s'),
            'received_at' => date('Y-m-d H:i:s'),
        ]);
        db_execute_prepared(
            'INSERT INTO plugin_icct_nms_netflow_flows(' .
                implode(',', array_keys($row)) .
                ') VALUES(' .
                implode(',', array_fill(0, count($row), '?')) .
                ')',
            array_values($row),
        );
    }
    icct_nms_netflow_anomalies($id);
    $events = icct_nms_netflow_read($id, [])['events'];
    $live = icct_nms_netflow_live_readings($id);
    nfAssert(
        abs($live['values']['distributed_syn_rate'] - 400 / 300) < 0.0001,
        'Live peak distributed SYN rate used the wrong destination or included SYN-ACK packets.',
    );
    nfAssert(count($events) === 6, 'Anomaly report lost findings or raised unexpected findings');
    $messages = implode("\n", array_column($events, 'message'));
    nfAssert(
        str_contains($messages, 'targeting 10.22.2.1') &&
            str_contains($messages, 'from 2 source IPs') &&
            str_contains($messages, 'Top source IPs by traffic share:') &&
            !str_contains($messages, 'worm-like lateral scanning from 192.0.2.40'),
        'Distributed flood, top talkers or worm evidence was incorrect',
    );
    $criticalEvents = icct_nms_netflow_read($id, ['event_severity' => 'Critical', 'event_state' => 'Active'])['events'];
    nfAssert(
        count($criticalEvents) === 2 && array_unique(array_column($criticalEvents, 'severity')) === ['Critical'],
        'Anomaly severity filter did not isolate the critical finding',
    );
    nfAssert(
        icct_nms_netflow_read($id, ['event_state' => 'Resolved'])['events'] === [],
        'Anomaly status filter included active findings',
    );
    nfAssert(
        (int) db_fetch_cell(
            "SELECT COUNT(*) FROM plugin_icct_nms_netflow_events WHERE state='Active'",
        ) === 6,
        'DoS, scanning, worm-like traffic and bandwidth anomalies were not all detected',
    );
    icct_nms_netflow_sync_faults();
    nfAssert(
        (int) db_fetch_cell(
            "SELECT COUNT(*) FROM plugin_icct_nms_alarms WHERE source='NetFlow' AND state='Active'",
        ) === 6,
        'NetFlow anomalies did not reach the shared Fault console',
    );
    $alarm = db_fetch_row(
        "SELECT * FROM plugin_icct_nms_alarms WHERE source='NetFlow' ORDER BY alarm_key LIMIT 1",
    );
    $linked = icct_nms_netflow_read($id, [
        'netflow_alarm' => $alarm['alarm_key'],
        'end' => date('Y-m-d H:i:s', time() - 86400),
    ])['events'];
    nfAssert(
        count($linked) === 1 && $linked[0]['alarm_key'] === $alarm['alarm_key'] &&
            icct_nms_netflow_read($id, ['netflow_alarm' => str_repeat('0', 64)])['events'] === [],
        'Alarm link did not isolate the matching finding outside the normal time range',
    );
    icct_nms_alarm_action([
        'alarm_key' => $alarm['alarm_key'],
        'alarm_action' => 'ack',
        'revision' => $alarm['revision'],
        'note' => 'Isolated NetFlow QA acknowledgement',
    ]);
    icct_nms_netflow_sync_faults();
    nfAssert(
        (int) db_fetch_cell(
            "SELECT COUNT(*) FROM plugin_icct_nms_alarms WHERE source='NetFlow'",
        ) === 6 &&
            (int) db_fetch_cell(
                "SELECT SUM(acknowledged) FROM plugin_icct_nms_alarms WHERE source='NetFlow'",
            ) === 1,
        'Bridge replay duplicated alarms or reset acknowledgement',
    );
    db_execute(
        'UPDATE plugin_icct_nms_netflow_flows SET last_seen=DATE_SUB(NOW(),INTERVAL 10 MINUTE),received_at=NOW()',
    );
    icct_nms_netflow_anomalies($id);
    nfAssert(
        (int) db_fetch_cell(
            "SELECT COUNT(*) FROM plugin_icct_nms_netflow_events WHERE state='Active'",
        ) === 6,
        'Late historical data incorrectly cleared anomalies',
    );
    icct_nms_netflow_sync_faults();
    nfAssert(
        (int) db_fetch_cell(
            "SELECT COUNT(*) FROM plugin_icct_nms_alarms WHERE source='NetFlow' AND state='Active'",
        ) === 6,
        'A capture outage falsely resolved shared NetFlow faults',
    );

    db_execute(
        'UPDATE plugin_icct_nms_netflow_flows SET ' .
            'last_seen=NOW(),received_at=NOW(),packets=1,bytes=10,tcp_flags=\'...A....\',dst_addr=\'10.22.1.1\',dst_port=443',
    );
    icct_nms_netflow_anomalies($id);
    nfAssert(
        (int) db_fetch_cell(
            "SELECT COUNT(*) FROM plugin_icct_nms_netflow_events WHERE state='Active'",
        ) === 0,
        'Fresh normal observations failed to resolve anomalies',
    );
    icct_nms_netflow_sync_faults();
    nfAssert(
        (int) db_fetch_cell(
            "SELECT COUNT(*) FROM plugin_icct_nms_alarms WHERE source='NetFlow' AND state='Resolved'",
        ) === 6,
        'Fresh flow recovery did not resolve the shared NetFlow faults',
    );
    $rule = [
        'id' => str_repeat('a', 24), 'name' => 'Test NetFlow bandwidth', 'metric' => 'bandwidth',
        'threshold' => 0.001, 'severity' => 'Warning', 'enabled' => true, 'dst_port' => 443,
    ];
    $initialRevision = icct_nms_netflow_rule_revision([]);
    $saveRules = static fn ($rules, $revision) => icct_nms_netflow_save_rules($id, [
        'netflow_rules' => json_encode($rules, JSON_THROW_ON_ERROR), 'netflow_revision' => $revision,
    ]);
    $revision = $saveRules([$rule], $initialRevision);
    try {
        $saveRules([], $initialRevision);
        throw new LogicException('A stale edit overwrote the NetFlow rules.');
    } catch (RuntimeException $error) {
        nfAssert(str_contains($error->getMessage(), 'another session'), 'Unexpected save rejection.');
    }
    db_execute('UPDATE plugin_icct_nms_netflow_flows SET bytes=200000,last_seen=NOW(),received_at=NOW()');
    icct_nms_netflow_anomalies($id);
    icct_nms_netflow_sync_faults();
    $customAlarm = db_fetch_row("SELECT * FROM plugin_icct_nms_alarms WHERE name='Test NetFlow bandwidth'");
    nfAssert($customAlarm && $customAlarm['severity'] === 'Warning' && $customAlarm['state'] === 'Active' &&
        str_contains($customAlarm['description'], 'destination port 443'), 'Custom rule did not open the expected alarm.');
    $rule['name'] = 'Edited NetFlow bandwidth';
    $rule['severity'] = 'Major';
    $revision = $saveRules([$rule], $revision);
    icct_nms_netflow_anomalies($id);
    icct_nms_netflow_sync_faults();
    $alarm = db_fetch_row_prepared('SELECT * FROM plugin_icct_nms_alarms WHERE alarm_key=?', [$customAlarm['alarm_key']]);
    nfAssert($alarm['name'] === $rule['name'] && $alarm['severity'] === 'Major', 'Edit did not update the same alarm.');
    db_execute('UPDATE plugin_icct_nms_netflow_flows SET last_seen=DATE_SUB(NOW(),INTERVAL 10 MINUTE)');
    $saveRules([], $revision);
    icct_nms_netflow_sync_faults();
    $alarm = db_fetch_row_prepared('SELECT * FROM plugin_icct_nms_alarms WHERE alarm_key=?', [$customAlarm['alarm_key']]);
    nfAssert(icct_nms_netflow_rules($id) === [] && $alarm['state'] === 'Resolved' &&
        str_contains($alarm['description'], 'Rule disabled or removed'), 'Deleting the rule did not retire its active alarm.');
    icct_nms_netflow_remove($id);
    nfAssert(
        !icct_nms_netflow_assignment($id) &&
            db_fetch_cell('SELECT COUNT(*) FROM plugin_icct_nms_netflow_flows') > 0,
        'Removing assignment should retain history',
    );
    echo 'PASS: all four wire formats, device saves, decoded counters, exactly-once imports, ' .
        'filtered reports, authorization, classifications, anomaly detection/recovery and history ' .
        "retention.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n");
    foreach ($e->getTrace() as $frame) {
        fwrite(STDERR, ($frame['function'] ?? '') . ' ' . ($frame['line'] ?? '') . "\n");
    }
    exit(1);
}
