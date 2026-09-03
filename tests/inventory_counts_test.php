<?php
/** Regression coverage for empty, enabled, disabled, and transitional inventory counts. */
define('HOST_UP', 3);
define('HOST_DOWN', 1);
require_once(__DIR__ . '/../includes/functions.php');

/** Use the configured native cadence when checking whether a core status is current. */
function read_config_option($name) { return 300; }

function check_inventory_counts($devices, $expected) {
	foreach ($devices as &$device) if (!array_key_exists('last_updated', $device)) $device['last_updated'] = date('Y-m-d H:i:s');
	unset($device);
	$actual = nms_device_inventory_counts($devices);
	if ($actual !== $expected) throw new RuntimeException('Unexpected inventory counts: ' . json_encode($actual));
}

check_inventory_counts(array(), array('total' => 0, 'enabled' => 0, 'up' => 0, 'down' => 0));
check_inventory_counts(array(
	array('disabled' => '', 'status' => '3'),
	array('disabled' => '', 'status' => '1'),
	array('disabled' => '', 'status' => '0'),
	array('disabled' => '', 'status' => '2'),
	array('disabled' => 'on', 'status' => '3'),
	array('disabled' => 'on', 'status' => '1')
), array('total' => 6, 'enabled' => 4, 'up' => 1, 'down' => 1));
check_inventory_counts(array(
	array('disabled' => '', 'status' => '3', 'last_updated' => date('Y-m-d H:i:s', time() - 1800)),
	array('disabled' => '', 'status' => '1', 'last_updated' => date('Y-m-d H:i:s', time() + 30)),
	array('disabled' => '', 'status' => '3', 'last_updated' => '')
), array('total' => 3, 'enabled' => 3, 'up' => 0, 'down' => 0));
print "Inventory count tests passed.\n";
