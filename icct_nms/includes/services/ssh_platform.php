<?php
/** ICCT-owned ssh platform services, derived from the existing ICCT NMS implementation. */

/** Reused Inventory service: ssh absolute path. */
function icct_backend_ssh_absolute_path($path, $family = null)
{
    $family = $family ?? PHP_OS_FAMILY;
    if (
        !is_string($path) ||
        $path === '' ||
        preg_match('/[\x00-\x1f]/', $path) ||
        strpos($path, '://') !== false
    ) {
        return false;
    }
    if ($family === 'Windows') {
        return preg_match('~^[A-Za-z]:[\\\\/]~', $path) &&
            !preg_match('~[<>"|?*]|:(?![\\\\/])~', substr($path, 2)) &&
            !preg_match('~(?:^|[\\\\/])\.{1,2}(?:[\\\\/]|$)~', $path);
    }
    return $path[0] === '/' && !preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path);
}

/** Reused Inventory service: ssh rpc cipher. */
function icct_backend_ssh_rpc_cipher($payload, $c, $decrypt = false, $context = 'request')
{
    $key = file_get_contents($c['rpc_key']);
    if (strlen($key) !== 32) {
        throw new RuntimeException('Invalid local RPC encryption key.');
    }
    if (!$decrypt) {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(
            $payload,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'nms-ssh-rpc-v1:' . $context
        );
        if ($cipher === false) {
            throw new RuntimeException('RPC encryption failed.');
        }
        return base64_encode($iv . $tag . $cipher);
    }
    $data = base64_decode($payload, true);
    if ($data === false || strlen($data) < 29) {
        throw new RuntimeException('Invalid encrypted RPC frame.');
    }
    $plain = openssl_decrypt(
        substr($data, 28),
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        substr($data, 0, 12),
        substr($data, 12, 16),
        'nms-ssh-rpc-v1:' . $context
    );
    if ($plain === false) {
        throw new RuntimeException('Local RPC authentication failed.');
    }
    return $plain;
}

/** Reused Inventory service: ssh windows identity. */
function icct_backend_ssh_windows_identity($path = null)
{
    if (PHP_OS_FAMILY !== 'Windows') {
        throw new RuntimeException('Windows ACL inspection requested on another platform.');
    }
    static $cache = [];
    $cacheKey = $path ?? 'identity';
    if (isset($cache[$cacheKey]) && microtime(true) - $cache[$cacheKey]['time'] < 5) {
        return $cache[$cacheKey]['value'];
    }
    $exe = getenv('SystemRoot') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
    if (!is_file($exe)) {
        throw new RuntimeException('Windows PowerShell is required for native ACL verification.');
    }
    $args = [
        $exe,
        '-NoProfile',
        '-NonInteractive',
        '-ExecutionPolicy',
        'Bypass',
        '-File',
        ICCT_NMS_ROOT . '/ssh/windows-acl.ps1'
    ];
    if ($path !== null) {
        $args[] = '-InspectPath';
        $args[] = $path;
    }
    $p = proc_open(
        $args,
        [['file', 'NUL', 'r'], ['pipe', 'w'], ['file', 'NUL', 'a']],
        $pipes,
        null,
        null,
        [
            'bypass_shell' => true
        ]
    );
    if (!is_resource($p)) {
        throw new RuntimeException('Windows ACL inspection unavailable.');
    }
    $output = stream_get_contents($pipes[1], 65537);
    fclose($pipes[1]);
    $exit = proc_close($p);
    if ($exit !== 0 || strlen($output) > 65536) {
        throw new RuntimeException('Windows ACL inspection failed.');
    }
    $value = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
    $cache[$cacheKey] = ['time' => microtime(true), 'value' => $value];
    return $value;
}
