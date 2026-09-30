<?php
/** ICCT-owned functions services, derived from the existing ICCT NMS implementation. */

/** Reused Inventory service: current user id. */
function icct_backend_current_user_id($fallback = 0)
{
    $user_id = isset($_SESSION['sess_user_id']) ? (int) $_SESSION['sess_user_id'] : (int) $fallback;
    return $user_id > 0 ? $user_id : (int) $fallback;
}

/** Reused Inventory service: device status name. */
function icct_backend_device_status_name($device)
{
    if (($device['disabled'] ?? '') !== '') {
        return 'Disabled';
    }
    if (!empty($device['id'])) {
        $serial =
            $device['serial_monitoring'] ??
            icct_backend_config_connection_status((int) $device['id']);
        if ($serial !== null) {
            // Cacti's no-ping status is not evidence that a serial unit answered.
            return $serial['status'] === 'Responding' ? 'Up' : $serial['status'];
        }
    }
    $updated = (string) ($device['last_updated'] ?? '');
    if ($updated === '' || $updated === '0000-00-00 00:00:00') {
        return 'Pending';
    }
    if (!icct_backend_parameter_is_fresh($updated)) {
        return 'Stale';
    }
    return icct_backend_host_status_name((int) $device['status']);
}

/** Reused Inventory service: host status name. */
function icct_backend_host_status_name($status)
{
    $map = [
        HOST_UNKNOWN => 'Unknown',
        HOST_DOWN => 'Down',
        HOST_RECOVERING => 'Recovering',
        HOST_UP => 'Up',
        HOST_ERROR => 'Error'
    ];
    return isset($map[$status]) ? $map[$status] : 'Invalid state ' . $status;
}

/** Reused Inventory service: inventory display name. */
function icct_backend_inventory_display_name($inventory_key)
{
    $labels = ['serial_number' => 'Chassis serial number'];
    return $labels[$inventory_key] ?? ucwords(str_replace('_', ' ', (string) $inventory_key));
}

/** Reused Inventory service: managed object record. */
function icct_backend_managed_object_record($type, $object_id, $user_id = null)
{
    $type = (string) $type;
    $object_id = (int) $object_id;
    if (
        !in_array(
            $type,
            [
                'device',
                'tree',
                'graph_template',
                'data_template',
                'host_template',
                'data_input',
                'graph',
                'data_source'
            ],
            true
        ) ||
        $object_id < 1
    ) {
        throw new InvalidArgumentException('Invalid ICCT NMS-managed object.');
    }
    if ($user_id === null) {
        $user_id = icct_backend_current_user_id();
    }
    db_execute_prepared(
        'INSERT IGNORE INTO plugin_icct_nms_managed_objects
		(object_type, object_id, created_by, created_at) VALUES (?, ?, ?, NOW())',
        [$type, $object_id, (int) $user_id]
    );
}

/** Reused Inventory service: now. */
function icct_backend_now()
{
    return date('Y-m-d H:i:s');
}

/** Reused Inventory service: parameter is fresh. */
function icct_backend_parameter_is_fresh($last_seen)
{
    $timestamp = strtotime((string) $last_seen);
    return $timestamp !== false &&
        $timestamp <= time() &&
        time() - $timestamp <= max(120, icct_backend_poller_interval() * 2);
}

/** Reused Inventory service: poller interval. */
function icct_backend_poller_interval()
{
    $interval = (int) read_config_option('poller_interval');
    if ($interval < 1) {
        throw new RuntimeException(
            'Cacti poller_interval is not configured as a positive interval.'
        );
    }
    return $interval;
}

/** Reused Inventory service: require device access. */
function icct_backend_require_device_access($host_id)
{
    if (function_exists('is_device_allowed') && is_device_allowed((int) $host_id)) {
        return;
    }
    // Cacti's list helper applies hide_disabled before checking permissions.
    // For an existing disabled host, evaluate the same native ACL without that display filter.
    $user = icct_backend_current_user_id();
    if (
        ($user > 0 || (int) read_config_option('auth_method') === 0) &&
        function_exists('auth_valid_user') &&
        auth_valid_user($user) &&
        function_exists('get_simple_device_perms') &&
        function_exists('get_policy_where') &&
        function_exists('get_policies')
    ) {
        $where = 'WHERE h.id=' . (int) $host_id . " AND h.deleted='' AND h.disabled='on'";
        if ((int) read_config_option('auth_method') !== 0 && !get_simple_device_perms($user)) {
            $where = get_policy_where(
                read_config_option('graph_auth_method'),
                get_policies($user),
                $where
            );
        }
        $allowed = db_fetch_cell("SELECT COUNT(DISTINCT h.id) FROM host h
            LEFT JOIN graph_local gl ON h.id=gl.host_id
            LEFT JOIN graph_templates gt ON gt.id=gl.graph_template_id
            LEFT JOIN host_template ht ON h.host_template_id=ht.id $where");
        if ((int) $allowed > 0) {
            return;
        }
    }
    throw new RuntimeException('Your Cacti account cannot access this device.');
}

/** Reused Inventory service: require management. */
function icct_backend_require_management($realm = 3)
{
    if (!function_exists('is_realm_allowed') || !is_realm_allowed((int) $realm)) {
        throw new RuntimeException(
            'Your Cacti account does not have permission to change this configuration.'
        );
    }
}
