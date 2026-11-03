<?php

/** CLI collector; scans never run inside a web request and never create monitored devices. */
function icct_nms_scan_stop($id, $deadline)
{
    if (microtime(true) > $deadline) {
        throw new RuntimeException(
            'Maximum scan runtime reached. Retry to continue pending addresses.',
        );
    }
    if (
        db_fetch_cell_prepared(
            'SELECT cancel_requested FROM plugin_icct_nms_scan_jobs WHERE id=?',
            [$id],
        )
    ) {
        throw new RuntimeException('Discovery cancelled.');
    }
    static $pulse = [];
    if (time() > ($pulse[$id] ?? 0)) {
        if (
            (int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory=?', [
                'icct_nms',
            ]) !== 1
        ) {
            throw new RuntimeException('ICCT NMS is disabled.');
        }
        $actor = (int) db_fetch_cell_prepared(
            'SELECT requested_by FROM plugin_icct_nms_scan_jobs WHERE id=?',
            [$id],
        );
        $_SESSION = ['sess_user_id' => $actor];
        if (
            !db_fetch_cell_prepared("SELECT id FROM user_auth WHERE id=? AND enabled='on'", [
                $actor,
            ]) ||
            !is_realm_allowed(23)
        ) {
            throw new RuntimeException('Requesting account no longer has discovery permission.');
        }
        icct_backend_category_execute(
            'UPDATE plugin_icct_nms_scan_jobs SET heartbeat=NOW() WHERE id=?',
            [$id],
        );
        $pulse[$id] = time();
    }
}
function icct_nms_scan_tcp($address, $v, $id, $deadline)
{
    $pipes = [];
    $python = is_executable('/usr/bin/python3') ? '/usr/bin/python3' : 'python3';
    $p = proc_open(
        [$python, __DIR__ . '/../cli/port_probe.py'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']],
        $pipes,
    );
    if (!is_resource($p)) {
        throw new RuntimeException('Python 3 port scanner could not start on this collector.');
    }
    fwrite(
        $pipes[0],
        json_encode(
            ['address' => $address, 'ports' => $v['ports'], 'timeout_ms' => $v['timeout_ms']],
            JSON_THROW_ON_ERROR,
        ),
    );
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    $output = '';
    try {
        while (true) {
            $output .= stream_get_contents($pipes[1]);
            $state = proc_get_status($p);
            if (!$state['running']) {
                break;
            }
            icct_nms_scan_stop($id, $deadline);
            usleep(100000);
        }
        $output .= stream_get_contents($pipes[1]);
        $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($result) || isset($result['error'])) {
            throw new RuntimeException('TCP probe failed on the assigned collector.');
        }
        return $result;
    } finally {
        if (proc_get_status($p)['running']) {
            proc_terminate($p);
        }
        fclose($pipes[1]);
        proc_close($p);
    }
}
function icct_nms_scan_snmp($address, $v, $deadline = null)
{
    // Use native credential discovery rather than duplicating SNMP authentication or storing secrets in result JSON.
    $items = db_fetch_assoc_prepared(
        'SELECT * FROM automation_snmp_items WHERE snmp_id=? ORDER BY sequence',
        [$v['snmp_id']],
    );
    if (!$items) {
        throw new RuntimeException('SNMP credential set has no entries.');
    }
    $budget = 0;
    foreach ($items as $item) {
        $budget += (int) $item['snmp_timeout'] * ((int) $item['snmp_retries'] + 1);
    }
    if (count($items) > 32 || $budget > 60000) {
        throw new RuntimeException(
            'Limit the SNMP credential set to 32 entries and 60 seconds total probe timeout.',
        );
    }
    foreach ($items as $item) {
        $result = icct_nms_scan_snmp_attempt($address, $item, $deadline);
        if ($result['ok']) {
            return ['ok' => true, 'device' => $result['device'], 'credential' => $item];
        }
    }
    return ['ok' => false];
}

