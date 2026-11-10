<?php

require_once __DIR__ . '/../../ntp/services/ntp_service.php';

/** Preset values populate drafts; explicit overrides belong to one device. */
function icct_nms_protocol_preset_fields($protocol)
{
    switch ($protocol) {
        case 'snmp':
            return [
                'snmp_version',
                'snmp_port',
                'snmp_timeout',
                'max_oids',
                'availability_method',
                'ping_method',
                'ping_timeout',
                'ping_retries',
                'snmp_username',
                'snmp_auth_protocol',
                'snmp_priv_protocol',
                'snmp_context',
                'snmp_security_level',
            ];
        case 'cdp':
        case 'lldp':
        case 'snmp_discovery':
            return ['interval_seconds', 'stale_seconds', 'refresh_seconds'];
        case 'ssh':
            return [
                'port',
                'connect_timeout',
                'auth_method',
                'username',
                'command_timeout',
                'retries',
                'keepalive',
                'monitoring',
            ];
        case 'ntp':
            return icct_nms_ntp_fields();
        case 'snmp_notifications':
            return ['trap_enabled', 'trap_source_mode'];
        case 'netflow':
            return icct_nms_netflow_fields();
        case 'syslog':
            return ['severity_codes', 'facility_codes', 'match_strings', 'transport'];
        case 'serial':
            return [
                'serial_interface',
                'baud_rate',
                'data_bits',
                'parity',
                'stop_bits',
                'flow_control',
                'response_timeout',
                'serial_retries',
                'serial_interval',
                'serial_protocol',
            ];
        default:
            throw new InvalidArgumentException('Choose a supported protocol.');
    }
}
function icct_nms_protocol_presets()
{
    $json = db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?', [
        'protocol_defaults',
    ]);
    if (!$json) {
        return [];
    }
    $values = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($values)) {
        throw new RuntimeException('Invalid protocol defaults.');
    }
    return $values;
}
/** Version profiles are separate from the active defaults inherited by new devices. */
function icct_nms_netflow_profiles()
{
    $json = db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?', [
        'protocol_netflow_versions',
    ]);
    if (!$json) {
        return [];
    }
    $profiles = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($profiles)) {
        throw new RuntimeException('Invalid NetFlow version presets.');
    }
    return array_intersect_key($profiles, array_flip([5, 7, 9, 10]));
}
function icct_nms_protocol_variants()
{
    return [
        'snmp' => [
            'field' => 'snmp_version',
            'labels' => [1 => 'SNMP v1', 2 => 'SNMP v2', 3 => 'SNMP v3'],
        ],
        'ssh' => [
            'field' => 'auth_method',
            'labels' => ['password' => 'SSH password', 'key' => 'SSH private key'],
        ],
        'serial' => [
            'field' => 'serial_protocol',
            'labels' => ['modbus_rtu' => 'Modbus RTU', 'modbus_ascii' => 'Modbus ASCII'],
        ],
    ];
}
function icct_nms_protocol_profiles()
{
    $json = db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?', [
        'protocol_versions',
    ]);
    if (!$json) {
        return [];
    }
    $profiles = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($profiles)) {
        throw new RuntimeException('Invalid protocol version presets.');
    }
    return $profiles;
}
/** Original preset values let untracked devices keep any settings that already differed. */
function icct_nms_protocol_inheritance_baseline(): array
{
    $json = db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?', [
        'protocol_inheritance_baseline',
    ]);
    return $json ? json_decode($json, true, 32, JSON_THROW_ON_ERROR) : [];
}

