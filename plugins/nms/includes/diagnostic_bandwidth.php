<?php
/** A local iPerf test is explicitly a collector self-test, never a network-link measurement. */
function nms_diag_iperf_loopback($target)
{
    $ip = @inet_pton((string) $target);
    if ($ip === false) return false;
    return (strlen($ip) === 4 && ord($ip[0]) === 127) ||
        (strlen($ip) === 16 && ($ip === str_repeat(chr(0), 15) . chr(1) ||
        (substr($ip, 0, 12) === str_repeat(chr(0), 10) . chr(255) . chr(255) && ord($ip[12]) === 127)));
}

/** Match literal IPs against actual interfaces, never hostname/DNS guesses. */
function nms_diag_collector_address($target)
{
    if (nms_diag_iperf_loopback($target)) return true;
    if (!filter_var($target, FILTER_VALIDATE_IP)) return false;
    $ip = nms_diag_program('ip');
    if (!$ip) return false;
    $result = nms_diag_run_command([$ip, '-j', 'address', 'show'], 3);
    if ($result['exit'] !== 0 || !empty($result['truncated'])) return false;
    return nms_diag_address_in_interfaces($target, json_decode($result['stdout'] ?? '', true) ?: []);
}

/** Compare binary addresses so equivalent IPv6 spellings match the collector interface. */
function nms_diag_address_in_interfaces($target, array $interfaces)
{
    $packed = @inet_pton($target);
    if ($packed === false) return false;
    foreach ($interfaces as $interface) {
        foreach ($interface['addr_info'] ?? [] as $address) {
            if (!empty($address['tentative']) || !empty($address['dadfailed'])) continue;
            if (@inet_pton($address['local'] ?? '') === $packed) return true;
        }
    }
    return false;
}

/** Run a one-client server bound only to the selected collector address, with an independent lifetime limit. */
function nms_diag_iperf_self_test($command, $timeout)
{
    $target = $command[2];
    if (!nms_diag_collector_address($target)) throw new RuntimeException('Local self-tests require a loopback or assigned collector IP address.');
    $timer = nms_diag_program('timeout');
    if (!$timer) throw new RuntimeException('A bounded local self-test requires the collector timeout executable.');
    $address = strpos($target, ':') !== false ? '[' . $target . ']' : $target;
    $socket = @stream_socket_server('tcp://' . $address . ':0', $errno, $error);
    if (!$socket) throw new RuntimeException('Cannot allocate a local port for the local iPerf3 self-test.');
    $bound = stream_socket_get_name($socket, false);
    $port = substr($bound, strrpos($bound, ':') + 1);
    fclose($socket);
    $command[4] = $port;
    $server = [$timer, '--signal=TERM', '--kill-after=1', (string) ($timeout + 4), $command[0], '-s', '-1', '-B', $target, '-p', $port, '--forceflush'];
    $process = @proc_open($server, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Cannot start the temporary iPerf3 self-test server.');
    try {
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = $stderr = ''; $truncated = false;
        $deadline = hrtime(true) + 2000000000;
        do {
            nms_diag_drain($pipes[1], $stdout, $truncated, 8192);
            nms_diag_drain($pipes[2], $stderr, $truncated, 8192);
            if (strpos($stdout, 'Server listening on') !== false) break;
            if (!proc_get_status($process)['running']) throw new RuntimeException('The temporary iPerf3 server could not start: ' . trim($stderr));
            usleep(20000);
        } while (hrtime(true) < $deadline);
        // Do not probe with a TCP connection: that would consume the one-client server.
        if (strpos($stdout, 'Server listening on') === false) throw new RuntimeException('The temporary iPerf3 server did not become ready.');
        $result = nms_diag_run_command($command, $timeout);
        $result['self_test'] = true;
        return [$command, $result];
    } finally {
        if (proc_get_status($process)['running']) proc_terminate($process);
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($process);
    }
}

/** Run a serial server bound only to the selected collector address, with an independent lifetime limit. */
function nms_diag_netperf_self_test($command, $timeout)
{
    $target = $command[2];
    if (!nms_diag_collector_address($target)) throw new RuntimeException('Local self-tests require a loopback or assigned collector IP address.');
    $binary = nms_diag_program('netserver');
    if (!$binary) throw new RuntimeException('Netserver is required for a local Netperf self-test.');
    $timer = nms_diag_program('timeout');
    if (!$timer) throw new RuntimeException('A bounded local self-test requires the collector timeout executable.');
    $address = strpos($target, ':') !== false ? '[' . $target . ']' : $target;
    $socket = @stream_socket_server('tcp://' . $address . ':0', $errno, $error);
    if (!$socket) throw new RuntimeException('Cannot allocate a local port for the local Netperf self-test.');
    $bound = stream_socket_get_name($socket, false);
    $port = substr($bound, strrpos($bound, ':') + 1);
    fclose($socket);
    $command[4] = $port;
    $server = [$timer, '--signal=TERM', '--kill-after=1', (string) ($timeout + 4), $binary, '-D', '-f', '-L', $target, '-p', $port];
    $process = @proc_open($server, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Cannot start the temporary Netperf self-test server.');
    try {
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = $stderr = ''; $truncated = false;
        $deadline = hrtime(true) + 2000000000;
        do {
            nms_diag_drain($pipes[1], $stdout, $truncated, 8192);
            nms_diag_drain($pipes[2], $stderr, $truncated, 8192);
            $ready = @stream_socket_client('tcp://' . $address . ':' . $port, $errno, $error, 0.1);
            if ($ready) { fclose($ready); break; }
            if (!proc_get_status($process)['running']) throw new RuntimeException('The temporary Netperf server could not start: ' . trim($stderr));
            usleep(20000);
        } while (hrtime(true) < $deadline);
        // Netserver accepts repeated control connections while running serially.
        if (!$ready) throw new RuntimeException('The temporary Netperf server did not become ready.');
        $result = nms_diag_run_command($command, $timeout);
        $result['self_test'] = true;
        return [$command, $result];
    } finally {
        if (proc_get_status($process)['running']) proc_terminate($process);
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($process);
    }
}
