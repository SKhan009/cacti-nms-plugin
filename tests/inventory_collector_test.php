<?php
/** Run the inventory collector against native API/database doubles, without real SNMP or SQL writes. */
require_once(__DIR__ . '/../includes/inventory.php');
define('HOST_UP', 3);
define('SNMP_STRING_OUTPUT_ASCII', 1);
$native_retries = '4';
$collector_enabled = true;
$write_failure = false;
$writes = $probe_calls = $selects = array();
$probe_result = 'SERIAL-LIVE';
$retained = array('baseline_value' => 'SERIAL-ORIGINAL', 'observed_value' => 'SERIAL-PREVIOUS');

/** Explicit failures remain visible when assertions are disabled. */
function inventory_expect($condition, $message) {
	if (!$condition) throw new RuntimeException($message);
}

/** Native metadata check rejects missing/disabled collectors instead of choosing another one. */
function db_fetch_cell_prepared($sql, $params) {
	inventory_expect(strpos($sql, "FROM poller WHERE id = ? AND disabled = ''") !== false, 'Enabled native collector check missing');
	return $params === array(7) && $GLOBALS['collector_enabled'] ? 1 : 0;
}

/** Assert collector scoping in the real query, then emulate its host and collector predicates. */
function db_fetch_assoc_prepared($sql, $params) {
	inventory_expect(strpos($sql, 'h.poller_id = ?') !== false, 'Inventory query is not collector scoped');
	inventory_expect(strpos($sql, 'i.host_template_id = h.host_template_id') !== false, 'Native device-template mapping missing');
	inventory_expect(strpos($sql, 'raw_value') === false, 'Imported samples selected as inventory');
	$GLOBALS['selects'][] = array($sql, $params);
	return array_values(array_filter($GLOBALS['definitions'], function($row) use ($params) {
		return $row['poller_id'] === $params[0] && $row['disabled'] === '' && $row['deleted'] === '' &&
			(count($params) === 1 || $row['host_id'] === $params[1]);
	}));
}

/** Supply the saved SNMP baseline without touching manual serial metadata. */
function db_fetch_row_prepared($sql, $params) {
	inventory_expect(strpos($sql, 'FROM plugin_nms_device_inventory') !== false && $params === array(21, 'serial_number'), 'Baseline read escaped its device');
	return $GLOBALS['retained'];
}

/** Record checked writes and inject the database false result used by Cacti on failure. */
function db_execute_prepared($sql, $params) {
	inventory_expect(strpos($sql, 'INSERT INTO plugin_nms_device_inventory') === 0, 'Collector attempted a destructive/global write');
	$GLOBALS['writes'][] = array($sql, $params);
	return !$GLOBALS['write_failure'];
}

/** No cleanup may infer deletion from a collector's partial host cache. */
function db_execute($sql) { throw new LogicException('Inventory collector attempted unchecked SQL'); }

/** Verify retry policy is read from the native setting, not a simulator setting. */
function read_config_option($key) {
	inventory_expect($key === 'snmp_retries', 'Unexpected setting');
	return $GLOBALS['native_retries'];
}

/** Minimal native row-count contract. */
function cacti_sizeof($value) { return count($value); }

/** Capture actual collection arguments; never transmit fixture credentials. */
function cacti_snmp_get(...$args) {
	$GLOBALS['probe_calls'][] = $args;
	$GLOBALS['snmp_error'] = 'credential-sentinel-do-not-store';
	return $GLOBALS['probe_result'];
}

$temp = sys_get_temp_dir() . '/nms-inventory-' . bin2hex(random_bytes(8));
if (!mkdir($temp, 0700) || !mkdir($temp . '/lib', 0700)) throw new RuntimeException('Cannot create fixture');
file_put_contents($temp . '/lib/snmp.php', "<?php // Test owns the native SNMP double.\n");
$config = array('base_path' => $temp, 'poller_id' => '7');
$local = array('host_id' => 21, 'poller_id' => 7, 'hostname' => '127.0.0.1', 'status' => HOST_UP,
	'disabled' => '', 'deleted' => '', 'snmp_community' => 'fixture-community', 'snmp_version' => 2,
	'snmp_username' => '', 'snmp_password' => '', 'snmp_auth_protocol' => '', 'snmp_priv_passphrase' => '',
	'snmp_priv_protocol' => '', 'snmp_context' => '', 'snmp_engine_id' => '', 'snmp_port' => 20161,
	'snmp_timeout' => 2300, 'inventory_key' => 'serial_number', 'oid' => '1.3.6.1.4.1.9.3.6.3.0', 'import_id' => 12);
