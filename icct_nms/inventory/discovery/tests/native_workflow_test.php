<?php

/** Native core APIs and isolated connection-local tables. No live device/credential changes. */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
try {
    require '/var/www/html/cacti/include/cli_check.php';
    api_plugin_load_realms();
    require_once $config['base_path'] . '/lib/auth.php';
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION = ['sess_user_id' => 1];
    require_once '/var/www/html/cacti/plugins/icct_nms/shared/services/bootstrap.php';
    icct_nms_backend();
    require_once $config['base_path'] . '/include/global_form.php';
    require_once '/var/www/html/cacti/plugins/icct_nms/shared/services/forms.php';
    require_once '/var/www/html/cacti/plugins/icct_nms/inventory/services/device_service.php';
    require_once '/var/www/html/cacti/plugins/icct_nms/inventory/services/inventory.php';
    require_once '/var/www/html/cacti/plugins/icct_nms/presets/services/device_type_service.php';
    require_once '/var/www/html/cacti/plugins/icct_nms/graphs/services/graph_service.php';
    require_once '/var/www/html/cacti/plugins/icct_nms/protocols/shared/services/protocol_service.php';
    require_once __DIR__ . '/../services/inventory_events.php';
    require_once __DIR__ . '/../services/discovery_workflow.php';
    require_once __DIR__ . '/../services/discovery_collector.php';
    function scanCheck($ok, $message)
    {
        if (!$ok) {
            throw new RuntimeException($message);
        }
    }
    foreach (
        [
            'meta',
            'scan_jobs',
            'scan_results',
            'inventory_events',
            'device_classification',
            'managed_objects',
            'device_metadata',
            'rack_devices',
        ] as $suffix
    ) {
        $table = 'plugin_icct_nms_' . $suffix;
        if (in_array($suffix, ['scan_jobs', 'scan_results', 'inventory_events'], true)) {
            $schema = file_get_contents(__DIR__ . '/../../../shared/database/schema.sql');
            if (
                !preg_match(
                    '/CREATE TABLE IF NOT EXISTS ' . preg_quote($table, '/') . ' \(.*?;/s',
                    $schema,
                    $match,
                )
            ) {
                throw new RuntimeException('Missing workflow schema.');
            }
            scanCheck(
                db_execute(
                    str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $match[0]),
                ),
                'Temporary schema failed.',
            );
        } else {
            if (!db_fetch_cell("SHOW TABLES LIKE '$table'")) {
                continue;
            }
            scanCheck(
                db_execute('CREATE TEMPORARY TABLE qa_scan_copy LIKE ' . $table),
                'Cannot isolate ' . $table,
            );
            if ($suffix === 'meta') {
                db_execute('INSERT INTO qa_scan_copy SELECT * FROM ' . $table);
            }
            scanCheck(
                db_execute('CREATE TEMPORARY TABLE ' . $table . ' LIKE qa_scan_copy'),
                'Cannot isolate ' . $table,
            );
            if ($suffix === 'meta') {
                db_execute('INSERT INTO ' . $table . ' SELECT * FROM qa_scan_copy');
            }
            db_execute('DROP TEMPORARY TABLE qa_scan_copy');
        }
    }
    scanCheck(
        icct_nms_scan_addresses('192.0.2.0/30') === ['192.0.2.1', '192.0.2.2'],
        'Native CIDR expansion failed.',
    );
    scanCheck(
        icct_nms_scan_addresses('192.0.2.1,192.0.2.1,::1') === ['192.0.2.1', '::1'],
        'Address deduplication failed.',
    );
    scanCheck(icct_nms_scan_ports('22,20-23') === [20, 21, 22, 23], 'Port range parsing failed.');
    foreach (['0', '65536', '80-22', '22;id'] as $bad) {
        try {
            icct_nms_scan_ports($bad);
            throw new LogicException('Invalid ports accepted.');
        } catch (InvalidArgumentException $e) {
        }
    }
    $v = [
        'name' => 'Isolated QA',
        'ranges' => '127.0.0.1',
        'methods' => ['ports'],
        'ports' => '22',
        'poller_id' => $config['poller_id'],
        'site_id' => 0,
        'snmp_id' => 0,
        'timeout_ms' => 100,
        'run_limit' => 30,
    ];
    $valid = icct_nms_scan_validate($v);
    $addresses = $valid['addresses'];
    unset($valid['addresses']);
    db_execute_prepared(
        'INSERT INTO plugin_icct_nms_scan_jobs(poller_id,site_id,name,config_json,requested_by,total,created_at) VALUES(?,0,?,?,1,1,NOW())',
        [$config['poller_id'], 'QA', json_encode($valid)],
    );
    $id = (int) db_fetch_insert_id();
    db_execute_prepared('INSERT INTO plugin_icct_nms_scan_results(job_id,address) VALUES(?,?)', [
        $id,
        '127.0.0.1',
    ]);
    scanCheck(
        icct_nms_scan_work((int) $config['poller_id'] + 100000) === 0,
        'Wrong poller claimed a scan.',
    );
    scanCheck(
        icct_nms_scan_work((int) $config['poller_id']) === $id,
        'Correct poller did not claim a scan.',
    );
    $r = icct_nms_scan_result(
        (int) db_fetch_cell('SELECT id FROM plugin_icct_nms_scan_results LIMIT 1'),
    );
    scanCheck(
        $r['state'] === 'observed' && in_array(22, $r['evidence']['ports']['open'], true),
        'Real loopback SSH port not detected.',
    );
    scanCheck(icct_nms_scan_job($id)['state'] === 'completed', 'Job did not finish.');
    // Cancellation and retry preserve completed evidence and restart only unfinished work.
    db_execute_prepared(
        "UPDATE plugin_icct_nms_scan_jobs SET state='queued',cancel_requested=1 WHERE id=?",
        [$id],
    );
    icct_nms_scan_work((int) $config['poller_id']);
    // No pending address remains; add one to exercise the cancellation gate.
    db_execute_prepared('INSERT INTO plugin_icct_nms_scan_results(job_id,address) VALUES(?,?)', [
        $id,
        '127.0.0.2',
    ]);
    db_execute_prepared(
        "UPDATE plugin_icct_nms_scan_jobs SET state='queued',cancel_requested=1 WHERE id=?",
        [$id],
    );
    icct_nms_scan_work((int) $config['poller_id']);
    scanCheck(icct_nms_scan_job($id)['state'] === 'cancelled', 'Cancellation failed.');
    $savedEvidence = (string) db_fetch_cell_prepared(
        'SELECT evidence_json FROM plugin_icct_nms_scan_results WHERE id=?',
        [$r['id']],
    );
    icct_nms_scan_retry($id);
    scanCheck(icct_nms_scan_job($id)['state'] === 'queued', 'Retry did not queue pending work.');
    icct_nms_scan_work((int) $config['poller_id']);
    scanCheck(
        icct_nms_scan_job($id)['state'] === 'completed',
        'Retry did not finish pending work.',
    );
    scanCheck(
        (string) db_fetch_cell_prepared(
            'SELECT evidence_json FROM plugin_icct_nms_scan_results WHERE id=?',
            [$r['id']],
        ) === $savedEvidence,
        'Retry changed completed evidence.',
    );
    scanCheck(icct_nms_scan_existing('127.0.0.1') !== [], 'Existing core device match failed.');
    // Isolate every core table written by native device creation and its cache/tree hooks.
    foreach (
        [
            'host',
            'host_graph',
            'host_snmp_query',
            'poller_reindex',
            'settings',
            'poller',
            'graph_tree_items',
            'automation_devices',
            'automation_snmp_items',
            'automation_networks',
            'data_template_data',
            'graph_templates_graph',
        ] as $table
    ) {
        scanCheck(
            db_execute('CREATE TEMPORARY TABLE qa_scan_core LIKE ' . $table),
            'Core isolation failed.',
        );
        db_execute('INSERT INTO qa_scan_core SELECT * FROM ' . $table);
        scanCheck(
            db_execute('CREATE TEMPORARY TABLE ' . $table . ' LIKE qa_scan_core'),
            'Core isolation failed.',
        );
        db_execute('INSERT INTO ' . $table . ' SELECT * FROM qa_scan_core');
        db_execute('DROP TEMPORARY TABLE qa_scan_core');
    }
    $beforeCount = (int) db_fetch_cell("SELECT COUNT(*) FROM host WHERE deleted=''");
    // Existing Basic Information save path adopts discovered credentials only on the server.
    $proof2 = ['reachable' => true, 'snmp' => false, 'hostname' => '192.0.2.253'];
    db_execute_prepared(
        "INSERT INTO plugin_icct_nms_scan_results(job_id,address,state,evidence_json,observed_at) VALUES(?,?,'observed',?,NOW())",
        [$id, '192.0.2.253', json_encode($proof2)],
    );
    $wizardResult = (int) db_fetch_insert_id();
    $wizardInput = icct_nms_defaults() + [
        'short_name' => '',
        'serial_number' => '',
        'mac_address' => '',
        'chassis_id' => '',
        'category_id' => 0,
        'device_type' => '',
        'rack_id' => 0,
        'rack_position' => '',
        'cross_launch_url' => '',
        'enabled' => 1,
    ];
    $wizardInput = array_replace($wizardInput, icct_nms_scan_wizard_values($wizardResult), [
        'discovery_result' => $wizardResult,
        'description' => 'Existing wizard discovery QA',
        'host_template_id' => 0,
    ]);
    $wizardInput['notes'] = '';
    $wizardId = icct_nms_scan_wizard_save($wizardInput);
    scanCheck(
        $wizardId > 0 && icct_nms_device($wizardId)['hostname'] === '192.0.2.253',
        'Existing Add Device wizard integration failed.',
    );
    scanCheck(
        icct_nms_scan_wizard_save($wizardInput) === $wizardId,
        'Existing wizard retry created another native host.',
    );
    scanCheck(
        (int) icct_nms_device($wizardId)['snmp_version'] === 0,
        'Port-only discovery accidentally adopted SNMP secrets.',
    );
    scanCheck(
        (int) db_fetch_cell("SELECT COUNT(*) FROM host WHERE deleted=''") === $beforeCount + 1,
        'Unexpected native device count.',
    );
    $inventory = array_column(icct_nms_inventory(), null, 'id');
    scanCheck(isset($inventory[$wizardId]), 'Native device missing from Inventory.');
    scanCheck(
        (int) db_fetch_cell(
            'SELECT COUNT(*) FROM plugin_icct_nms_inventory_events WHERE host_id=' . $wizardId,
        ) === 1,
        'Onboarding event missing or duplicated.',
    );
    $rows = icct_nms_scan_rows($inventory);
    scanCheck(count($rows) >= 1, 'Existing topology table did not receive scan results.');
    // A result pointing to an unrelated saved device must never modify it.
    db_execute_prepared("UPDATE host SET external_id='' WHERE id=?", [$wizardId]);
    $beforeHosts = db_fetch_assoc(
        'SELECT id,description,host_template_id,external_id FROM host ORDER BY id',
    );
    scanCheck(
        icct_nms_scan_wizard_save(
            array_replace($wizardInput, ['description' => 'Must not overwrite saved host']),
        ) === $wizardId,
        'Existing native identity was not reused.',
    );
    scanCheck(
        db_fetch_assoc(
            'SELECT id,description,host_template_id,external_id FROM host ORDER BY id',
        ) === $beforeHosts,
        'Review overwrote an existing native device.',
    );

    icct_nms_inventory_observe((int) $config['poller_id']);
    $beforeEvents = (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_icct_nms_inventory_events');
    db_execute_prepared('UPDATE host SET status=1,description=? WHERE id=?', [
        'Changed QA identity',
        $wizardId,
    ]);
    icct_nms_inventory_observe((int) $config['poller_id']);
    icct_nms_inventory_observe((int) $config['poller_id']);
    scanCheck(
        (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_icct_nms_inventory_events') ===
            $beforeEvents + 1,
        'Inventory status/identity change was missing or duplicated.',
    );
    // Credential inheritance is server-side and cannot be replaced by a new-device starter preset.
    $credential = db_fetch_row('SELECT * FROM automation_snmp_items ORDER BY id LIMIT 1');
    if ($credential) {
        $snmpProof = [
            'reachable' => true,
            'snmp' => true,
            'hostname' => '192.0.2.252',
            'credential_hash' => hash('sha256', json_encode($credential, JSON_THROW_ON_ERROR)),
        ];
        db_execute_prepared(
            "INSERT INTO plugin_icct_nms_scan_results(job_id,address,state,evidence_json,credential_id,observed_at) VALUES(?,?,'observed',?,?,NOW())",
            [$id, '192.0.2.252', json_encode($snmpProof), $credential['id']],
        );
        $snmpResult = (int) db_fetch_insert_id();
        $serverValues = icct_nms_scan_wizard_host($snmpResult);
        scanCheck(
            (string) $serverValues['snmp_version'] === (string) $credential['snmp_version'] &&
                $serverValues['snmp_community'] === $credential['snmp_community'],
            'Discovered SNMP values were replaced by defaults.',
        );
        scanCheck(
            !array_key_exists('snmp_community', icct_nms_scan_wizard_values($snmpResult)) &&
                !array_key_exists('snmp_password', icct_nms_scan_wizard_values($snmpResult)),
            'Public discovery prefill contains credentials.',
        );
        db_execute_prepared(
            'UPDATE automation_snmp_items SET snmp_timeout=snmp_timeout+1 WHERE id=?',
            [$credential['id']],
        );
        try {
            icct_nms_scan_wizard_host($snmpResult);
            throw new LogicException('Changed discovery credentials were accepted.');
        } catch (InvalidArgumentException $error) {
            scanCheck(
                str_contains($error->getMessage(), 'Rescan'),
                'Wrong changed-credential failure.',
            );
        }
    }
    // Create this fixture only after automation_networks has been isolated above.
    // Empty production Networks must not silently skip scheduler integration coverage.
    require_once __DIR__ . '/../../../configuration/services/network_configuration_service.php';
    $networkInput = icct_nms_network_defaults(icct_nms_network_options()) + ['network_id' => 0];
    $networkInput = array_replace($networkInput, [
        'name' => 'Isolated native discovery scheduler QA',
        'subnet_range' => '127.0.0.1',
        'poller_id' => (int) $config['poller_id'],
        'site_id' => 0,
        'enabled' => 0,
        'ping_method' => 0,
        'run_limit' => 60,
    ]);
    $networkId = icct_nms_network_save($networkInput);
    scanCheck($networkId > 0, 'Native scheduler fixture was not saved.');
    db_execute_prepared(
        "UPDATE automation_networks SET poller_id=?,enabled='on',subnet_range='127.0.0.1',last_started=NOW(),run_limit=60 WHERE id=?",
        [$config['poller_id'], $networkId],
    );
    db_execute_prepared(
        'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)',
        [
            'discovery_network_' . $networkId,
            json_encode([
                'scan_methods' => ['ports'],
                'scan_ports' => '22',
                'scan_timeout' => 100,
                'scan_requested_by' => 1,
            ]),
        ],
    );
    $beforeJobs = (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_icct_nms_scan_jobs');
    icct_nms_scan_native_runs((int) $config['poller_id']);
    icct_nms_scan_native_runs((int) $config['poller_id']);
    scanCheck(
        (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_icct_nms_scan_jobs') ===
            $beforeJobs + 1,
        'Native run mirroring missed or duplicated a job.',
    );
    scanCheck(
        (int) db_fetch_cell(
            'SELECT network_id FROM plugin_icct_nms_scan_jobs ORDER BY id DESC LIMIT 1',
        ) === $networkId,
        'Native run lost its network association.',
    );
    unset($_SESSION['sess_user_id']);
    try {
        icct_nms_scan_job($id);
        throw new LogicException('Unauthorized scan read accepted.');
    } catch (RuntimeException $e) {
        scanCheck(
            str_contains($e->getMessage(), 'permission'),
            'Unexpected authorization failure.',
        );
    }
    // Remote workers must use the native primary session or fail closed without a replica fallback.
    $keys = [
        'config',
        'remote_db_cnn_id',
        'database_hostname',
        'database_port',
        'database_default',
        'rdatabase_hostname',
        'rdatabase_port',
        'rdatabase_default',
    ];
    $globalsBefore = [];
    foreach ($keys as $key) {
        $globalsBefore[$key] = [array_key_exists($key, $GLOBALS), $GLOBALS[$key] ?? null];
    }
    try {
        $config['poller_id'] = 2;
        $config['connection'] = 'offline';
        $remote_db_cnn_id = null;
        try {
            icct_backend_diag_worker_database();
            throw new LogicException('Offline remote primary was accepted.');
        } catch (RuntimeException $error) {
            scanCheck(
                str_contains($error->getMessage(), 'Primary Cacti database unavailable'),
                'Wrong offline remote failure.',
            );
        }
        $config['connection'] = 'online';
        $config['config_options_array'] = ['stale_permission' => 'must clear'];
        $remote_db_cnn_id = new stdClass();
        $rdatabase_hostname = 'qa-primary.invalid';
        $rdatabase_port = 3306;
        $rdatabase_default = 'qa_primary';
        icct_backend_diag_worker_database();
        scanCheck(
            $database_hostname === 'qa-primary.invalid' &&
                $database_default === 'qa_primary' &&
                $config['config_options_array'] === [],
            'Remote worker did not select primary database context and clear replica cache.',
        );
    } finally {
        foreach ($globalsBefore as $key => [$exists, $value]) {
            if ($exists) {
                $GLOBALS[$key] = $value;
            } else {
                unset($GLOBALS[$key]);
            }
        }
    }
    echo 'PASS: native range parsing, input bounds, collector isolation, real TCP scan, ' .
        'cancellation, native device creation, repeated approval, Inventory identity and ACL; no ' .
        "live writes.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
    exit(1);
}
