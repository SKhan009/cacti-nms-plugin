<?php
/** Freshness and ACL contracts with deterministic Cacti doubles; no live polling or database. */
require_once(__DIR__ . '/../includes/functions.php');
require_once(__DIR__ . '/../includes/polling.php');
define('HOST_UP', 3);
define('HOST_UNKNOWN', 0);
define('HOST_DOWN', 1);
define('HOST_RECOVERING', 2);
define('HOST_ERROR', 4);
$config = array('base_path' => dirname(__DIR__, 3));
$writes = array();
$logs = array();
$schema_ready = true;
$write_failure = false;

/** Model schema availability without silently installing it from a poller hook. */
function db_fetch_cell($sql) { return 1; }
/** Simulate a completed upgrade or a partial upgrade marker. */
function db_fetch_cell_prepared($sql, $params) {
	if (!$GLOBALS['schema_ready']) return null;
	return $params[0] === 'equipment_categories_v1' ? 'complete' : '1.10.1';
}

/** Fail without relying on optional PHP assertions. */
function expect_fresh($ok, $message) { if (!$ok) throw new RuntimeException($message); }
/** Mirror configured Cacti cadence, not a plugin fallback. */
function read_config_option($key) { return '300'; }
/** Fixture for one native poller item and its reusable data-template item. */
function db_fetch_row_prepared($sql, $params) {
	if (strpos($sql, 'FROM poller_item') !== false) return array('local_data_id' => 17, 'host_id' => 5, 'data_template_id' => 4, 'snmp_index' => '9', 'name_cache' => 'Port 9');
	return array('local_data_template_rrd_id' => 28, 'data_template_id' => 4, 'data_source_name' => 'temperature', 'template_name' => 'Sensor');
}
/** Record writes to verify exact collection timestamps and immutable input. */
function db_execute_prepared($sql, $params) { global $writes; $writes[] = array($sql, $params); return !$GLOBALS['write_failure']; }
/** Native helper contracts used by the polling hook. */
function cacti_sizeof($value) { return count($value); }
/** Record diagnostics without masking an invalid timestamp. */
function cacti_log($text, $output, $source) { global $logs; $logs[] = $text; }
/** Return only the fixture's visible devices, independent of plugin classification. */
function get_allowed_devices($where, $order, $limit, &$total) { $total = 2; return array(array('id' => 2), array('id' => 9)); }
/** Native realm fixture. */
function is_realm_allowed($realm) { return $realm === 3; }
/** Native per-device fixture. */
function is_device_allowed($id) { return $id === 2 || $id === 9; }

$old_time = time() - 1800;
$now = time() - 2;
$payload = array('/qa.rrd' => array('times' => array(
	$old_time => array('temperature' => '55'), $now => array('temperature' => 'U'),
	(time() + 500) => array('temperature' => '99'), 'invalid' => array('temperature' => '88'))));
expect_fresh(nms_poller_output($payload) === $payload, 'NMS changed native poller payload');
expect_fresh(count($writes) === 2 && count($logs) === 2, 'Invalid timestamps were accepted or valid samples lost');
expect_fresh($writes[0][1][7] === date('Y-m-d H:i:s', $old_time), 'Historical sample was stamped as fresh now');
expect_fresh($writes[1][1][5] === 'U' && $writes[1][1][6] === null, 'Unknown sample replaced with a fallback');
expect_fresh(strpos($writes[0][0], 'GREATEST(last_seen, VALUES(last_seen))') !== false, 'Out-of-order replay guard missing');
$schema_ready = false;
$before_writes = count($writes);
expect_fresh(nms_poller_output($payload) === $payload, 'Incomplete upgrade interrupted native polling');
expect_fresh(count($writes) === $before_writes, 'Poller wrote or repaired an incomplete schema');
expect_fresh(strpos(end($logs), 'upgrade is incomplete') !== false, 'Incomplete upgrade was silently ignored');
$schema_ready = true;
$write_failure = true;
expect_fresh(nms_poller_output($payload) === $payload, 'Plugin write failure interrupted native RRD updates');
expect_fresh(strpos(end($logs), 'capture failed') !== false, 'Plugin storage failure was not reported');
$write_failure = false;
expect_fresh(!nms_parameter_is_fresh(date('Y-m-d H:i:s', $old_time)), 'Old sample considered current');
expect_fresh(!nms_parameter_is_fresh(date('Y-m-d H:i:s', time() + 30)), 'Future sample considered current');
expect_fresh(!nms_parameter_has_current_value(array('host_status' => HOST_UP, 'last_seen' => date('Y-m-d H:i:s'), 'raw_value' => 'U')), 'Unknown sample shown as current');
expect_fresh(nms_serial_observation_state('ok', date('Y-m-d H:i:s', $old_time), HOST_UP) === 'stale', 'Retained serial shown as live');
expect_fresh(nms_serial_observation_state('ok', date('Y-m-d H:i:s'), 1) === 'failed', 'Down host serial shown as live');
expect_fresh(nms_serial_observation_state('changed', date('Y-m-d H:i:s'), HOST_UP) === 'changed', 'Fresh changed serial hidden');
expect_fresh(nms_serial_observation_state('unconfigured', null, HOST_UP) === 'unconfigured', 'SNMP-disabled inventory was labeled as a pending live observation');
expect_fresh(nms_serial_observation_state('ok', date('Y-m-d H:i:s'), HOST_UP, '', date('Y-m-d H:i:s', $old_time)) === 'stale', 'Serial display ignored stale core state');
expect_fresh(nms_device_status_name(array('status' => HOST_UP, 'last_updated' => date('Y-m-d H:i:s', $old_time))) === 'Stale', 'Retained Up state was displayed as live');
expect_fresh(nms_device_status_name(array('status' => HOST_UP, 'last_updated' => '')) === 'Pending', 'Missing core update was considered live');
expect_fresh(nms_device_status_name(array('status' => HOST_UP, 'last_updated' => date('Y-m-d H:i:s'))) === 'Up', 'Fresh Up state was lost');
expect_fresh(nms_device_status_name(array('status' => HOST_UP, 'disabled' => 'on', 'last_updated' => date('Y-m-d H:i:s'))) === 'Disabled', 'Disabled device was considered Up');
expect_fresh(!nms_parameter_has_current_value(array('host_status' => HOST_UP, 'host_last_updated' => date('Y-m-d H:i:s', $old_time), 'last_seen' => date('Y-m-d H:i:s'), 'raw_value' => '20')), 'Current-value count ignored stale core state');
expect_fresh(nms_visible_host_sql() === 'h.id IN (2,9)', 'Device list bypassed native ACL');
nms_require_management();
nms_require_device_access(2);
try { nms_require_management(10); throw new LogicException('Realm bypass'); } catch (RuntimeException $expected) {}
try { nms_require_device_access(4); throw new LogicException('Device ACL bypass'); } catch (RuntimeException $expected) {}
echo "Freshness and ACL contracts passed: native timestamps, replay guards, unknown/future/stale rejection, serial state and native permissions.\n";
