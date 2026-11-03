<?php

/** Read-only installation inventory. Never invokes a package manager from HTTP. */
function icct_nms_installation_manifest()
{
    return json_decode(
        file_get_contents(__DIR__ . '/../dependencies.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
}
function icct_nms_installation_binary($names, $executable = true)
{
    foreach ((array) $names as $name) {
        if (strpos($name, '/') === 0 && is_file($name) && (!$executable || is_executable($name))) return $name;
        foreach (
            ['/usr/bin', '/usr/sbin', '/usr/local/bin', '/usr/local/sbin', '/bin', '/sbin'] as $dir
        ) {
            if (is_file($dir . '/' . $name) && (!$executable || is_executable($dir . '/' . $name))) {
                return $dir . '/' . $name;
            }
        };
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
function icct_nms_installation_checks()
{
    global $config;
    $manifest = icct_nms_installation_manifest();
    $checks = [];
    $saved = [];
    if (PHP_SAPI !== 'cli') {
        $raw = db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?', ['installation_services_'.(int)$config['poller_id']]);
        $saved = icct_nms_installation_snapshot($raw, gethostname(), time(), max(600, 3 * (int)read_config_option('poller_interval')));
    }
    $add = static function ($feature, $item, $status, $detail) use (&$checks) {
        $checks[] = compact('feature', 'item', 'status', 'detail');
    };
    $version = (string) db_fetch_cell('SELECT cacti FROM version LIMIT 1');
    $add(
        'Core',
        'Cacti version',
        version_compare($version, $manifest['cacti_min'], '>=') ? 'Ready' : 'Missing',
        $version .
            ' · minimum ' .
            $manifest['cacti_min'] .
            '; tested on ' .
            $manifest['cacti_tested'],
    );
    $add(
        'Core',
        'PHP ' . (PHP_SAPI === 'cli' ? 'CLI' : 'Web'),
        version_compare(PHP_VERSION, $manifest['php_min'], '>=') ? 'Ready' : 'Missing',
        PHP_VERSION . ' · minimum ' . $manifest['php_min'],
    );
    foreach ($manifest['php_extensions'] as $ext) {
        $add(
            'Core',
            'PHP ' . $ext,
            extension_loaded($ext) ? 'Ready' : 'Missing',
            'Required in the web server and PHP CLI.',
        );
    }
    if (PHP_SAPI === 'cli') {
        foreach (['pcntl','posix'] as $ext) {
            $add('Workers', 'PHP '.$ext, extension_loaded($ext) ? 'Ready' : 'Missing', 'Required in PHP CLI for background collectors.');
        }
    }
    foreach ($manifest['features'] as $feature => $spec) {
        foreach ($spec['binaries'] as $label => $names) {
            $path = icct_nms_installation_binary($names, !in_array($label, $spec['root_binaries'] ?? [], true));
            if (!$path && in_array($label, $spec['root_binaries'] ?? [], true)) {
                $path = $saved['binaries'][$feature][$label] ?? '';
            }
            $present = $path ?: icct_nms_installation_binary($names, false);
            $add(
                $feature,
                $label,
                $path ? 'Ready' : ($present ? 'Needs setup' : 'Missing'),
                $path ?: ($present ? $present.' · installed; check execution permissions for the collector.' : 'Install ' . implode(' / ', (array) $names) . ' on this collector.'),
            );
            if ($present) {
                $checks[count($checks) - 1]['installed'] = true;
            }
        }
        foreach ($spec['services'] as $unit) {
            $state = icct_nms_installation_service($unit);
            $installed = PHP_SAPI === 'cli' ? icct_nms_installation_unit_exists($unit) : ($saved['installed_services'][$unit] ?? null);
            $observed = '';
            if ($state === 'Not checked' && isset($saved['services'][$unit])) {
                $state = $saved['services'][$unit];
                $observed = ' · collector check '.date('Y-m-d H:i:s', (int)$saved['time']);
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
            $add($feature, 'Configuration', 'Needs setup', $spec['note']);
        }
    }
    foreach ($manifest['optional_components'] ?? [] as $id => $spec) {
        $observation = PHP_SAPI === 'cli' ? icct_nms_installation_component($id, $spec) : ($saved['components'][$id] ?? []);
        $installed = $observation['installed'] ?? null;
        $add($spec['label'], 'Package', $installed === true ? 'Installed' : ($installed === false ? 'Missing' : 'Not checked'), ($observation['detail'] ?? 'Awaiting local collector check.').' · '.$spec['note']);
        $checks[count($checks)-1]['installed'] = $installed;
        $checks[count($checks)-1]['component'] = $id;
    }
    foreach (['installation/services/checks.php','installation/cli/setup.py','setup.php','shared/services/backend.php'] as $source) {
        $path = dirname(__DIR__, 2).'/'.$source;
        $unsafe = is_file($path) && (fileperms($path) & 0002);
        // Root CLI may write source; the HTTP account must not.
        $webWritable = (PHP_SAPI !== 'cli' || (function_exists('posix_geteuid') && posix_geteuid() !== 0)) && is_writable($path);
        $add('Permissions', $source, !$unsafe && !$webWritable && is_readable($path) ? 'Ready' : 'Needs setup', $unsafe ? 'Source is world-writable; restrict ownership and mode.' : ($webWritable ? 'Web account can modify source; restrict source ownership.' : 'Source readable; no world-write permission.'));
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
    return $checks;
}

function icct_nms_installation_snapshot($raw, $hostname, $now, $maxAge)
{
    $snapshot = json_decode((string)$raw, true);
    if (!is_array($snapshot) || ($snapshot['hostname'] ?? '') !== $hostname || !is_int($snapshot['time'] ?? null) || $snapshot['time'] > $now || $now - $snapshot['time'] > $maxAge || !is_array($snapshot['services'] ?? null)) {
        return [];
    }
    return $snapshot;
}

function icct_nms_installation_unit_exists($unit)
{
    foreach (['/etc/systemd/system','/usr/lib/systemd/system','/lib/systemd/system'] as $directory) {
        if (is_file($directory.'/'.$unit)) {
            return true;
        }
    }
    return false;
}

/** Only missing installable components expose an Install action. */
function icct_nms_installation_action($check)
{
    if (isset($check['component'])) return ($check['installed'] ?? null) === false ? 'component-'.$check['component'] : '';
    if ($check['status'] === 'Ready' || $check['status'] === 'Unavailable' || ($check['installed'] ?? null) === true) {
        return '';
    }
    if (($check['installed'] ?? null) === false) {
        return $check['feature'] === 'SSH' ? 'ssh' : 'packages';
    }
    if ($check['status'] !== 'Missing') {
        return '';
    }
    if ($check['feature'] === 'SSH') {
        return 'ssh';
    }
    if ($check['item'] === 'Pathchar') {
        return 'pathchar';
    }
    if (strpos($check['detail'], 'Bundled plugin asset') !== false) {
        return 'plugin';
    }
    if ($check['item'] === 'Plugin database') {
        return 'plugin';
    }
    if (in_array($check['item'], ['Cacti version','PHP CLI','PHP Web'], true)) {
        return '';
    }
    return 'packages';
}

/** CLI observation avoids granting the PHP web domain system-service control. */
function icct_nms_installation_save_services()
{
    global $config;
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Service observations can only be saved by the local collector.');
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
            $binaries[$feature][$label] = icct_nms_installation_binary($spec['binaries'][$label], false);
        }
    }
    $components = [];
    foreach (icct_nms_installation_manifest()['optional_components'] ?? [] as $id => $spec) $components[$id] = icct_nms_installation_component($id, $spec);
    $snapshot = json_encode(['components' => $components, 'time' => time(),'hostname' => gethostname(),'services' => $services,'installed_services' => $installedServices,'binaries' => $binaries], JSON_THROW_ON_ERROR);
    if (!db_execute_prepared('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()', ['installation_services_'.(int)$config['poller_id'],$snapshot])) {
        throw new RuntimeException('Could not save collector service observations.');
    }
}

/** Local package inventory only; never called from HTTP. */
function icct_nms_installation_component($id, $spec)
{
    if (PHP_SAPI !== 'cli') throw new RuntimeException('Package inventory requires CLI.');
    $family = is_file('/etc/redhat-release') ? 'rhel' : 'debian';
    $names = $spec['binaries'] ?? [];
    if (isset($names[$family])) $names = $names[$family];
    $paths = [];
    foreach ($names as $name) {
        $path = icct_nms_installation_binary([$name], false);
        if ($path) $paths[] = $path;
    }
    // Guacamole's approved custom build must not be replaced by a stock package.
    if ($id === 'guacamole' && $paths) return ['installed'=>true,'detail'=>implode(', ', $paths)];
    $packages = $spec['packages'][$family];
    if (!$packages) return ['installed'=>null,'detail'=>'Approved native build required for this OS.'];
    if (!is_callable('shell_exec') || !icct_nms_installation_binary('timeout')) return ['installed'=>null,'detail'=>'Package inventory unavailable.'];
    $manager = icct_nms_installation_binary($family === 'rhel' ? 'rpm' : 'dpkg-query');
    if (!$manager) return ['installed'=>null,'detail'=>'Package manager unavailable.'];
    $found = [];
    foreach ($packages as $package) {
        $arguments = $family === 'rhel' ? ' -q --qf '.escapeshellarg('%{NAME} %{VERSION}-%{RELEASE}').' -- ' : ' -W -f='.escapeshellarg('${db:Status-Status} ${Version}').' -- ';
        $value = trim((string)shell_exec(escapeshellarg(icct_nms_installation_binary('timeout')).' 3s '.escapeshellarg($manager).$arguments.escapeshellarg($package).' 2>/dev/null'));
        if ($family === 'rhel' ? strpos($value, $package.' ') === 0 : strpos($value, 'installed ') === 0) $found[] = $package.': '.$value;
    }
    return ['installed'=>count($found) === count($packages),'detail'=>$found ? implode('; ', $found) : 'Not installed.'];
}