/** A fresh process prevents Net-SNMP from reusing another attempt's SNMPv3 USM keys. */
function icct_nms_scan_snmp_attempt($address, $item, $deadline)
{
    if ($deadline !== null && microtime(true) >= $deadline) {
        throw new RuntimeException('Native SNMP authentication exceeded its time budget.');
    }
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/../cli/snmp_probe.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start isolated native SNMP authentication.');
    }
    // Credentials travel only through a private pipe, never command arguments, logs or result evidence.
    $input = json_encode(['address' => $address, 'credential' => $item], JSON_THROW_ON_ERROR);
    $limit = microtime(true) + 5 + ((int) $item['snmp_timeout'] * ((int) $item['snmp_retries'] + 1)) / 1000;
    if ($deadline !== null) {
        $limit = min($limit, $deadline);
    }
    $output = '';
    $exit = -1;
    try {
        $written = 0;
        while ($written < strlen($input)) {
            $bytes = fwrite($pipes[0], substr($input, $written));
            if ($bytes === false || $bytes === 0) {
                throw new RuntimeException('Cannot deliver native SNMP credential.');
            }
            $written += $bytes;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        do {
            $output .= stream_get_contents($pipes[1]);
            if (strlen($output) > 65536) {
                throw new RuntimeException('Native SNMP response exceeds the discovery limit.');
            }
            $state = proc_get_status($process);
            if (!$state['running']) {
                $exit = $state['exitcode'];
                $output .= stream_get_contents($pipes[1]);
                break;
            }
            if (microtime(true) >= $limit) {
                throw new RuntimeException('Native SNMP authentication exceeded its time budget.');
            }
            usleep(10000);
        } while (true);
        if ($exit !== 0) {
            throw new RuntimeException('Native SNMP authentication process failed.');
        }
        $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($result) || !isset($result['ok']) || !is_bool($result['ok']) ||
            ($result['ok'] && !is_array($result['device'] ?? null))) {
            throw new RuntimeException('Native SNMP authentication returned an invalid response.');
        }
        return $result;
    } finally {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
        proc_close($process);
    }
}

