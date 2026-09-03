<?php
/** Portable simulator validation and manual activation; no Cacti database or network. */
require_once(__DIR__ . '/../includes/snmprec.php');
require_once(__DIR__ . '/../includes/functions.php');
function html_escape($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
set_error_handler(function($severity, $message, $file, $line) {
	throw new ErrorException($message, 0, $severity, $file, $line);
});
function portable_check($condition, $message) {
	if (!$condition) throw new RuntimeException($message);
}
foreach (array('/srv/SNMP records', '/opt/data') as $path) {
	portable_check(nms_snmpsim_local_path($path, 'Linux'), 'POSIX path rejected');
}
foreach (array('C:\\SNMP records\\data', 'D:/monitor/data') as $path) {
	portable_check(nms_snmpsim_local_path($path, 'Windows'), 'Windows drive path rejected');
}
foreach (array('', '/', 'relative', '/srv/../etc', '/srv/./data', 'https://example.com/data', 'php://filter', "\0data") as $path) {
	portable_check(!nms_snmpsim_local_path($path, 'Linux'), 'Unsafe POSIX path accepted');
}
foreach (array('C:\\', 'C:data', '\\\\server\\share', 'C:/data/../secret', 'C:/data:stream') as $path) {
	portable_check(!nms_snmpsim_local_path($path, 'Windows'), 'Unsafe Windows path accepted');
}
$settings = array('activation' => 'manual', 'data_dir' => '/srv/data', 'client_address' => '127.0.0.1', 'port' => 1161, 'poller_id' => 7);
portable_check(nms_snmpsim_validate_portable_config($settings, 'Linux') === array(
	'data_dir' => '/srv/data', 'client_address' => '127.0.0.1', 'port' => 1161, 'activation' => 'manual', 'poller_id' => 7), 'Configuration changed');
$windows = $settings; $windows['data_dir'] = 'C:/SNMP records/data';
portable_check(nms_snmpsim_validate_portable_config($windows, 'Windows')['data_dir'] === $windows['data_dir'], 'Windows settings rejected');
foreach (array('activation' => 'automatic', 'port' => '1161', 'client_address' => '0.0.0.0', 'data_dir' => '/') as $key => $value) {
	$invalid = $settings; $invalid[$key] = $value;
	try { nms_snmpsim_validate_portable_config($invalid, 'Linux'); }
	catch (RuntimeException $e) { continue; }
	throw new RuntimeException('Accepted invalid ' . $key);
}
// An explicit bad configuration must not fall through to any existing Linux config.
$config = array('nms_snmpsim' => false);
portable_check(nms_snmpsim_health()['config'] === null, 'Invalid explicit config did not fail closed');
portable_check(!function_exists('nms_snmpsim_systemd_config'), 'Manual config loaded Linux adapter');

$temp = sys_get_temp_dir() . '/nms-portable-' . bin2hex(random_bytes(8));
if (!mkdir($temp, 0700)) throw new RuntimeException('Cannot create temporary test directory');
$config = array('nms_snmpsim' => array('activation' => 'manual', 'data_dir' => $temp,
	'client_address' => '127.0.0.1', 'port' => 1161, 'poller_id' => 7));
try {
	$health = nms_snmpsim_health();
	portable_check($health['error'] === '' && $health['service_state'] === 'manual', 'Manual health failed');
	$content = "1.3.6.1.2.1.1.1.0|4|Portable test\n";
	$file = nms_snmprec_deploy('portable-test', $content);
	portable_check(file_get_contents($file) === $content, 'Record not written');
	portable_check(!file_exists($temp . '/.reload.pending'), 'Manual import incorrectly queued systemd');
	portable_check(!function_exists('nms_snmpsim_systemd_config'), 'Manual health loaded Linux adapter');
	$config['url_path'] = '/cacti/';
	$categories = $imports = array();
	$simulator_message = $nms_csrf_token = '';
	ob_start(); include __DIR__ . '/../templates/devices/import.php'; $html = ob_get_clean();
	portable_check(strpos($html, 'Activation: administrator-managed') !== false, 'Manual activation not explained');
	portable_check(strpos($html, 'Activation queue:') === false && strpos($html, 'Executable:') === false, 'Linux-only health rendered in manual mode');
	try { nms_snmprec_deploy('portable-test', 'replacement'); throw new LogicException('Overwrote record'); }
	catch (RuntimeException $e) { /* Existing records are protected. */ }
	portable_check(file_get_contents($file) === $content, 'Existing record changed');
} finally {
	if (is_file($temp . '/portable-test.snmprec')) unlink($temp . '/portable-test.snmprec');
	rmdir($temp);
}
print "Portable path/configuration and manual simulator deployment tests passed.\n";
