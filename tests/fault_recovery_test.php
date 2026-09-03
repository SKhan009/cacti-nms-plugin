<?php
/** Exercise real incident evaluation with controlled Cacti rows; never poll a device or write a real database. */
require_once(__DIR__ . '/../includes/functions.php');
define('HOST_UP', 3);
define('HOST_DOWN', 1);
define('HOST_UNKNOWN', 0);
define('HOST_RECOVERING', 2);
define('HOST_ERROR', 4);
$core_rows = array();
$parameter_rows = array();
$inventory_rows = array();
$inventory_rules = array();
$writes = array();
$incidents = array(
	'device-rule:7:host:2:data:4' => array('id' => 11, 'status' => 'open', 'severity' => 'warning', 'title' => 'Temperature'),
	'inventory-rule:8:host:2' => array('id' => 12, 'status' => 'open', 'severity' => 'major', 'title' => 'Serial changed'),
	'inventory:host:2:serial_number' => array('id' => 13, 'status' => 'open', 'severity' => 'major', 'title' => 'Legacy implicit serial incident'),
	'device-rule:9:host:3' => array('id' => 14, 'status' => 'open', 'severity' => 'critical', 'title' => 'Unavailable rule')
);

/** Fail independently of runtime assertion flags. */
function expect_recovery($ok, $message) { if (!$ok) throw new RuntimeException($message); }
/** Return native collection cadence. */
function read_config_option($name) { return 300; }
/** Mirror Cacti's row-count helper. */
function cacti_sizeof($value) { return count($value); }
/** Silence fixture logging without altering evaluation behavior. */
function cacti_log($text, $output, $source) {}
/** Feed core, parameter and string-inventory rows independently. */
function db_fetch_assoc($sql) {
	if (strpos($sql, 'FROM plugin_nms_device_inventory') !== false) return $GLOBALS['inventory_rows'];
	return strpos($sql, "r.metric = 'core_status'") !== false ? $GLOBALS['core_rows'] : $GLOBALS['parameter_rows'];
}
/** Supply explicit policy and retained incident fingerprints, including an out-of-scope incident. */
function db_fetch_assoc_prepared($sql, $params) {
	if (strpos($sql, 'FROM plugin_nms_fault_rules') !== false) return $GLOBALS['inventory_rules'];
	$rows = array();
	foreach (array_keys($GLOBALS['incidents']) as $fingerprint) {
		if (strpos($fingerprint, $params[0]) === 0) $rows[] = array('fingerprint' => $fingerprint);
	}
	return $rows;
}
/** Read retained incidents for open/resolve operations. */
function db_fetch_row_prepared($sql, $params) { return $GLOBALS['incidents'][$params[0]] ?? array(); }
/** Record writes without changing the fixture between scenarios; optionally inject a native failure. */
function db_execute_prepared($sql, $params) {
	$GLOBALS['writes'][] = array($sql, $params);
	if (!empty($GLOBALS['fail_write']) && strpos($sql, $GLOBALS['fail_write']) !== false) return false;
	return true;
}

$fail_write = 'plugin_nms_events';
$write_failed_closed = false;
try {
	nms_event(99, 'opened', 'major', 'Controlled failure');
} catch (RuntimeException $error) {
	$write_failed_closed = $error->getMessage() === 'NMS storage operation failed. Check the Cacti database log.';
}
expect_recovery($write_failed_closed, 'An incident audit write failure was silently accepted');
$fail_write = '';
$writes = array();

$fresh = date('Y-m-d H:i:s');
$stale = date('Y-m-d H:i:s', time() - 1800);
$parameter = array('id' => 2, 'description' => 'Sensor', 'host_status' => HOST_UP, 'host_last_updated' => $fresh,
	'template_name' => 'Sensor template', 'category_name' => 'Sensors', 'rule_id' => 7, 'rule_name' => 'Temperature',
	'parameter_key' => 'dtrr:6', 'comparison' => 'greater_than', 'threshold_value' => '80', 'unit' => 'C', 'severity' => 'warning',
	'local_data_id' => 4, 'parameter_name' => 'temperature', 'display_name' => 'Sensor temperature', 'raw_value' => '20', 'last_seen' => $fresh);
