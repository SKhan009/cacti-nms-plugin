<?php
/** ICCT-owned device metadata services, derived from the existing ICCT NMS implementation. */

/** Reused Inventory service: identity manual save. */
function icct_backend_identity_manual_save($host_id, $values)
{
    $values = icct_backend_identity_manual_validate($values);
    if (!$values) {
        return;
    }
    foreach ($values as $key => $value) {
        $column = substr($key, 7); // Keys are restricted by the validator.
        if (
            db_execute_prepared(
                'INSERT INTO plugin_icct_nms_device_metadata (host_id,' .
                    $column .
                    ',updated_by,updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE ' .
                    $column .
                    '=VALUES(' .
                    $column .
                    '),updated_by=VALUES(updated_by),updated_at=NOW()',
                [(int) $host_id, $value, icct_backend_current_user_id()]
            ) === false
        ) {
            throw new RuntimeException(
                'Device saved but manual identity could not be saved. Reopen this device and retry.'
            );
        }
    }
}

/** Reused Inventory service: identity manual validate. */
function icct_backend_identity_manual_validate($input)
{
    $out = [];
    foreach (['manual_chassis_id', 'manual_mac_address', 'manual_port_count'] as $key) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $value = icct_backend_manual_serial_validate($input[$key]);
        if ($key === 'manual_mac_address' && $value !== '') {
            if (!preg_match('/^(?:[0-9a-f]{2}[:-]){5}[0-9a-f]{2}$/iD', $value)) {
                throw new InvalidArgumentException(
                    'MAC address must contain six hexadecimal pairs, for example 02:00:00:00:00:01.'
                );
            }
            $value = strtolower(str_replace('-', ':', $value));
            if ($value === '00:00:00:00:00:00' || hexdec(substr($value, 0, 2)) & 1) {
                throw new InvalidArgumentException('Enter a nonzero unicast device MAC address.');
            }
        }
        if (
            $key === 'manual_port_count' &&
            $value !== '' &&
            (!preg_match('/^[0-9]{1,5}$/D', $value) || (int) $value > 65535)
        ) {
            throw new InvalidArgumentException(
                'Physical port count must be blank or an integer from 0 to 65535.'
            );
        }
        $out[$key] = $value;
    }
    return $out;
}

/** Reused Inventory service: manual serial save. */
function icct_backend_manual_serial_save($host_id, $value)
{
    $value = icct_backend_manual_serial_validate($value);
    $host_id = (int) $host_id;
    if (
        $host_id < 1 ||
        !(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM host WHERE id = ? AND deleted = ''", [
            $host_id
        ])
    ) {
        throw new InvalidArgumentException(
            'Select an existing Cacti device before saving its serial number.'
        );
    }
    if ($value === '') {
        $saved = db_execute_prepared(
            "UPDATE plugin_icct_nms_device_metadata SET serial_number='' WHERE host_id = ?",
            [$host_id]
        );
    } else {
        $saved = db_execute_prepared(
            'INSERT INTO plugin_icct_nms_device_metadata
			(host_id, serial_number, updated_by, updated_at) VALUES (?, ?, ?, NOW())
			ON DUPLICATE KEY UPDATE serial_number = VALUES(serial_number), updated_by = VALUES(updated_by), updated_at = NOW()',
            [$host_id, $value, icct_backend_current_user_id()]
        );
    }
    if ($saved === false) {
        throw new RuntimeException('ICCT NMS could not save the manual serial number. Please retry.');
    }
    return $value;
}

/** Reused Inventory service: manual serial validate. */
function icct_backend_manual_serial_validate($value)
{
    if (
        !is_string($value) ||
        preg_match('//u', $value) !== 1 ||
        preg_match('/[\x00-\x1F\x7F]/', $value)
    ) {
        throw new InvalidArgumentException('Enter a serial number as a single line of text.');
    }
    $value = trim($value);
    if (strlen($value) > 191) {
        throw new InvalidArgumentException('Serial number must be 191 bytes or fewer.');
    }
    return $value;
}

/** Reused Inventory service: shared endpoint get. */
function icct_backend_shared_endpoint_get($host_id)
{
    return (int) db_fetch_cell_prepared(
        'SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
        ['device_shared_endpoint_' . (int) $host_id]
    ) === 1;
}

/** Reused Inventory service: short name get. */
function icct_backend_short_name_get($id)
{
    return substr(
        (string) db_fetch_cell_prepared(
            'SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
            ['device_short_name_' . (int) $id]
        ),
        0,
        8
    );
}

/** Reused Inventory service: short name save. */
function icct_backend_short_name_save($id, $value)
{
    icct_backend_require_device_access((int) $id);
    $value = icct_backend_short_name_validate($value);
    icct_backend_category_execute(
        'REPLACE INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES (?,?,NOW())',
        ['device_short_name_' . (int) $id, $value]
    );
}

/** Reused Inventory service: short name validate. */
function icct_backend_short_name_validate($value)
{
    if (
        !is_string($value) ||
        strlen($value) > 8 ||
        ($value !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9 _-]*$/D', $value))
    ) {
        throw new InvalidArgumentException(
            'Short name must be at most 8 characters: letters, numbers, spaces, hyphens or underscores. Leave blank to generate it from the device name.'
        );
    }
    return trim($value);
}

/** Reused Inventory service: single topology site. */
function icct_backend_single_topology_site()
{
    return (int) db_fetch_cell_prepared(
        'SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
        ['topology_site_id']
    );
}

/** Derive an editable, eight-character ASCII short name from the device's words. */
function icct_backend_short_name_generate($name, $type = '', $id = 0)
{
    preg_match_all('/[A-Za-z0-9]+/', (string) $name, $matches);
    $words = $matches[0];
    if (!$words) return 'DEVICE';
    $short = count($words) === 1 ? $words[0] : implode('', array_map(function ($word) { return $word[0]; }, $words));
    $type = strtolower(trim((string)$type));
    if (preg_match('/core.*(?:switch|sw)|(?:switch|sw).*core/i', (string)$name)) return 'CORE SW';
    if (preg_match('/core.*(?:switch|sw)|(?:switch|sw).*core/i', $type)) return substr('CSW-'.($id ? (string)(int)$id : strtoupper($short)),0,8);
    $prefix = preg_match('/switch|\bsw\b/', $type) ? 'SW' : (preg_match('/router/', $type) ? 'RTR' : (preg_match('/server/', $type) ? 'SRV' : (preg_match('/sensor/', $type) ? 'SNS' : '')));
    if ($prefix !== '') return substr($prefix.'-'.($id ? (string)(int)$id : strtoupper($short)),0,8);
    return strtoupper(substr($short, 0, 8));
}
