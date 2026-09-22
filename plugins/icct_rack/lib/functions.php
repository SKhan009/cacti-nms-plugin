<?php
/* SPDX-License-Identifier: GPL-2.0-or-later */

function icct_rack_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function icct_rack_current_user_id() {
    if (defined('SESS_USER_ID') && isset($_SESSION[SESS_USER_ID])) {
        return (int)$_SESSION[SESS_USER_ID];
    }
    return isset($_SESSION['sess_user_id']) ? (int)$_SESSION['sess_user_id'] : 0;
}

function icct_rack_client_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 64) : '';
}

function icct_rack_audit($action, $entity_type, $entity_id, $details = []) {
    $json = is_string($details) ? $details : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    db_execute_prepared(
        'INSERT INTO plugin_icct_rack_audit (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)',
        [
            icct_rack_current_user_id(),
            substr((string)$action, 0, 64),
            substr((string)$entity_type, 0, 32),
            (int)$entity_id,
            $json === false ? '' : $json,
            icct_rack_client_ip(),
        ]
    );
}

function icct_rack_can_manage() {
    return function_exists('api_user_realm_auth') && api_user_realm_auth('icct_rack_admin.php');
}

function icct_rack_get_racks($enabled_only = false) {
    $where = $enabled_only ? " WHERE enabled = 'on'" : '';
    return db_fetch_assoc("SELECT * FROM plugin_icct_rack_racks{$where} ORDER BY icct_name, name, code");
}

function icct_rack_get_rack($rack_id) {
    return db_fetch_row_prepared('SELECT * FROM plugin_icct_rack_racks WHERE id = ?', [(int)$rack_id]);
}

function icct_rack_get_placement($placement_id) {
    return db_fetch_row_prepared(
        "SELECT p.*, h.description AS host_description, h.hostname AS host_hostname, h.status AS host_status, h.disabled AS host_disabled
         FROM plugin_icct_rack_placements AS p
         /* FIX 2026-09-15: Treat deleted Cacti hosts as missing placements. */
         LEFT JOIN host AS h ON h.id = p.host_id AND h.deleted = ''
         WHERE p.id = ?",
        [(int)$placement_id]
    );
}

function icct_rack_get_placements($rack_id) {
    return db_fetch_assoc_prepared(
        "SELECT p.*, h.description AS host_description, h.hostname AS host_hostname, h.status AS host_status, h.disabled AS host_disabled
         FROM plugin_icct_rack_placements AS p
         /* FIX 2026-09-15: Do not expose deleted Cacti hosts in rack data. */
         LEFT JOIN host AS h ON h.id = p.host_id AND h.deleted = ''
         WHERE p.rack_id = ?
         ORDER BY p.face, p.start_u DESC, p.id",
        [(int)$rack_id]
    );
}

function icct_rack_get_available_hosts($placement_id = 0) {
    $placement_id = (int)$placement_id;

    if ($placement_id > 0) {
        return db_fetch_assoc_prepared(
            "SELECT h.id, h.description, h.hostname, h.status, h.disabled
             FROM host AS h
             LEFT JOIN plugin_icct_rack_placements AS p ON p.host_id = h.id
             /* FIX 2026-09-15: Deleted Cacti hosts cannot be newly placed. */
             WHERE h.deleted = '' AND (p.id IS NULL OR p.id = ?)
             ORDER BY h.description, h.hostname",
            [$placement_id]
        );
    }

    return db_fetch_assoc(
        "SELECT h.id, h.description, h.hostname, h.status, h.disabled
         FROM host AS h
         LEFT JOIN plugin_icct_rack_placements AS p ON p.host_id = h.id
             /* FIX 2026-09-15: Deleted Cacti hosts cannot be newly placed. */
             WHERE h.deleted = '' AND p.id IS NULL
         ORDER BY h.description, h.hostname"
    );
}

function icct_rack_get_host($host_id) {
    /* FIX 2026-09-15: Reject deleted hosts during placement validation and AJAX lookup. */
    return db_fetch_row_prepared("SELECT * FROM host WHERE id = ? AND deleted = ''", [(int)$host_id]);
}

function icct_rack_categories() {
    return [
        'router'   => 'Router / Edge Router',
        'switch'   => 'Switch / Core Switch',
        'server'   => 'Server / Workstation',
        'firewall' => 'Firewall / Security Device',
        'radio'    => 'LOS / Radio Device',
        'vsat'     => 'VSAT / Satellite Device',
        'ups'      => 'UPS / Power Device',
        'sensor'   => 'Sensor / Instrumentation',
        'camera'   => 'Camera / Video Device',
        'voice'    => 'Voice / VoIP Device',
        'encryptor'=> 'Encryptor',
        'generic'  => 'Other / Generic Device',
    ];
}

