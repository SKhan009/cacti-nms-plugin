<?php
/** Explicit read-only native Cacti diagnostic; prints no passwords, communities or complete configuration files. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$nms_qa_options = getopt('', array('read-only', 'cacti-root:'));
if (!array_key_exists('read-only', $nms_qa_options) || empty($nms_qa_options['cacti-root'])) {
	fwrite(STDERR, "Usage: php environment_report.php --read-only --cacti-root=/actual/cacti/root\n");
	exit(2);
}
$nms_qa_root = realpath($nms_qa_options['cacti-root']);
if ($nms_qa_root === false || !is_file($nms_qa_root . '/include/cli_check.php')) {
	fwrite(STDERR, "The selected Cacti CLI bootstrap does not exist.\n");
	exit(2);
}
require_once($nms_qa_root . '/include/cli_check.php');
require_once(__DIR__ . '/../includes/database.php');
require_once(__DIR__ . '/../includes/snmpsim.php');

/** Query only a fixed allowlist of record counts; never enumerate secret-bearing row values. */
function nms_qa_record_counts() {
	$result = array();
	foreach (array('host', 'graph_templates', 'graph_local', 'data_local', 'graph_tree',
		'plugin_nms_categories', 'plugin_nms_fault_rules', 'plugin_nms_incidents', 'plugin_nms_events',
		'plugin_nms_device_metadata', 'plugin_nms_device_inventory') as $table) {
		$exists = db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', array($table));
		$result[$table] = $exists ? (int) db_fetch_cell('SELECT COUNT(*) FROM ' . $table) : null;
	}
	return $result;
}

/** Keep report variables out of Cacti's global configuration namespace. */
function nms_qa_environment_report() {
	global $config;
	$settings = array();
	foreach (array('path_cactilog', 'path_php_binary', 'path_snmpget', 'path_rrdtool', 'poller_interval',
		'cron_interval', 'snmp_timeout', 'snmp_retries', 'default_poller') as $key) $settings[$key] = read_config_option($key);
	$simulator = array();
	try {
		$sim = nms_snmpsim_config();
		foreach (array('activation', 'data_dir', 'executable', 'client_address', 'port', 'poller_id', 'service') as $key) {
			if (array_key_exists($key, $sim)) $simulator[$key] = $sim[$key];
		}
	} catch (RuntimeException $error) {
		$simulator['configuration_error'] = $error->getMessage();
	}
	$report = array(
		'mode' => 'read-only; no collection, schema migration or configuration writes',
		'cacti_root' => $config['base_path'], 'php' => PHP_VERSION, 'os_family' => PHP_OS_FAMILY,
		'php_utc' => gmdate('c'), 'php_timezone' => date_default_timezone_get(),
		'database_clock' => db_fetch_row('SELECT NOW() AS db_now, UTC_TIMESTAMP() AS db_utc, @@session.time_zone AS db_zone'),
		'cacti_version' => db_fetch_cell('SELECT cacti FROM version LIMIT 1'),
		'database_version' => db_fetch_cell('SELECT VERSION()'),
		'process_collector_id' => $config['poller_id'] ?? null,
		'database_connection_mode' => $config['connection'] ?? null,
		'extensions' => array_values(array_intersect(array('pdo_mysql', 'mysqli', 'mbstring', 'snmp', 'xml', 'gd', 'curl', 'openssl'), get_loaded_extensions())),
		'plugins' => db_fetch_assoc('SELECT directory, version, status FROM plugin_config ORDER BY directory'),
		'nms_schema_ready' => nms_database_ready(), 'counts' => nms_qa_record_counts(),
		'paths_and_polling' => $settings, 'optional_simulator' => $simulator,
		'log_device_1' => db_fetch_row_prepared('SELECT id, description, hostname, status, poller_id, snmp_version, snmp_port FROM host WHERE id = ?', array(1)),
		'log_query_51' => db_fetch_row_prepared('SELECT sq.id, sq.name, sq.xml_path, sq.data_input_id, di.type_id AS input_type FROM snmp_query AS sq LEFT JOIN data_input AS di ON di.id = sq.data_input_id WHERE sq.id = ?', array(51))
	);
	return $report;
}
echo json_encode(nms_qa_environment_report(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