function icct_nms_scan_probe($row, $v, $deadline)
{
    global $config;
    $address = $row['address'];
    $job = (int) $row['job_id'];
    $e = [
        'reachable' => false,
        'icmp' => null,
        'snmp' => false,
        'hostname' => $address,
        'neighbors' => [],
    ];
    $credential = 0;
    $suggested = 0;
    if (in_array('icmp', $v['methods'], true)) {
        require_once $config['base_path'] . '/lib/ping.php';
        $ping = new Net_Ping();
        $ping->host['hostname'] = $address;
        $e['icmp'] = (bool) $ping->ping(AVAIL_PING, PING_ICMP, $v['timeout_ms'], 1);
        $e['reachable'] = $e['icmp'];
    }
    icct_nms_scan_stop($job, $deadline);
    if (in_array('ports', $v['methods'], true)) {
        $e['ports'] = icct_nms_scan_tcp($address, $v, $job, $deadline);
        if ($e['ports']['open']) {
            $e['reachable'] = true;
        }
    }
    if (array_intersect($v['methods'], ['snmp', 'cdp', 'lldp'])) {
        try {
            $s = icct_nms_scan_snmp($address, $v, $deadline);
        } catch (Throwable $error) {
            $s = ['ok' => false];
            $e['method_errors']['snmp'] = 'SNMP probe failed. Check the saved credential set.';
        }
        $e['snmp'] = $s['ok'];
        if ($s['ok']) {
            $e['reachable'] = true;
            $d = $s['device'];
            $credential = (int) $s['credential']['id'];
            $e['credential_hash'] = hash(
                'sha256',
                json_encode($s['credential'], JSON_THROW_ON_ERROR),
            );
            foreach (
                ['sysName', 'sysDescr', 'sysObjectID', 'sysLocation', 'sysContact', 'sysUptime'] as $field
            ) {
                $e[$field] = $d['snmp_' . $field] ?? '';
            }
            if ($e['sysName']) {
                $e['hostname'] = $e['sysName'];
            }
            $match = automation_find_os($e['sysDescr'], $e['sysObjectID'], $e['sysName']);
            $suggested = (int) ($match['host_template'] ?? 0);
            $e['device_type'] = $match['name'] ?? 'Unmatched';
            $host = array_replace($s['credential'], [
                'id' => 0,
                'hostname' => $address,
                'disabled' => '',
                'poller_id' => (int) $config['poller_id'],
                'icct_backend_snmp_retries' => (int) $s['credential']['snmp_retries'],
            ]);
            foreach (array_intersect($v['methods'], ['cdp', 'lldp']) as $method) {
                icct_nms_scan_stop($job, $deadline);
                try {
                    $data = icct_backend_nd_collect_direct($host, $method, $deadline);
                    $e['neighbors'][$method] = $data['neighbors'] ?? [];
                    // Empty neighbor lists must retain the collector's explanation of missing tables.
                    if (!empty($data['warning'])) {
                        $e['method_errors'][$method] = strtoupper($method) . ': ' . $data['warning'];
                    }
                } catch (Throwable $error) {
                    $e['method_errors'][$method] = 'Neighbour tables unavailable or timed out.';
                }
            }
        }
    }
    icct_nms_scan_stop($job, $deadline);
    $existing = icct_nms_scan_existing($address, $e);
    $e['matching_devices'] = $existing;
    icct_backend_category_execute(
        'UPDATE plugin_icct_nms_scan_results SET ' .
            'state=\'observed\',evidence_json=?,credential_id=?,suggested_template=?,host_id=?,error=\'\',observed_at=NOW() ' .
            'WHERE id=?',
        [
            json_encode($e, JSON_THROW_ON_ERROR),
            $credential,
            $suggested,
            count($existing) === 1 ? $existing[0] : 0,
            $row['id'],
        ],
    );
}
function icct_nms_scan_work($poller)
{
    $lock = 'icct_scan_collector_' . (int) $poller;
    if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?,0)', [$lock]) !== 1) {
        return 0;
    }
    try {
        // One collector lock owns recovery; pending results are resumable after a worker crash.
        $job = db_fetch_row_prepared(
            "SELECT * FROM plugin_icct_nms_scan_jobs WHERE poller_id=? AND state IN ('running','queued') ORDER BY id LIMIT 1",
            [(int) $poller],
        );
        if (!$job) {
            return 0;
        }
        $id = (int) $job['id'];
        $_SESSION = ['sess_user_id' => (int) $job['requested_by']];
        if (
            !db_fetch_cell_prepared("SELECT id FROM user_auth WHERE id=? AND enabled='on'", [
                $job['requested_by'],
            ]) ||
            !is_realm_allowed(23)
        ) {
            icct_backend_category_execute(
                'UPDATE plugin_icct_nms_scan_jobs SET ' .
                    'state=\'failed\',finished_at=NOW(),last_error=\'Requesting account no longer has discovery ' .
                    'permission.\' WHERE id=?',
                [$id],
            );
            return $id;
        }
        $v = json_decode($job['config_json'], true, 32, JSON_THROW_ON_ERROR);
        $deadline = microtime(true) + (int) $v['run_limit'];
        icct_backend_category_execute(
            "UPDATE plugin_icct_nms_scan_jobs SET state='running',started_at=COALESCE(started_at,NOW()),heartbeat=NOW() WHERE id=?",
            [$id],
        );
        try {
            while (
                $row = db_fetch_row_prepared(
                    "SELECT * FROM plugin_icct_nms_scan_results WHERE job_id=? AND state='pending' ORDER BY id LIMIT 1",
                    [$id],
                )
            ) {
                icct_nms_scan_stop($id, $deadline);
                try {
                    icct_nms_scan_probe($row, $v, $deadline);
                } catch (Throwable $e) {
                    icct_nms_scan_stop($id, $deadline);
                    icct_backend_category_execute(
                        "UPDATE plugin_icct_nms_scan_results SET state='failed',error=?,observed_at=NOW() WHERE id=?",
                        [substr($e->getMessage(), 0, 500), $row['id']],
                    );
                }
                icct_backend_category_execute(
                    'UPDATE plugin_icct_nms_scan_jobs SET completed=(SELECT COUNT(*) FROM ' .
                        'plugin_icct_nms_scan_results WHERE job_id=? AND state<>\'pending\'),heartbeat=NOW() WHERE ' .
                        'id=?',
                    [$id, $id],
                );
            }
            icct_nms_scan_stop($id, $deadline);
            $failed = (int) db_fetch_cell_prepared(
                "SELECT COUNT(*) FROM plugin_icct_nms_scan_results WHERE job_id=? AND state='failed'",
                [$id],
            );
            icct_backend_category_execute(
                'UPDATE plugin_icct_nms_scan_jobs SET state=?,finished_at=NOW(),heartbeat=NOW(),last_error=? WHERE id=?',
                [
                    $failed ? 'partial' : 'completed',
                    $failed ? 'Some probes failed. Review results and retry.' : '',
                    $id,
                ],
            );
        } catch (Throwable $e) {
            icct_backend_category_execute(
                'UPDATE plugin_icct_nms_scan_jobs SET ' .
                    'state=IF(cancel_requested=1,\'cancelled\',\'partial\'),last_error=?,finished_at=NOW(),heartbeat=NOW() ' .
                    'WHERE id=?',
                [substr($e->getMessage(), 0, 500), $id],
            );
        }
        return $id;
    } finally {
        db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}