function icct_rack_category_icon($category) {
    $icons = [
        'router'    => 'fas fa-route',
        'switch'    => 'fas fa-network-wired',
        'server'    => 'fas fa-server',
        'firewall'  => 'fas fa-shield-alt',
        'radio'     => 'fas fa-broadcast-tower',
        'vsat'      => 'fas fa-satellite-dish',
        'ups'       => 'fas fa-battery-full',
        'sensor'    => 'fas fa-thermometer-half',
        'camera'    => 'fas fa-video',
        'voice'     => 'fas fa-phone',
        'encryptor' => 'fas fa-lock',
        'generic'   => 'fas fa-microchip',
    ];

    return $icons[$category] ?? $icons['generic'];
}

function icct_rack_status_info($host) {
    if (!$host) {
        return ['key' => 'missing', 'label' => 'Missing from Cacti', 'class' => 'status-missing'];
    }

    if (isset($host['disabled']) && (string)$host['disabled'] === 'on') {
        return ['key' => 'disabled', 'label' => 'Disabled', 'class' => 'status-disabled'];
    }

    $status = isset($host['status']) ? (int)$host['status'] : (isset($host['host_status']) ? (int)$host['host_status'] : null);

    if (defined('HOST_UP') && $status === (int)HOST_UP) {
        return ['key' => 'up', 'label' => 'Up', 'class' => 'status-up'];
    }
    if (defined('HOST_DOWN') && $status === (int)HOST_DOWN) {
        return ['key' => 'down', 'label' => 'Down', 'class' => 'status-down'];
    }
    if (defined('HOST_RECOVERING') && $status === (int)HOST_RECOVERING) {
        return ['key' => 'recovering', 'label' => 'Recovering', 'class' => 'status-recovering'];
    }
    if (defined('HOST_ERROR') && $status === (int)HOST_ERROR) {
        return ['key' => 'error', 'label' => 'Error', 'class' => 'status-error'];
    }

    return ['key' => 'unknown', 'label' => 'Unknown', 'class' => 'status-unknown'];
}

function icct_rack_validate_rack_payload($data, $rack_id = 0) {
    $errors = [];
    $code = trim((string)($data['code'] ?? ''));
    $name = trim((string)($data['name'] ?? ''));
    $rack_units = (int)($data['rack_units'] ?? 42);

    if ($code === '' || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $code)) {
        $errors[] = 'Rack code is required and may contain only letters, numbers, dot, underscore and dash.';
    }
    if ($name === '') {
        $errors[] = 'Rack name is required.';
    }
    if ($rack_units < 6 || $rack_units > 60) {
        $errors[] = 'Rack units must be between 6U and 60U.';
    }

    if ($code !== '') {
        $exists = db_fetch_cell_prepared(
            'SELECT COUNT(*) FROM plugin_icct_rack_racks WHERE code = ? AND id <> ?',
            [$code, (int)$rack_id]
        );
        if ((int)$exists > 0) {
            $errors[] = 'Rack code already exists.';
        }
    }

    if ((int)$rack_id > 0) {
        $max_end_u = db_fetch_cell_prepared(
            'SELECT COALESCE(MAX(start_u + height_u - 1), 0) FROM plugin_icct_rack_placements WHERE rack_id = ?',
            [(int)$rack_id]
        );
        if ($rack_units < (int)$max_end_u) {
            $errors[] = 'Rack size cannot be smaller than the highest currently occupied U position.';
        }
    }

    return $errors;
}

