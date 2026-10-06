<?php
/** ICCT-owned configuration monitoring services, derived from the existing ICCT NMS implementation. */

/** Reused Inventory service: config connection status. */
function icct_backend_config_connection_status($host_id)
{
    icct_backend_require_device_access($host_id);
    $serial = db_fetch_row_prepared(
        'SELECT c.transport,c.endpoint,d.device_address FROM plugin_icct_nms_serial_devices d LEFT JOIN plugin_icct_nms_serial_connections c ON c.id=d.connection_id WHERE d.host_id=?',
        [$host_id]
    );
    if (!$serial) {
        return null;
    }
    $label =
        ($serial['transport'] === 'direct' ? 'Serial port' : 'Serial gateway') .
        ' · ' .
        $serial['endpoint'] .
        ' · Address ' .
        $serial['device_address'];
    try {
        $target = icct_backend_config_target($host_id, false);
        $states = [];
        foreach ($target['fields'] as $key => $field) {
            $states[] = icct_backend_config_reading($host_id, $key)['status'];
        }
        $status = !$states ? 'Unavailable' : (in_array('failed', $states, true)
            ? 'Read failed'
            : (in_array('unavailable', $states, true)
                ? 'Unavailable'
                : (in_array('stale', $states, true)
                    ? 'Stale'
                    : 'Responding')));
    } catch (Throwable $e) {
        $status = 'Unavailable';
    }
    return ['connection' => $label, 'status' => $status];
}

/** Reused Inventory service: config monitor once. */
function icct_backend_config_monitor_once($collector)
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Scheduled collection is CLI-only.');
    }
    $devices = db_fetch_assoc_prepared(
        "SELECT a.host_id,a.updated_by FROM plugin_icct_nms_config_devices a JOIN host h ON h.id=a.host_id JOIN poller p ON p.id=h.poller_id LEFT JOIN plugin_icct_nms_serial_readings r ON r.host_id=a.host_id WHERE h.poller_id=? AND h.deleted='' AND h.disabled='' AND p.disabled='' GROUP BY a.host_id,a.updated_by ORDER BY MIN(r.observed_at),a.host_id",
        [$collector]
    );
    $previous = $_SESSION ?? [];
    try {
        foreach ($devices as $device) {
            try {
                // Monitoring retains the assigning operator's device scope; revoked access stops reads.
                icct_backend_config_job_authorize([
                    'host_id' => $device['host_id'],
                    'user_id' => $device['updated_by']
                ]);
                $_SESSION = ['sess_user_id' => (int) $device['updated_by']];
                $done = icct_backend_serial_mutation(function () use ($device, $collector) {
                    $target = icct_backend_config_target($device['host_id']);
                    if ((int) $target['host']['poller_id'] !== (int) $collector) {
                        return false;
                    }
                    foreach ($target['fields'] as $key => $field) {
                        $row = db_fetch_row_prepared(
                            'SELECT signature,TIMESTAMPDIFF(SECOND,observed_at,NOW()) AS age_seconds FROM plugin_icct_nms_serial_readings WHERE host_id=? AND field_key=?',
                            [$device['host_id'], $key]
                        );
                        if (
                            $row &&
                            hash_equals($target['signature'], $row['signature']) &&
                            (int) $row['age_seconds'] >= 0 &&
                            (int) $row['age_seconds'] <
                                (int) $target['assignment']['interval_seconds']
                        ) {
                            continue;
                        }
                        try {
                            $result =
                                $target['profile']['protocol'] === 'modbus_rtu'
                                    ? icct_backend_config_serial_execute($target, $field, 'read')
                                    : icct_backend_config_snmp_execute($target, $field, 'read');
                        } catch (Throwable $e) {
                            $result = [
                                'status' => 'failed',
                                'error' =>
                                    'Collector read failed. Check connection settings and collector logs.'
                            ];
                        }
                        icct_backend_config_store_reading($target, $key, $result);
                        return true;
                    }
                    return false;
                });
                if ($done) {
                    return true;
                }
            } catch (Throwable $e) {
                /* Ineligible assignments remain unavailable; never fall back to another collector. */
            }
        }
    } finally {
        $_SESSION = $previous;
    }
    return false;
}

/** Reused Inventory service: config reading. */
function icct_backend_config_reading($host_id, $key, $authorize = true)
{
    if ($authorize) {
        icct_backend_require_device_access($host_id);
    }
    try {
        $target = icct_backend_config_target($host_id, false);
    } catch (Throwable $e) {
        return ['status' => 'unavailable', 'value' => null, 'observed_at' => null];
    }
    if (!isset($target['fields'][$key])) {
        return ['status' => 'unavailable', 'value' => null, 'observed_at' => null];
    }
    $row = db_fetch_row_prepared(
        'SELECT *,TIMESTAMPDIFF(SECOND,observed_at,NOW()) AS age_seconds FROM plugin_icct_nms_serial_readings WHERE host_id=? AND field_key=?',
        [$host_id, $key]
    );
    if (!$row || !hash_equals($target['signature'], $row['signature'])) {
        return ['status' => 'unavailable', 'value' => null, 'observed_at' => null];
    }
    $status = $row['status'] === 'complete' ? 'current' : 'failed';
    if (
        (int) $row['age_seconds'] < 0 ||
        (int) $row['age_seconds'] > max(120, 2 * (int) $target['assignment']['interval_seconds'])
    ) {
        $status = 'stale';
    }
    return [
        'status' => $status,
        'value' => $status === 'current' ? json_decode($row['value_json'], true) : null,
        'observed_at' => $row['observed_at']
    ];
}

/** Reused Inventory service: config store reading. */
function icct_backend_config_store_reading(array $target, $key, array $result)
{
    $status = ($result['status'] ?? '') === 'read' ? 'complete' : 'failed';
    icct_backend_category_execute(
        'INSERT INTO plugin_icct_nms_serial_readings(host_id,field_key,signature,value_json,status,error_text,observed_at) VALUES (?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE signature=VALUES(signature),value_json=VALUES(value_json),status=VALUES(status),error_text=VALUES(error_text),observed_at=NOW()',
        [
            $target['host']['id'],
            $key,
            $target['signature'],
            json_encode($result['value'] ?? null, JSON_THROW_ON_ERROR),
            $status,
            substr($result['error'] ?? '', 0, 512)
        ]
    );
}
