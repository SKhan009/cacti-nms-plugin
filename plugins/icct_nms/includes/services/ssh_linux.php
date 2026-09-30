<?php
/** ICCT-owned ssh linux services, derived from the existing ICCT NMS implementation. */

/** Reused Inventory service: ssh key envelope. */
function icct_backend_ssh_key_envelope($key)
{
    if (!is_string($key) || strlen($key) > 65536 || strpos($key, "\0") !== false) {
        throw new InvalidArgumentException('Private key must be text and at most 64 KB.');
    }
    $text = trim($key);
    if (
        preg_match(
            '/\A-----BEGIN (OPENSSH PRIVATE KEY|RSA PRIVATE KEY|EC PRIVATE KEY|DSA PRIVATE KEY|PRIVATE KEY|ENCRYPTED PRIVATE KEY)-----\r?\n[\s\S]+\r?\n-----END \1-----\z/',
            $text
        )
    ) {
        return;
    }
    if (
        preg_match('/\APuTTY-User-Key-File-[23]: [^\r\n]+\r?\n/', $text) &&
        preg_match('/(?:^|\n)Private-Lines: [1-9][0-9]*\r?\n/', $text) &&
        preg_match('/(?:^|\n)Private-MAC: [a-fA-F0-9]+\z/', $text)
    ) {
        return;
    }
    throw new InvalidArgumentException(
        'Choose a PEM, OpenSSH or PuTTY private key. Public keys, certificates, logs and other files are not accepted.'
    );
}

/** Reused Inventory service: ssh preset values. */
function icct_backend_ssh_preset_values($input)
{
    $out = [];
    foreach (['name' => 80, 'description' => 500, 'username' => 128] as $field => $limit) {
        if (
            !isset($input[$field]) ||
            !is_string($input[$field]) ||
            strlen($input[$field]) > $limit ||
            preg_match('/[\x00-\x1f]/', $input[$field])
        ) {
            throw new InvalidArgumentException('Invalid ' . $field . '.');
        }
        $out[$field] = trim($input[$field]);
    }
    if ($out['name'] === '' || $out['username'] === '') {
        throw new InvalidArgumentException('Name and username are required.');
    }
    foreach (
        [
            'port' => [1, 65535],
            'connect_timeout' => [1, 120],
            'command_timeout' => [1, 300],
            'retries' => [0, 2],
            'keepalive' => [0, 300]
        ]
        as $field => $range
    ) {
        $value = $input[$field] ?? null;
        if (
            !is_scalar($value) ||
            filter_var($value, FILTER_VALIDATE_INT) === false ||
            (int) $value < $range[0] ||
            (int) $value > $range[1]
        ) {
            throw new InvalidArgumentException('Invalid ' . $field . '.');
        }
        $out[$field] = (int) $value;
    }
    if (!in_array($input['auth_method'] ?? '', ['key', 'password'], true)) {
        throw new InvalidArgumentException('Select exactly one authentication method.');
    }
    if (isset($input['enabled']) && !in_array($input['enabled'], ['1', 1], true)) {
        throw new InvalidArgumentException('Invalid enabled state.');
    }
    $out['auth_method'] = $input['auth_method'];
    $out['enabled'] = !empty($input['enabled']) ? 1 : 0;
    return $out;
}
