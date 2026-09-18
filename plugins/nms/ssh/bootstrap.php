<?php
/** CLI processes use Cacti's own bootstrap/connection, never a parallel DB client. */
if (PHP_SAPI !== "cli") {
	http_response_code(404);
	exit();
}
if (!in_array(PHP_OS_FAMILY, ["Linux", "Windows", "Darwin"], true)) {
	throw new RuntimeException("Unsupported SSH backend operating system.");
}
require_once __DIR__ . "/../../../include/cli_check.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/database.php";
require_once __DIR__ . "/../includes/ssh.php";
nms_ssh_require_schema();
if (!is_file(__DIR__ . "/vendor/autoload.php")) {
	throw new RuntimeException("Pinned offline SSH dependencies are missing.");
}
require_once __DIR__ . "/vendor/autoload.php";
foreach (
	PHP_OS_FAMILY === "Windows" ? ["openssl", "sockets"] : ["openssl", "posix", "pcntl", "sockets"]
	as $extension
) {
	if (!extension_loaded($extension)) {
		throw new RuntimeException("Missing SSH extension: " . $extension);
	}
}
if (PHP_OS_FAMILY !== "Windows" && posix_geteuid() === 0) {
	throw new RuntimeException("Do not run SSH workers as root.");
}
if (!nms_ssh_enabled()) {
	throw new RuntimeException("NMS plugin is disabled.");
}
if (
	strpos(
		str_replace("\\", "/", (new ReflectionClass(phpseclib3\Net\SSH2::class))->getFileName()),
		str_replace("\\", "/", __DIR__) . "/vendor/",
	) !== 0
) {
	throw new RuntimeException("Unexpected SSH library source; pinned NMS dependencies required.");
}
if (
	strpos(
		file_get_contents(__DIR__ . "/vendor/phpseclib/phpseclib/phpseclib/Net/SSH2.php"),
		"NMS policy: never switch from password to keyboard-interactive authentication.",
	) === false
) {
	throw new RuntimeException("Strict SSH authentication dependency patch is missing.");
}

if (PHP_OS_FAMILY === "Windows") {
	$identity = nms_ssh_windows_identity();
	$cfg = nms_ssh_config();
	if ($identity["administrator"] || $identity["current_sid"] !== $cfg["service_sid"]) {
		throw new RuntimeException("Use the configured unprivileged Windows service account.");
	}
}

// Transport warnings must fail this worker, not trigger Cacti's fatal-plugin shutdown path.
set_error_handler(function ($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) {
		return false;
	}
	throw new ErrorException($message, 0, $severity, $file, $line);
});
