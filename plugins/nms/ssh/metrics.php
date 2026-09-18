<?php
/** Native script input: read validated cache only, never open SSH from graph polling. */
if (PHP_SAPI !== "cli") {
	http_response_code(404);
	exit();
}
require_once __DIR__ . "/../../../include/cli_check.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/database.php";
require_once __DIR__ . "/../includes/ssh.php";
try {
	$id = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
	$metric = $argv[2] ?? "";
	if (!$id || !in_array($metric, ["cpu_percent", "memory_percent", "uptime_seconds"], true) || !nms_ssh_enabled()) {
		throw new RuntimeException("Invalid metric request.");
	}
	if (!nms_database_ready()) {
		throw new RuntimeException("Schema unavailable.");
	}
	$sample = nms_ssh_current_sample($id);
	$value = $sample[$metric] ?? null;
	if (
		!is_numeric($value) ||
		!is_finite((float) $value) ||
		$value < 0 ||
		($metric !== "uptime_seconds" && $value > 100)
	) {
		throw new RuntimeException("Unknown reading.");
	}
	echo sprintf("%.3F", (float) $value), "\n";
} catch (Throwable $e) {
	echo "U\n";
}
