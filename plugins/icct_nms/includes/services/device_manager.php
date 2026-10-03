<?php
/** ICCT-owned device manager services, derived from the existing ICCT NMS implementation. */

/** Reused Inventory service: device activate imported templates. */
function icct_backend_device_activate_imported_templates($device_id, $host_template_id)
{
    $device_id = icct_backend_device_require($device_id);
    $host_template_id = (int) $host_template_id;
    $templates = db_fetch_assoc_prepared(
        "SELECT DISTINCT o.graph_template_id
		FROM plugin_icct_nms_snmprec_imports AS i
		INNER JOIN plugin_icct_nms_snmprec_oids AS o ON o.import_id = i.id
		WHERE i.host_template_id = ? AND o.graphable = 'on' AND o.graph_template_id > 0
		AND NOT EXISTS (SELECT 1 FROM graph_local AS gl
			WHERE gl.host_id = ? AND gl.graph_template_id = o.graph_template_id)
		ORDER BY o.graph_template_id",
        [$host_template_id, $device_id]
    );
    $bundle = json_decode(
        (string) db_fetch_cell_prepared(
            'SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
            ['mib_bundle_' . $host_template_id]
        ),
        true
    );
    if (is_array($bundle)) {
        $known = array_column($templates, 'graph_template_id');
        foreach ($bundle['rows'] as $row) {
            $graph_id = (int) $row['graph_template_id'];
            if (
                !in_array($graph_id, $known) &&
                db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE id=?', [$graph_id]) &&
                !db_fetch_cell_prepared(
                    'SELECT id FROM graph_local WHERE host_id=? AND graph_template_id=?',
                    [$device_id, $graph_id]
                )
            ) {
                $templates[] = ['graph_template_id' => $graph_id];
            }
        }
    }
    $created = 0;

    foreach ($templates as $template) {
        $graph_template_id = (int) $template['graph_template_id'];
        /* Cacti accepts suggested values by reference, so pass a real variable. */
        $suggested_values = [];
        $result = create_complete_graph_from_template(
            $graph_template_id,
            $device_id,
            null,
            $suggested_values
        );
        if (!is_array($result) || empty($result['local_graph_id'])) {
            throw new RuntimeException(
                'Cacti could not activate imported graph template ' . $graph_template_id . '.'
            );
        }
        $local_data_ids = isset($result['local_data_id']) ? (array) $result['local_data_id'] : [];
        foreach ($local_data_ids as $local_data_id) {
            if ((int) $local_data_id > 0) {
                push_out_host($device_id, (int) $local_data_id);
            }
        }
        db_execute_prepared('REPLACE INTO host_graph (host_id, graph_template_id) VALUES (?, ?)', [
            $device_id,
            $graph_template_id
        ]);
        $created++;
    }

    if ($created > 0) {
        set_config_option('time_last_change_graph', time());
        set_config_option('time_last_change_data_source', time());
    }
    return $created;
}

/** Reused Inventory service: device reindex. */
function icct_backend_device_reindex($device_id)
{
    $device_id = icct_backend_device_require($device_id);
    $queries = db_fetch_assoc_prepared(
        'SELECT snmp_query_id FROM host_snmp_query WHERE host_id = ? ORDER BY snmp_query_id',
        [$device_id]
    );
    $started_at = microtime(true);
    foreach ($queries as $query) {
        run_data_query($device_id, (int) $query['snmp_query_id'], false, true);
    }
    return [
        'query_count' => count($queries),
        'item_count' => (int) db_fetch_cell_prepared(
            'SELECT COUNT(*) FROM host_snmp_cache WHERE host_id = ?',
            [$device_id]
        ),
        'seconds' => microtime(true) - $started_at
    ];
}

/** Reused Inventory service: device require. */
function icct_backend_device_require($device_id)
{
    $device_id = (int) $device_id;
    if (
        $device_id < 1 ||
        !(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM host WHERE id = ? AND deleted = ''", [
            $device_id
        ])
    ) {
        throw new InvalidArgumentException('Select a valid Cacti device.');
    }
    return $device_id;
}

