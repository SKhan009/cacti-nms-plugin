<?php

require_once __DIR__ . '/checks.php';

/** The web process can submit a fixed request and read progress from the local RPM runner. */
const ICCT_NMS_PACKAGE_STATE = '/var/lib/icct-nms-package-runner';

/** Package actions share the manifest used by the privileged RHEL runner. */
function icct_nms_installation_package_catalog(array $manifest): array
{
    $packages = [];
    foreach ($manifest['rhel_package_groups'] ?? [] as $id => $group) {
        $packages[$id] = [
            'label' => $group['label'],
            'packages' => ['rhel' => $group['packages']],
            'detail' => $group['detail'],
            'category' => $group['category'],
            'version_profile' => 'base',
        ];
    }
    foreach ($manifest['optional_components'] as $id => $component) {
        $packages[$id] = $component + ['detail' => $component['note']];
    }
    $categories = array_flip([
        'Cacti framework requirements', 'Plugin protocols and diagnostics', 'Optional services',
    ]);
    uasort($packages, static fn($left, $right) =>
        $categories[$left['category']] <=> $categories[$right['category']]);
    return $packages;
}

function icct_nms_package_status($root)
{
    if (PHP_OS_FAMILY !== 'Linux') {
        return [
            'available' => false,
            'message' => 'Package management is available on a configured RHEL collector.',
        ];
    }

    $root = realpath($root) ?: $root;
    $account = function_exists('posix_geteuid') && posix_geteuid() !== 0
        ? posix_getpwuid(posix_geteuid()) : false;
    $python = icct_nms_installation_binary('python3');
    $provision = $account && $python ? 'sudo ' . escapeshellarg($python) . ' ' .
        escapeshellarg($root . '/plugins/icct_nms/installation/cli/package_runner.py') .
        ' provision --cacti-root ' . escapeshellarg($root) . ' --web-user ' .
        escapeshellarg($account['name']) . ' --no-packages' : null;
    $runnerCheck = [
        'next_step' => 'On the RHEL collector, check the timer and the latest runner messages for the startup or media error.',
        'command' => "systemctl status icct-nms-package-runner.timer\nsudo journalctl -u icct-nms-package-runner.service -n 30 --no-pager",
    ];
    $path = ICCT_NMS_PACKAGE_STATE . '/public/status.json';
    if (!is_dir(ICCT_NMS_PACKAGE_STATE)) {
        return [
            'available' => false,
            'message' => 'Offline Packages is unavailable because the runner has not been set up on this collector.',
            'next_step' => 'Use complete offline media for this RHEL release and CPU architecture. The complete installer sets up the runner. With prerequisites already installed, the command below enables local inventory and Syslog port management even without RPM media. The command uses the current PHP service account and discovered Python executable. If it is unavailable, run provisioning from the command line with the actual PHP account and interpreter.',
            'command' => $provision,
        ];
    }
    $raw = is_readable($path) ? file_get_contents($path) : false;
    $status = json_decode((string) $raw, true);
    if (!is_array($status) || !isset($status['root'], $status['time'])) {
        return [
            'available' => false,
            'message' => 'Offline Packages cannot read a valid status from the local runner.',
        ] + $runnerCheck;
    }
    if ($status['root'] !== $root) {
        return [
            'available' => false,
            'message' => 'The Offline Packages runner is configured for a different Cacti installation.',
            'next_step' => 'If this is the Cacti installation the runner should serve, reprovision it with the command below. Use the account that serves Cacti’s PHP requests.',
            'command' => $provision,
        ];
    }
    unset($status['root']);

    $running = ($status['job']['status'] ?? '') === 'running';
    $age = time() - (int) ($status['time'] ?? 0);
    $fresh = $age >= 0 && $age < ($running ? 930 : 40);
    $writable = is_writable(ICCT_NMS_PACKAGE_STATE . '/requests');
    $status['available'] = $fresh && $writable;
    $status['inventory_available'] = $fresh && isset($status['installed']);
    $status['syslog_available'] = $status['available'] && isset($status['syslog_port']);
    if (!$fresh) {
        $status['message'] = 'The Offline Packages runner has stopped reporting its status.';
        $status += $runnerCheck;
    } elseif (!$writable) {
        $status['message'] = 'The PHP service account cannot submit requests to the Offline Packages runner.';
        $status['next_step'] = 'Reprovision the runner using the account that serves Cacti’s PHP requests. The command uses the current PHP service account and discovered Python executable.';
        $status['command'] = $provision;
    } elseif (!empty($status['syslog_only'])) {
        $status['available'] = false;
        $status['message'] = 'Offline RPM media is unavailable. Service readiness is shown under Checks.';
    } else {
        $status['message'] = '';
    }
    return $status;
}

function icct_nms_valid_syslog_port($port)
{
    return is_string($port) && preg_match('/^[1-9][0-9]{0,4}$/D', $port) && (int) $port <= 65535;
}

function icct_nms_package_submit($root, $action, $target, $preview = '')
{
    $status = icct_nms_package_status($root);
    if (!($action === 'syslog-port' ? ($status['syslog_available'] ?? false) : $status['available'])) {
        throw new RuntimeException($status['message']);
    }
    if (($status['job']['status'] ?? '') === 'running') {
        throw new RuntimeException('The package runner is already processing a request.');
    }
    $manifest = icct_nms_installation_manifest();
    $targets = array_keys($manifest['optional_components']);
    $targets = array_merge($targets, array_keys($manifest['rhel_package_groups'] ?? []));
    $targets[] = 'base';
    $rpmNames = $manifest['packages']['rhel'];
    foreach ($manifest['optional_components'] as $component) {
        $rpmNames = array_merge($rpmNames, $component['packages']['rhel']);
    }
    foreach (array_unique($rpmNames) as $package) {
        $targets[] = 'rpm:' . $package;
    }
    $valid =
        $action === 'verify'
            ? $target === 'media'
            : in_array($action, ['install', 'uninstall', 'preview-uninstall'], true) &&
                in_array($target, $targets, true);
    if ($action === 'syslog-port') {
        $valid = isset($status['syslog_port']) && icct_nms_valid_syslog_port($target);
    }
    if (
        !$valid ||
        ($action === 'uninstall' &&
            (!is_string($preview) || !preg_match('/^[a-f0-9]{32}$/D', $preview)))
    ) {
        throw new InvalidArgumentException('Choose an available package action.');
    }

    $id = bin2hex(random_bytes(16));
    $directory = ICCT_NMS_PACKAGE_STATE . '/requests';
    $path = $directory . '/' . $id . '.tmp';
    $data = json_encode(
        compact('id', 'action', 'target') +
            ($action === 'uninstall' ? ['preview' => $preview] : []),
        JSON_THROW_ON_ERROR,
    );
    $stream = @fopen($path, 'xb');
    if ($stream === false) {
        throw new RuntimeException('Could not write the local package request.');
    }
    try {
        $written = fwrite($stream, $data);
        fclose($stream);
        if ($written !== strlen($data) || !@link($path, $directory . '/pending.json')) {
            throw new RuntimeException(
                'Could not queue the package request. Another request may be pending.',
            );
        }
    } finally {
        @unlink($path);
    }
    return $id;
}
