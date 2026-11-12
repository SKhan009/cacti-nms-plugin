<?php

require_once __DIR__ . '/ntp_service.php';
require_once __DIR__ . '/ntp_details.php';
require_once __DIR__ . '/ntp_udp.php';

/** Fixed read-only commands; neither request text nor source addresses become shell commands. */
function icct_nms_ntp_command($kind = 'tracking')
{
    // Pin the Unix socket explicitly; chronyc must never switch to loopback UDP.
    $prefix = ['/usr/bin/sudo', '-n', '-u', 'chrony', '--', '/usr/bin/chronyc', '-n'];
    $commands = [
        'tracking' => array_merge($prefix, ['-c', '-h', '/run/chrony/chronyd.sock', 'tracking']),
        'jitter' => array_merge($prefix, ['-c', '-h', '/run/chrony/chronyd.sock', 'sourcestats']),
        'delay' => array_merge($prefix, ['-h', '/run/chrony/chronyd.sock', 'ntpdata']),
    ];
    if (!isset($commands[$kind])) {
        throw new InvalidArgumentException('Unsupported NTP monitoring command.');
    }
    return $commands[$kind];
}

function icct_nms_ntp_parse($output)
{
    if (!is_string($output) || strlen($output) > 4096 || str_contains(trim($output), "\n")) {
        throw new RuntimeException('Invalid chrony tracking response.');
    }
    $fields = str_getcsv(trim($output), ',', '"', '');
    if (
        count($fields) !== 14 ||
        !preg_match('/^[a-fA-F0-9]{8}$/D', $fields[0]) ||
        ($fields[1] !== '' && !filter_var($fields[1], FILTER_VALIDATE_IP))
    ) {
        throw new RuntimeException('Unsupported or incomplete chrony tracking response.');
    }
    foreach (range(2, 12) as $index) {
        if (!is_numeric($fields[$index]) || !is_finite((float) $fields[$index])) {
            throw new RuntimeException('Invalid numeric chrony tracking field.');
        }
    }
    if (
        !preg_match('/^[0-9]+$/D', $fields[2]) ||
        (int) $fields[2] > 16 ||
        (float) $fields[3] < 0 ||
        (float) $fields[6] < 0 ||
        (float) $fields[10] < 0 ||
        (float) $fields[11] < 0 ||
        !in_array(
            $fields[13],
            ['Normal', 'Insert second', 'Delete second', 'Not synchronised'],
            true,
        )
    ) {
        throw new RuntimeException('Invalid chrony synchronization state.');
    }
    foreach ([5, 6, 10] as $index) {
        if (!is_finite((float) $fields[$index] * 1000)) {
            throw new RuntimeException(
                'Chrony measurement is outside the supported numeric range.',
            );
        }
    }
    $synchronized =
        $fields[13] !== 'Not synchronised' &&
        (int) $fields[2] > 0 &&
        (int) $fields[2] < 16 &&
        (float) $fields[3] > 0 &&
        strtoupper($fields[0]) !== '00000000';
    $local = strtoupper($fields[0]) === '7F7F0101' || $fields[1] === '127.127.1.1';
    return [
        'synchronized' => $synchronized && !$local,
        'local_reference' => $local,
        'source_id' => strtoupper($fields[0]),
        'source_address' => $fields[1] === '' ? null : $fields[1],
        'stratum' => (int) $fields[2],
        'reference_epoch' => (float) $fields[3],
        'leap_status' => $fields[13],
        // Chrony CSV reports seconds. RMS offset is not jitter, and root delay is not a device ping.
        'offset_ms' => $synchronized && !$local ? (float) $fields[5] * 1000 : null,
        'rms_offset_ms' => $synchronized && !$local ? (float) $fields[6] * 1000 : null,
        'jitter_ms' => null,
        'peer_delay_ms' => null,
        'root_delay_ms' => $synchronized && !$local ? (float) $fields[10] * 1000 : null,
    ];
}

function icct_nms_ntp_evaluate($sample, $device)
{
    if (!$sample['synchronized']) {
        return [
            $sample['local_reference'] ? 'Local reference' : 'Unsynchronized',
            isset($sample['server_address'])
                ? 'The NTP server reports that it is unsynchronized.'
                : 'The device is not synchronized to an external time source.',
        ];
    }
    return ['Synchronized', ''];
}

/** One transport per assignment and one command timeout budget for the coherent observation. */
function icct_nms_ntp_read($device)
{
    $method = $device['method'];
    if ($method === 'auto') {
        $method = icct_nms_ntp_local_device($device) ? 'local' : 'udp';
    }
    if ($method === 'udp') {
        return icct_nms_ntp_udp_read($device['hostname'], (int) $device['timeout']);
    }
    $ssh = null;
    if ($method === 'local') {
        if (!icct_nms_ntp_local_device($device)) {
            throw new RuntimeException(
                'Local NTP collection is not allowed for a remote inventory address.',
            );
        }
        if (!is_executable('/usr/bin/chronyc')) {
            throw new RuntimeException(
                'Install chrony from the offline RHEL bundle; /usr/bin/chronyc is unavailable.',
            );
        }
    } elseif ($method === 'ssh') {
        // Reuse saved credentials and host-key approval without enabling SSH health monitoring.
        $ssh = icct_backend_ssh_connect(icct_backend_ssh_device($device['host_id']));
    } else {
        throw new RuntimeException('Unsupported NTP collection method.');
    }
    $deadline = microtime(true) + (int) $device['timeout'];
    try {
        $read = static function ($kind) use ($ssh, $deadline) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new RuntimeException('NTP collection exceeded its command timeout.');
            }
            $command = icct_nms_ntp_command($kind);
            if ($ssh === null) {
                $result = icct_backend_diag_run_command($command, $remaining);
                $output = $result['stdout'];
                $failed = $result['exit'] !== 0 || $result['truncated'];
            } else {
                $ssh->setTimeout($remaining);
                $output = '';
                $ssh->exec(
                    'LC_ALL=C ' . implode(' ', array_map('escapeshellarg', $command)),
                    static function ($chunk) use (&$output) {
                        $output .= $chunk;
                        if (strlen($output) > 65536) {
                            throw new RuntimeException('Chrony response exceeded its limit.');
                        }
                    },
                );
                $failed = $ssh->isTimeout() || $ssh->getExitStatus() !== 0;
            }
            if ($failed || microtime(true) > $deadline || strlen($output) > 65536) {
                throw new RuntimeException(
                    'Read-only chrony ' .
                        $kind .
                        ' failed or timed out.' .
                        ' Check the chrony socket and offline NTP read-only access policy for this device account.',
                );
            }
            return $output;
        };
        return icct_nms_ntp_observe($read);
    } finally {
        if ($ssh !== null) {
            $ssh->disconnect();
        }
    }
}

