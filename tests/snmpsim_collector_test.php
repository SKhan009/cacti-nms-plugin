<?php
/** Collector/import/probe contracts using native API doubles, not real SNMP or a VM. */
require_once(__DIR__ . '/../includes/snmpsim.php');
define('AVAIL_SNMP', 2);
define('SNMP_STRING_OUTPUT_ASCII', 1);
$collector_enabled = true;
$probe_calls = array();
$probe_result = 'live-observation';
$native_settings = array('snmp_timeout' => '2500', 'snmp_retries' => '3');

/** Fail even when PHP assertions are disabled. */
function collector_expect($condition, $message) {
	if (!$condition) throw new RuntimeException($message);
}

/** Verify the real selector restricts a configured collector to an enabled native record. */
function db_fetch_cell_prepared($sql, $params) {
	if (strpos($sql, 'FROM poller') !== false) {
		collector_expect(strpos($sql, "disabled = ''") !== false && $params === array(7), 'Collector selection was replaced or not checked');
		return $GLOBALS['collector_enabled'] ? 1 : 0;
	}
	collector_expect(strpos($sql, 'FROM plugin_nms_snmprec_oids') !== false, 'Unexpected probe query');
	return '1.3.6.1.2.1.1.1.0';
}

/** Two independently imported records share an endpoint, not a community or template. */
function db_fetch_row_prepared($sql, $params) {
	return array('id' => $params[0], 'community' => 'record-' . $params[0], 'host_template_id' => 100 + $params[0]);
}

/** Supply configured Cacti policy without private timeout/retry defaults. */
function read_config_option($key) { return $GLOBALS['native_settings'][$key]; }

/** Capture the real probe arguments without sending network traffic. */
function cacti_snmp_get(...$args) {
	$GLOBALS['probe_calls'][] = $args;
	return $GLOBALS['probe_result'];
}

$temp = sys_get_temp_dir() . '/nms-collector-' . bin2hex(random_bytes(8));
if (!mkdir($temp, 0700) || !mkdir($temp . '/lib', 0700)) throw new RuntimeException('Cannot create isolated fixture');
file_put_contents($temp . '/lib/snmp.php', "<?php // Native function double is declared by the test.\n");
foreach (array(11, 12) as $id) file_put_contents($temp . '/record-' . $id . '.snmprec', "1.3.6.1.2.1.1.1.0|4|sample-not-live\n");
$config = array('base_path' => $temp, 'poller_id' => 7, 'nms_snmpsim' => array(
	'activation' => 'manual', 'data_dir' => $temp, 'client_address' => '127.0.0.1', 'port' => 10161, 'poller_id' => 7));
try {
	foreach (array(null, 0, -1, true, '7', array(7), 4294967296) as $invalid) {
		try { nms_snmpsim_configured_poller(array('poller_id' => $invalid)); throw new LogicException('Invalid collector accepted'); }
		catch (RuntimeException $expected) {}
	}
	foreach (array(11, 12) as $id) {
		$device = nms_snmpsim_import_defaults($id);
		collector_expect($device['poller_id'] === 7 && $device['snmp_community'] === 'record-' . $id &&
			$device['host_template_id'] === 100 + $id && $device['snmp_port'] === 10161, 'New device did not retain its configured mapping');
	}
	$collector_enabled = false;
	try { nms_snmpsim_import_defaults(11); throw new LogicException('Missing/disabled collector accepted'); }
	catch (RuntimeException $expected) {}
	$collector_enabled = true;
	foreach (array(1, null, 'invalid') as $other_collector) {
		$config['poller_id'] = $other_collector;
		try { nms_snmpsim_probe_import(11); throw new LogicException('Wrong-vantage probe accepted'); }
		catch (RuntimeException $expected) {}
	}
	collector_expect(count($probe_calls) === 0, 'Wrong collector sent SNMP traffic');
	$config['poller_id'] = '7'; // Native configuration may supply a numeric string.
	collector_expect(strpos(nms_snmpsim_probe_import(12), 'live-observation') !== false, 'Actual API result lost');
	collector_expect($probe_calls[0][10] === 10161 && $probe_calls[0][11] === 2500 && $probe_calls[0][12] === 3, 'Probe ignored native policy');
	$native_settings['snmp_timeout'] = '';
	try { nms_snmpsim_probe_import(11); throw new LogicException('Invalid native timeout silently replaced'); }
	catch (RuntimeException $expected) {}
	collector_expect(count($probe_calls) === 1, 'Invalid policy sent SNMP traffic');
	$native_settings['snmp_timeout'] = '2500';
	foreach (array('', false, 'U', 'Timeout: No Response') as $failure) {
		$probe_result = $failure;
		try { nms_snmpsim_probe_import(11); throw new LogicException('Failed SNMP used a sample fallback'); }
		catch (RuntimeException $expected) {}
	}
} finally {
	foreach (array('lib/snmp.php', 'record-11.snmprec', 'record-12.snmprec') as $file) unlink($temp . '/' . $file);
	rmdir($temp . '/lib');
	rmdir($temp);
}
echo "Simulator collector contracts passed: explicit native collector, per-record defaults, probe locality, native policy and no failed-read fallback. Real collector/SNMP validation remains pending.\n";