function icct_rack_save_rack($data, $rack_id = 0) {
    $rack_id = (int)$rack_id;
    $errors = icct_rack_validate_rack_payload($data, $rack_id);
    if ($errors) {
        return ['ok' => false, 'errors' => $errors, 'id' => $rack_id];
    }

    $params = [
        trim((string)$data['code']),
        trim((string)$data['name']),
        trim((string)($data['icct_name'] ?? '')),
        trim((string)($data['room'] ?? '')),
        trim((string)($data['location'] ?? '')),
        (int)$data['rack_units'],
        trim((string)($data['description'] ?? '')),
        !empty($data['enabled']) ? 'on' : '',
    ];

    if ($rack_id > 0) {
        $before = icct_rack_get_rack($rack_id);
        db_execute_prepared(
            'UPDATE plugin_icct_rack_racks SET code = ?, name = ?, icct_name = ?, room = ?, location = ?, rack_units = ?, description = ?, enabled = ? WHERE id = ?',
            array_merge($params, [$rack_id])
        );
        icct_rack_audit('rack.update', 'rack', $rack_id, ['before' => $before, 'after' => $data]);
        return ['ok' => true, 'errors' => [], 'id' => $rack_id];
    }

    db_execute_prepared(
        'INSERT INTO plugin_icct_rack_racks (code, name, icct_name, room, location, rack_units, description, enabled, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        array_merge($params, [icct_rack_current_user_id()])
    );
    $rack_id = (int)db_fetch_insert_id();
    icct_rack_audit('rack.create', 'rack', $rack_id, $data);

    return ['ok' => true, 'errors' => [], 'id' => $rack_id];
}

function icct_rack_delete_rack($rack_id) {
    $rack_id = (int)$rack_id;
    $rack = icct_rack_get_rack($rack_id);
    if (!$rack) {
        return ['ok' => false, 'error' => 'Rack not found.'];
    }

    $count = db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_icct_rack_placements WHERE rack_id = ?', [$rack_id]);
    if ((int)$count > 0) {
        return ['ok' => false, 'error' => 'Remove all device placements from this rack before deleting it.'];
    }

    db_execute_prepared('DELETE FROM plugin_icct_rack_racks WHERE id = ?', [$rack_id]);
    icct_rack_audit('rack.delete', 'rack', $rack_id, $rack);

    return ['ok' => true];
}

function icct_rack_validate_placement_payload($data, $placement_id = 0) {
    $errors = [];
    $placement_id = (int)$placement_id;
    $rack_id = (int)($data['rack_id'] ?? 0);
    $host_id = (int)($data['host_id'] ?? 0);
    $start_u = (int)($data['start_u'] ?? 0);
    $height_u = (int)($data['height_u'] ?? 1);
    $face = (string)($data['face'] ?? 'front');
    $category = (string)($data['category'] ?? 'generic');

    $rack = icct_rack_get_rack($rack_id);
    if (!$rack) {
        $errors[] = 'Rack does not exist.';
    }
    if (!$host_id || !icct_rack_get_host($host_id)) {
        $errors[] = 'Select a valid Cacti device.';
    }
    if (!in_array($face, ['front', 'rear'], true)) {
        $errors[] = 'Rack face must be front or rear.';
    }
    if ($start_u < 1) {
        $errors[] = 'Start U must be 1 or greater.';
    }
    if ($height_u < 1 || $height_u > 20) {
        $errors[] = 'Device height must be between 1U and 20U.';
    }
    if ($rack && ($start_u + $height_u - 1) > (int)$rack['rack_units']) {
        $errors[] = 'Device extends beyond the top of the selected rack.';
    }
    if (!array_key_exists($category, icct_rack_categories())) {
        $errors[] = 'Invalid device category.';
    }

    if ($host_id > 0) {
        $exists = db_fetch_cell_prepared(
            'SELECT COUNT(*) FROM plugin_icct_rack_placements WHERE host_id = ? AND id <> ?',
            [$host_id, $placement_id]
        );
        if ((int)$exists > 0) {
            $errors[] = 'This Cacti device is already placed in a rack.';
        }
    }

    if ($rack && $start_u > 0 && $height_u > 0 && in_array($face, ['front', 'rear'], true)) {
        $end_u = $start_u + $height_u - 1;
        $overlap = db_fetch_cell_prepared(
            'SELECT COUNT(*) FROM plugin_icct_rack_placements
             WHERE rack_id = ? AND face = ? AND id <> ?
               AND start_u <= ?
               AND (start_u + height_u - 1) >= ?',
            [$rack_id, $face, $placement_id, $end_u, $start_u]
        );
        if ((int)$overlap > 0) {
            $errors[] = 'The selected U range overlaps another device on the same rack face.';
        }
    }

    return $errors;
}

