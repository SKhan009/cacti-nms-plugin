<?php
/** Obsolete implicit-path configuration is rejected; administrators select the JSON file explicitly. */
function nms_snmpsim_systemd_config() {
	throw new RuntimeException('Implicit systemd configuration paths are no longer supported. Select the configuration with NMS_SNMPSIM_CONFIG.');
}

/** Fixed, read-only systemd query; no shell interpolation and no start/stop privileges. */
function nms_snmpsim_systemd_state($service, $executable = '') {
	if (PHP_OS_FAMILY !== 'Linux' || !function_exists('proc_open') || !nms_snmpsim_local_path($executable, 'Linux') || !is_executable($executable)) return 'unavailable';
	if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}\.service$/D', $service)) return 'unavailable';
	$pipes = array();
	$process = @proc_open(array($executable, '--no-pager', 'is-active', $service),
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
