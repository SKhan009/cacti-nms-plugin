<?php
/**
 * @file snmpsim.php
 * Read and validate root-managed simulator configuration, expose installation health, and resolve per-record device defaults.
 * Explicit live probes use Cacti SNMP; missing configuration or failed SNMP never returns a record-file value as a reading.
 */

/** One root-managed configuration shared with systemd; never guess an installation path. */
function nms_snmpsim_config() {
	$path = '/etc/cacti-nms/snmpsim.json';
	if (!is_readable($path)) throw new RuntimeException('SNMPSim is not configured. Install /etc/cacti-nms/snmpsim.json using snmpsim/configure.py.');
	if (is_link($path) || fileowner($path) !== 0 || (fileperms($path) & 0022) ||
		fileowner(dirname($path)) !== 0 || (fileperms(dirname($path)) & 0022)) {
		throw new RuntimeException('SNMPSim configuration and its directory must be root-owned and not group/world writable.');
	}
	$settings = json_decode(file_get_contents($path), true);
	return nms_snmpsim_validate_config($settings);
}

/** Validate explicit simulator paths, endpoint, and service settings before the configuration is used. */
function nms_snmpsim_validate_config($settings) {
	if (!is_array($settings)) throw new RuntimeException('Invalid SNMPSim configuration JSON.');
	foreach (array('executable', 'data_dir') as $key) {
		if (!isset($settings[$key]) || !is_string($settings[$key]) ||
			!preg_match('~^/[A-Za-z0-9/_.-]+$~D', $settings[$key]) || $settings[$key] === '/') {
			throw new RuntimeException('SNMPSim requires an absolute ' . $key . ' path without spaces or shell characters.');
		}
	}
	foreach (array('listen_address', 'client_address') as $key) {
		if (!isset($settings[$key]) || !is_string($settings[$key]) || !filter_var($settings[$key], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			throw new RuntimeException('SNMPSim requires an explicit IPv4 ' . $key . '.');
		}
	}
	if ($settings['client_address'] === '0.0.0.0') throw new RuntimeException('Use a reachable client address, not 0.0.0.0.');
	if (!isset($settings['port']) || !is_int($settings['port']) || $settings['port'] < 1 || $settings['port'] > 65535) {
		throw new RuntimeException('SNMPSim port must be an integer from 1 to 65535.');
	}
	if (!isset($settings['service']) || !is_string($settings['service']) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}\.service$/D', $settings['service'])) {
		throw new RuntimeException('Invalid SNMPSim systemd service name.');
	}
	return $settings;
}

/** Configuration checks are not proof of a live SNMP response. */
function nms_snmpsim_health() {
	try {
		$settings = nms_snmpsim_config();
		return array('config' => $settings, 'error' => '',
			'executable' => is_file($settings['executable']) && is_executable($settings['executable']),
			'data_writable' => is_dir($settings['data_dir']) && is_writable($settings['data_dir']),
			'reload_pending' => file_exists($settings['data_dir'] . '/.reload.pending') || file_exists($settings['data_dir'] . '/.reload.processing'),
			'service_state' => nms_snmpsim_service_state($settings['service']));
	} catch (Throwable $exception) {
		return array('config' => null, 'error' => $exception->getMessage());
	}
}

/** Read only: PHP never starts/stops services or executes the configured responder. */
function nms_snmpsim_service_state($service) {
	if (!function_exists('proc_open') || !is_executable('/usr/bin/systemctl')) return 'unavailable';
	if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}\.service$/D', $service)) return 'unavailable';
	$pipes = array();
	$process = @proc_open('/usr/bin/systemctl --no-pager is-active ' . escapeshellarg($service),
		array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
	if (!is_resource($process)) return 'unavailable';
	stream_set_blocking($pipes[1], false);
	$output = '';
	$deadline = microtime(true) + 1;
	do {
		$output .= stream_get_contents($pipes[1]);
		$status = proc_get_status($process);
		if (!$status['running']) break;
		usleep(10000);
	} while (microtime(true) < $deadline);
	if ($status['running']) proc_terminate($process);
	$output .= stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	proc_close($process);
	$state = trim($output);
	return in_array($state, array('active', 'inactive', 'failed', 'activating', 'deactivating', 'unknown'), true) ? $state : 'unavailable';
}

/** Resolve each record independently; templates alone do not imply a simulated device. */
function nms_snmpsim_import_defaults($import_id) {
	$settings = nms_snmpsim_config();
	$record = db_fetch_row_prepared('SELECT id, community, host_template_id FROM plugin_nms_snmprec_imports WHERE id = ?', array((int) $import_id));
	if (!$record) throw new InvalidArgumentException('Select an existing imported simulator record.');
	if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/D', $record['community']) ||
		!is_readable($settings['data_dir'] . '/' . $record['community'] . '.snmprec')) {
		throw new RuntimeException('This record is not readable in the configured simulator data directory. Check the directory configuration or migrate the record before adding its device.');
	}
	return array('hostname' => $settings['client_address'], 'snmp_port' => $settings['port'],
		'snmp_community' => $record['community'], 'host_template_id' => (int) $record['host_template_id'],
		'snmp_version' => 2, 'availability_method' => AVAIL_SNMP, 'proxy' => true, 'poller_id' => 1);
}

/** On-demand protocol check using Cacti SNMP, never the sample value in the record file. */
function nms_snmpsim_probe_import($import_id) {
	global $config, $snmp_error;
	require_once($config['base_path'] . '/lib/snmp.php');
	require_once(__DIR__ . '/inventory.php');
	$device = nms_snmpsim_import_defaults($import_id);
	$oid = db_fetch_cell_prepared('SELECT oid FROM plugin_nms_snmprec_oids WHERE import_id = ? ORDER BY id LIMIT 1', array((int) $import_id));
	if (!$oid) throw new RuntimeException('This imported record has no OID to test.');
	$snmp_error = '';
	$value = cacti_snmp_get($device['hostname'], $device['snmp_community'], $oid, 2,
		'', '', '', '', '', '', $device['snmp_port'], 1000, 0, 'NMS simulator check', '', SNMP_STRING_OUTPUT_ASCII);
	if (nms_inventory_snmp_failed($value)) throw new RuntimeException('Live SNMP check failed. Check the responder service, endpoint, community and activation queue. No fallback value was used.');
	return 'Live SNMP response received for OID ' . $oid . ': ' . (string) $value;
}
