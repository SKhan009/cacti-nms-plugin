<?php
/** Standalone classification contracts; these doubles do not replace MariaDB migration QA. */
require_once(__DIR__ . '/../includes/categories.php');
$statements = array();
$next_id = 0;
$complete = false;
$fail_write = false;
$legacy_table = false;
$template_suggestion = 2;

/** Report failed contracts even when PHP assertions are disabled. */
function expect_category($condition, $message) {
	if (!$condition) throw new RuntimeException($message);
}
/** Supply a stable audit actor without a web session. */
function nms_current_user_id() { return 23; }
/** Record prepared writes and emulate only allocation/marker behavior. */
function db_execute_prepared($sql, $params = array()) {
	global $statements, $next_id, $complete, $fail_write;
	$statements[] = array($sql, $params);
	if ($fail_write) return false;
	if (strpos($sql, 'INSERT INTO plugin_nms_categories ') === 0) $next_id++;
	if (strpos($sql, "VALUES ('equipment_categories_v1', 'complete'") !== false) $complete = true;
	return true;
}
/** Record rollback for the failure contract. */
function db_execute($sql) { return db_execute_prepared($sql); }
/** Return two old tree references, including one whose core tree was deleted. */
function db_fetch_assoc($sql) {
	return array(array('legacy_tree_id' => 2, 'name' => 'Power'), array('legacy_tree_id' => 3, 'name' => null));
}
/** Emulate scalar metadata reads needed by the migration. */
function db_fetch_cell($sql) {
	global $next_id, $legacy_table;
	if ($sql === 'SELECT DATABASE()') return 'category_qa';
	if ($sql === 'SELECT LAST_INSERT_ID()') return $next_id;
	if (strpos($sql, 'information_schema.TABLES') !== false) return $legacy_table ? 1 : 0;
	if ($sql === 'SELECT COUNT(*) FROM plugin_nms_device_categories') return $legacy_table ? 1 : 0;
	return 0;
}
/** Emulate validated IDs and serialized migration access. */
function db_fetch_cell_prepared($sql, $params) {
	global $complete, $statements, $template_suggestion;
	if (strpos($sql, 'meta_value') !== false) return $complete ? 'complete' : false;
	if (strpos($sql, 'GET_LOCK') !== false || strpos($sql, 'RELEASE_LOCK') !== false) {
		$statements[] = array($sql, $params);
		return 1;
	}
	if (strpos($sql, 'SELECT category_id FROM plugin_nms_category_templates') === 0) return $template_suggestion;
	if (strpos($sql, 'FROM plugin_nms_categories WHERE id') !== false) return in_array((int) $params[0], array(1, 2), true) ? 1 : 0;
	if (strpos($sql, 'FROM host_template WHERE id') !== false || strpos($sql, 'FROM host WHERE id') !== false) return (int) $params[0] > 0 ? 1 : 0;
	if (strpos($sql, 'FROM plugin_nms_categories WHERE name') !== false) return $params[0] === 'Power' ? 1 : 0;
	return 0;
}

nms_category_schema();
nms_category_migrate();
expect_category($complete, 'Migration completion marker missing');
$joined_updates = 0;
$snapshot = false;
$mapping = array();
foreach ($statements as $statement) {
	list($sql, $params) = $statement;
	expect_category(!preg_match('/(?:UPDATE|DELETE FROM|INSERT INTO|ALTER TABLE|DROP TABLE)\s+(?:graph_tree|host\s|host_template\s)/i', $sql), 'Core object mutation in category migration');
	expect_category(strpos($sql, 'DELETE FROM plugin_nms_fault_rules') === false, 'Migration deletes rule history');
	if (strpos($sql, 'INSERT INTO plugin_nms_category_migration') === 0) $mapping[(int) $params[0]] = (int) $params[1];
	if (strpos($sql, 'UPDATE plugin_nms_') === 0 && strpos($sql, 'INNER JOIN plugin_nms_category_migration') !== false) $joined_updates++;
	if (strpos($sql, "'legacy_template'") !== false) $snapshot = true;
}
expect_category($mapping === array(2 => 1, 3 => 2), 'Explicit mapping lost overlapping old/new IDs');
expect_category($joined_updates === 3 && $snapshot, 'References or per-device snapshot missing');
$count = count($statements);
nms_category_migrate();
expect_category(count($statements) === $count, 'Repeat migration changed state');

$statements = array();
nms_assign_template_category(7, 2);
expect_category(count($statements) === 1 && strpos($statements[0][0], 'plugin_nms_category_templates') !== false, 'Template default reclassified existing devices');
nms_device_classification_save(8, 0, 'UPS', 'Backup power');
expect_category($statements[1][1] === array(8, 0, 'UPS', 'Backup power', 23), 'Explicit unclassified assignment or type/role changed');
expect_category(nms_new_device_category('template', 7) === 2, 'Explicit template suggestion not resolved');
expect_category(nms_new_device_category('0', 7) === 0, 'Unclassified incorrectly inherited a template');
$template_suggestion = 0;
try { nms_new_device_category('template', 7); throw new LogicException('Missing template suggestion accepted'); }
catch (InvalidArgumentException $expected) {}
foreach (array('', '-1', '2x', array(2), '99') as $invalid) {
	try { nms_new_device_category($invalid, 7); throw new LogicException('Invalid category accepted'); }
	catch (InvalidArgumentException $expected) {}
}
expect_category(nms_classification_text('  Sensor °C  ', 150) === 'Sensor °C', 'Unicode classification changed');
foreach (array("Bad\nText", str_repeat('x', 151), array('name'), new stdClass(), null, true) as $invalid) {
	try { nms_classification_text($invalid, 150); throw new LogicException('Invalid text accepted'); }
	catch (InvalidArgumentException $expected) {}
}

$complete = false;
$statements = array();
$legacy_table = true;
try { nms_category_migrate(); throw new LogicException('Ambiguous legacy namespace accepted'); }
catch (RuntimeException $expected) { expect_category(strpos($expected->getMessage(), 'Legacy category') !== false, 'Unexpected legacy error'); }
expect_category(!$complete, 'Ambiguous migration marked complete');
expect_category(strpos(end($statements)[0], 'RELEASE_LOCK') !== false, 'Migration lock not released after rejection');
$legacy_table = false;
$fail_write = true;
try { nms_category_migrate(); throw new LogicException('Database failure ignored'); }
catch (RuntimeException $expected) { expect_category(strpos($expected->getMessage(), 'storage operation failed') !== false, 'Unexpected failure'); }
expect_category(!$complete, 'Failed migration marked complete');
expect_category(strpos(end($statements)[0], 'RELEASE_LOCK') !== false, 'Failed migration lock not released');
echo "Category contracts passed: explicit migration map, preserved core/rules, independent assignment, repeat guard, validation and failure handling. MariaDB execution remains a separate gate.\n";
