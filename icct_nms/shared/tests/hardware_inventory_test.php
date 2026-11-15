<?php
require __DIR__ . '/../../ports/services/backend/hardware.php';
$fixture = "ICCT-HARDWARE-1\nUSB\t1-2\tKeyboard\tEnumerated\nSerial\t/dev/ttyUSB0\tOS serial interface\tConnection not reported\nPCI/PCIe\t0000:00:01.0\tVendor 0x1234\tEnumerated\nDisplay connector\tcard0-HDMI-A-1\tGraphics driver connector\tdisconnected\nUSB\t1-2\tKeyboard\tEnumerated\n";
$rows = icct_nms_hardware_parse($fixture);
if (count($rows) !== 4 || $rows[1]['status'] !== 'Connection not reported' || $rows[3]['status'] !== 'disconnected') { throw new Exception('Inventory or duplicate handling failed'); }
foreach (['', 'permission denied', str_repeat('x', 1048577)] as $invalid) {
    try { icct_nms_hardware_parse($invalid); throw new Exception('Invalid response accepted'); }
    catch (RuntimeException $expected) {}
}
if (icct_nms_hardware_parse("ICCT-HARDWARE-1\n") !== []) { throw new Exception('Empty inventory failed'); }
$power = icct_nms_hardware_parse("ICCT-HARDWARE-1\nPower input\tAC\tMains\tconnected\nPower input\tUSB-C\tUSB_PD\tdisconnected\n");
$ports = icct_nms_hardware_ports(['ports' => $power, 'fresh' => true, 'source' => 'Local Linux hardware'], ['ports' => []]);
if (array_column($ports, 'oper') !== [1, 2] || array_column($ports, 'panel_port') !== ['AC', 'USB-C']) {
    throw new Exception('Power input states did not reach the port model');
}
$visual = icct_nms_visual_hardware_ports(icct_nms_hardware_ports([
    'ports' => [
        ['type' => 'USB', 'name' => '1-2', 'status' => 'Enumerated'],
        ['type' => 'USB', 'name' => 'usb1', 'status' => 'Enumerated'],
        ['type' => 'PCI/PCIe', 'name' => '0000:00:01.0', 'status' => 'Enumerated'],
        ...$power,
    ],
    'fresh' => true,
    'source' => 'Local Linux hardware',
], ['ports' => []]));
if (array_column($visual, 'panel_port') !== ['1-2', 'AC', 'USB-C']) {
    throw new Exception('Plugged USB and power inputs must appear without root hubs or PCI devices');
}
$panel = icct_nms_usb_panel_ports(
    [['type' => 'USB host', 'name' => 'usb1', 'description' => '4']],
    $visual,
);
if (array_column($panel, 'panel_port') !== ['1-1', '1-2', '1-3', '1-4', 'AC', 'USB-C'] ||
    empty($panel[0]['placeholder']) || !empty($panel[1]['placeholder'])) {
    throw new Exception('USB root hub capacity did not create truthful grey empty sockets');
}
echo "Hardware fixtures passed\n";
if (PHP_OS_FAMILY === 'Linux') {
    $output = shell_exec('/bin/sh -c ' . escapeshellarg(icct_nms_hardware_command()));
    $actual = icct_nms_hardware_parse($output);
    echo 'Offline Linux OS enumeration passed: ' . count($actual) . " entries\n";
}