/** Mirror native scheduled runs, retaining Cacti's scheduling, notifications and auto-add policy. */
function icct_nms_scan_native_runs($poller)
{
    require_once __DIR__ . '/../../../configuration/services/network_configuration_service.php';
    $session = $_SESSION ?? [];
    $lock = 'icct_native_scan_' . (int) $poller;
    if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?,0)', [$lock]) !== 1) {
        return;
    }
    try {
        foreach (
            db_fetch_assoc_prepared(
                "SELECT id,last_started FROM automation_networks WHERE poller_id=? AND enabled='on' AND last_started>'1970-01-01'",
                [(int) $poller],
            ) as $n
        ) {
            $key = 'discovery_native_run_' . (int) $n['id'];
            $seen = (string) db_fetch_cell_prepared(
                'SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
                [$key],
            );
            if ($seen === $n['last_started']) {
                continue;
            }
            $network = icct_nms_network_get($n['id']);
            if (empty($network['scan_methods']) || empty($network['scan_requested_by'])) {
                continue;
            }
            // A manual Discover Now request already created the matching extended job.
            $job = db_fetch_row_prepared(
                'SELECT created_at FROM plugin_icct_nms_scan_jobs WHERE network_id=? ORDER BY id DESC LIMIT 1',
                [$n['id']],
            );
            if (!$job || abs(strtotime($n['last_started']) - strtotime($job['created_at'])) > 15) {
                $_SESSION = ['sess_user_id' => (int) $network['scan_requested_by']];
                if (
                    !db_fetch_cell_prepared(
                        "SELECT id FROM user_auth WHERE id=? AND enabled='on'",
                        [$network['scan_requested_by']],
                    ) ||
                    !is_realm_allowed(23)
                ) {
                    continue;
                }
                if (
                    !db_fetch_cell_prepared(
                        "SELECT id FROM plugin_icct_nms_scan_jobs WHERE network_id=? AND state IN ('queued','running') LIMIT 1",
                        [$n['id']],
                    )
                ) {
                    icct_nms_scan_queue([
                        'name' => $network['name'],
                        'ranges' => $network['subnet_range'],
                        'methods' => $network['scan_methods'],
                        'ports' => $network['scan_ports'] ?? '',
                        'timeout_ms' => $network['scan_timeout'] ?? 500,
                        'run_limit' => min(14400, (int) $network['run_limit']),
                        'poller_id' => $poller,
                        'site_id' => $network['site_id'],
                        'snmp_id' => $network['snmp_id'],
                        'network_id' => $n['id'],
                    ]);
                } else {
                    continue;
                }
            }
            icct_backend_category_execute(
                'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON ' .
                    'DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
                [$key, $n['last_started']],
            );
        }
    } finally {
        $_SESSION = $session;
        db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}
