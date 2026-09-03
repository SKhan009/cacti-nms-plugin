<?php
/**
 * @file snmpsim_config_test.php
 * Standalone PHP regression checks for explicit simulator configuration validation.
 * Run from the plugin directory with php tests/snmpsim_config_test.php; no Cacti database is required.
 */
/* Run: php tests/snmpsim_config_test.php (no Cacti database required). */
require_once(__DIR__ . '/../includes/snmpsim.php');

$valid = array('executable' => '/opt/simulator/bin/responder', 'data_dir' => '/srv/simulator/data',
	'listen_address' => '127.0.0.1', 'client_address' => '192.0.2.20', 'port' => 10161, 'service' => 'lab-sim.service',
	'activation' => 'systemd', 'systemctl' => '/usr/bin/systemctl', 'lock_path' => '/run/nms-sim/reload.lock');
$valid['poller_id'] = 7;
if (nms_snmpsim_validate_config($valid) !== $valid) throw new Exception('Configuration changed unexpectedly.');
foreach (array('port' => 0, 'executable' => 'relative/path', 'data_dir' => '/',
	'client_address' => '0.0.0.0', 'listen_address' => 'invalid', 'service' => 'x;evil.service') as $key => $value) {
	$invalid = $valid;
	$invalid[$key] = $value;
	try {
		nms_snmpsim_validate_config($invalid);
	} catch (RuntimeException $exception) {
		continue;
	}
	throw new Exception('Accepted invalid configuration: ' . $key);
}
foreach (array('activation', 'systemctl', 'lock_path', 'poller_id') as $required) {
	$invalid = $valid;
	unset($invalid[$required]);
	try { nms_snmpsim_validate_config($invalid); throw new LogicException('Missing explicit setting accepted: ' . $required); }
	catch (RuntimeException $expected) {}
}
try {
	nms_snmpsim_validate_config(array());
} catch (RuntimeException $exception) {
	print "SNMPSim configuration tests passed.\n";
	exit(0);
}
throw new Exception('Empty configuration should fail, not use a fallback.');
