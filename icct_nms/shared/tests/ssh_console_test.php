<?php

/** No native database: exercise browser/device/session boundaries before any broker operation. */
require __DIR__ . '/../../protocols/ssh/services/console_service.php';
$directory = sys_get_temp_dir() . '/icct-console-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
session_save_path($directory);
session_start();
$GLOBALS['realm'] = true;
$GLOBALS['device'] = ['device_revision' => 7, 'preset_revision' => 2, 'host_key' => 'approved key', 'verified_endpoint' => '127.0.0.1:22', 'endpoint' => '127.0.0.1:22'];
$GLOBALS['session'] = ['id' => str_repeat('a', 64), 'host_id' => 2, 'user_id' => 1, 'session_hash' => hash('sha256', session_id()), 'device_revision' => 7, 'preset_revision' => 2, 'status' => 'active', 'lease_valid' => 1];
$GLOBALS['rpc'] = [];
$GLOBALS['closed'] = [];
$GLOBALS['queries'] = [];
$GLOBALS['failRpc'] = false;
function api_user_realm_auth($name)
{
    return $GLOBALS['realm'];
}
function icct_backend_require_device_access($id)
{
    if ($id !== 2) {
        throw new RuntimeException('Device access denied.');
    }
}
function icct_backend_ssh_device($id)
{
    return $GLOBALS['device'];
}
function icct_backend_current_user_id()
{
    return 1;
}
function icct_backend_ssh_https()
{
    if (!empty($GLOBALS['http'])) {
        throw new RuntimeException('HTTPS required.');
    }
}
function db_fetch_row_prepared($sql, $params)
{
    $GLOBALS['queries'][] = $sql;
    return $GLOBALS['session'];
}
function db_execute_prepared($sql, $params)
{
    $GLOBALS['queries'][] = $sql;
    return true;
}
function icct_backend_ssh_ticket($kind, $id)
{
    return ['id' => str_repeat('a', 64), 'token' => str_repeat('b', 64)];
}
function icct_backend_ssh_rpc($request)
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        throw new LogicException('Browser-session lock held during broker request.');
    }
    $GLOBALS['rpc'][] = $request;
    if ($GLOBALS['failRpc']) {
        throw new RuntimeException('Broker unavailable.');
    }
    return ['id' => $request['id'], 'messages' => []];
}
function icct_backend_ssh_close_session($id, $outcome)
{
    $GLOBALS['closed'][] = $id;
}
function consoleCheck($value, $message)
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}
function consoleReject($operation)
{
    try {
        $operation();
    } catch (RuntimeException | InvalidArgumentException $error) {
        return;
    }
    throw new LogicException('Invalid console operation accepted.');
}
try {
    $valid = $GLOBALS['session'];
    $id = $valid['id'];
    consoleCheck(icct_nms_ssh_console_session($id, 2, $GLOBALS['device']) === $valid, 'Valid binding lost.');
    foreach (['host_id' => 3, 'user_id' => 3, 'session_hash' => hash('sha256', 'other-browser'), 'device_revision' => 8, 'preset_revision' => 3, 'status' => 'closed', 'lease_valid' => 0] as $key => $value) {
        $GLOBALS['session'] = array_replace($valid, [$key => $value]);
        consoleReject(static fn () => icct_nms_ssh_console_session($id, 2, $GLOBALS['device']));
    }
    foreach (['status' => 'closed', 'lease_valid' => 0] as $field => $value) {
        $GLOBALS['session'] = array_replace($valid, [$field => $value]);
        consoleReject(static fn () => icct_nms_ssh_console_session($id, 2, $GLOBALS['device']));
        consoleCheck(icct_nms_ssh_console_session($id, 2, $GLOBALS['device'], true) === $GLOBALS['session'], 'Owner must be able to close an expired or already closed session.');
    }
    $GLOBALS['session'] = false;
    consoleReject(static fn () => icct_nms_ssh_console_session($id, 2, $GLOBALS['device']));
    $GLOBALS['session'] = $valid;
    consoleReject(static fn () => icct_nms_ssh_console_session('../invalid', 2, $GLOBALS['device']));
    $GLOBALS['realm'] = false;
    consoleReject(static fn () => icct_nms_ssh_console_operation(2, ['action' => 'open', 'width' => 800, 'height' => 600]));
    $GLOBALS['realm'] = true;
    consoleReject(static fn () => icct_nms_ssh_console_operation(3, ['action' => 'read', 'session_id' => $id]));
    $GLOBALS['http'] = true;
    consoleReject(static fn () => icct_nms_ssh_console_operation(2, ['action' => 'read', 'session_id' => $id]));
    $GLOBALS['http'] = false;
    foreach ([['width' => 99, 'height' => 600], ['width' => 800, 'height' => 2161], ['width' => 'invalid', 'height' => 600]] as $size) {
        consoleReject(static fn () => icct_nms_ssh_console_operation(2, ['action' => 'open'] + $size));
    }
    consoleCheck(!$GLOBALS['rpc'], 'Invalid requests reached the broker.');
    $GLOBALS['device']['host_key'] = '';
    consoleReject(static fn () => icct_nms_ssh_console_operation(2, ['action' => 'open', 'width' => 800, 'height' => 600]));
    $GLOBALS['device']['host_key'] = 'approved key';
    icct_nms_ssh_console_operation(2, ['action' => 'open', 'width' => 800, 'height' => 600]);
    consoleCheck($GLOBALS['rpc'][0]['console_action'] === 'open' && !isset($GLOBALS['rpc'][0]['secret']), 'Open must use native ticket and saved credential.');
    session_start();
    icct_nms_ssh_console_operation(2, ['action' => 'read', 'session_id' => $id]);
    session_start();
    icct_nms_ssh_console_operation(2, ['action' => 'write', 'session_id' => $id, 'data' => base64_encode('3.nop;')]);
    session_start();
    consoleReject(static fn () => icct_nms_ssh_console_operation(2, ['action' => 'write', 'session_id' => $id, 'data' => '!invalid']));
    $GLOBALS['failRpc'] = true;
    consoleReject(static fn () => icct_nms_ssh_console_operation(2, ['action' => 'test']));
    consoleCheck(in_array($id, $GLOBALS['closed'], true), 'Failed ticket was not closed.');
    session_start();
    $before = count($GLOBALS['rpc']);
    icct_nms_ssh_console_operation(2, ['action' => 'close', 'session_id' => $id]);
    consoleCheck(count($GLOBALS['rpc']) === $before, 'Disconnect must revoke authorization even without a reachable broker.');
    foreach ($GLOBALS['queries'] as $query) {
        consoleCheck(!str_contains($query, 'monitoring='), 'Terminal operations must not change monitoring.');
    }
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    foreach (glob($directory . '/*') as $path) {
        unlink($path);
    }
    rmdir($directory);
}
echo "PASS: HTTPS, realm/device ACLs, browser ownership, expiry/revision checks, native tickets, lock-free polling, failure cleanup and monitoring preservation.\n";
