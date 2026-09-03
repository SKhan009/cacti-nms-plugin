<?php
/** Schema lifecycle contracts with fault injection; native MariaDB migration tests remain mandatory. */
require_once(__DIR__ . '/../includes/database.php');
$queries = array();
$schema_marker = '1.10.1';
$fail_sql = null;
$locked = false;
$busy = false;
$missing_columns = array_merge(array('parameter_key', 'comparison', 'threshold_value', 'unit'), array_keys(nms_rule_scope_defaults()));
$unsupported_rules = array();

/** Model existing legacy rule semantics separately from database write failures. */
function db_fetch_assoc($sql) { return $GLOBALS['unsupported_rules']; }

/** Fail independently of PHP assertion configuration. */
function expect_lifecycle($condition, $message) { if (!$condition) throw new RuntimeException($message); }
/** Supply table/index metadata without pretending to execute DDL against a real server. */
function db_fetch_cell($sql) {
	if ($sql === 'SELECT DATABASE()') return 'qa_cacti';
	if (strpos($sql, 'information_schema.TABLES') !== false) return 1;
	if (strpos($sql, 'information_schema.STATISTICS') !== false) return 0;
	return 1;
}
/** Track advisory-lock ownership, markers and individual partially migrated columns. */
function db_fetch_cell_prepared($sql, $params) {
	global $queries, $locked, $busy, $schema_marker, $missing_columns;
	$queries[] = array($sql, $params);
	if (strpos($sql, 'GET_LOCK') !== false) { if ($busy) return 0; $locked = true; return 1; }
	if (strpos($sql, 'RELEASE_LOCK') !== false) { $locked = false; return 1; }
	if (strpos($sql, 'information_schema.COLUMNS') !== false) return in_array($params[0], $missing_columns, true) ? 0 : 1;
	if ($params[0] === 'equipment_categories_v1') return 'complete';
	if ($params[0] === 'nms_schema_version') return $schema_marker;
	throw new LogicException('Unexpected fixture read: ' . $sql);
}
/** Inject a false database return and ensure all writes are serialized and readiness is accurate. */
function db_execute_prepared($sql, $params = array()) {
	global $queries, $schema_marker, $fail_sql, $locked;
	expect_lifecycle($locked, 'Schema write occurred without the upgrade lock');
	$queries[] = array($sql, $params);
	if ($sql === $fail_sql) return false;
	if (strpos($sql, "DELETE FROM plugin_nms_meta WHERE meta_key = 'nms_schema_version'") !== false) $schema_marker = null;
	if (strpos($sql, "VALUES ('nms_schema_version', '1.10.1'") !== false) $schema_marker = '1.10.1';
	return true;
}

nms_setup_database();
expect_lifecycle(!$locked && nms_database_ready(), 'Successful upgrade did not release the lock or publish readiness');
$successful = $queries;
$writes = array();
foreach ($successful as $query) {
	if (preg_match('/^(CREATE|ALTER|UPDATE|INSERT|DELETE)/', $query[0])) $writes[$query[0]] = $query[0];
}
expect_lifecycle(count($writes) > 20, 'Fixture did not exercise the complete schema write sequence');
foreach ($writes as $candidate) {
	$queries = array();
	$schema_marker = '1.10.1';
	$fail_sql = $candidate;
	try { nms_setup_database(); throw new LogicException('Failed SQL did not abort upgrade'); }
	catch (RuntimeException $expected) {}
	expect_lifecycle(!$locked, 'Upgrade failure leaked its advisory lock');
	// If withdrawing readiness itself fails, the existing marker is untouched and no DDL follows.
	if (strpos($candidate, 'DELETE FROM plugin_nms_meta') === false) {
		expect_lifecycle(!nms_database_ready(), 'Failed upgrade advertised a usable schema');
	} else {
		expect_lifecycle(count($queries) === 3, 'DDL continued after failure to withdraw readiness');
	}
}
$fail_sql = null;
$missing_columns = array('unit');
$queries = array();
nms_setup_database();
$alters = array_filter(array_column($queries, 0), function($sql) {
	// A retry must add only the missing column, rather than re-add all four at once.
	return strpos($sql, 'ALTER TABLE plugin_nms_fault_rules ADD ') === 0 && strpos($sql, 'ADD KEY') === false;
});
expect_lifecycle(count($alters) === 1 && strpos(reset($alters), 'ADD unit ') !== false, 'Partial-column upgrade is not repeatable');
nms_setup_database();
expect_lifecycle(nms_database_ready(), 'Repeated upgrade lost readiness');
$unsupported_rules = array(array('id' => 42, 'metric' => 'rrd_missing_count'));
$queries = array();
try { nms_setup_database(); throw new LogicException('Unsupported legacy rule silently ignored'); }
catch (RuntimeException $expected) { expect_lifecycle(strpos($expected->getMessage(), '42') !== false, 'Migration error did not identify the retained rule'); }
expect_lifecycle(!nms_database_ready() && !$locked, 'Unsupported rule marked migrated or retained the lock');
foreach ($queries as $query) expect_lifecycle(strpos($query[0], 'DELETE FROM plugin_nms_fault_rules') === false, 'Legacy rule deleted');
$unsupported_rules = array();
$busy = true;
$queries = array();
try { nms_setup_database(); throw new LogicException('Concurrent upgrade entered schema writes'); }
catch (RuntimeException $expected) {}
expect_lifecycle(count($queries) === 1, 'Busy upgrade performed writes or released another owner’s lock');
echo "Database lifecycle contracts passed: checked writes, failure injection, readiness, lock release, partial-column retry and repeat upgrade. Native SQL execution remains unverified.\n";
