<?php
/** Regression coverage for empty, enabled, disabled, and transitional inventory counts. */
define('HOST_UP', 3);
define('HOST_DOWN', 1);
require_once(__DIR__ . '/../includes/functions.php');

function check_inventory_counts($devices, $expected) {
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
print "Inventory count tests passed.\n";