function icct_rack_save_placement($data, $placement_id = 0) {
    $placement_id = (int)$placement_id;
    $errors = icct_rack_validate_placement_payload($data, $placement_id);
    if ($errors) {
        return ['ok' => false, 'errors' => $errors, 'id' => $placement_id];
    }

    $params = [
        (int)$data['rack_id'],
        (int)$data['host_id'],
        trim((string)($data['label'] ?? '')),
        (int)$data['start_u'],
        (int)$data['height_u'],
        (string)$data['face'],
        (string)$data['category'],
        trim((string)($data['asset_tag'] ?? '')),
        trim((string)($data['power_feed'] ?? '')),
        trim((string)($data['notes'] ?? '')),
    ];

    if ($placement_id > 0) {
        $before = icct_rack_get_placement($placement_id);
        db_execute_prepared(
            'UPDATE plugin_icct_rack_placements
             SET rack_id = ?, host_id = ?, label = ?, start_u = ?, height_u = ?, face = ?, category = ?, asset_tag = ?, power_feed = ?, notes = ?, updated_by = ?
             WHERE id = ?',
            array_merge($params, [icct_rack_current_user_id(), $placement_id])
        );
        icct_rack_audit('placement.update', 'placement', $placement_id, ['before' => $before, 'after' => $data]);
        return ['ok' => true, 'errors' => [], 'id' => $placement_id];
    }

    db_execute_prepared(
        'INSERT INTO plugin_icct_rack_placements
         (rack_id, host_id, label, start_u, height_u, face, category, asset_tag, power_feed, notes, created_by, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        array_merge($params, [icct_rack_current_user_id(), icct_rack_current_user_id()])
    );
    $placement_id = (int)db_fetch_insert_id();
    icct_rack_audit('placement.create', 'placement', $placement_id, $data);

    return ['ok' => true, 'errors' => [], 'id' => $placement_id];
}

function icct_rack_move_placement($placement_id, $rack_id, $start_u, $face) {
    $placement = icct_rack_get_placement((int)$placement_id);
    if (!$placement) {
        return ['ok' => false, 'errors' => ['Placement not found.']];
    }

    $data = [
        'rack_id' => (int)$rack_id,
        'host_id' => (int)$placement['host_id'],
        'label' => $placement['label'],
        'start_u' => (int)$start_u,
        'height_u' => (int)$placement['height_u'],
        'face' => (string)$face,
        'category' => $placement['category'],
        'asset_tag' => $placement['asset_tag'],
        'power_feed' => $placement['power_feed'],
        'notes' => $placement['notes'],
    ];

    $result = icct_rack_save_placement($data, (int)$placement_id);
    if ($result['ok']) {
        icct_rack_audit('placement.move', 'placement', (int)$placement_id, [
            'from' => ['rack_id' => (int)$placement['rack_id'], 'start_u' => (int)$placement['start_u'], 'face' => $placement['face']],
            'to'   => ['rack_id' => (int)$rack_id, 'start_u' => (int)$start_u, 'face' => (string)$face],
        ]);
    }
    return $result;
}

function icct_rack_delete_placement($placement_id) {
    $placement_id = (int)$placement_id;
    $placement = icct_rack_get_placement($placement_id);
    if (!$placement) {
        return ['ok' => false, 'error' => 'Placement not found.'];
    }

    db_execute_prepared('DELETE FROM plugin_icct_rack_placements WHERE id = ?', [$placement_id]);
    icct_rack_audit('placement.delete', 'placement', $placement_id, $placement);

    return ['ok' => true];
}

function icct_rack_status_counts($placements) {
    $counts = ['up' => 0, 'down' => 0, 'recovering' => 0, 'error' => 0, 'unknown' => 0, 'disabled' => 0, 'missing' => 0];
    foreach ($placements as $placement) {
        $host = [
            'status' => $placement['host_status'] ?? null,
            'disabled' => $placement['host_disabled'] ?? '',
        ];
        if (empty($placement['host_id']) || $placement['host_description'] === null) {
            $info = ['key' => 'missing'];
        } else {
            $info = icct_rack_status_info($host);
        }
        $counts[$info['key']] = ($counts[$info['key']] ?? 0) + 1;
    }
    return $counts;
}

function icct_rack_get_recent_audit($limit = 50) {
    $limit = max(1, min(200, (int)$limit));
    return db_fetch_assoc(
        'SELECT a.*, u.username
         FROM plugin_icct_rack_audit AS a
         LEFT JOIN user_auth AS u ON u.id = a.user_id
         ORDER BY a.id DESC LIMIT ' . $limit
    );
}

function icct_rack_request_string($key, $default = '') {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function icct_rack_request_int($key, $default = 0) {
    return isset($_POST[$key]) ? (int)$_POST[$key] : (int)$default;
}

function icct_rack_raise_errors($key, $errors) {
    $message = is_array($errors) ? implode(' ', $errors) : (string)$errors;
    raise_message($key, $message, MESSAGE_LEVEL_ERROR);
}
