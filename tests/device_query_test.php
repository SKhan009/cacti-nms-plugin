<?php
/** Exercise query attachment through the native API boundary with remapped input-record IDs. */
define('DATA_INPUT_TYPE_SNMP', 2);
define('DATA_INPUT_TYPE_SNMP_QUERY', 3);
$version = 0;
$attached = array();
$reindex_types = array(0 => 'None', 1 => 'Uptime');
$queries = array(51 => array('input_id' => 42, 'type_id' => DATA_INPUT_TYPE_SNMP_QUERY),
	52 => array('input_id' => 2, 'type_id' => 1), 53 => array('input_id' => 43, 'type_id' => DATA_INPUT_TYPE_SNMP));

/** Fail independently of assertion configuration. */
function query_expect($ok, $message) { if (!$ok) throw new RuntimeException($message); }

/** Return native host/query metadata and verify parameterized protocol filtering. */
function db_fetch_cell_prepared($sql, $params) {
	if (strpos($sql, 'SELECT COUNT(*) FROM host') === 0) return $params === array(8) ? 1 : 0;
	if (strpos($sql, 'SELECT snmp_version FROM host') === 0) return $GLOBALS['version'];
	query_expect(strpos($sql, 'INNER JOIN data_input AS di ON di.id = sq.data_input_id') !== false, 'Input method not resolved through native records');
	$query = $GLOBALS['queries'][$params[0]] ?? null;
	if ($GLOBALS['version'] === 0) {
		query_expect(strpos($sql, 'di.type_id NOT IN (?, ?)') !== false && array_slice($params, 1) === array(DATA_INPUT_TYPE_SNMP, DATA_INPUT_TYPE_SNMP_QUERY), 'SNMP-disabled validation uses record IDs instead of native protocol types');
		return $query && !in_array($query['type_id'], array_slice($params, 1), true) ? 1 : 0;
	}
	query_expect(count($params) === 1, 'Enabled SNMP was incorrectly restricted');
	return $query ? 1 : 0;
}

/** Observe the genuine wrapper's native attachment call, without writing Cacti records. */
function api_device_dq_add($host_id, $query_id, $method) { $GLOBALS['attached'][] = array($host_id, $query_id, $method); }

$temp = sys_get_temp_dir() . '/nms-query-' . bin2hex(random_bytes(8));
if (!mkdir($temp, 0700) || !mkdir($temp . '/lib', 0700)) throw new RuntimeException('Cannot create fixture');
$files = array('api_device.php', 'api_automation.php', 'api_graph.php', 'data_query.php', 'template.php');
foreach ($files as $file) file_put_contents($temp . '/lib/' . $file, "<?php // Native functions are doubled in this test.\n");
$config = array('base_path' => $temp);
try {
	require_once(__DIR__ . '/../includes/device_manager.php');
	foreach (array(51, 53, 999, 0) as $invalid_query) {
		try { nms_device_add_data_query(8, $invalid_query, 1); throw new LogicException('Invalid or SNMP query accepted on an SNMP-disabled device'); }
		catch (InvalidArgumentException $expected) {}
	}
	query_expect(!$attached, 'Rejected query reached native write API');
	query_expect(nms_device_add_data_query(8, 52, 1) === 52, 'Script input with record ID 2 was incorrectly excluded');
	query_expect($attached === array(array(8, 52, 1)), 'Native attachment arguments changed');
	$version = 2;
	nms_device_add_data_query(8, 51, 0);
	query_expect(end($attached) === array(8, 51, 0), 'Remapped native SNMP query was not attached');
	try { nms_device_add_data_query(8, 51, 999); throw new LogicException('Invalid native reindex method accepted'); }
	catch (InvalidArgumentException $expected) {}
	query_expect(count($attached) === 2, 'Invalid reindex method reached write API');
	$controller = file_get_contents(__DIR__ . '/../devices.php');
	query_expect(strpos($controller, "\$data_query_filter = ' AND di.type_id NOT IN (?, ?)'") !== false, 'UI protocol predicate missing');
	query_expect(strpos($controller, '$data_query_params[] = DATA_INPUT_TYPE_SNMP;') !== false &&
		strpos($controller, '$data_query_params[] = DATA_INPUT_TYPE_SNMP_QUERY;') !== false &&
		strpos($controller, 'sq.data_input_id != 2') === false, 'UI and save-path protocol checks diverged');
} finally {
	foreach ($files as $file) unlink($temp . '/lib/' . $file);
	rmdir($temp . '/lib');
	rmdir($temp);
}
echo "Device query contracts passed: native protocol types, remapped input IDs, UI/save parity and native attachment/reindex arguments. Native SQL/browser validation remains pending.\n";
