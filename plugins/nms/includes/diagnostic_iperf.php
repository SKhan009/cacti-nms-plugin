<?php
/** A local iPerf test is explicitly a collector self-test, never a network-link measurement. */
function nms_diag_iperf_loopback($target)
{
    return $target === '::1' || (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && strpos($target, '127.') === 0);
}

/** Run a one-client server bound only to loopback, with an independent lifetime limit. */
function nms_diag_iperf_self_test($command, $timeout)
{
    $target = $command[2];
    if (!nms_diag_iperf_loopback($target)) throw new RuntimeException('Local self-tests require a loopback IP address.');
    $timer = nms_diag_program('timeout');
    if (!$timer) throw new RuntimeException('A bounded local self-test requires the collector timeout executable.');
    $address = $target === '::1' ? '[::1]' : $target;
    $socket = @stream_socket_server('tcp://' . $address . ':0', $errno, $error);
    if (!$socket) throw new RuntimeException('Cannot allocate a loopback port for the local iPerf3 self-test.');
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
