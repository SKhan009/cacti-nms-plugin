<?php
/**
 * @file device_metadata_test.php
 * Run: php tests/device_metadata_test.php. No Cacti installation is required.
 * Database doubles reject writes outside NMS metadata.
 */
require_once(__DIR__ . '/../includes/device_metadata.php');
$metadata = array();
$writes = array();
$fail_write = false;
$_SESSION['sess_user_id'] = 7;
define('HOST_UP', 3);

/** Fail explicitly instead of relying on PHP's optional assertion settings. */
function metadata_check($condition, $message) {
	if (!$condition) throw new Exception($message);
}

/** Use a deterministic core poll interval while exercising the real freshness helper. */
function read_config_option($name) {
	metadata_check($name === 'poller_interval', 'Unexpected configuration read');
	return 300;
}

/** Check the device-to-template-to-OID query contract and return a linked-reading fixture. */
function db_fetch_row_prepared($sql, $params) {
	metadata_check($params === array(2), 'Serial lookup must be scoped to the requested device');
	metadata_check(strpos($sql, 'i.host_template_id = h.host_template_id') !== false, 'Current template link missing');
	metadata_check(strpos($sql, 'di.oid = o.oid') !== false, 'Observation must match the linked OID');
	metadata_check(strpos($sql, 'ORDER BY i.id DESC, o.id LIMIT 1') !== false, 'Serial definition order must match polling');
	metadata_check(strpos($sql, 'raw_value') === false, 'Imported sample must never be selected');
	return array('oid' => '1.3.6.1.4.1.9.3.6.3.0');
}

/** Simulate existing-device checks and plugin metadata lookups. */
function db_fetch_cell_prepared($sql, $params) {
	global $metadata;
	if (strpos($sql, 'SELECT COUNT(*) FROM host') === 0) return in_array($params[0], array(2, 3), true) ? 1 : 0;
	if (strpos($sql, 'SELECT serial_number FROM plugin_nms_device_metadata') === 0) return $metadata[$params[0]] ?? false;
	throw new Exception('Unexpected database read: ' . $sql);
}

/** Emulate upsert/clear operations and reject writes to core tables or live inventory. */
function db_execute_prepared($sql, $params) {
	global $metadata, $writes, $fail_write;
	$writes[] = $sql;
	if ($fail_write) return false;
	if (strpos($sql, 'INSERT INTO plugin_nms_device_metadata') === 0) {
		metadata_check($params[2] === 7, 'Audit actor missing');
		$metadata[$params[0]] = $params[1];
	} elseif (strpos($sql, 'DELETE FROM plugin_nms_device_metadata') === 0) {
		unset($metadata[$params[0]]);
	} else {
		throw new Exception('Write escaped plugin metadata: ' . $sql);
	}
	return true;
}

metadata_check(nms_manual_serial_get(2) === '', 'Missing metadata must not synthesize a live value');
metadata_check(nms_manual_serial_save(2, '  FOC12345678  ') === 'FOC12345678', 'Serial must be trimmed');
metadata_check(nms_manual_serial_get(2) === 'FOC12345678', 'Saved serial must reload');
nms_manual_serial_save(3, 'OTHER');
nms_manual_serial_save(2, '0');
metadata_check(nms_manual_serial_get(2) === '0', 'Zero is valid serial text');
metadata_check(nms_manual_serial_get(3) === 'OTHER', 'Device values must be independent');
nms_manual_serial_save(2, '<test>&"serial');
metadata_check(nms_manual_serial_get(2) === '<test>&"serial', 'Store text literally; escape at rendering');
nms_manual_serial_save(2, str_repeat('A', 191));
metadata_check(strlen(nms_manual_serial_get(2)) === 191, 'Maximum length must round-trip');
foreach (array(array('bad'), null, "line\nbreak", "tab\tvalue", "nul\0value", "\xFF", str_repeat('A', 192)) as $invalid) {
	$before = count($writes);
	try {
		nms_manual_serial_save(2, $invalid);
		throw new Exception('Invalid serial accepted');
	} catch (InvalidArgumentException $expected) {
		metadata_check(count($writes) === $before, 'Invalid input caused a write');
	}
}
foreach (array(0, -1, 999) as $missing_host) {
	$before = count($writes);
	try {
		nms_manual_serial_save($missing_host, 'ABC');
		throw new Exception('Missing device accepted');
	} catch (InvalidArgumentException $expected) {
		metadata_check(count($writes) === $before, 'Missing device caused a write');
	}
}
nms_manual_serial_save(2, '   ');
metadata_check(nms_manual_serial_get(2) === '', 'Blank input must clear metadata');
metadata_check(nms_manual_serial_get(3) === 'OTHER', 'Clear must not affect another device');
$fail_write = true;
try {
	nms_manual_serial_save(2, 'ABC');
	throw new Exception('Database failure was hidden');
} catch (RuntimeException $expected) {
	metadata_check(nms_manual_serial_get(2) === '', 'Failed save changed metadata');
}
// Reading or prefilling the form must never persist a suggestion automatically.
$before = count($writes);
metadata_check(nms_device_serial_reading(2)['oid'] === '1.3.6.1.4.1.9.3.6.3.0', 'Linked serial definition missing');
$live = array('host_status' => HOST_UP, 'disabled' => '', 'oid' => '1.3.6.1.4.1.9.3.6.3.0',
	'observed_value' => 'LIVE123', 'status' => 'ok', 'last_success' => date('Y-m-d H:i:s'), 'host_last_updated' => date('Y-m-d H:i:s'));
$suggestion = nms_serial_form_prefill('', $live);
metadata_check($suggestion['value'] === 'LIVE123' && $suggestion['source'] === 'snmp', 'Fresh serial should prefill');
metadata_check($suggestion['oid'] === $live['oid'] && $suggestion['last_success'] === $live['last_success'], 'Prefill provenance missing');
metadata_check(nms_serial_form_prefill('', array_merge($live, array('host_last_updated' => date('Y-m-d H:i:s', time() - 1800))))['value'] === '', 'Serial suggestion ignored stale core state');
metadata_check(nms_serial_form_prefill('MANUAL', $live)['value'] === 'MANUAL', 'Saved serial was overwritten');
metadata_check(nms_serial_form_prefill('0', $live)['source'] === 'saved', 'Saved zero must not be treated as absent');
metadata_check(nms_serial_form_prefill('', $live, false)['value'] === '', 'Clear confirmation must stay blank');
metadata_check(nms_serial_form_prefill('', array())['value'] === '', 'Missing OID must not produce a suggestion');
metadata_check(nms_serial_form_prefill('', array_merge($live, array('status' => 'changed')))['value'] === 'LIVE123', 'Changed-but-successful serial should remain reviewable');
foreach (array(
	array('status' => 'failed'), array('status' => 'unknown'), array('host_status' => 1), array('disabled' => 'on'),
	array('oid' => ''), array('last_success' => ''), array('last_success' => 'invalid'),
	array('last_success' => date('Y-m-d H:i:s', time() - 3600)), array('last_success' => date('Y-m-d H:i:s', time() + 3600)),
	array('observed_value' => 'unknown'), array('observed_value' => 'No Such Instance'), array('observed_value' => ''),
	array('observed_value' => str_repeat('A', 192)), array('observed_value' => array('bad'))
) as $invalid_reading) {
	metadata_check(nms_serial_form_prefill('', array_merge($live, $invalid_reading))['value'] === '', 'Unsafe/stale reading was suggested');
}
metadata_check(count($writes) === $before, 'Reading/prefilling the form caused a write');
print "Device metadata and serial-prefill tests passed.\n";