$parameter += nms_rule_scope_defaults() + array('metric' => 'parameter');
foreach (array(array('last_seen' => $stale), array('host_status' => HOST_DOWN), array('host_last_updated' => $stale), array('raw_value' => 'U')) as $unverified) {
	$parameter_rows = array(array_merge($parameter, $unverified));
	$writes = array();
	nms_sync_device_faults();
	expect_recovery(!$writes, 'Unverified reading refreshed or resolved an incident');
}
$parameter_rows = array();
$writes = array();
nms_sync_device_faults();
expect_recovery(!$writes, 'Missing device/source/rule was treated as recovery');
$parameter_rows = array($parameter);
nms_sync_device_faults();
expect_recovery(count($writes) === 2 && $writes[0][1][2] === 11 && $writes[1][1][1] === 'resolved', 'Fresh clear value did not resolve only the evaluated incident');

$inventory = array('host_id' => 2, 'description' => 'Sensor', 'host_status' => HOST_UP, 'host_last_updated' => $fresh,
	'category_id' => 1, 'category_name' => 'Sensors', 'inventory_key' => 'serial_number', 'display_name' => 'Serial number',
	'oid' => '1.3.6.1.4.1.9.3.6.3.0', 'status' => 'ok', 'last_attempt' => $fresh, 'last_success' => $fresh);
$inventory_rules = array(array('id' => 8, 'name' => 'Serial changed', 'enabled' => 'on', 'comparison' => 'equals', 'threshold_value' => 'changed', 'severity' => 'major'));
$inventory_rules[0] += nms_rule_scope_defaults() + array('metric' => 'inventory_status');
foreach (array(array('last_attempt' => $stale), array('last_success' => $stale), array('host_last_updated' => $stale), array('host_status' => HOST_DOWN)) as $unverified) {
	$inventory_rows = array(array_merge($inventory, $unverified));
	$writes = array();
	nms_sync_inventory_faults();
	expect_recovery(!$writes, 'Unverified inventory check refreshed or resolved an incident');
}
$inventory_rows = array($inventory);
$inventory_rules[0]['threshold_value'] = 'changed';
foreach (array('failed', 'unconfigured', 'unknown') as $non_observation) {
	$inventory_rows = array(array_merge($inventory, array('status' => $non_observation)));
	$writes = array();
	nms_sync_inventory_faults();
	expect_recovery(!$writes, 'Missing observation falsely resolved an existing serial-change incident');
}
// Explicit failure policies still raise/refresh faults; suppressing false recovery must not suppress alarms.
$inventory_rows = array(array_merge($inventory, array('status' => 'failed')));
$inventory_rules[0]['threshold_value'] = 'failed';
$writes = array();
nms_sync_inventory_faults();
expect_recovery(count($writes) === 1 && strpos($writes[0][0], 'SET severity = ?') !== false &&
	$writes[0][1][7] === 12, 'Explicit failed-read policy stopped refreshing its incident');
$inventory_rules[0]['threshold_value'] = 'changed';
$inventory_rows = array($inventory);
$writes = array();
nms_sync_inventory_faults();
expect_recovery(count($writes) === 2 && $writes[0][1][2] === 12, 'Fresh serial recovery did not preserve the legacy unconfigured incident');
$inventory_rules = array();
$inventory_rows[0]['status'] = 'changed';
$writes = array();
nms_sync_inventory_faults();
expect_recovery(!$writes, 'Missing policy invented a default fault or cleared retained history');
echo "Fault recovery contracts passed: fresh explicit evidence only; stale/down/unknown/missing sources and absent inventory rules do not fabricate recovery or refresh old incidents.\n";
