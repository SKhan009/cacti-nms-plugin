<?php

/** Read-only installation inventory. Never invokes a package manager from HTTP. */
require_once __DIR__ . '/runtime.php';
function icct_nms_installation_binary($names, $executable = true)
{
    foreach ((array) $names as $name) {
        if (strpos($name, '/') === 0 && is_file($name) && (!$executable || is_executable($name))) {
            return $name;
        }
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir === '' || $dir[0] !== DIRECTORY_SEPARATOR) {
                continue;
            }
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
        $spec = icct_nms_installation_manifest()['optional_components']['guacamole-strict'];
        $binary = icct_nms_installation_native_binary($spec);
        icct_nms_installation_verify_guacamole($binary);
        $socket = @stream_socket_client('unix://' . $c['control_socket'], $errno, $error, 1);
        if (!$socket) {
            throw new RuntimeException('Configured SSH broker socket is unavailable to this account.');
        }
        fclose($socket);
        return [
            'status' => 'Ready',
            'detail' => 'Strict-auth build verified; broker reachable; HTTPS origin ' .
                $c['origin'] . '. Device credentials and host-key approval remain required.',
        ];
    } catch (Throwable $error) {
        return [
            'status' => 'Needs setup',
            'detail' => 'Offline SSH configuration or broker verification failed. Run setup with --ssh-origin and a verified guacamole-strict native bundle.',
        ];
    }
}

/** Verify the receipt beside the discovered executable, never an assumed version directory. */
function icct_nms_installation_verify_guacamole($binary)
{
    if (!$binary || !is_file($binary)) {
        throw new RuntimeException('Strict-auth executable is unavailable.');
    }
    $prefix = dirname(realpath($binary), 2);
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
    if (realpath($prefix . '/sbin/guacd') !== realpath($binary)) {
        throw new RuntimeException('The service executable does not match the verified strict-auth daemon.');
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
    return $binary;
}


/** The native daemon path comes from its installed service, or the fresh local collector observation. */
function icct_nms_installation_native_binary(array $spec): string
{
    if (PHP_SAPI !== 'cli') {
        global $config;
        $snapshot = icct_nms_installation_snapshot(
            db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
                ['installation_services_' . icct_backend_inventory_collector_id()]),
            gethostname(), time(), max(600, 3 * (int) read_config_option('poller_interval')),
        );
        return (string) ($snapshot['components'][$spec['runtime_component']]['runtime_path'] ?? '');
    }
    $systemctl = icct_nms_installation_binary('systemctl');
    $timeout = icct_nms_installation_binary('timeout');
    if (!$systemctl || !$timeout || !is_callable('shell_exec')) {
        return '';
    }
    $value = (string) shell_exec(escapeshellarg($timeout) . ' 2s ' . escapeshellarg($systemctl) .
        ' show --property=ExecStart --value -- ' . escapeshellarg($spec['runtime_service']) . ' 2>/dev/null');
    if (!preg_match('/^\{ path=([^;]+) ;/', trim($value), $match)) {
        return '';
    }
    $path = trim($match[1]);
    return $path !== '' && $path[0] === '/' && is_file($path) ? $path : '';
}

/** Read actual observations for enabled devices assigned to this collector. */
function icct_nms_installation_ntp()
{
    if (!icct_nms_ntp_ready()) {
        return ['status' => 'Needs setup', 'detail' => 'NTP monitor schema is not installed.'];
    }
    $devices = db_fetch_assoc_prepared(
        "SELECT d.host_id FROM plugin_icct_nms_ntp_monitors d JOIN host h ON h.id=d.host_id " .
        "WHERE h.deleted='' AND h.disabled='' AND h.poller_id=? ORDER BY d.host_id",
        [icct_backend_inventory_collector_id()],
    );
    if ($devices === false) {
        throw new RuntimeException('Could not read collector NTP assignments.');
    }
    $observations = [];
    foreach ($devices as $device) {
        $id = (int) $device['host_id'];
        if (icct_backend_protocol_enabled($id, 'ntp')) {
            $observations[] = icct_nms_ntp_current($id);
        }
    }
    return icct_nms_installation_ntp_status($observations);
}

function icct_nms_installation_ntp_status(array $observations): array
{
    if (!$observations) {
        return ['status' => 'Not configured', 'detail' => 'No enabled NTP monitors are assigned to this collector.'];
    }
    $healthy = 0;
    $problems = [];
    foreach ($observations as $reading) {
        if (($reading['status'] ?? '') === 'Synchronized' &&
            ($reading['sample']['synchronized'] ?? null) === true && empty($reading['error'])) {
            $healthy++;
        } else {
            $status = (string) ($reading['status'] ?? 'Not collected');
            $problems[$status] = ($problems[$status] ?? 0) + 1;
        }
    }
    $detail = $healthy . ' of ' . count($observations) . ' enabled NTP monitors have current synchronized readings.';
    foreach ($problems as $status => $count) {
        $detail .= ' ' . $status . ': ' . $count . '.';
    }
    return ['status' => $healthy === count($observations) ? 'Ready' : 'Needs setup', 'detail' => $detail];
}