function icct_nms_protocol_preset_validate($protocol, $input)
{
    global $fields_host_edit;
    if ($protocol === 'snmp') {
        $input += array_replace(
            icct_nms_defaults(),
            icct_nms_protocol_presets()['snmp'] ?? [],
            icct_nms_protocol_profiles()['snmp'][(int) ($input['snmp_version'] ?? 0)] ?? [],
        );
    }
    if ($protocol === 'syslog') {
        $input += ['transport' => 'both'];
    }
    $out = [];
    foreach (icct_nms_protocol_preset_fields($protocol) as $field) {
        if (!isset($input[$field]) && $field !== 'monitoring') {
            throw new InvalidArgumentException('Missing field: ' . $field);
        }
        if (isset($input[$field]) && !is_scalar($input[$field])) {
            throw new InvalidArgumentException('Invalid field: ' . $field);
        }
        $out[$field] = $input[$field] ?? '0';
    }
    if ($protocol === 'snmp') {
        $out['snmp_version'] = icct_backend_config_choice(
            (string) $out['snmp_version'],
            ['1', '2', '3'],
            'SNMP version',
        );
        $out['snmp_security_level'] = icct_backend_config_choice(
            $out['snmp_security_level'],
            ['noAuthNoPriv', 'authNoPriv', 'authPriv'],
            'SNMP security level',
        );
        foreach ($out as $field => $value) {
            if (isset($fields_host_edit[$field])) {
                $out[$field] = icct_backend_core_field_value(
                    $field,
                    $fields_host_edit[$field],
                    $value,
                );
            }
        }
        foreach (icct_nms_native_ranges() as $field => $range) {
            $out[$field] = icct_backend_topology_integer(
                $out[$field],
                $range[0],
                $range[1],
                $field,
            );
        }
        if ($out['snmp_security_level'] === 'noAuthNoPriv') {
            $out['snmp_auth_protocol'] = '[None]';
        }
        if ($out['snmp_security_level'] !== 'authPriv') {
            $out['snmp_priv_protocol'] = '[None]';
        }
    } elseif ($protocol === 'snmp_notifications') {
        $out['trap_enabled'] = icct_backend_config_choice((string) $out['trap_enabled'], ['0', '1'], 'Collect SNMP notifications');
        $out['trap_source_mode'] = icct_backend_config_choice($out['trap_source_mode'], ['device', 'manual'], 'Trap sender IP mode');
    } elseif (in_array($protocol, ['cdp', 'lldp', 'snmp_discovery'], true)) {
        $validated = icct_backend_nd_preset_validate(
            $out + [
                'name' => 'Protocol defaults',
                'methods' => [$protocol === 'snmp_discovery' ? 'arp' : $protocol],
                'enabled' => 1,
            ],
        );
        $cadence = (int) read_config_option('poller_interval');
        if ($cadence < 1 || $validated['interval_seconds'] % $cadence !== 0) {
            throw new InvalidArgumentException(
                'Collection interval must be a multiple of the poller interval.',
            );
        }
        foreach ($out as $field => $value) {
            $out[$field] = $validated[$field];
        }
    } elseif ($protocol === 'ssh') {
        $validated = icct_backend_ssh_preset_values(
            $out + ['name' => 'Protocol defaults', 'description' => '', 'enabled' => 1],
        );
        foreach ($out as $field => $value) {
            if ($field !== 'monitoring') {
                $out[$field] = $validated[$field];
            }
        }
        $out['monitoring'] = !empty($input['monitoring']) ? '1' : '0';
    } elseif ($protocol === 'ntp') {
        $out = icct_nms_ntp_validate($out);
    } elseif ($protocol === 'netflow') {
        $out = icct_nms_netflow_validate($out);
    } elseif ($protocol === 'syslog') {
        $out['transport'] = icct_backend_config_choice($out['transport'], ['both', 'udp', 'tcp'], 'Syslog transport');
        foreach (icct_backend_syslog_filters($out) as $field => $values) {
            $out[$field] = json_encode($values, JSON_THROW_ON_ERROR);
        }
    } else {
        icct_nms_serial_settings([], $out, true);
        $out['serial_interval'] = icct_backend_config_integer(
            $out['serial_interval'],
            1,
            86400,
            'Polling interval',
        );
    }
    return $out;
}
function icct_nms_save_protocol_preset($input)
{
    $protocol = $input['preset_protocol'] ?? '';
    if (!is_string($protocol)) {
        throw new InvalidArgumentException('Choose a supported protocol.');
    }
    $values = icct_nms_protocol_preset_validate($protocol, $input);
    $variant = icct_nms_protocol_variants()[$protocol] ?? null;
    $lock = 'icct_protocol_defaults';
    if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?,5)', [$lock]) !== 1) {
        throw new RuntimeException('Protocol defaults are busy. Please retry.');
    }
    try {
        $presets = icct_nms_protocol_presets();
        $profiles = $protocol === 'netflow' ? icct_nms_netflow_profiles() : ($variant ? icct_nms_protocol_profiles() : []);
        $profileField = $protocol === 'netflow' ? 'version' : ($variant['field'] ?? null);
        $profile = $profileField ? (string) $values[$profileField] : 'default';
        $previous = $presets[$protocol] ?? [];
        if ($profileField) {
            $previous = ($variant ? ($profiles[$protocol][$profile] ?? null) : ($profiles[$profile] ?? null)) ??
                ((string) ($previous[$profileField] ?? '') === $profile ? $previous : []);
        }
        $baseline = icct_nms_protocol_inheritance_baseline();
        $priorBaseline = $baseline;
        foreach (array_intersect_key($previous, $values) as $field => $value) {
            if ($field !== $profileField && (string) $value !== (string) $values[$field]) {
                $baseline[$protocol][$profile][$field] ??= $value;
            }
        }
        $presets[$protocol] = $values;
        icct_backend_category_execute('START TRANSACTION', []);
        try {
            if ($protocol === 'netflow') {
                $profiles[(int) $values['version']] = $values;
                icct_backend_category_execute(
                    'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON ' .
                        'DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
                    ['protocol_netflow_versions', json_encode($profiles, JSON_THROW_ON_ERROR)],
                );
            }
            if ($variant) {
                $profiles[$protocol][(string) $values[$variant['field']]] = $values;
                icct_backend_category_execute(
                    'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON ' .
                        'DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
                    ['protocol_versions', json_encode($profiles, JSON_THROW_ON_ERROR)],
                );
            }
            icct_backend_category_execute(
                'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON ' .
                    'DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
                ['protocol_defaults', json_encode($presets, JSON_THROW_ON_ERROR)],
            );
            if ($baseline !== $priorBaseline) {
                icct_backend_category_execute(
                    'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON ' .
                        'DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
                    ['protocol_inheritance_baseline', json_encode($baseline, JSON_THROW_ON_ERROR)],
                );
            }
            icct_backend_category_execute('COMMIT', []);
        } catch (Throwable $e) {
            icct_backend_category_execute('ROLLBACK', []);
            throw $e;
        }
    } finally {
        db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
    }
    return (['snmp_notifications' => 'SNMP notification', 'snmp_discovery' => 'SNMP discovery'][$protocol] ?? strtoupper($protocol)) .
        ' defaults saved. Inherited fields in Inventory use these values when the device is opened.';
}

