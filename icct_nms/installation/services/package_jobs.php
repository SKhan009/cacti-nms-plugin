<?php

/** The web process can submit a fixed request and read progress from the local RPM runner. */
const ICCT_NMS_PACKAGE_STATE = '/var/lib/icct-nms-package-runner';

function icct_nms_package_status($root)
{
    if (PHP_OS_FAMILY !== 'Linux') {
        return ['available' => false, 'message' => 'Package management is available on a configured RHEL collector.'];
    }

    $path = ICCT_NMS_PACKAGE_STATE . '/public/status.json';
    $raw = is_readable($path) ? file_get_contents($path) : false;
    $status = json_decode((string) $raw, true);
    if (!is_array($status) || ($status['root'] ?? '') !== realpath($root)) {
        return ['available' => false, 'message' => 'The offline package runner has not been provisioned for this Cacti installation.'];
    }
    unset($status['root']);

    $running = ($status['job']['status'] ?? '') === 'running';
    $status['available'] = (time() - $status['time']) < ($running ? 930 : 40)
        && is_writable(ICCT_NMS_PACKAGE_STATE . '/requests');
    $status['message'] = $status['available']
        ? 'Offline packages are ready on this collector.'
        : 'The local package runner is unavailable. Check its timer and request directory.';
    return $status;
}

function icct_nms_package_submit($root, $action, $target, $preview = '')
{
    $status = icct_nms_package_status($root);
    if (!$status['available'] || ($status['job']['status'] ?? '') === 'running') {
        throw new RuntimeException('The package runner is busy or unavailable.');
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
    $valid = $action === 'verify'
        ? $target === 'media'
        : in_array($action, ['install', 'uninstall', 'preview-uninstall'], true) && in_array($target, $targets, true);
    if (!$valid || ($action === 'uninstall' && (!is_string($preview) || !preg_match('/^[a-f0-9]{32}$/D', $preview)))) {
        throw new InvalidArgumentException('Choose an available package action.');
    }

    $id = bin2hex(random_bytes(16));
    $directory = ICCT_NMS_PACKAGE_STATE . '/requests';
    $path = $directory . '/' . $id . '.tmp';
    $data = json_encode(compact('id', 'action', 'target') + ($action === 'uninstall' ? ['preview' => $preview] : []), JSON_THROW_ON_ERROR);
    $stream = @fopen($path, 'xb');
    if ($stream === false) {
        throw new RuntimeException('Could not write the local package request.');
    }
    try {
        $written = fwrite($stream, $data);
        fclose($stream);
        if ($written !== strlen($data) || !@link($path, $directory . '/pending.json')) {
            throw new RuntimeException('Could not queue the package request. Another request may be pending.');
        }
    } finally {
        @unlink($path);
    }
    return $id;
}
