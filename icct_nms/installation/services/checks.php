<?php

/** Read-only installation inventory. Never invokes a package manager from HTTP. */
require_once __DIR__ . '/runtime.php';
function icct_nms_installation_binary($names, $executable = true)
{
    foreach ((array) $names as $name) {
        if (strpos($name, '/') === 0 && is_file($name) && (!$executable || is_executable($name))) {
            return $name;
        }
        foreach (
            ['/usr/bin', '/usr/sbin', '/usr/local/bin', '/usr/local/sbin', '/bin', '/sbin']
            as $dir
        ) {
            if (
                is_file($dir . '/' . $name) &&
                (!$executable || is_executable($dir . '/' . $name))
            ) {
                return $dir . '/' . $name;
            }
        }
    }
    return '';
}
function icct_nms_installation_service($unit)
{
    if (PHP_SAPI !== 'cli') {
        return 'Not checked';
    }
    if (!is_dir('/run/systemd/system')) {
        return 'Not systemd';
    }
    if (!is_callable('shell_exec') || !icct_nms_installation_binary('timeout')) {
        return 'Not checked';
    }
    $command = icct_nms_installation_binary('systemctl');
    if (!$command) {
        return 'Not checked';
    }
    $state = trim(
        (string) shell_exec(
            escapeshellarg(icct_nms_installation_binary('timeout')) .
                ' 2s ' .
                escapeshellarg($command) .
                ' is-active -- ' .
                escapeshellarg($unit) .
                ' 2>/dev/null',
        ),
    );
    return in_array(
        $state,
        ['active', 'inactive', 'failed', 'activating', 'deactivating', 'unknown'],
        true,
    )
        ? $state
        : 'Not checked';
}
/** Readiness requires measured local configuration and a reachable broker, not just installed files. */
function icct_nms_installation_ssh()
{
    try {
        $c = icct_backend_ssh_config();
        $prefix = '/opt/icct-nms/native/guacamole-1.6.0';
        $receipt = $prefix . '/icct-verified.json';
        if (
            !is_file($receipt) ||
            is_link($receipt) ||
            fileowner($receipt) !== 0 ||
            fileperms($receipt) & 0022
        ) {
            throw new RuntimeException(
                'Run offline SSH setup with the verified strict-auth native bundle.',
            );
        }
        $data = json_decode(file_get_contents($receipt), true, 32, JSON_THROW_ON_ERROR);
        $pins = json_decode(
            file_get_contents(dirname(__DIR__, 2) . '/protocols/ssh/guacamole/manifest.json'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        if (
            ($data['component'] ?? '') !== 'guacamole-strict' ||
            ($data['source_sha256'] ?? '') !== $pins['upstream']['server_sha256'] ||
            ($data['patch_sha256'] ?? '') !== $pins['protocols/ssh/guacamole/strict-auth.patch'] ||
            !isset($data['files']['sbin/guacd'], $data['files']['lib/libguac-client-ssh.so'])
        ) {
            throw new RuntimeException('Strict-auth native verification receipt is invalid.');
        }
        foreach ($data['files'] as $name => $details) {
            if (
                str_contains($name, '..') ||
                str_starts_with($name, '/') ||
                str_contains($name, '\\')
            ) {
                throw new RuntimeException('Invalid native verification path.');
            }
            $path = $prefix . '/' . $name;
            if (
                !is_file($path) ||
                is_link($path) ||
                fileowner($path) !== 0 ||
                fileperms($path) & 0022 ||
                !hash_equals($details['sha256'], hash_file('sha256', $path))
            ) {
                throw new RuntimeException(
                    'Installed strict-auth native files changed; verify the offline bundle.',
                );
            }
        }
        $socket = @stream_socket_client('unix://' . $c['control_socket'], $errno, $error, 1);
        if (!$socket) {
            throw new RuntimeException(
                'Configured SSH broker socket is unavailable to this account.',
            );
        }
        fclose($socket);
        return [
            'status' => 'Ready',
            'detail' =>
                'Strict-auth build verified; broker reachable; HTTPS origin ' .
                $c['origin'] .
                '. Device credentials and host-key approval remain required.',
        ];
    } catch (Throwable $error) {
        // Configuration paths contain no credentials; never expose lower-level authentication diagnostics.
        return [
            'status' => 'Needs setup',
            'detail' =>
                'Offline SSH configuration or broker verification failed. Run setup with --ssh-origin and a verified guacamole-strict native bundle.',
        ];
    }
}

function icct_nms_installation_checks()
{
    global $config;
    $manifest = icct_nms_installation_manifest();
    $checks = [];
    $saved = [];
    if (PHP_SAPI !== 'cli') {
        $raw = db_fetch_cell_prepared(
            'SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
            ['installation_services_' . (int) $config['poller_id']],
        );
        $saved = icct_nms_installation_snapshot(
            $raw,
            gethostname(),
            time(),
            max(600, 3 * (int) read_config_option('poller_interval')),
        );
    }
    $add = static function ($feature, $item, $status, $detail) use (&$checks) {
        $checks[] = compact('feature', 'item', 'status', 'detail');
    };
    $version = (string) db_fetch_cell('SELECT cacti FROM version LIMIT 1');
    $add(
        'Core',
        'Cacti version',
        $version !== '' ? 'Ready' : 'Missing',
        $version . ' · tested on ' . $manifest['cacti_tested'],
    );
    $add(
        'Core',
        'PHP ' . (PHP_SAPI === 'cli' ? 'CLI' : 'Web'),
        version_compare(PHP_VERSION, $manifest['php_min'], '>=') ? 'Ready' : 'Missing',
        PHP_VERSION . ' · minimum ' . $manifest['php_min'],
    );
    $python = PHP_SAPI === 'cli' ? icct_nms_installation_python() : $saved['python'] ?? [];
    $pythonVersion = (string) ($python['version'] ?? '');
    $pythonPath = (string) ($python['path'] ?? '');
    $pythonStatus = icct_nms_installation_python_status($python, $manifest['python_min']);
    $add(
        'Workers',
        'Python runtime',
        $pythonStatus,
        ($pythonVersion ?: ($pythonPath ?: 'Awaiting collector runtime check')) .
            ' · minimum ' .
            $manifest['python_min'],
    );
    if ($pythonPath) {
        $checks[count($checks) - 1]['installed'] = true;
    }
    foreach ($manifest['php_extensions'] as $ext) {
        $add(
            'Core',
            'PHP ' . $ext,
            extension_loaded($ext) ? 'Ready' : 'Missing',
            'Required in the web server and PHP CLI.',
        );
    }
    if (PHP_SAPI === 'cli') {
        foreach (['pcntl', 'posix', 'sockets'] as $ext) {
            $add(
                'Workers',
                'PHP ' . $ext,
                extension_loaded($ext) ? 'Ready' : 'Missing',
                'Required in PHP CLI for background collectors.',
            );
        }
    }
    foreach ($manifest['features'] as $feature => $spec) {
        foreach ($spec['binaries'] as $label => $names) {
            $path = icct_nms_installation_binary(
                $names,
                !in_array($label, $spec['root_binaries'] ?? [], true),
            );
            if (!$path && in_array($label, $spec['root_binaries'] ?? [], true)) {
                $path = $saved['binaries'][$feature][$label] ?? '';
            }
            $present = $path ?: icct_nms_installation_binary($names, false);
            $add(
                $feature,
                $label,
                $path ? 'Ready' : ($present ? 'Needs setup' : 'Missing'),
                $path ?:
                ($present
                    ? $present . ' · installed; check execution permissions for the collector.'
                    : 'Install ' . implode(' / ', (array) $names) . ' on this collector.'),
            );
            if ($present) {
                $checks[count($checks) - 1]['installed'] = true;
            }
        }
        foreach ($spec['services'] as $unit) {
            $state = icct_nms_installation_service($unit);
            $installed =
                PHP_SAPI === 'cli'
                    ? icct_nms_installation_unit_exists($unit)
                    : $saved['installed_services'][$unit] ?? null;
            $observed = '';
            if ($state === 'Not checked' && isset($saved['services'][$unit])) {
                $state = $saved['services'][$unit];
                $observed = ' · collector check ' . date('Y-m-d H:i:s', (int) $saved['time']);
            }
            $add(
                $feature,
                $unit,
                $state === 'active'
                    ? 'Ready'
                    : ($state === 'Not checked'
                        ? 'Not checked'
                        : 'Needs setup'),
                $state . $observed . ' · applies to this server/collector only.',
            );
            $checks[count($checks) - 1]['installed'] = $installed;
        }
        foreach ($spec['files'] as $file) {
            $add(
                $feature,
                $file,
                is_readable(dirname(__DIR__, 2) . '/' . $file) ? 'Ready' : 'Missing',
                'Bundled plugin asset.',
            );
        }
        if (!empty($spec['note'])) {
            if ($feature === 'SSH') {
                $ssh = icct_nms_installation_ssh();
                $add($feature, 'Configuration', $ssh['status'], $ssh['detail']);
            } else {
                $add($feature, 'Configuration', 'Needs setup', $spec['note']);
            }
        }
    }
    foreach ($manifest['optional_components'] ?? [] as $id => $spec) {
        $observation =
            PHP_SAPI === 'cli'
                ? icct_nms_installation_component($id, $spec)
                : $saved['components'][$id] ?? [];
        $installed = $observation['installed'] ?? null;
        $add(
            $spec['label'],
            'Package',
            $installed === true ? 'Installed' : ($installed === false ? 'Missing' : 'Not checked'),
            ($observation['detail'] ?? 'Awaiting local collector check.') . ' · ' . $spec['note'],
        );
        $checks[count($checks) - 1]['installed'] = $installed;
        $checks[count($checks) - 1]['component'] = $id;
    }
    foreach (
        [
            'installation/services/checks.php',
            'installation/cli/setup.py',
            'setup.php',
            'shared/services/backend.php',
        ]
        as $source
    ) {
        $path = dirname(__DIR__, 2) . '/' . $source;
        $unsafe = is_file($path) && fileperms($path) & 0002;
        // Root CLI may write source; the HTTP account must not.
        $webWritable =
            (PHP_SAPI !== 'cli' || (function_exists('posix_geteuid') && posix_geteuid() !== 0)) &&
            is_writable($path);
        $add(
            'Permissions',
            $source,
            !$unsafe && !$webWritable && is_readable($path) ? 'Ready' : 'Needs setup',
            $unsafe
                ? 'Source is world-writable; restrict ownership and mode.'
                : ($webWritable
                    ? 'Web account can modify source; restrict source ownership.'
                    : 'Source readable; no world-write permission.'),
        );
    }
    foreach ($manifest['pending_features'] as $feature => $note) {
        $add($feature, 'Backend', 'Unavailable', $note);
    }
    $add(
        'Core',
        'Plugin database',
        icct_backend_database_ready() ? 'Ready' : 'Missing',
        'Installed through Cacti Plugin Management; existing devices and presets are retained.',
    );
    foreach (['protocols/snmp/mibs/storage', 'shared/assets/images/device-types/uploads'] as $dir) {
        $add(
            'Core',
            $dir,
            is_writable(dirname(__DIR__, 2) . '/' . $dir) ? 'Ready' : 'Needs setup',
            'Writable by the web account; all other source files should remain read-only.',
        );
    }
    $queryDirectory = $config['base_path'] . '/resource/snmp_queries/icct_nms';
    $add(
        'SNMP / MIB',
        'Native query directory',
        is_writable($queryDirectory) ? 'Ready' : 'Needs setup',
        'Cacti resource/snmp_queries/icct_nms must be writable by the collector/web account.',
    );
    return $checks;
}

function icct_nms_installation_snapshot($raw, $hostname, $now, $maxAge)
{
    $snapshot = json_decode((string) $raw, true);
    if (
        !is_array($snapshot) ||
        ($snapshot['hostname'] ?? '') !== $hostname ||
        !is_int($snapshot['time'] ?? null) ||
        $snapshot['time'] > $now ||
        $now - $snapshot['time'] > $maxAge ||
        !is_array($snapshot['services'] ?? null)
    ) {
        return [];
    }
    return $snapshot;
}

function icct_nms_installation_unit_exists($unit)
{
    foreach (
        ['/etc/systemd/system', '/usr/lib/systemd/system', '/lib/systemd/system']
        as $directory
    ) {
        if (is_file($directory . '/' . $unit)) {
            return true;
        }
    }
    return false;
}

/** CLI observation avoids granting the PHP web domain system-service control. */
function icct_nms_installation_save_services()
{
    global $config;
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException(
            'Service observations can only be saved by the local collector.',
        );
    }
    $services = [];
    $installedServices = [];
    $binaries = [];
    foreach (icct_nms_installation_manifest()['features'] as $feature => $spec) {
        foreach ($spec['services'] as $unit) {
            $services[$unit] = icct_nms_installation_service($unit);
            $installedServices[$unit] = icct_nms_installation_unit_exists($unit);
        }
        foreach ($spec['root_binaries'] ?? [] as $label) {
            $binaries[$feature][$label] = icct_nms_installation_binary(
                $spec['binaries'][$label],
                false,
            );
        }
    }
    $components = [];
    foreach (icct_nms_installation_manifest()['optional_components'] ?? [] as $id => $spec) {
        $components[$id] = icct_nms_installation_component($id, $spec);
    }
    $snapshot = json_encode(
        [
            'python' => icct_nms_installation_python(),
            'components' => $components,
            'time' => time(),
            'hostname' => gethostname(),
            'services' => $services,
            'installed_services' => $installedServices,
            'binaries' => $binaries,
        ],
        JSON_THROW_ON_ERROR,
    );
    if (
        !db_execute_prepared(
            'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
            ['installation_services_' . (int) $config['poller_id'], $snapshot],
        )
    ) {
        throw new RuntimeException('Could not save collector service observations.');
    }
}

/** Local package inventory only; never called from HTTP. */
function icct_nms_installation_component($id, $spec)
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Package inventory requires CLI.');
    }
    $family = is_file('/etc/redhat-release') ? 'rhel' : 'debian';
    $names = $spec['binaries'] ?? [];
    if (isset($names[$family])) {
        $names = $names[$family];
    }
    $paths = [];
    foreach ($names as $name) {
        $path = icct_nms_installation_binary([$name], false);
        if ($path) {
            $paths[] = $path;
        }
    }
    // Recognize existing Guacamole builds and NTP client tools without replacing them.
    if (in_array($id, ['guacamole', 'ntp'], true) && $paths) {
        return ['installed' => true, 'detail' => implode(', ', $paths)];
    }
    $packages = $spec['packages'][$family];
    if (!$packages) {
        return ['installed' => null, 'detail' => 'Approved native build required for this OS.'];
    }
    if (!is_callable('shell_exec') || !icct_nms_installation_binary('timeout')) {
        return ['installed' => null, 'detail' => 'Package inventory unavailable.'];
    }
    $manager = icct_nms_installation_binary($family === 'rhel' ? 'rpm' : 'dpkg-query');
    if (!$manager) {
        return ['installed' => null, 'detail' => 'Package manager unavailable.'];
    }
    $found = [];
    foreach ($packages as $package) {
        $arguments =
            $family === 'rhel'
                ? ' -q --qf ' . escapeshellarg('%{NAME} %{VERSION}-%{RELEASE}') . ' -- '
                : ' -W -f=' . escapeshellarg('${db:Status-Status} ${Version}') . ' -- ';
        $value = trim(
            (string) shell_exec(
                escapeshellarg(icct_nms_installation_binary('timeout')) .
                    ' 3s ' .
                    escapeshellarg($manager) .
                    $arguments .
                    escapeshellarg($package) .
                    ' 2>/dev/null',
            ),
        );
        if (
            $family === 'rhel'
                ? strpos($value, $package . ' ') === 0
                : strpos($value, 'installed ') === 0
        ) {
            $found[] = $package . ': ' . $value;
        }
    }
    return [
        'installed' => count($found) === count($packages),
        'detail' => $found ? implode('; ', $found) : 'Not installed.',
    ];
}

