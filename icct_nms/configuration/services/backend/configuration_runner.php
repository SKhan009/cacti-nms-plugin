<?php

/** ICCT-owned configuration runner services, derived from the existing ICCT NMS implementation. */

function icct_backend_config_serial_execute(
    array $target,
    array $field,
    $operation,
    $before = null,
    $requested = null,
) {
    global $config;
    $serial = $target['serial'];
    $job = [
        'connection' => [
            'transport' => $serial['transport'],
            'endpoint' => $serial['endpoint'],
            'settings' => $serial['settings'],
        ],
        'unit' => (int) $serial['device_address'],
        'offset' => $field['offset'],
        'function' => $field['function'],
        'operation' => $operation,
        'count' => 1,
    ];
    if ($operation === 'write') {
        $job['expected'] = ((int) $before) & 65535;
        $job['value'] = ((int) $requested) & 65535;
    }
    $input = json_encode($job, JSON_THROW_ON_ERROR);
    if (strlen($input) > 4096) {
        throw new RuntimeException('Serial request is too large.');
    }
    $python = $config['icct_backend_serial_python'] ?? '/usr/bin/python3';
    $locks = $config['icct_backend_serial_lock_dir'] ?? '/run/cacti-nms-serial';
    if (!is_dir($locks) || !is_writable($locks)) {
        throw new RuntimeException('Collector serial lock directory is not provisioned.');
    }
    $process = proc_open(
        [$python, ICCT_NMS_ROOT . '/protocols/serial/collector/serial_transport.py', $locks],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start serial adapter.');
    }
    $stdout = '';
    $stderr = '';
    $timeout = false;
    $deadline = microtime(true) + 60;
    try {
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        do {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            $state = proc_get_status($process);
            if (strlen($stdout) > 16384 || strlen($stderr) > 16384) {
                throw new RuntimeException('Serial adapter output exceeded limit.');
            }
            if (!$state['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                $timeout = true;
                proc_terminate($process, 9);
                break;
            }
            usleep(20000);
        } while (true);
        $stdout .= stream_get_contents($pipes[1]);
    } finally {
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
    }
    if ($timeout) {
        return [
            'status' => $operation === 'write' ? 'unverified' : 'failed',
            'error' => 'Collector execution deadline exceeded. Writes are not replayed.',
        ];
    }
    try {
        $result = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return [
            'status' => $operation === 'write' ? 'unverified' : 'failed',
            'error' =>
                'Serial adapter failed to return a valid result. Check collector installation.',
        ];
    }
    if (!is_array($result) || !isset($result['status'])) {
        throw new RuntimeException('Invalid adapter response.');
    }
    if ($operation === 'read' && $result['status'] === 'read') {
        $result['value'] = $result['values'][0];
    }
    foreach (['value', 'before', 'observed'] as $key) {
        if (isset($result[$key]) && $field['type'] === 'int16' && $result[$key] > 32767) {
            $result[$key] -= 65536;
        }
    }
    if (isset($result['value'])) {
        $result['value'] = icct_backend_equipment_value($field, $result['value']);
    }
    return $result;
}

function icct_backend_config_snmp_execute(
    array $target,
    array $field,
    $operation,
    $before = null,
    $requested = null,
) {
    $session = null;
    try {
        $session = icct_backend_config_snmp_session($target, $operation === 'write');
        $current = icct_backend_equipment_value($field, $session->get($field['oid']));
        if ($operation === 'read') {
            return ['status' => 'read', 'value' => $current, 'device_responded' => true];
        }
        if ($current !== $before) {
            return [
                'status' => 'failed',
                'before' => $current,
                'error' => 'Value changed since preview; no write sent.',
            ];
        }
        $ack = false;
        try {
            $ack = $session->set(
                $field['oid'],
                ['integer' => 'i', 'unsigned' => 'u', 'string' => 's'][$field['type']],
                $requested,
            );
        } catch (Throwable $e) {
            /* Ambiguous result: read back without retrying SET. */
        }
        try {
            $observed = icct_backend_equipment_value($field, $session->get($field['oid']));
        } catch (Throwable $e) {
            return [
                'status' => 'unverified',
                'before' => $before,
                'acknowledged' => $ack,
                'error' => 'SNMP read-back failed. Do not assume the setting is unchanged.',
            ];
        }
        return [
            'status' => $observed === $requested ? 'verified' : 'unverified',
            'before' => $before,
            'observed' => $observed,
            'acknowledged' => $ack,
        ];
    } catch (Throwable $e) {
        // SNMP library exceptions may contain connection details; do not persist them.
        return [
            'status' => 'failed',
            'error' =>
                'SNMP access failed. Check collector credentials, device permissions and the field definition.',
        ];
    } finally {
        if ($session) {
            $session->close();
        }
    }
}

function icct_backend_config_snmp_session(array $target, $write = false)
{
    global $config;
    if (!class_exists('SNMP')) {
        throw new RuntimeException('Install the collector PHP SNMP extension.');
    }
    $host = $target['host'];
    $credentials = $host;
    if ($write) {
        $ref = $target['assignment']['credential_ref'];
        $credentials = $config['icct_backend_configuration_credentials'][$ref] ?? null;
        if (!is_array($credentials)) {
            throw new RuntimeException(
                'Write credential reference is not configured on this collector.',
            );
        }
        // Restrict credential use to explicit devices, not every user who guesses its name.
        if (
            !in_array((int) $host['id'], array_map('intval', $credentials['host_ids'] ?? []), true)
        ) {
            throw new RuntimeException('Write credential is not authorised for this device.');
        }
    }
    $version = (int) ($credentials['snmp_version'] ?? $host['snmp_version']);
    if (!in_array($version, [1, 2, 3], true)) {
        throw new RuntimeException('Unsupported SNMP version.');
    }
    $endpoint = filter_var($host['hostname'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
        ? '[' . $host['hostname'] . ']'
        : $host['hostname'];
    $endpoint .= ':' . (int) $host['snmp_port'];
    $session = new SNMP(
        [1 => SNMP::VERSION_1, 2 => SNMP::VERSION_2c, 3 => SNMP::VERSION_3][$version],
        $endpoint,
        $version === 3 ? $credentials['snmp_username'] ?? '' : $credentials['snmp_community'] ?? '',
        min(10000000, max(100000, (int) $host['snmp_timeout'] * 1000)),
        0,
    );
    $session->exceptions_enabled = SNMP::ERRNO_ANY;
    $session->valueretrieval = SNMP_VALUE_PLAIN;
    if ($version === 3) {
        $auth = $credentials['snmp_auth_protocol'] ?? '[None]';
        $priv = $credentials['snmp_priv_protocol'] ?? '[None]';
        $session->setSecurity(
            $auth === '[None]' ? 'noAuthNoPriv' : ($priv === '[None]' ? 'authNoPriv' : 'authPriv'),
            $auth === '[None]' ? '' : $auth,
            $credentials['snmp_password'] ?? '',
            $priv === '[None]' ? '' : $priv,
            $credentials['snmp_priv_passphrase'] ?? '',
            $host['snmp_context'],
        );
    }
    return $session;
}
