<?php
/**
 * CSV controller: export permitted saved inventory with spreadsheet formula protection.
 */

require __DIR__ . '/../../include/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/inventory.php';
try {
    icct_nms_backend();
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
        'Manual Serial Number',
        'Manual MAC Address',
        'Hostname/IP',
        'Rack',
        'Start Unit',
        'Height',
        'Uptime',
        'Polling Interval (sec)',
        'Availability (%)'
    ],
    ',',
    '"',
    ''
);
foreach ($devices as $d) {
    $values = [
        $d['id'],
        $d['description'],
        $d['short_name'],
        $d['segment'],
        $d['device_type'],
        $d['status_label'],
        $d['manual_serial_number'],
        $d['mac_address'],
        $d['hostname'],
        $d['rack_name'],
        $d['start_unit'],
        $d['unit_height'],
        icct_nms_uptime($d['snmp_sysUpTimeInstance']),
        $d['polling_interval'],
        $d['availability']
    ];
    $values = array_map(static function ($v) {
        $v = (string) $v;
        return preg_match('/^[\s]*[=+@-]/', $v) ? "'" . $v : $v;
    }, $values);
    fputcsv($out, $values, ',', '"', '');
}
fclose($out);
