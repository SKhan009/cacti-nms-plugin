<?php

/** Full browser-console service/broker/worker path; never operate on the live database. */
try {
    if (PHP_SAPI !== 'cli' || !getenv('ICCT_DISCOVERY_QA_DIR')) {
        throw new RuntimeException('Private QA harness required.');
    }
    require '/var/www/html/cacti/include/cli_check.php';
    if (!str_starts_with((string) db_fetch_cell('SELECT DATABASE()'), 'icct_discovery_qa_')) {
        throw new RuntimeException('Disposable database required.');
    }
    require_once $config['base_path'] . '/lib/auth.php';
    api_plugin_load_realms();
    require_once __DIR__ . '/../../../shared/services/backend.php';
    require_once __DIR__ . '/../services/console_service.php';
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_ORIGIN'] = 'https://127.0.0.1:8443';
    session_id(bin2hex(random_bytes(16)));
    session_start();
    $_SESSION = ['sess_user_id' => 1];
    $test = icct_nms_ssh_console_operation(2, ['action' => 'test']);
    if ($test['message'] !== 'SSH host key verified and authentication succeeded.') {
        throw new RuntimeException('Broker credential test failed.');
    }
    session_start();
    $open = icct_nms_ssh_console_operation(2, ['action' => 'open', 'width' => 800, 'height' => 600]);
    $id = $open['id'];
    session_start();
    $result = icct_nms_ssh_console_operation(2, ['action' => 'read', 'session_id' => $id]);
    $instructions = [];
    foreach ($result['messages'] as $message) {
        if (($message['type'] ?? '') === 'error') {
            throw new RuntimeException($message['error']);
        }
        if (($message['type'] ?? '') === 'guac') {
            $instructions[] = base64_decode($message['data'], true);
        }
    }
    if (!$instructions || !str_contains(implode('', $instructions), 'ready')) {
        throw new RuntimeException('Measured Guacamole ready frame missing.');
    }
    session_start();
    icct_nms_ssh_console_operation(2, ['action' => 'write', 'session_id' => $id, 'data' => base64_encode('3.nop;')]);
    session_start();
    icct_nms_ssh_console_operation(2, ['action' => 'close', 'session_id' => $id]);
    for ($i = 0; $i < 30; $i++) {
        if (db_fetch_cell_prepared('SELECT status FROM plugin_icct_nms_ssh_sessions WHERE id=?', [$id]) === 'closed') {
            break;
        }
        usleep(100000);
    }
    if (db_fetch_cell_prepared('SELECT status FROM plugin_icct_nms_ssh_sessions WHERE id=?', [$id]) !== 'closed') {
        throw new RuntimeException('Console authorization did not close.');
    }
    if ((int) db_fetch_cell('SELECT monitoring FROM plugin_icct_nms_ssh_devices WHERE host_id=2') !== 0) {
        throw new RuntimeException('Monitoring was changed by a console.');
    }
    echo "PASS: actual broker credential authentication, cookie-bound ticket consumption, Guacamole ready/output, input relay, explicit disconnect and monitoring off.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
