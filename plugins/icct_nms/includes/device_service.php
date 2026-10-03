<?php
require_once __DIR__.'/device_type_service.php';
require_once __DIR__.'/rack_preset_service.php';
require_once __DIR__.'/configuration_history.php';

/**
 * Device and SNMP writes using Cacti field definitions and existing NMS services.
 */

/**
 * Validate submitted Basic Information before saving core data and related metadata.
 */
function icct_nms_save_device($id, $old, $input)
{
    global $fields_host_edit;
    foreach (
        [
            'short_name',
            'serial_number',
            'mac_address',
            'chassis_id',
            'category_id',
            'device_type',
            'rack_id',
            'rack_position',
            'cross_launch_url'
        ]
        as $field
    ) {
        if (!array_key_exists($field, $input) || !is_scalar($input[$field])) {
            throw new InvalidArgumentException('Missing field: ' . $field);
        }
    }
    $values = icct_nms_native_values($old, $input, [
        'description',
        'hostname',
        'host_template_id',
        'site_id',
        'poller_id',
        'device_threads',
        'notes'
    ]);
    $values['disabled'] = isset($input['enabled']) ? '' : 'on';
    // Preserve explicit shared endpoint state, never infer it from duplicates.
    $values['proxy'] = $id ? icct_backend_shared_endpoint_get($id) : false;
    $short = icct_backend_short_name_validate($input['short_name'] ?? '');
    if ($short === '') {
        $short = icct_backend_short_name_generate($values['description']);
    }
    // Displayed collector evidence is not silently promoted to a manual override.
    $observed = $id ? icct_backend_identity_observed($old) : [];
    $manual = $id ? db_fetch_row_prepared('SELECT serial_number,mac_address,chassis_id FROM plugin_icct_nms_device_metadata WHERE host_id=?', [$id]) : [];
    foreach (['serial_number', 'mac_address', 'chassis_id'] as $field) {
        if (empty($manual[$field]) && isset($observed[$field]) && ($input[$field] ?? '') === $observed[$field]) $input[$field] = '';
    }
    $serial = icct_backend_manual_serial_validate($input['serial_number'] ?? '');
    $identity = icct_backend_identity_manual_validate([
        'manual_mac_address' => $input['mac_address'] ?? '',
        'manual_chassis_id' => $input['chassis_id'] ?? ''
    ]);
    $category = icct_backend_topology_integer($input['category_id'] ?? '', 0, 2147483647, 'Segment');
    if ($category && !icct_backend_category_exists($category)) {
        throw new InvalidArgumentException('Select a saved segment.');
    }
    // Only a saved profile in the selected segment can be assigned.
    $type = icct_nms_resolve_device_type($category, $input['device_type']);
    $url = icct_backend_config_text($input['cross_launch_url'] ?? '', 2048, 'Cross Launch URL', false);
    if (
        $url !== '' &&
        (!filter_var($url, FILTER_VALIDATE_URL) ||
            !in_array(strtolower(parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true))
    ) {
        throw new InvalidArgumentException('Cross Launch URL must use HTTP or HTTPS.');
    }
    $rackSelection=$input['rack_id'];
    $rack = is_string($rackSelection) && str_starts_with($rackSelection,'preset:')
        ? icct_nms_resolve_preset_rack($rackSelection,(int)$values['site_id'],$input['rack_position'])
        : icct_backend_topology_integer($rackSelection,0,2147483647,'Rack');
    $placement = null;
    if ($rack) {
        $r = db_fetch_row_prepared(
            'SELECT r.*,n.site_id FROM plugin_icct_nms_racks r JOIN plugin_icct_nms_rack_nodes n ON n.id=r.node_id WHERE r.id=?',
            [$rack]
        );
        if (!$r || (int) $r['site_id'] !== (int) $values['site_id']) {
            throw new InvalidArgumentException('Select a saved rack at the device site.');
        }
        $position = explode(':', (string) ($input['rack_position'] ?? ''));
        if (count($position) !== 2) {
            throw new InvalidArgumentException('Select rack placement.');
        }
        $start = icct_backend_topology_integer($position[0], 1, 100, 'Rack unit');
        $height = icct_backend_topology_integer($position[1], 1, 100, 'Rack height');
        if ($start + $height - 1 > (int) $r['unit_count']) {
            throw new InvalidArgumentException('Placement exceeds rack capacity.');
        }
        if (
            db_fetch_cell_prepared(
                'SELECT host_id FROM plugin_icct_nms_rack_devices WHERE rack_id=? AND host_id<>? AND start_unit<=? AND start_unit+unit_height-1>=?',
                [$rack, $id, $start + $height - 1, $start]
            )
        ) {
            throw new InvalidArgumentException('Rack units are occupied.');
        }
        $placement = ['rack_id' => $rack, 'start_unit' => $start, 'unit_height' => $height];
    }
    if($id && !icct_nms_meta('configuration_latest_'.$id))icct_nms_configuration_record($id,'Initial baseline');
    $saved = icct_backend_device_save($id, $values);
    try {
        $classification = $id
            ? db_fetch_row_prepared(
                'SELECT device_role FROM plugin_icct_nms_device_classification WHERE host_id=?',
                [$id]
            )
            : [];
        icct_backend_device_classification_save(
            $saved,
            $category,
            $type,
            $classification['device_role'] ?? ''
        );
        icct_backend_short_name_save($saved, $short);
        icct_backend_manual_serial_save($saved, $serial);
        icct_backend_identity_manual_save($saved, $identity);
        icct_backend_category_execute(
            'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
            ['icct_cross_launch_' . $saved, $url]
        );
        $oldrack = $id
            ? db_fetch_row_prepared('SELECT rack_id FROM plugin_icct_nms_rack_devices WHERE host_id=?', [
                $id
            ])
            : [];
        if ($placement) {
            icct_backend_topology_config_write(
                'place_device',
                (int) $values['site_id'],
                $placement + ['host_id' => $saved]
            );
        } elseif ($oldrack) {
            icct_backend_topology_config_write('unplace_device', (int) $old['site_id'], [
                'rack_id' => $oldrack['rack_id'],
                'host_id' => $saved
            ]);
        }
    } catch (Throwable $e) {
        throw new RuntimeException(
            'Device ' .
                $saved .
                ' was saved in Cacti, but metadata was not fully saved. Edit this ID; do not create a duplicate. ' .
                $e->getMessage(),
            0,
            $e
        );
    }
    icct_nms_configuration_record($saved, $id ? 'Basic Information saved' : 'Device created');
    return $saved;
}

/** Native ranges shared by validation and SNMP/availability controls. */
function icct_nms_native_ranges()
{
    return [
        'snmp_port' => [1, 65535],
        // Cacti host.snmp_timeout is an unsigned MEDIUMINT.
        'snmp_timeout' => [1, 16777215],
        'ping_timeout' => [1, 2147483647],
        'ping_retries' => [0, 2147483647]
    ];
}
function icct_nms_native_range_attributes($field)
{
    [$min,$max] = icct_nms_native_ranges()[$field];
    return 'required min="'.$min.'" max="'.$max.'"';
}

/**
 * Merge only submitted controls into native values, retaining blank credential replacements.
 */
function icct_nms_native_values($old, $input, $fields)
{
    global $fields_host_edit;
    $values = $old;
    foreach ($fields as $field) {
        if (!array_key_exists($field, $input) || !is_scalar($input[$field])) {
            throw new InvalidArgumentException('Missing field: ' . $field);
        }
        if (!isset($fields_host_edit[$field])) {
            throw new RuntimeException('Cacti form field is unavailable: ' . $field);
        }
        if (
            in_array($field, ['snmp_password', 'snmp_priv_passphrase'], true) &&
            $input[$field] === ''
        ) {
            continue;
        }
        $values[$field] = icct_backend_core_field_value($field, $fields_host_edit[$field], $input[$field]);
    }
    foreach (
        icct_nms_native_ranges()
        as $key => $range
    ) {
        if (!array_key_exists($key, $values)) {
            throw new RuntimeException('Required Cacti default is missing: ' . $key);
        }
        $values[$key] = icct_backend_topology_integer($values[$key], $range[0], $range[1], $key);
    }
    foreach (['snmp_engine_id', 'external_id', 'location', 'ping_port'] as $key) {
        if (!array_key_exists($key, $values)) {
            throw new RuntimeException('Required Cacti default is missing: ' . $key);
        }
    }
    return $values;
}

/**
 * Save supported SNMP fields without changing unshown device configuration.
 */
function icct_nms_save_snmp($id, $old, $input)
{
    foreach (['snmp_password' => 'snmp_password_confirm', 'snmp_priv_passphrase' => 'snmp_priv_confirm'] as $secret => $confirm) {
        if (isset($input[$confirm]) && ($input[$secret] ?? '') !== $input[$confirm]) {
            throw new InvalidArgumentException('SNMP replacement passphrases must match their confirmation.');
        }
    }
    if (isset($input['snmp_security_level'])) {
        if (!in_array($input['snmp_security_level'], ['noAuthNoPriv', 'authNoPriv', 'authPriv'], true)) {
            throw new InvalidArgumentException('Invalid SNMP security level.');
        }
        if ($input['snmp_security_level'] === 'noAuthNoPriv') $input['snmp_auth_protocol'] = '[None]';
        if ($input['snmp_security_level'] !== 'authPriv') $input['snmp_priv_protocol'] = '[None]';
    }
    $values = icct_nms_native_values($old, $input, [
        'snmp_version',
        'snmp_community',
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
        'snmp_password',
        'snmp_priv_passphrase'
    ]);
    $values['proxy'] = icct_backend_shared_endpoint_get($id);
    return icct_backend_device_save($id, $values);
}
