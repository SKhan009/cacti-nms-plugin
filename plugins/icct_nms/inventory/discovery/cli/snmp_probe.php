<?php

/** One native credential attempt per process; no persistent credentials or device writes. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
ob_start();
try {
    $request = json_decode(stream_get_contents(STDIN, 65537), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($request) || !filter_var($request['address'] ?? '', FILTER_VALIDATE_IP) ||
        !is_array($request['credential'] ?? null)) {
        throw new RuntimeException('Invalid native SNMP probe request.');
    }
    require __DIR__ . '/../../../../../include/cli_check.php';
    require_once $config['base_path'] . '/lib/snmp.php';
    require_once $config['base_path'] . '/lib/api_automation.php';
    // Shadow only this connection's credential table so native discovery tries exactly one entry.
    // The parent supplies its current primary-database credential; live tables remain untouched.
    if (!db_execute('CREATE TEMPORARY TABLE icct_probe_credential LIKE automation_snmp_items') ||
        !db_execute('CREATE TEMPORARY TABLE automation_snmp_items LIKE icct_probe_credential')) {
        throw new RuntimeException('Cannot isolate native SNMP credential.');
    }
    $columns = array_keys(db_get_table_column_types('automation_snmp_items'));
    if (array_diff($columns, array_keys($request['credential'])) ||
        array_diff(array_keys($request['credential']), $columns)) {
        throw new RuntimeException('Native SNMP credential schema changed. Rescan.');
    }
    // Prepared values avoid sql_save's development log, which serializes authentication fields.
    if (!db_execute_prepared(
        'INSERT INTO automation_snmp_items (`' . implode('`,`', $columns) . '`) VALUES (' .
            implode(',', array_fill(0, count($columns), '?')) . ')',
        array_map(static function ($column) use ($request) {
            return $request['credential'][$column];
        }, $columns),
        false,
    )) {
        throw new RuntimeException('Cannot stage native SNMP credential.');
    }
    $device = ['ip_address' => $request['address'], 'snmp_id' => $request['credential']['snmp_id']];
    $ok = (bool) automation_valid_snmp_device($device);
    // Return measured identity fields only; native device arrays also contain authentication secrets.
    $identity = array_intersect_key($device, array_flip([
        'snmp_version', 'snmp_sysName', 'snmp_sysDescr', 'snmp_sysObjectID',
        'snmp_sysLocation', 'snmp_sysContact', 'snmp_sysUptime',
    ]));
    ob_end_clean();
    echo json_encode($ok ? ['ok' => true, 'device' => $identity] : ['ok' => false], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    ob_end_clean();
    // Never echo native exceptions: some SNMP diagnostics include credentials.
    fwrite(STDERR, "Native SNMP probe failed.\n");
    exit(1);
}
