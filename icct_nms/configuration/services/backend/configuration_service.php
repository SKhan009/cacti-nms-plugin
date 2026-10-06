<?php
/** ICCT-owned configuration service services, derived from the existing ICCT NMS implementation. */

/** Reused Inventory service: serial assign. */
function icct_backend_serial_assign($host_id, array $input)
{
    return icct_backend_serial_mutation(function () use ($host_id, $input) {
        return icct_backend_serial_assign_locked($host_id, $input);
    });
}

/** Reused Inventory service: serial assign locked. */
function icct_backend_serial_assign_locked($host_id, array $input)
{
    $host_id = icct_backend_config_integer($host_id, 1, 16777215, 'Device ID');
    $connection_id = icct_backend_config_integer(
        $input['connection_id'] ?? '',
        0,
        2147483647,
        'Connection ID'
    );
    $revision = icct_backend_config_integer(
        $input['assignment_revision'] ?? '',
        0,
        2147483647,
        'Assignment revision'
    );
    icct_backend_require_device_access($host_id);
    $host = db_fetch_row_prepared(
        "SELECT id,poller_id,disabled FROM host WHERE id=? AND deleted='' FOR UPDATE",
        [$host_id]
    );
    if (!$host) {
        throw new InvalidArgumentException('Device no longer exists.');
    }
    $old = db_fetch_row_prepared(
        'SELECT * FROM plugin_icct_nms_serial_devices WHERE host_id=? FOR UPDATE',
        [$host_id]
    );
    if ((int) ($old['revision'] ?? 0) !== $revision) {
        throw new RuntimeException('Device connection changed. Reload before saving.');
    }
    if (!$connection_id) {
        icct_backend_category_execute('DELETE FROM plugin_icct_nms_meta WHERE meta_key=?',['serial_settings_'.(int)$host_id]);
        icct_backend_category_execute(
            'DELETE FROM plugin_icct_nms_serial_devices WHERE host_id=?',
            [$host_id]
        );
        return;
    }
    $connection = icct_backend_serial_connection_get($connection_id);
    if ((int) $connection['poller_id'] !== (int) $host['poller_id']) {
        throw new InvalidArgumentException(
            'Connection must use the device’s assigned Cacti collector.'
        );
    }
    if (!$connection['enabled']) {
        throw new InvalidArgumentException('Connection is disabled.');
    }
    $address = icct_backend_serial_device_address($input['device_address'] ?? '');
    if (
        db_fetch_cell_prepared(
            'SELECT host_id FROM plugin_icct_nms_serial_devices WHERE connection_id=? AND device_address=? AND host_id<>?',
            [$connection_id, $address, $host_id]
        )
    ) {
        throw new InvalidArgumentException('This address is already used on the connection.');
    }
    icct_backend_category_execute(
        'INSERT INTO plugin_icct_nms_serial_devices (host_id,connection_id,device_address,revision,updated_by,updated_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE connection_id=VALUES(connection_id),device_address=VALUES(device_address),revision=VALUES(revision),updated_by=VALUES(updated_by),updated_at=NOW()',
        [$host_id, $connection_id, $address, $revision + 1, icct_backend_current_user_id()]
    );
}

/** Reused Inventory service: serial assignment. */
function icct_backend_serial_assignment($host_id)
{
    icct_backend_require_device_access($host_id);
    $assignment=db_fetch_row_prepared(
        'SELECT d.*,c.name AS connection_name,c.poller_id,c.transport,c.endpoint,c.enabled,c.settings_json FROM plugin_icct_nms_serial_devices d JOIN plugin_icct_nms_serial_connections c ON c.id=d.connection_id WHERE d.host_id=?',
        [(int)$host_id]
    );
    if ($assignment) $assignment['settings_json']=json_encode(icct_backend_serial_device_settings($host_id,$assignment['connection_id'],json_decode($assignment['settings_json'],true,32,JSON_THROW_ON_ERROR)),JSON_THROW_ON_ERROR);
    return $assignment;
}
/** A device's private settings apply only to the connection they were saved against. */
function icct_backend_serial_device_settings($host_id,$connection_id,$fallback)
{
    $json=db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['serial_settings_'.(int)$host_id]);
    if (!$json) return $fallback;
    $snapshot=json_decode($json,true,32,JSON_THROW_ON_ERROR);
    if ((int)($snapshot['connection_id'] ?? 0)!==(int)$connection_id) return $fallback;
    if (!is_array($snapshot['settings'] ?? null)) throw new RuntimeException('Invalid device serial settings.');
    return $snapshot['settings'];
}

/** Reused Inventory service: serial connection get. */
function icct_backend_serial_connection_get($id)
{
    icct_backend_require_management();
    $id = icct_backend_config_integer($id, 1, 2147483647, 'Connection ID');
    $row = db_fetch_row_prepared('SELECT * FROM plugin_icct_nms_serial_connections WHERE id=?', [
        $id
    ]);
    if (!$row) {
        throw new InvalidArgumentException('Serial connection not found.');
    }
    foreach (
        db_fetch_assoc_prepared(
            "SELECT d.host_id FROM plugin_icct_nms_serial_devices d JOIN host h ON h.id=d.host_id WHERE d.connection_id=? AND h.deleted=''",
            [$id]
        )
        as $member
    ) {
        icct_backend_require_device_access((int) $member['host_id']);
    }
    $row['settings'] = json_decode($row['settings_json'], true, 32, JSON_THROW_ON_ERROR);
    return $row;
}

/** Reused Inventory service: serial connections. */
function icct_backend_serial_connections($collector = 0)
{
    icct_backend_require_management();
    $rows =
        (int) $collector > 0
            ? db_fetch_assoc_prepared(
                'SELECT id FROM plugin_icct_nms_serial_connections WHERE poller_id=? ORDER BY name',
                [(int) $collector]
            )
            : db_fetch_assoc('SELECT id FROM plugin_icct_nms_serial_connections ORDER BY name');
    $result = [];
    foreach ($rows as $row) {
        try {
            $result[] = icct_backend_serial_connection_get($row['id']);
        } catch (RuntimeException $e) {
            continue;
        } // Hide connections with inaccessible members.
    }
    return $result;
}

/** Reused Inventory service: serial mutation. */
function icct_backend_serial_mutation(callable $operation)
{
    icct_backend_require_management();
    $key =
        'icct_backend_serial_' .
        substr(hash('sha256', (string) db_fetch_cell('SELECT DATABASE()')), 0, 32);
    if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?,10)', [$key]) !== 1) {
        throw new RuntimeException('Serial settings are busy. Retry shortly.');
    }
    try {
        icct_backend_category_execute('START TRANSACTION');
        try {
            $result = $operation();
            icct_backend_category_execute('COMMIT');
            return $result;
        } catch (Throwable $e) {
            db_execute('ROLLBACK');
            throw $e;
        }
    } finally {
        db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$key]);
    }
}
