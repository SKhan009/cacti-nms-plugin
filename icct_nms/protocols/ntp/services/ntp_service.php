<?php

/** Read-only chrony monitoring. Presets seed drafts; saved assignments are independent. */
function icct_nms_ntp_fields()
{
    return ['method', 'timeout', 'interval_seconds', 'stale_seconds'];
}

function icct_nms_ntp_methods()
{
    return ['local' => 'Local collector chrony', 'ssh' => 'Device chrony over verified SSH'];
}

function icct_nms_ntp_validate($input)
{
    foreach (icct_nms_ntp_fields() as $field) {
        if (!array_key_exists($field, $input) || !is_scalar($input[$field])) {
            throw new InvalidArgumentException('Missing or invalid NTP field: ' . $field);
        }
    }
    $values = array_intersect_key($input, array_flip(icct_nms_ntp_fields()));
    if (!isset(icct_nms_ntp_methods()[$values['method']])) {
        throw new InvalidArgumentException('Choose an NTP collection method.');
    }
    foreach (
        ['timeout' => [1, 30], 'interval_seconds' => [60, 86400], 'stale_seconds' => [60, 604800]]
        as $field => $range
    ) {
        $values[$field] = icct_backend_topology_integer(
            $values[$field],
            $range[0],
            $range[1],
            'NTP ' . $field,
        );
    }
    $cadence = (int) read_config_option('poller_interval');
    if ($cadence < 1 || $values['interval_seconds'] % $cadence !== 0) {
        throw new InvalidArgumentException(
            'NTP collection interval must be a multiple of the Cacti poller interval.',
        );
    }
    if ($values['stale_seconds'] < $values['interval_seconds'] + $values['timeout']) {
        throw new InvalidArgumentException(
            'NTP stale interval must cover the collection interval and timeout.',
        );
    }
    return $values;
}

function icct_nms_ntp_ready()
{
    return db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?', [
        'icct_nms_ntp_monitor_schema_version',
    ]) === '1';
}

function icct_nms_ntp_assignment($id)
{
    if (!$id || !icct_nms_ntp_ready()) {
        return [];
    }
    return db_fetch_row_prepared(
        'SELECT d.*,h.hostname,h.poller_id,h.disabled,h.deleted FROM plugin_icct_nms_ntp_monitors d ' .
            'JOIN host h ON h.id=d.host_id WHERE d.host_id=? AND h.deleted=\'\'',
        [(int) $id],
    );
}

/** Local collection must never relabel the collector's clock as an unrelated device's clock. */
function icct_nms_ntp_local_device($device)
{
    return in_array(strtolower($device['hostname']), ['localhost', '127.0.0.1', '::1'], true);
}

function icct_nms_ntp_save($id, $input)
{
    icct_backend_require_management(3);
    icct_backend_require_device_access($id);
    if (!icct_nms_ntp_ready()) {
        throw new RuntimeException(
            'Upgrade ICCT NMS through Plugin Management before configuring NTP.',
        );
    }
    $values = icct_nms_ntp_validate($input);
    $existing = icct_nms_ntp_assignment($id);
    $host = db_fetch_row_prepared(
        'SELECT hostname,poller_id FROM host WHERE id=? AND deleted=\'\'',
        [(int) $id],
    );
    if (!$host) {
        throw new RuntimeException('Existing inventory device required for NTP.');
    }
    if ($values['method'] === 'local' && !icct_nms_ntp_local_device($host)) {
        throw new InvalidArgumentException(
            'Local chrony monitoring is restricted to an explicit localhost device. Use verified SSH for remote devices.',
        );
    }
    if ($values['method'] === 'ssh') {
        $ssh = icct_backend_ssh_device($id);
        if (!$ssh['host_key'] || $ssh['verified_endpoint'] !== $ssh['endpoint']) {
            throw new InvalidArgumentException(
                'Configure SSH and approve this device host key before assigning NTP over SSH.',
            );
        }
    }
    $lock = 'icct_ntp_' . (int) $id;
    if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?,5)', [$lock]) !== 1) {
        throw new RuntimeException('NTP collection is busy. Retry saving the settings.');
    }
    try {
        icct_backend_category_execute('START TRANSACTION');
        try {
            icct_backend_category_execute(
                'INSERT INTO plugin_icct_nms_ntp_monitors(host_id,method,timeout,interval_seconds,stale_seconds,revision,updated_by,updated_at) ' .
                    'VALUES(?,?,?,?,?,1,?,NOW()) ON DUPLICATE KEY UPDATE method=VALUES(method),timeout=VALUES(timeout),' .
                    'interval_seconds=VALUES(interval_seconds),stale_seconds=VALUES(stale_seconds),revision=revision+1,updated_by=VALUES(updated_by),updated_at=NOW()',
                [
                    (int) $id,
                    $values['method'],
                    $values['timeout'],
                    $values['interval_seconds'],
                    $values['stale_seconds'],
                    icct_backend_current_user_id(),
                ],
            );
            if (!$existing) {
                require_once __DIR__ . '/../../../fcaps/services/ntp_fault_service.php';
                icct_nms_ntp_fault_assign_defaults($id);
            }
            icct_backend_category_execute(
                'DELETE FROM plugin_icct_nms_ntp_monitor_state WHERE host_id=?',
                [(int) $id],
            );
            icct_backend_category_execute('COMMIT');
        } catch (Throwable $error) {
            icct_backend_category_execute('ROLLBACK');
            throw $error;
        }
    } finally {
        db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}

