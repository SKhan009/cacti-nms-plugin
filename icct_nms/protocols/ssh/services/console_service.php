<?php

/** Browser consoles reuse native Cacti identity, device ACLs and the existing SSH broker. */
function icct_nms_ssh_console_device($id)
{
    if (!api_user_realm_auth('protocol.php')) {
        throw new RuntimeException('SSH console permission denied.');
    }
    icct_backend_require_device_access($id);
    return icct_backend_ssh_device($id);
}

function icct_nms_ssh_terminal_url($id)
{
    global $config;
    $service = icct_backend_ssh_config();
    return $service['origin'] .
        $config['url_path'] .
        'plugins/icct_nms/protocols/ssh/controllers/ssh_terminal.php?host_id=' .
        (int) $id;
}

/** A console ID alone grants nothing: every operation requires the same user, browser and device. */
function icct_nms_ssh_console_session($id, $hostId, $device, $closing = false)
{
    if (!is_string($id) || !preg_match('/^[a-f0-9]{64}$/D', $id)) {
        throw new RuntimeException('Invalid SSH console session.');
    }
    $session = db_fetch_row_prepared(
        "SELECT *,lease_until>NOW() AS lease_valid FROM plugin_icct_nms_ssh_sessions WHERE id=? AND kind='console'",
        [$id],
    );
    if (
        !$session ||
        (int) $session['host_id'] !== $hostId ||
        (int) $session['user_id'] !== icct_backend_current_user_id() ||
        !hash_equals($session['session_hash'], hash('sha256', session_id()))
    ) {
        throw new RuntimeException('SSH console belongs to another browser or device.');
    }
    if (
        !$closing &&
        ($session['status'] !== 'active' ||
            !(int) $session['lease_valid'] ||
            (int) $session['device_revision'] !== (int) $device['device_revision'] ||
            (int) $session['preset_revision'] !== (int) $device['preset_revision'])
    ) {
        throw new RuntimeException('SSH console expired or its settings changed.');
    }
    return $session;
}

/** Issue/consume through one path so failed workers cannot leave reusable console tickets. */
function icct_nms_ssh_ticket_request($ticket, $request = [])
{
    session_write_close();
    try {
        return icct_backend_ssh_rpc($ticket + $request);
    } catch (Throwable $error) {
        icct_backend_ssh_close_session($ticket['id'], 'SSH connection failed');
        throw $error;
    }
}

function icct_nms_ssh_console_operation($hostId, $input)
{
    icct_backend_ssh_https();
    $device = icct_nms_ssh_console_device($hostId);
    $action = $input['action'] ?? '';
    if (!in_array($action, ['open', 'read', 'write', 'close', 'test', 'audit'], true)) {
        throw new InvalidArgumentException('Unsupported SSH console action.');
    }
    $identity = [
        'user_id' => icct_backend_current_user_id(),
        'browser_hash' => hash('sha256', session_id()),
    ];
    if ($action === 'test') {
        $ticket = icct_backend_ssh_ticket('test', $hostId);
        return icct_nms_ssh_ticket_request($ticket);
    }
    if ($action === 'open') {
        $size = [];
        foreach (['width' => 4096, 'height' => 2160] as $field => $max) {
            $value = filter_var($input[$field] ?? null, FILTER_VALIDATE_INT);
            if ($value === false || $value < 100 || $value > $max) {
                throw new InvalidArgumentException('Invalid terminal ' . $field . '.');
            }
            $size[$field] = $value;
        }
        if (!$device['host_key'] || $device['verified_endpoint'] !== $device['endpoint']) {
            throw new RuntimeException('Approve the device host key before opening its terminal.');
        }
        $ticket = icct_backend_ssh_ticket('console', $hostId);
        // The worker receives only this session cookie, never unrelated browser cookies or credentials.
        $cookie = session_name() . '=' . rawurlencode(session_id());
        return icct_nms_ssh_ticket_request(
            $ticket,
            $identity + $size + ['console_action' => 'open', 'cookie' => $cookie],
        );
    }
    $id = $input['session_id'] ?? '';
    icct_nms_ssh_console_session($id, $hostId, $device, $action === 'close');
    if ($action === 'audit') {
        $parameter = $input['parameter'] ?? '';
        $requestId = $input['request_id'] ?? '';
        if (
            !is_string($parameter) ||
            !preg_match('~^[\p{L}\p{N}][\p{L}\p{N} ._:/()\[\]-]{0,119}$~uD', trim($parameter)) ||
            !is_string($requestId) ||
            !preg_match('/^[a-f0-9]{32}$/D', $requestId)
        ) {
            throw new InvalidArgumentException('Enter a setting name of up to 120 characters.');
        }
        icct_backend_require_management(3);
        $actor = $identity['user_id'];
        $source = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP);
        $record = [
            'host_id' => (int) $hostId,
            'user_id' => $actor,
            'user' => (string) db_fetch_cell_prepared('SELECT username FROM user_auth WHERE id=?', [
                $actor,
            ]),
            'parameter' => trim($parameter),
            'time' => date(DATE_ATOM),
            'source_ip' => $source ?: '',
        ];
        icct_backend_category_execute(
            'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ' .
                'ON DUPLICATE KEY UPDATE meta_key=VALUES(meta_key)',
            [
                icct_nms_ssh_audit_prefix($hostId) .
                substr(hash('sha256', $id . $requestId), 0, 32),
                json_encode($record, JSON_THROW_ON_ERROR),
            ],
        );
        return ['recorded' => true];
    }
    if ($action === 'close') {
        session_write_close();
        icct_backend_ssh_close_session($id, 'Disconnected by user');
        return ['closed' => true];
    }
    $request = ['id' => $id, 'console_action' => $action] + $identity;
    if ($action === 'write') {
        $data = $input['data'] ?? '';
        if (!is_string($data) || strlen($data) > 43692 || base64_decode($data, true) === false) {
            throw new InvalidArgumentException('Invalid terminal input.');
        }
        $request['data'] = $data;
    }
    if (
        !db_execute_prepared(
            'UPDATE plugin_icct_nms_ssh_sessions SET lease_until=DATE_ADD(NOW(),INTERVAL 30 SECOND) ' .
                "WHERE id=? AND status='active' AND lease_until>NOW()",
            [$id],
        )
    ) {
        throw new RuntimeException('Cannot renew terminal authorization.');
    }
    // Long polling must not hold PHP's browser-session lock and block keyboard or disconnect requests.
    session_write_close();
    return icct_backend_ssh_rpc($request);
}

function icct_nms_ssh_audit_prefix($id)
{
    return 'ssh-config-audit-' . (int) $id . '-';
}
