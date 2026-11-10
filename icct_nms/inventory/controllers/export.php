<?php

/**
 * CSV controller: export permitted saved inventory with spreadsheet formula protection.
 */

require dirname(__DIR__, 2) . '/../../include/auth.php';
require_once dirname(__DIR__, 2) . '/shared/services/bootstrap.php';
require_once dirname(__DIR__, 2) . '/inventory/services/inventory.php';
try {
    icct_nms_backend();
    if (isset($_GET['ntp'])) {
        $id = icct_nms_id($_GET['id'] ?? 0);
        icct_backend_require_device_access($id);
        require_once dirname(__DIR__, 2) . '/protocols/ntp/services/ntp_history.php';
        icct_nms_ntp_export_history($id, $_GET);
        exit();
    }
    $devices = icct_nms_inventory();
} catch (Throwable $e) {
    icct_nms_failure($e);
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="icct-inventory.csv"');
header('Cache-Control: no-store');
$out = fopen('php://output', 'w');
fputcsv(
    $out,
    [
        'ID',
        'Device Name',
        'Short Name',
        'Segment',
        'Device Type',
        'Status',
        'Serial Number',
        'MAC Address',
        'Hostname/IP',
        'Rack',
        'Start Unit',
        'Height',
        'Uptime',
        'Polling Interval (sec)',
        'Availability (%)',
    ],
    ',',
    '"',
    '',
);
foreach ($devices as $d) {
    $values = [
        $d['id'],
        $d['description'],
        $d['short_name'],
        $d['segment'],
        $d['device_type'],
        $d['status_label'],
        $d['serial_number'],
        $d['mac_address'],
        $d['hostname'],
        $d['rack_name'],
        $d['start_unit'],
        $d['unit_height'],
        icct_nms_uptime($d['snmp_sysUpTimeInstance']),
        $d['polling_interval'],
        $d['availability'],
    ];
    $values = array_map(static function ($v) {
        $v = (string) $v;
        return preg_match('/^[\s]*[=+@-]/', $v) ? "'" . $v : $v;
    }, $values);
    fputcsv($out, $values, ',', '"', '');
}
fclose($out);