/** Only names of overridden non-secret preset fields are stored here. */
function icct_nms_protocol_device_overrides($id)
{
    $out = [];
    foreach (['snmp', 'snmp_discovery', 'cdp', 'lldp', 'ssh', 'serial', 'syslog', 'netflow', 'ntp'] as $protocol) {
        $json = $id
            ? db_fetch_cell_prepared(
                'SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
                ['device_preset_overrides_' . $id . '_' . $protocol],
            )
            : false;
        if ($json === false || $json === null || $json === '') {
            $out[$protocol] = null;
            continue;
        }
        $names = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($names) || array_diff($names, icct_nms_protocol_preset_fields($protocol))) {
            throw new RuntimeException('Invalid device preset overrides.');
        }
        $out[$protocol] = $names;
    }
    return $out;
}
function icct_nms_protocol_record_overrides($id, $action, $input)
{
    if ($action === 'discovery' && ($input['discovery_timing_source'] ?? '') === '0') {
        return;
    }
    if (
        $action === 'remove_protocol' &&
        in_array(
            $input['protocol'] ?? '',
            ['snmp', 'cdp', 'lldp', 'ssh', 'serial', 'syslog', 'netflow', 'ntp'],
            true,
        )
    ) {
        icct_backend_category_execute('DELETE FROM plugin_icct_nms_meta WHERE meta_key=?', [
            'device_preset_overrides_' . $id . '_' . $input['protocol'],
        ]);
        return;
    }
    $protocol = $action === 'discovery'
        ? ($input['discovery_protocol'] ?? (!empty($input['discovery_policy']) ? 'snmp_discovery' : ''))
        : $action;
    if (
        !is_string($protocol) ||
        !in_array(
            $protocol,
            ['snmp', 'snmp_discovery', 'cdp', 'lldp', 'ssh', 'serial', 'syslog', 'netflow', 'ntp'],
            true,
        )
    ) {
        return;
    }
    $defaults = icct_nms_protocol_presets()[$protocol] ?? [];
    if ($protocol === 'netflow') {
        $defaults = icct_nms_netflow_profiles()[(int) ($input['version'] ?? 0)] ?? $defaults;
    }
    $variant = icct_nms_protocol_variants()[$protocol] ?? null;
    if ($variant) {
        $defaults =
            icct_nms_protocol_profiles()[$protocol][(string) ($input[$variant['field']] ?? '')] ??
            $defaults;
    }
    if (!$defaults) {
        return;
    }
    $overrides = icct_nms_protocol_device_overrides($id)[$protocol] ?? [];
    foreach ($defaults as $field => $value) {
        if (!in_array($field, icct_nms_protocol_preset_fields($protocol), true)) {
            continue;
        }
        // Missing controls do not override defaults, except the unchecked monitoring box.
        if (!array_key_exists($field, $input) && $field !== 'monitoring') {
            continue;
        }
        $submitted = $input[$field] ?? '0';
        if (!is_scalar($submitted)) {
            throw new InvalidArgumentException('Invalid preset field: ' . $field);
        }
        $same =
            (string) $submitted === (string) $value ||
            ((is_int($value) || is_float($value)) && is_numeric($submitted) &&
                (float) $submitted === (float) $value);
        $overrides = array_values(array_diff($overrides, [$field]));
        if (!$same) {
            $overrides[] = $field;
        }
    }
    icct_backend_category_execute(
        'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON ' .
            'DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
        [
            'device_preset_overrides_' . $id . '_' . $protocol,
            json_encode($overrides, JSON_THROW_ON_ERROR),
        ],
    );
    if ($action === 'discovery') {
        icct_backend_category_execute(
            'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON ' .
                'DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
            ['device_discovery_preset_' . $id, $protocol],
        );
    }
}