/** Runtime availability and package ownership are independent facts. */
function icct_nms_installation_component_status(array $observation): string
{
    if (($observation['installed'] ?? null) === true) {
        return 'Installed';
    }
    if (!empty($observation['runtime_available'])) {
        return 'Available outside package manager';
    }
    return ($observation['installed'] ?? null) === false ? 'Missing' : 'Not checked';
}

function icct_nms_installation_component_runtime(array $spec, array $paths): array
{
    if (isset($spec['runtime_feature'], $spec['runtime_binary'])) {
        $manifest = icct_nms_installation_manifest();
        $names = $manifest['features'][$spec['runtime_feature']]['binaries'][$spec['runtime_binary']];
        $path = icct_nms_installation_binary($names);
        $paths = $path ? [$path] : [];
    }
    if (isset($spec['runtime_service'])) {
        $path = icct_nms_installation_native_binary($spec);
        $paths = $path ? [$path] : [];
    }
    if (!$paths) {
        return !empty($spec['binaries']) || isset($spec['runtime_feature'], $spec['runtime_binary']) || isset($spec['runtime_service'])
            ? ['runtime_available' => false, 'runtime_detail' => 'No declared runtime binary found.']
            : [];
    }
    if (($spec['runtime_verification'] ?? '') === 'strict-auth') {
        try {
            icct_nms_installation_verify_guacamole($paths[0]);
        } catch (Throwable $error) {
            return ['runtime_available' => false, 'runtime_detail' => 'Strict-auth native verification failed.'];
        }
    }
    return ['runtime_available' => true, 'runtime_path' => $paths[0],
        'runtime_detail' => 'Runtime found: ' . implode(', ', $paths)];
}

/** A read-only file can still be replaced through a writable source directory. */
function icct_nms_installation_source_permissions($path, $root, $webProcess): array
{
    $root = realpath($root);
    $path = realpath($path);
    if (!$root || !$path || !is_file($path) || !is_readable($path) ||
        !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
        return ['status' => 'Needs setup', 'detail' => 'Source is missing, unreadable or outside the Cacti installation.'];
    }
    if (fileperms($path) & 0002 || ($webProcess && is_writable($path))) {
        return ['status' => 'Needs setup', 'detail' => 'Source can be modified by the web account or other users: ' . $path];
    }
    for ($directory = dirname($path); ; $directory = dirname($directory)) {
        if (fileperms($directory) & 0002 || ($webProcess && is_writable($directory))) {
            return ['status' => 'Needs setup', 'detail' => 'Source can be replaced through a writable directory: ' . $directory];
        }
        if ($directory === $root) {
            break;
        }
    }
    return ['status' => 'Ready', 'detail' => 'Source and its Cacti parent directories are protected from web-account and world writes.'];
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
            if ($feature === 'SSH' && $label === 'SSH terminal backend') {
                $path = icct_nms_installation_native_binary($manifest['optional_components']['guacamole-strict']);
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
                $observed = ' · collector check ' . icct_nms_display_time((int) $saved['time']);
            }
            $add(
                $feature,
                $unit,
                $state === 'active'
                    ? 'Ready'
                    : ($state === 'Not checked'
                        ? 'Not checked'
                        : 'Needs setup'),
                $feature === 'SNMP Traps' && $installed === false && $state !== 'active'
                    ? 'Configure trap authorization and listener using --trap-auth-file and --trap-listener.'
                    : $state . $observed . ' · applies to this server/collector only.',
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
            } elseif ($feature === 'NTP') {
                $ntp = icct_nms_installation_ntp();
                $add($feature, 'Configuration', $ntp['status'], $ntp['detail']);
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
            icct_nms_installation_component_status($observation),
            ($observation['detail'] ?? 'Awaiting local collector check.') .
                (!empty($observation['runtime_detail']) ? ' · ' . $observation['runtime_detail'] : '') .
                ' · ' . $spec['note'],
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
        $webProcess = PHP_SAPI !== 'cli' ||
            (function_exists('posix_geteuid') && posix_geteuid() !== 0);
        $permissions = icct_nms_installation_source_permissions($path, $config['base_path'], $webProcess);
        $add('Permissions', $source, $permissions['status'], $permissions['detail']);
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
    $runtime = icct_nms_installation_component_runtime($spec, $paths);
    $packages = $spec['packages'][$family];
    if (!$packages) {
        return ['installed' => null, 'detail' => 'No package profile for this OS.'] + $runtime;
    }
    if (!is_callable('shell_exec') || !icct_nms_installation_binary('timeout')) {
        return ['installed' => null, 'detail' => 'Package inventory unavailable.'] + $runtime;
    }
    $manager = icct_nms_installation_binary($family === 'rhel' ? 'rpm' : 'dpkg-query');
    if (!$manager) {
        return ['installed' => null, 'detail' => 'Package manager unavailable.'] + $runtime;
    }
    $found = [];
    $missing = [];
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
        } else {
            $missing[] = $package;
        }
    }
    return [
        'installed' => !$missing,
        'detail' => implode('; ', array_merge(
            $found,
            $missing ? ['Missing packages: ' . implode(', ', $missing) . '.'] : [],
        )),
    ] + $runtime;
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
