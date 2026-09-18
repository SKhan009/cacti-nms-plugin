<?php
/** Obsolete implicit-path configuration is rejected; administrators select the JSON file explicitly. */
function nms_snmpsim_systemd_config()
{
	throw new RuntimeException(
		"Implicit systemd configuration paths are no longer supported. Select the configuration with NMS_SNMPSIM_CONFIG.",
	);
}

/** Read a recent root-published state before attempting a fixed, read-only systemd query. */
function nms_snmpsim_systemd_state($service, $executable = "", $status_path = "")
{
	if (PHP_OS_FAMILY !== "Linux") {
		return "unavailable";
	}
	if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}\.service$/D', $service)) {
		return "unavailable";
	}
	$allowed = ["active", "inactive", "failed", "activating", "deactivating", "unknown"];
	if (
		$status_path !== "" &&
		nms_snmpsim_local_path($status_path, "Linux") &&
		is_file($status_path) &&
		!is_link($status_path) &&
		is_readable($status_path)
	) {
		$stat = @stat($status_path);
		$state = trim((string) @file_get_contents($status_path));
		if (
			is_array($stat) &&
			(int) $stat["uid"] === 0 &&
			(((int) $stat["mode"]) & 0022) === 0 &&
			time() - (int) $stat["mtime"] <= 120 &&
			in_array($state, $allowed, true)
		) {
			return $state;
		}
	}
	if (!function_exists("proc_open") || !nms_snmpsim_local_path($executable, "Linux") || !is_executable($executable)) {
		return "unavailable";
	}
	$pipes = [];
	$process = @proc_open(
		[$executable, "--no-pager", "is-active", $service],
		[0 => ["file", "/dev/null", "r"], 1 => ["pipe", "w"], 2 => ["file", "/dev/null", "w"]],
		$pipes,
	);
	if (!is_resource($process)) {
		return "unavailable";
	}
	stream_set_blocking($pipes[1], false);
	$output = "";
	$deadline = microtime(true) + 1;
	do {
		$output .= stream_get_contents($pipes[1]);
		$status = proc_get_status($process);
		if (!$status["running"]) {
			break;
		}
		usleep(10000);
	} while (microtime(true) < $deadline);
	if ($status["running"]) {
		proc_terminate($process);
	}
	$output .= stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	proc_close($process);
	$state = trim($output);
	return in_array($state, $allowed, true) ? $state : "unavailable";
}