/** Reused Inventory service: device save. */
function icct_backend_device_save($device_id, $input)
{
    global $snmp_versions,
        $snmp_auth_protocols,
        $snmp_priv_protocols,
        $availability_options,
        $ping_methods,
        $fields_host_edit;
    $device_id = (int) $device_id;
    $is_new_device = $device_id === 0;
    $description = trim((string) $input['description']);
    $hostname = trim((string) $input['hostname']);
    $template_id = (int) $input['host_template_id'];
    $site_id = (int) $input['site_id'];
    $poller_id = (int) $input['poller_id'];
    $snmp_version = (int) $input['snmp_version'];
    $snmp_port = (int) $input['snmp_port'];
    $snmp_timeout = (int) $input['snmp_timeout'];
    $community = trim((string) $input['snmp_community']);
    $snmp_username = trim((string) $input['snmp_username']);
    $snmp_password = (string) $input['snmp_password'];
    $snmp_auth_protocol = trim((string) $input['snmp_auth_protocol']);
    $snmp_priv_protocol = trim((string) $input['snmp_priv_protocol']);
    $snmp_priv_passphrase = (string) $input['snmp_priv_passphrase'];
    $proxy = !empty($input['proxy']);
    foreach (['max_oids', 'device_threads'] as $field_name) {
        $input[$field_name] = icct_backend_core_field_value(
            $field_name,
            $fields_host_edit[$field_name],
            $input[$field_name]
        );
    }

    if ($description === '' || $hostname === '') {
        throw new InvalidArgumentException('Device name and hostname are required.');
    }
    if (!array_key_exists($snmp_version, $snmp_versions)) {
        throw new InvalidArgumentException('Select an SNMP version supported by Cacti.');
    }
    if ($snmp_port < 1 || $snmp_port > 65535) {
        throw new InvalidArgumentException('SNMP port must be between 1 and 65535.');
    }
    if ($snmp_timeout < 1) {
        throw new InvalidArgumentException('SNMP timeout must be positive.');
    }
    if (in_array($snmp_version, [1, 2], true) && $community === '') {
        throw new InvalidArgumentException('Enter the SNMP community for version 1 or 2c.');
    }
    if ($snmp_version === 3) {
        $allowed_auth_protocols = array_keys($snmp_auth_protocols);
        $allowed_priv_protocols = array_keys($snmp_priv_protocols);
        if ($snmp_username === '') {
            throw new InvalidArgumentException('Enter the SNMP v3 username.');
        }
        if (!in_array($snmp_auth_protocol, $allowed_auth_protocols, true)) {
            throw new InvalidArgumentException('Select a valid SNMP v3 authentication method.');
        }
        if (!in_array($snmp_priv_protocol, $allowed_priv_protocols, true)) {
            throw new InvalidArgumentException('Select a valid SNMP v3 privacy method.');
        }
        if ($snmp_auth_protocol !== '[None]' && strlen($snmp_password) < 8) {
            throw new InvalidArgumentException(
                'SNMP v3 authentication password must contain at least 8 characters.'
            );
        }
        if ($snmp_auth_protocol === '[None]' && $snmp_priv_protocol !== '[None]') {
            throw new InvalidArgumentException('SNMP v3 privacy requires authentication.');
        }
        if ($snmp_priv_protocol !== '[None]' && strlen($snmp_priv_passphrase) < 8) {
            throw new InvalidArgumentException(
                'SNMP v3 privacy passphrase must contain at least 8 characters.'
            );
        }
        $community = '';
    } else {
        $snmp_username = '';
        $snmp_password = '';
        $snmp_auth_protocol = '[None]';
        $snmp_priv_protocol = '[None]';
        $snmp_priv_passphrase = '';
    }
    if (
        $site_id < 0 ||
        ($site_id !== 0 &&
            !(int) db_fetch_cell_prepared('SELECT COUNT(*) FROM sites WHERE id=?', [$site_id]))
    ) {
        throw new InvalidArgumentException('Select an existing Cacti site.');
    }
    if (
        $template_id !== 0 &&
        !(int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host_template WHERE id = ?', [
            $template_id
        ])
    ) {
        throw new InvalidArgumentException('Select a valid Cacti host template.');
    }
    if (!(int) db_fetch_cell_prepared('SELECT COUNT(*) FROM poller WHERE id = ?', [$poller_id])) {
        throw new InvalidArgumentException('Select a valid data collector.');
    }
    if (
        (int) db_fetch_cell_prepared(
            "SELECT COUNT(*) FROM host WHERE description = ? AND id != ? AND deleted = ''",
            [$description, $device_id]
        )
    ) {
        throw new InvalidArgumentException('A Cacti device already uses this name.');
    }
    // Serial members share a physical endpoint; bus-address uniqueness is enforced by ICCT NMS.
    $serial_identity =
        $device_id > 0 &&
        $snmp_version === 0 &&
        (bool) db_fetch_cell_prepared(
            'SELECT d.host_id FROM plugin_icct_nms_serial_devices d JOIN plugin_icct_nms_serial_connections c ON c.id=d.connection_id WHERE d.host_id=? AND c.endpoint=?',
            [$device_id, $hostname]
        );
    if (
        !$proxy &&
        !$serial_identity &&
        (int) db_fetch_cell_prepared(
            "SELECT COUNT(*) FROM host WHERE hostname = ? AND snmp_port = ? AND snmp_community = ? AND id != ? AND deleted = ''",
            [$hostname, $snmp_port, $community, $device_id]
        )
    ) {
        throw new InvalidArgumentException(
            'This SNMP endpoint already exists. Enable proxy/simulator mode to share an address.'
        );
    }

    $availability = (int) $input['availability_method'];
    if (!array_key_exists($availability, $availability_options)) {
        throw new InvalidArgumentException('Select an availability method from Cacti.');
    }
    $ping_method = (int) $input['ping_method'];
    if (!array_key_exists($ping_method, $ping_methods)) {
        throw new InvalidArgumentException('Select a ping method from Cacti.');
    }
    if (
        (int) $input['ping_port'] < 0 ||
        (int) $input['ping_port'] > 65535 ||
        (int) $input['ping_timeout'] < 1 ||
        (int) $input['ping_retries'] < 0
    ) {
        throw new InvalidArgumentException('Invalid ping port, timeout, or retries.');
    }

    $saved_device_id = api_device_save(
        $device_id,
        $template_id,
        $description,
        $hostname,
        $community,
        $snmp_version,
        $snmp_username,
        $snmp_password,
        $snmp_port,
        $snmp_timeout,
        !empty($input['disabled']) ? 'on' : '',
        $availability,
        $ping_method,
        (int) $input['ping_port'],
        (int) $input['ping_timeout'],
        (int) $input['ping_retries'],
        trim((string) $input['notes']),
        $snmp_auth_protocol,
        $snmp_priv_passphrase,
        $snmp_priv_protocol,
        trim((string) $input['snmp_context']),
        trim((string) $input['snmp_engine_id']),
        (int) $input['max_oids'],
        (int) $input['device_threads'],
        $poller_id,
        $site_id,
        trim((string) $input['external_id']),
        trim((string) $input['location']),
        -1
    );
    if (!$saved_device_id) {
        throw new RuntimeException(
            'Cacti could not save the device. Check the submitted SNMP settings.'
        );
    }
    if ($is_new_device) {
        icct_backend_managed_object_record('device', (int) $saved_device_id);
    }
    if (empty($input['disabled'])) {
        /*
         * Imported numeric OIDs become real Cacti poller items immediately. If
         * one template is temporarily invalid, keep the already-saved Cacti host;
         * the poller reconciliation path retries activation on its next cycle.
         */
        try {
            icct_backend_device_activate_imported_templates((int) $saved_device_id, $template_id);
        } catch (Throwable $exception) {
            cacti_log(
                'Device ' .
                    (int) $saved_device_id .
                    ' was saved, but imported readings could not be activated: ' .
                    $exception->getMessage(),
                false,
                'ICCT NMS'
            );
        }
    }
    return (int) $saved_device_id;
}
