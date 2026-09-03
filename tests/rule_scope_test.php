<?php
/** Applicability contracts: genuine native context, strict filters and preserved legacy breadth; no live DB. */
require_once(__DIR__ . '/../includes/functions.php');
define('DATA_INPUT_TYPE_SNMP', 2);
define('DATA_INPUT_TYPE_SNMP_QUERY', 3);
$snmp_versions = array(0 => 'Not in use', 1 => 'Version 1', 2 => 'Version 2', 3 => 'Version 3');
$writes = array();
$hidden_devices = false;
$rule = array('id' => 7, 'category_id' => 5, 'metric' => 'parameter', 'parameter_key' => 'dtrr:12') + nms_rule_scope_defaults();

/** Fail without PHP assertion flags. */
function expect_scope($ok, $message) { if (!$ok) throw new RuntimeException($message); }
/** Keep native permission checking separate from category membership. */
function is_device_allowed($id) { return $id === 9; }
/** Native management realm fixture, distinct from the plugin's view realm. */
function is_realm_allowed($realm) { return $realm === 3; }
/** Native category-write visibility includes only the fixture device. */
function get_allowed_devices($where, $order, $limit, &$total) { $total = 1; return array(array('id' => 9)); }
/** Supply existing native records only; parameterized query contracts are tested below. */
function db_fetch_cell_prepared($sql, $params) {
	if (strpos($sql, 'AND NOT (') !== false) return $GLOBALS['hidden_devices'] ? 1 : 0;
	return in_array((int) $params[0], array(4, 5, 9, 12, 20), true) ? 1 : 0;
}
/** Return the selected fixture rule, never a substituted category rule. */
function db_fetch_row_prepared($sql, $params) { return $params === array(7, 5) ? $GLOBALS['rule'] : array(); }
/** Record only plugin policy writes; no core device/template mutation is authorized here. */
function db_execute_prepared($sql, $params = array()) {
	expect_scope(strpos($sql, 'UPDATE plugin_nms_fault_rules SET ') === 0, 'Applicability wrote outside plugin policy');
	$GLOBALS['writes'][] = array($sql, $params);
	return true;
}

$native = array('host_id' => 9, 'host_template_id' => 4, 'device_type' => 'Access switch',
	'snmp_sysObjectID' => '1.3.6.1.4.1.9.1.1',
	'data_input_id' => 20, 'input_type_id' => DATA_INPUT_TYPE_SNMP_QUERY, 'snmp_version' => 3,
	'local_data_id' => 12, 'snmp_query_id' => 5, 'snmp_index' => '7', 'is_interface' => 1);
expect_scope(nms_rule_scope_matches($rule, $native), 'Persisted defaults narrowed a migrated rule');
$filters = array('scope_kind' => 'interface', 'scope_host_template_id' => 4, 'scope_device_type' => 'Access switch',
	'scope_sys_object_id' => '1.3.6.1.4.1.9.1.1',
	'scope_data_input_id' => 20, 'scope_snmp_version' => '3', 'scope_host_id' => 9,
	'scope_local_data_id' => 12, 'scope_query_id' => 5, 'scope_index' => '7');
$validated = nms_rule_scope_validate($filters, $rule);
$scoped = array_merge($rule, $validated);
expect_scope(nms_rule_scope_matches($scoped, $native), 'Valid native interface scope did not match');
foreach (array('host_id' => 99, 'host_template_id' => 99, 'device_type' => 'UPS', 'data_input_id' => 99,
	'snmp_sysObjectID' => '1.3.6.1.4.1.9.1.2',
	'snmp_version' => 2, 'local_data_id' => 99, 'snmp_query_id' => 99, 'snmp_index' => '99', 'is_interface' => 0, 'input_type_id' => 1) as $key => $value) {
	expect_scope(!nms_rule_scope_matches($scoped, array_merge($native, array($key => $value))), 'Rule widened across mismatched ' . $key);
}
$missing = $scoped;
unset($missing['scope_kind']);
try { nms_rule_scope_matches($missing, $native); throw new LogicException('Missing schema widened a rule'); }
catch (RuntimeException $expected) {}
foreach (array(array('scope_kind' => 'invented'), array('scope_host_id' => '9x'), array('scope_snmp_version' => '0'),
	array('scope_host_id' => 0), array('scope_query_id' => 0), array('scope_local_data_id' => 99),
	array('scope_device_type' => array('bad')), array('scope_data_input_id' => 99)) as $invalid) {
	try { nms_rule_scope_validate(array_merge($filters, $invalid), $rule); throw new LogicException('Invalid applicability accepted'); }
	catch (InvalidArgumentException $expected) {}
}
try { nms_rule_scope_validate(array_merge($filters, array('scope_host_id' => 99)), $rule); throw new LogicException('Unauthorized device accepted'); }
catch (RuntimeException $expected) {}
foreach (array('component', 'service') as $kind) {
	$input = nms_rule_scope_defaults(); $input['scope_kind'] = $kind;
	try { nms_rule_scope_validate($input, $rule); throw new LogicException('Guessed component/service accepted'); }
	catch (InvalidArgumentException $expected) {}
	$input['scope_host_id'] = 9; $input['scope_local_data_id'] = 12;
	expect_scope(nms_rule_scope_matches(array_merge($rule, nms_rule_scope_validate($input, $rule)), $native), 'Explicit native data-source declaration was lost');
}
$input = nms_rule_scope_defaults(); $input['scope_snmp_version'] = '3';
try { nms_rule_scope_validate($input, array('metric' => 'core_status', 'category_id' => 5)); throw new LogicException('Core availability incorrectly assumed SNMP'); }
catch (InvalidArgumentException $expected) {}
nms_rule_scope_save(5, 7, $filters);
expect_scope(count($writes) === 1 && array_slice($writes[0][1], -2) === array(7, 5), 'Write escaped selected rule/category');
expect_scope(strpos($writes[0][0], 'threshold') === false && strpos($writes[0][0], 'parameter_key') === false, 'Scope edit changed rule meaning beyond its reviewed targets');
$hidden_devices = true;
try { nms_rule_scope_save(5, 7, $filters); throw new LogicException('Category policy changed inaccessible devices'); }
catch (RuntimeException $expected) {}
expect_scope(count($writes) === 1, 'Unauthorized category-wide policy was saved');
$hidden_devices = false;
$input = array('name' => 'Temperature °C', 'parameter_key' => 'dtrr:12', 'comparison' => 'greater_than',
	'threshold_value' => '80', 'unit' => '°C', 'severity' => 'warning', 'enabled' => true);
nms_fault_rule_save(5, 7, $input);
expect_scope($writes[1][1][0] === 'Temperature °C', 'Unicode rule name changed');
foreach (array(array('parameter_key' => 'core:status'), array('name' => str_repeat('x', 151)), array('unit' => array('bad'))) as $bad) {
	try { nms_fault_rule_save(5, 7, array_merge($input, $bad)); throw new LogicException('Rule identity or text silently changed'); }
	catch (InvalidArgumentException $expected) {}
}
expect_scope(count($writes) === 2, 'Invalid rule edit reached storage');
foreach (array('dtrr:12x', 'dtrr:0', array('dtrr:12')) as $bad) expect_scope(!nms_fault_parameter_exists(5, $bad), 'Malformed native parameter key accepted');
echo "Rule applicability contracts passed: persisted legacy breadth, native protocol/input/template filters, indexed interfaces, exact component/service targets, strict validation and device ACLs.\n";