function icct_nms_ntp_due($method, $poller)
{
    if (!icct_nms_ntp_ready()) {
        return [];
    }
    // A slightly earlier Cacti cycle should not defer collection for a whole extra cycle.
    $methods = (array) $method;
    $placeholders = implode(',', array_fill(0, count($methods), '?'));
    $cycleTolerance = in_array('ssh', $methods, true) ? 0 : 5;
    return db_fetch_assoc_prepared(
        'SELECT d.host_id,d.method FROM plugin_icct_nms_ntp_monitors d JOIN host h ON h.id=d.host_id ' .
            'LEFT JOIN plugin_icct_nms_ntp_monitor_state s ON s.host_id=d.host_id ' .
            'WHERE h.deleted=\'\' AND h.disabled=\'\' AND h.poller_id=? AND d.method IN (' . $placeholders . ') ' .
            'AND NOT EXISTS(SELECT 1 FROM plugin_icct_nms_meta m WHERE m.meta_key=CONCAT(\'protocol_disabled_ntp_\',d.host_id) AND m.meta_value=\'1\') ' .
            'AND (s.last_attempt IS NULL OR s.last_attempt>NOW() OR ' .
            'TIMESTAMPDIFF(SECOND,s.last_attempt,NOW())+?>=d.interval_seconds) ORDER BY s.last_attempt LIMIT 4',
        [(int) $poller, ...$methods, $cycleTolerance],
    );
}

/** Connection-local lock and a fresh configuration check prevent cross-poller or stale writes. */
function icct_nms_ntp_collect($id, $method)
{
    global $config;
    if (PHP_SAPI !== 'cli' || !api_plugin_is_enabled('icct_nms')) {
        throw new RuntimeException(
            'NTP collection requires an enabled plugin and its CLI collector.',
        );
    }
    $lock = 'icct_ntp_' . (int) $id;
    if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?,0)', [$lock]) !== 1) {
        return;
    }
    try {
        $device = icct_nms_ntp_assignment($id);
        if (
            !$device ||
            $device['method'] !== $method ||
            $device['disabled'] !== '' ||
            (int) $device['poller_id'] !== (int) $config['poller_id'] ||
            !icct_backend_protocol_enabled($id, 'ntp')
        ) {
            return;
        }
        $sample = null;
        $hash = '';
        try {
            $hash = icct_nms_ntp_hash($device);
            $sample = icct_nms_ntp_read($device);
            [$status, $error] = icct_nms_ntp_evaluate($sample, $device);
        } catch (Throwable $failure) {
            $status = 'Unavailable';
            $error = mb_substr($failure->getMessage(), 0, 500);
        }
        $now = icct_nms_ntp_assignment($id);
        if (
            !$now ||
            $now['revision'] !== $device['revision'] ||
            $now['hostname'] !== $device['hostname'] ||
            $now['poller_id'] !== $device['poller_id'] ||
            $now['disabled'] !== '' ||
            !icct_backend_protocol_enabled($id, 'ntp')
        ) {
            return;
        }
        if ($sample !== null && !hash_equals($hash, icct_nms_ntp_hash($now))) {
            return;
        }
        $json = $sample === null ? null : json_encode($sample, JSON_THROW_ON_ERROR);
        icct_backend_category_execute('START TRANSACTION');
        try {
            icct_backend_category_execute(
                'INSERT INTO plugin_icct_nms_ntp_monitor_state(host_id,status,last_error,sample_json,config_hash,last_attempt,last_success) ' .
                    'VALUES(?,?,?,?,?,NOW(),IF(? IS NOT NULL,NOW(),NULL)) ON DUPLICATE KEY UPDATE status=VALUES(status),' .
                    'last_error=VALUES(last_error),sample_json=VALUES(sample_json),config_hash=VALUES(config_hash),' .
                    'last_attempt=NOW(),last_success=IF(VALUES(sample_json) IS NOT NULL,NOW(),last_success)',
                [(int) $id, $status, $error, $json, $hash, $json],
            );
            icct_backend_category_execute(
                'INSERT INTO plugin_icct_nms_ntp_monitor_history(host_id,status,sample_json,error,observed_at) VALUES(?,?,?,?,NOW())',
                [(int) $id, $status, $json, $error],
            );
            icct_backend_category_execute(
                'DELETE FROM plugin_icct_nms_ntp_monitor_history WHERE host_id=? AND observed_at<DATE_SUB(NOW(),INTERVAL 30 DAY)',
                [(int) $id],
            );
            icct_backend_category_execute('COMMIT');
        } catch (Throwable $failure) {
            icct_backend_category_execute('ROLLBACK');
            throw $failure;
        }
    } finally {
        db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}