$definitions = array($local, array_merge($local, array('host_id' => 22, 'poller_id' => 1)),
	array_merge($local, array('host_id' => 23, 'disabled' => 'on')), array_merge($local, array('host_id' => 24, 'deleted' => 'on')));
try {
	foreach (array(null, 0, -1, false, '07', '7x', array(7), 4294967296) as $bad) {
		$config['poller_id'] = $bad;
		try { nms_collect_inventory_values(); throw new LogicException('Invalid process identity accepted'); }
		catch (RuntimeException $expected) {}
	}
	inventory_expect(!$probe_calls && !$writes && !$selects, 'Invalid process identity performed inventory work');
	$config['poller_id'] = 7;
	$poller_id = 1;
	try { nms_collect_inventory_values(); throw new LogicException('Different CLI collector override accepted'); }
	catch (RuntimeException $expected) {}
	$poller_id = 7;
	$collector_enabled = false;
	try { nms_collect_inventory_values(); throw new LogicException('Disabled collector accepted'); }
	catch (RuntimeException $expected) {}
	$collector_enabled = true;
	inventory_expect(nms_collect_inventory_values() === 1, 'Wrong collector or disabled/deleted device collected');
	inventory_expect(count($probe_calls) === 1 && count($writes) === 1, 'Inventory crossed collector boundaries');
	inventory_expect($probe_calls[0][10] === 20161 && $probe_calls[0][11] === 2300 && $probe_calls[0][12] === 4, 'Native connection policy not retained');
	inventory_expect($writes[0][1][4] === 'SERIAL-ORIGINAL' && $writes[0][1][5] === 'SERIAL-LIVE' && $writes[0][1][6] === 'changed', 'Live result or baseline was fabricated');
	$writes = $probe_calls = array();
	inventory_expect(nms_collect_inventory_values(22) === 0, 'Explicit host filter bypassed collector ownership');
	inventory_expect(!$writes && !$probe_calls && end($selects)[1] === array(7, 22), 'Foreign host filter performed collection');
	foreach (array('', '-1', 'four', null, true) as $bad) {
		$native_retries = $bad;
		try { nms_collect_inventory_values(); throw new LogicException('Invalid native retries silently defaulted'); }
		catch (RuntimeException $expected) {}
	}
	inventory_expect(!$writes && !$probe_calls, 'Invalid retry policy performed a probe/write');
	$native_retries = '0';
	$definitions = array($local, $local); // Duplicate import mapping must not duplicate this observation.
	inventory_expect(nms_collect_inventory_values(21) === 1 && count($probe_calls) === 1, 'Duplicate import polled twice');
	inventory_expect($probe_calls[0][12] === 0, 'Explicit zero retries was replaced');
	foreach (array(array('status' => 1), array('snmp_version' => 0)) as $unpollable) {
		$definitions = array(array_merge($local, $unpollable));
		$writes = $probe_calls = array();
		nms_collect_inventory_values();
		inventory_expect(!$probe_calls && count($writes) === 1, 'Down/SNMP-disabled device was probed');
		inventory_expect($writes[0][1][4] === (isset($unpollable['snmp_version']) ? 'unconfigured' : 'failed'), 'Skipped collection status was mislabeled');
		inventory_expect(strpos($writes[0][0], 'last_success') === false && strpos($writes[0][0], 'observed_value') === false, 'Skipped read refreshed a successful observation');
	}
	$definitions = array($local);
	$probe_result = 'U';
	$writes = $probe_calls = array();
	nms_collect_inventory_values();
	inventory_expect(count($probe_calls) === 1 && strpos($writes[0][0], 'last_success') === false, 'Failed SNMP was recorded as success');
	inventory_expect(strpos(json_encode($writes), 'credential-sentinel-do-not-store') === false, 'Raw backend diagnostic leaked into inventory storage');
	// Every existing write branch must reject a false database result.
	foreach (array(array('status' => 1), array('status' => HOST_UP)) as $state) {
		foreach (array('U', 'SERIAL-LIVE') as $response) {
			$definitions = array(array_merge($local, $state));
			$probe_result = $response;
			$write_failure = true;
			try { nms_collect_inventory_values(); throw new LogicException('Failed inventory write reported success'); }
			catch (RuntimeException $expected) {}
		}
	}
} finally {
	unlink($temp . '/lib/snmp.php');
	rmdir($temp . '/lib');
	rmdir($temp);
}
echo "Inventory collector contracts passed: native ownership, explicit host isolation, configured retries, no skipped-read success, preserved baselines and checked writes. Native database/remote polling remain unverified.\n";
