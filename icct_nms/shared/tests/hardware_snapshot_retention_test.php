<?php
require __DIR__ . '/../../ports/services/backend/ports.php';
$stored = [];
function db_fetch_cell_prepared($sql, $args) {
    return $GLOBALS['stored'][$args[0]] ?? null;
}
function icct_backend_category_execute($sql, $args) {
    $GLOBALS['stored'][$args[0]] = $args[1];
}
function checkSnapshot($condition) {
    if (!$condition) {
        throw new RuntimeException('Hardware unplug snapshot retention failed');
    }
}
$host = ['id' => 2, 'hostname' => 'test'];
$usb = ['type' => 'USB', 'name' => '1-2', 'description' => 'USB disk', 'status' => 'Enumerated'];
$root = ['type' => 'USB', 'name' => 'usb1', 'description' => 'Root hub', 'status' => 'Enumerated'];
icct_backend_ports_store($host, [$usb, $root], 'Local Linux hardware', 'hardware_snapshot_');
icct_backend_ports_store($host, [$root], 'Local Linux hardware', 'hardware_snapshot_');
$ports = json_decode($stored['hardware_snapshot_2'], true)['ports'];
checkSnapshot(count($ports) === 2 && $ports[1]['name'] === '1-2' &&
    $ports[1]['status'] === 'Not present');
$visual = icct_nms_visual_hardware_ports(icct_nms_hardware_ports(
    ['ports' => $ports, 'fresh' => true, 'source' => 'Local Linux hardware'],
    ['ports' => []],
));
checkSnapshot(count($visual) === 1 && $visual[0]['oper'] === 2);
icct_backend_ports_store($host, [$usb, $root], 'Local Linux hardware', 'hardware_snapshot_');
checkSnapshot(json_decode($stored['hardware_snapshot_2'], true)['ports'][0]['status'] === 'Enumerated');
$host['hostname'] = 'different-device';
icct_backend_ports_store($host, [], 'Local Linux hardware', 'hardware_snapshot_');
checkSnapshot(json_decode($stored['hardware_snapshot_2'], true)['ports'] === []);
echo "Fresh unplug, reconnect and device identity changes passed\n";
