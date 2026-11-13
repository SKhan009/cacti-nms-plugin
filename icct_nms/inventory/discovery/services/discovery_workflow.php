<?php

/** Unified discovery orchestration. Native Cacti owns ranges, credentials and monitored devices. */
function icct_nms_scan_methods()
{
    return [
        'icmp' => 'ICMP reachability',
        'snmp' => 'SNMP identity',
        'ports' => 'TCP port scan',
        'cdp' => 'CDP neighbours',
        'lldp' => 'LLDP neighbours',
    ];
}
function icct_nms_scan_ports($text)
{
    if (!is_string($text) || strlen($text) > 4096) {
        throw new InvalidArgumentException('Enter comma-separated ports or ranges.');
    }
    $ports = [];
    foreach (explode(',', $text) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        if (!preg_match('/^([0-9]{1,5})(?:-([0-9]{1,5}))?$/D', $part, $m)) {
            throw new InvalidArgumentException('Use ports such as 22,80,443 or 1-1024.');
        }
        $first = (int) $m[1];
        $last = isset($m[2]) ? (int) $m[2] : $first;
        if ($first < 1 || $last > 65535 || $last < $first) {
            throw new InvalidArgumentException('Ports must be between 1 and 65535.');
        }
        for ($n = $first; $n <= $last; $n++) {
            $ports[$n] = $n;
        }
    }
    ksort($ports);
    return array_values($ports);
}
function icct_nms_scan_addresses($text)
{
    if (!is_string($text) || strlen($text) > 4096) {
        throw new InvalidArgumentException(
            'Enter IP addresses, CIDRs or native Cacti address ranges.',
        );
    }
    $addresses = [];
    foreach (preg_split('/[,\r\n]+/', $text, -1, PREG_SPLIT_NO_EMPTY) as $range) {
        $range = trim($range);
        if (filter_var($range, FILTER_VALIDATE_IP)) {
            $items = [inet_ntop(inet_pton($range))];
        } else {
            $count = automation_calculate_total_ips($range);
            $start = automation_calculate_start($range);
            if ($count === false || !$start || $count < 1) {
                throw new InvalidArgumentException('Invalid address range: ' . $range);
            }
            if ($count > 4096) {
                throw new InvalidArgumentException('Use at most 4096 addresses per scan.');
            }
            $items = [];
            for ($i = 0; $i < $count; $i++) {
                $items[] = automation_get_next_host($start, $count, $i, $range);
            }
        }
        foreach ($items as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                throw new InvalidArgumentException('Range contains an invalid address.');
            }
            $addresses[inet_ntop(inet_pton($ip))] = true;
        }
        if (count($addresses) > 4096) {
            throw new InvalidArgumentException('Use at most 4096 addresses per scan.');
        }
    }
    if (!$addresses) {
        throw new InvalidArgumentException('Enter at least one address.');
    }
    return array_keys($addresses);
}
function icct_nms_scan_validate($input)
{
    $name = mb_substr(
        icct_backend_config_text($input['name'] ?? '', 250, 'Scan name', true),
        0,
        128,
    );
    $addresses = icct_nms_scan_addresses($input['ranges'] ?? '');
    $methods = $input['methods'] ?? [];
    if (
        !is_array($methods) ||
        !$methods ||
        array_filter(
            $methods,
            fn($m) => !is_string($m) || !array_key_exists($m, icct_nms_scan_methods()),
        )
    ) {
        throw new InvalidArgumentException('Choose discovery methods.');
    }
    foreach ($methods as $method) {
        if (!is_string($method)) {
            throw new InvalidArgumentException('Choose valid discovery methods.');
        }
    }
    $methods = array_values(array_unique($methods));
    $ports = in_array('ports', $methods, true) ? icct_nms_scan_ports($input['ports'] ?? '') : [];
    if (in_array('ports', $methods, true) && !$ports) {
        throw new InvalidArgumentException('Enter TCP ports to scan.');
    }
    if (count($addresses) * max(1, count($ports)) > 262144) {
        throw new InvalidArgumentException(
            'Use at most 262144 address/port combinations per scan. Split larger scans.',
        );
    }
    $poller = icct_backend_topology_integer($input['poller_id'] ?? '', 1, 4294967295, 'Collector');
    if (!db_fetch_cell_prepared("SELECT id FROM poller WHERE id=? AND disabled=''", [$poller])) {
        throw new InvalidArgumentException('Select an enabled Cacti collector.');
    }
    $site = icct_backend_topology_integer($input['site_id'] ?? 0, 0, 4294967295, 'Site');
    if ($site && !db_fetch_cell_prepared('SELECT id FROM sites WHERE id=?', [$site])) {
        throw new InvalidArgumentException('Select a saved site.');
    }
    $snmp = icct_backend_topology_integer(
        $input['snmp_id'] ?? 0,
        0,
        4294967295,
        'SNMP credential set',
    );
    if (
        array_intersect($methods, ['snmp', 'cdp', 'lldp']) &&
        !db_fetch_cell_prepared('SELECT id FROM automation_snmp WHERE id=?', [$snmp])
    ) {
        throw new InvalidArgumentException('Choose a Cacti SNMP credential set for SNMP/CDP/LLDP.');
    }
    return [
        'name' => $name,
        'addresses' => $addresses,
        'methods' => $methods,
        'ports' => $ports,
        'poller_id' => $poller,
        'site_id' => $site,
        'snmp_id' => $snmp,
        'timeout_ms' => icct_backend_topology_integer(
            $input['timeout_ms'] ?? 500,
            100,
            10000,
            'Probe timeout',
        ),
        'run_limit' => icct_backend_topology_integer(
            $input['run_limit'] ?? 1200,
            30,
            14400,
            'Maximum runtime',
        ),
    ];
}
function icct_nms_scan_job($id)
{
    icct_backend_require_management(23);
    $job = db_fetch_row_prepared('SELECT * FROM plugin_icct_nms_scan_jobs WHERE id=?', [(int) $id]);
    if (!$job) {
        throw new InvalidArgumentException('Discovery job not found.');
    }
    return $job;
}
function icct_nms_scan_queue($input)
{
    icct_backend_require_management(23);
    $v = icct_nms_scan_validate($input);
    $addresses = $v['addresses'];
    unset($v['addresses']);
    icct_backend_category_execute('START TRANSACTION');
    try {
        icct_backend_category_execute(
            'INSERT INTO plugin_icct_nms_scan_jobs(poller_id,site_id,name,config_json,requested_by,total,created_at) VALUES(?,?,?,?,?,?,NOW())',
            [
                $v['poller_id'],
                $v['site_id'],
                $v['name'],
                json_encode($v, JSON_THROW_ON_ERROR),
                icct_backend_current_user_id(),
                count($addresses),
            ],
        );
        $id = (int) db_fetch_insert_id();
        foreach ($addresses as $address) {
            icct_backend_category_execute(
                'INSERT INTO plugin_icct_nms_scan_results(job_id,address) VALUES(?,?)',
                [$id, $address],
            );
        }
        icct_backend_category_execute('COMMIT');
    } catch (Throwable $e) {
        icct_backend_category_execute('ROLLBACK');
        throw $e;
    }
    if (!empty($input['network_id'])) {
        icct_backend_category_execute(
            'UPDATE plugin_icct_nms_scan_jobs SET network_id=? WHERE id=?',
            [(int) $input['network_id'], $id],
        );
    }
    if (PHP_SAPI !== 'cli') {
        icct_nms_scan_dispatch();
    }
    return $id;
}
function icct_nms_scan_cancel($id)
{
    $job = icct_nms_scan_job($id);
    if (!in_array($job['state'], ['queued', 'running'], true)) {
        throw new InvalidArgumentException('Only queued/running scans can be cancelled.');
    }
    icct_backend_category_execute(
        'UPDATE plugin_icct_nms_scan_jobs SET cancel_requested=1 WHERE id=?',
        [$id],
    );
}
function icct_nms_scan_retry($id)
{
    $job = icct_nms_scan_job($id);
    if (in_array($job['state'], ['queued', 'running'], true)) {
        throw new InvalidArgumentException('Wait for this scan to stop before retrying.');
    }
    icct_backend_category_execute(
        "UPDATE plugin_icct_nms_scan_results SET state='pending',error='' WHERE job_id=? AND state IN ('pending','failed')",
        [$id],
    );
    icct_backend_category_execute(
        "UPDATE plugin_icct_nms_scan_jobs SET state='queued',cancel_requested=0,last_error='',finished_at=NULL,requested_by=? WHERE id=?",
        [icct_backend_current_user_id(), $id],
    );
    if (PHP_SAPI !== 'cli') {
        icct_nms_scan_dispatch();
    }
}
function icct_nms_scan_dispatch()
{
    global $config;
    $poller = (int) ($config['poller_id'] ?? 1);
    if (
        !db_fetch_cell_prepared(
            "SELECT id FROM plugin_icct_nms_scan_jobs WHERE poller_id=? AND state IN ('queued','running') LIMIT 1",
            [$poller],
        )
    ) {
        return;
    }
    require_once $config['base_path'] . '/lib/poller.php';
    // Older Cacti releases accept only a shell argument string here.
    $script = ICCT_NMS_ROOT . '/inventory/discovery/cli/discovery_worker.php';
    exec_background(
        read_config_option('path_php_binary'),
        '-q ' . escapeshellarg($script) . ' --once',
    );
}
function icct_nms_scan_existing($address, $evidence = [])
{
    $ids = [];
    foreach (
        db_fetch_assoc("SELECT id,hostname,snmp_sysName FROM host WHERE deleted='' ")
        as $host
    ) {
        $literal = @inet_pton($host['hostname']);
        if (
            ($literal !== false && inet_ntop($literal) === $address) ||
            strcasecmp(
                rtrim($host['hostname'], '.'),
                rtrim((string) ($evidence['hostname'] ?? $address), '.'),
            ) === 0 ||
            (!empty($evidence['sysName']) &&
                !empty($host['snmp_sysName']) &&
                strcasecmp($host['snmp_sysName'], $evidence['sysName']) === 0)
        ) {
            $ids[] = (int) $host['id'];
        }
    }
    return array_values(array_unique($ids));
}
function icct_nms_scan_result($id)
{
    icct_backend_require_management(23);
    $r = db_fetch_row_prepared('SELECT * FROM plugin_icct_nms_scan_results WHERE id=?', [
        (int) $id,
    ]);
    if (!$r) {
        throw new InvalidArgumentException('Discovery result not found.');
    }
    $r['evidence'] = json_decode($r['evidence_json'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
    return $r;
}
/** Range probes are observations; they do not establish a physical cable/link. */
function icct_nms_scan_rows($allowedHosts)
{
    icct_backend_require_management(23);
    $rows = [];
    $seen = [];
    foreach (
        db_fetch_assoc(
            'SELECT r.*,j.network_id,j.name AS scan_name,j.poller_id,p.name AS collector FROM ' .
                'plugin_icct_nms_scan_results r JOIN plugin_icct_nms_scan_jobs j ON j.id=r.job_id LEFT ' .
                'JOIN poller p ON p.id=j.poller_id WHERE r.state<>\'pending\' ORDER BY r.observed_at ' .
                'DESC,r.id DESC',
        )
        as $r
    ) {
        $key = $r['poller_id'] . '|' . $r['network_id'] . '|' . $r['address'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $e = json_decode($r['evidence_json'] ?: '{}', true) ?: [];
        $matched = icct_nms_scan_existing($r['address'], $e);
        $host = count($matched) === 1 ? $matched[0] : 0;
        if ($host && !isset($allowedHosts[$host])) {
            continue;
        }
        $methods = [];
        if (!empty($e['icmp'])) {
            $methods[] = 'ICMP';
        }
        if (!empty($e['snmp'])) {
            $methods[] = 'SNMP';
        }
        if (!empty($e['ports']['open'])) {
            $methods[] = 'TCP';
        }
        $r['ambiguous'] = count($matched) > 1;
        $r['host_id'] = $host;
        $r['name'] = $host
            ? $allowedHosts[$host]['description']
            : (trim((string) ($e['sysName'] ?? '')) ?:
            (trim((string) ($e['hostname'] ?? '')) ?:
            $r['address']));
        $r['method'] = $methods ? implode(' / ', $methods) : 'IP scan';
        $r['reachable'] = !empty($e['reachable']);
        $r['detail'] = 'Device observation; physical link unconfirmed';
        if (!empty($e['ports']['open'])) {
            $r['detail'] .= ' · Open TCP: ' . implode(', ', $e['ports']['open']);
        }
        if (!empty($e['method_errors'])) {
            $r['detail'] .= ' · ' . implode(' ', array_values($e['method_errors']));
        }
        if ($r['error']) {
            $r['detail'] .= ' · ' . $r['error'];
        }
        $rows[] = $r;
    }
    return $rows;
}

/** Prefill the existing Add Device wizard; credential material stays server side. */
function icct_nms_scan_wizard_values($resultId)
{
    $r = icct_nms_scan_result($resultId);
    $job = icct_nms_scan_job($r['job_id']);
    $e = $r['evidence'];
    if ($r['state'] !== 'observed' || empty($e['reachable'])) {
        throw new InvalidArgumentException('Choose a completed, reachable discovery result.');
    }
    return [
        'hostname' => $r['address'],
        'description' => $e['sysName'] ?? $r['address'],
        'host_template_id' => (int) $r['suggested_template'],
        'site_id' => (int) $job['site_id'],
        'poller_id' => (int) $job['poller_id'],
    ];
}
/** Server-only native protocol values; the normal edit form keeps V3 password replacements blank. */
function icct_nms_scan_wizard_host($resultId)
{
    $r = icct_nms_scan_result($resultId);
    $old = icct_nms_defaults();
    $old = array_replace($old, icct_nms_scan_wizard_values($resultId));
    $old['external_id'] = 'icct_discovery_result_' . (int) $resultId;
    $old['snmp_version'] = 0;
    foreach (['snmp_community', 'snmp_username', 'snmp_password', 'snmp_priv_passphrase'] as $key) {
        $old[$key] = '';
    }
    if (!empty($r['evidence']['snmp'])) {
        $credential = db_fetch_row_prepared('SELECT * FROM automation_snmp_items WHERE id=?', [
            $r['credential_id'],
        ]);
        if (
            !$credential ||
            !hash_equals(
                $r['evidence']['credential_hash'] ?? '',
                hash('sha256', json_encode($credential, JSON_THROW_ON_ERROR)),
            )
        ) {
            throw new InvalidArgumentException(
                'SNMP credentials changed. Rescan before adding this device.',
            );
        }
        $old = array_replace(
            $old,
            array_intersect_key(
                $credential,
                array_flip([
                    'snmp_version',
                    'snmp_port',
                    'snmp_timeout',
                    'snmp_community',
                    'snmp_username',
                    'snmp_password',
                    'snmp_auth_protocol',
                    'snmp_priv_passphrase',
                    'snmp_priv_protocol',
                    'snmp_context',
                    'snmp_engine_id',
                    'max_oids',
                ]),
            ),
        );
        $old['availability_method'] = AVAIL_SNMP;
    } else {
        $old['availability_method'] = AVAIL_PING;
        $old['ping_method'] = PING_ICMP;
    }
    return $old;
}
function icct_nms_scan_wizard_save($input)
{
    icct_backend_require_management(23);
    icct_backend_require_management(3);
    $resultId = icct_backend_topology_integer(
        $input['discovery_result'] ?? 0,
        1,
        4294967295,
        'Discovery result',
    );
    $prefill = icct_nms_scan_wizard_values($resultId);
    if (($input['hostname'] ?? '') !== $prefill['hostname']) {
        throw new InvalidArgumentException(
            'Use the discovered address for this result, or add a different device from Inventory.',
        );
    }
    $lock = 'icct_discovery_onboard';
    if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?,10)', [$lock]) !== 1) {
        throw new RuntimeException('Another device is being added. Retry shortly.');
    }
    try {
        $r = icct_nms_scan_result($resultId);
        $job = icct_nms_scan_job($r['job_id']);
        if (
            !empty($job['network_id']) &&
            db_fetch_cell_prepared('SELECT COUNT(*) FROM automation_processes WHERE network_id=?', [
                $job['network_id'],
            ])
        ) {
            throw new InvalidArgumentException(
                'Wait for native Cacti discovery to finish before adding this result.',
            );
        }
        $e = $r['evidence'];
        $matches = icct_nms_scan_existing($r['address'], $e);
        if (count($matches) > 1) {
            throw new InvalidArgumentException(
                'Multiple saved devices match this address. Review them in Inventory.',
            );
        }
        $id = $matches ? $matches[0] : 0;
        $marker = 'icct_discovery_result_' . $resultId;
        if ($id) {
            icct_backend_require_device_access($id);
            $old = icct_nms_device($id);
            if (($old['external_id'] ?? '') !== $marker) {
                icct_backend_category_execute(
                    'UPDATE plugin_icct_nms_scan_results SET host_id=? WHERE id=?',
                    [$id, $resultId],
                );
                return $id;
            }
        } else {
            $old = icct_nms_scan_wizard_host($resultId);
        }
        $saved = icct_nms_save_device($id, $old, $input);
        icct_backend_category_execute(
            'UPDATE plugin_icct_nms_scan_results SET host_id=? WHERE id=?',
            [$saved, $resultId],
        );
        if (empty($e['onboarding_complete'])) {
            icct_backend_category_execute(
                'INSERT INTO plugin_icct_nms_inventory_events(host_id,event,details_json,created_at) VALUES(?,?,?,NOW())',
                [
                    $saved,
                    'Discovered device added',
                    json_encode(
                        ['job_id' => (int) $r['job_id'], 'address' => $r['address']],
                        JSON_THROW_ON_ERROR,
                    ),
                ],
            );
        }
        $e['onboarding_complete'] = true;
        icct_backend_category_execute(
            'UPDATE plugin_icct_nms_scan_results SET evidence_json=? WHERE id=?',
            [json_encode($e, JSON_THROW_ON_ERROR), $resultId],
        );
        return $saved;
    } finally {
        db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}

/** Keep neighbour evidence from range scans visible in the existing discovery tables. */
function icct_nms_scan_neighbour_rows($results)
{
    $out = [];
    foreach ($results as $r) {
        $e = json_decode($r['evidence_json'] ?: '{}', true) ?: [];
        foreach ($e['neighbors'] ?? [] as $protocol => $items) {
            if (!in_array($protocol, ['cdp', 'lldp'], true)) {
                continue;
            }
            foreach ($items as $item) {
                if (isset($item['present']) && !$item['present']) {
                    continue;
                }
                $out[] = [
                    'reporter_id' => (int) $r['host_id'],
                    'name' => $item['remote_name'] ?? ($item['peer_label'] ?? 'Unnamed neighbour'),
                    'address' => implode(
                        ', ',
                        icct_nms_management_addresses($item['management_addresses'] ?? []),
                    ),
                    'reporter' => $r['name'],
                    'protocol' => strtoupper($protocol),
                    'port' => $item['local_port'] ?? '',
                    'remote_port' => $item['remote_port'] ?? '',
                    'status' => 'Observed',
                    'last_check' => $r['observed_at'],
                    'detail' => 'Neighbour reported by range scan · ' . $r['scan_name'],
                ];
            }
        }
    }
    return $out;
}