/** Measure the runtime only in a collector process, never from HTTP. */
function icct_nms_installation_python()
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Runtime inventory requires CLI.');
    }
    $path = icct_nms_installation_binary(['python3'], false);
    $result = ['path' => $path, 'version' => '', 'executable' => $path && is_executable($path)];
    if (
        !$result['executable'] ||
        !is_callable('shell_exec') ||
        !icct_nms_installation_binary('timeout')
    ) {
        return $result;
    }
    $output = (string) shell_exec(
        escapeshellarg(icct_nms_installation_binary('timeout')) .
            ' 3s ' .
            escapeshellarg($path) .
            ' --version 2>/dev/null',
    );
    if (preg_match('/Python\s+([0-9]+\.[0-9]+\.[0-9]+)/', $output, $match)) {
        $result['version'] = $match[1];
    }
    return $result;
}

function icct_nms_installation_python_status($runtime, $minimum)
{
    if (!$runtime) {
        return 'Not checked';
    }
    if (empty($runtime['path'])) {
        return 'Missing';
    }
    if (empty($runtime['executable'])) {
        return 'Needs setup';
    }
    if (empty($runtime['version'])) {
        return 'Not checked';
    }
    return version_compare($runtime['version'], $minimum, '>=') ? 'Ready' : 'Needs setup';
}