/** Bind observations to the current endpoint, collector, assignment and SSH identity revisions. */
function icct_nms_ntp_hash($device)
{
    $values = array_intersect_key(
        $device,
        array_flip(
            array_merge(icct_nms_ntp_fields(), [
                'host_id',
                'hostname',
                'poller_id',
                'disabled',
                'deleted',
                'revision',
            ]),
        ),
    );
    $values['measurement_version'] = 2;
    if ($device['method'] === 'ssh') {
        $ssh = icct_backend_ssh_device($device['host_id']);
        foreach (['endpoint', 'preset_revision', 'device_revision', 'host_key'] as $field) {
            $values['ssh_' . $field] = $ssh[$field];
        }
    }
    return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
}

/** Keep stale/error data out of current metrics; historical samples remain explicitly historical. */
function icct_nms_ntp_current($id)
{
    $device = icct_nms_ntp_assignment($id);
    $out = [
        'status' => 'Not configured',
        'error' => '',
        'sample' => null,
        'last_attempt' => null,
        'last_success' => null,
        'attempted_epoch' => null,
    ];
    if (!$device) {
        return $out;
    }
    if ($device['disabled'] !== '' || !icct_backend_protocol_enabled($id, 'ntp')) {
        return array_replace($out, ['status' => 'Disabled']);
    }
    $state = db_fetch_row_prepared(
        'SELECT *,UNIX_TIMESTAMP(last_attempt) AS attempted_epoch FROM plugin_icct_nms_ntp_monitor_state WHERE host_id=?',
        [(int) $id],
    );
    if (!$state) {
        return array_replace($out, ['status' => 'Not collected']);
    }
    $out['last_attempt'] = $state['last_attempt'];
    $out['last_success'] = $state['last_success'];
    $out['attempted_epoch'] = (int) $state['attempted_epoch'];
    try {
        if (!hash_equals($state['config_hash'], icct_nms_ntp_hash($device))) {
            return array_replace($out, [
                'status' => 'Settings changed',
                'error' => 'Waiting for a reading with the current device settings.',
            ]);
        }
    } catch (Throwable $error) {
        return array_replace($out, ['status' => 'Unavailable', 'error' => $error->getMessage()]);
    }
    $age = time() - (int) $state['attempted_epoch'];
    if ($age < 0 || $age > (int) $device['stale_seconds']) {
        return array_replace($out, [
            'status' => 'Stale',
            'error' => 'No current NTP observation from the assigned collector.',
        ]);
    }
    return array_replace($out, [
        'status' => $state['status'],
        'error' => $state['last_error'],
        'sample' =>
            $state['sample_json'] === null
                ? null
                : json_decode($state['sample_json'], true, 16, JSON_THROW_ON_ERROR),
    ]);
}
