<?php
/** Platform contracts are explicit. Unsupported platforms fail closed. */
function nms_ssh_platform_supported($family = null)
{
	return in_array($family ?? PHP_OS_FAMILY, ["Linux", "Darwin", "Windows"], true);
}
function nms_ssh_absolute_path($path, $family = null)
{
	$family = $family ?? PHP_OS_FAMILY;
	if (!is_string($path) || $path === "" || preg_match('/[\x00-\x1f]/', $path) || strpos($path, "://") !== false) {
		return false;
	}
	if ($family === "Windows") {
		return preg_match("~^[A-Za-z]:[\\\\/]~", $path) &&
			!preg_match('~[<>"|?*]|:(?![\\\\/])~', substr($path, 2)) &&
			!preg_match('~(?:^|[\\\\/])\.{1,2}(?:[\\\\/]|$)~', $path);
	}
	return $path[0] === "/" && !preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path);
}
/**
 * Handles ssh windows identity.
 */
function nms_ssh_windows_identity($path = null)
{
	if (PHP_OS_FAMILY !== "Windows") {
		throw new RuntimeException("Windows ACL inspection requested on another platform.");
	}
	static $cache = [];
	$cacheKey = $path ?? "identity";
	if (isset($cache[$cacheKey]) && microtime(true) - $cache[$cacheKey]["time"] < 5) {
		return $cache[$cacheKey]["value"];
	}
	$exe = getenv("SystemRoot") . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
	if (!is_file($exe)) {
		throw new RuntimeException("Windows PowerShell is required for native ACL verification.");
	}
	$args = [
		$exe,
		"-NoProfile",
		"-NonInteractive",
		"-ExecutionPolicy",
		"Bypass",
		"-File",
		dirname(__DIR__) . "/ssh/windows-acl.ps1",
	];
	if ($path !== null) {
		$args[] = "-InspectPath";
		$args[] = $path;
	}
	$p = proc_open($args, [["file", "NUL", "r"], ["pipe", "w"], ["file", "NUL", "a"]], $pipes, null, null, [
		"bypass_shell" => true,
	]);
	if (!is_resource($p)) {
		throw new RuntimeException("Windows ACL inspection unavailable.");
	}
	$output = stream_get_contents($pipes[1], 65537);
	fclose($pipes[1]);
	$exit = proc_close($p);
	if ($exit !== 0 || strlen($output) > 65536) {
		throw new RuntimeException("Windows ACL inspection failed.");
	}
	$value = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
	$cache[$cacheKey] = ["time" => microtime(true), "value" => $value];
	return $value;
}
/**
 * Handles ssh private path.
 */
function nms_ssh_private_path($path, $config, $directory = false)
{
	if (!file_exists($path) || is_link($path)) {
		throw new RuntimeException("SSH private path missing or a link.");
	}
	if (PHP_OS_FAMILY === "Windows") {
		$a = nms_ssh_windows_identity($path);
		$service = $config["service_sid"] ?? "";
		if (!$service || $a["current_sid"] !== $service || $a["administrator"]) {
			throw new RuntimeException("Use the configured unprivileged SSH service identity.");
		}
		$allowed = [$service, "S-1-5-18", "S-1-5-32-544"];
		if (!in_array($a["owner"], $allowed, true) || $a["reparse"]) {
			throw new RuntimeException("Invalid private-path owner or reparse point.");
		}
		foreach ($a["rules"] as $rule) {
			if ($rule["allow"] && !in_array($rule["sid"], $allowed, true)) {
				throw new RuntimeException("SSH private path grants access to another Windows identity.");
			}
		}
	} elseif (!function_exists("posix_geteuid") || fileowner($path) !== posix_geteuid() || fileperms($path) & 0077) {
		throw new RuntimeException("SSH private paths require service ownership and private modes.");
	}
}
/**
 * Handles ssh rpc cipher.
 */
function nms_ssh_rpc_cipher($payload, $c, $decrypt = false, $context = "request")
{
	$key = file_get_contents($c["rpc_key"]);
	if (strlen($key) !== 32) {
		throw new RuntimeException("Invalid local RPC encryption key.");
	}
	if (!$decrypt) {
		$iv = random_bytes(12);
		$tag = "";
		$cipher = openssl_encrypt(
			$payload,
			"aes-256-gcm",
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			"nms-ssh-rpc-v1:" . $context,
		);
		if ($cipher === false) {
			throw new RuntimeException("RPC encryption failed.");
		}
		return base64_encode($iv . $tag . $cipher);
	}
	$data = base64_decode($payload, true);
	if ($data === false || strlen($data) < 29) {
		throw new RuntimeException("Invalid encrypted RPC frame.");
	}
	$plain = openssl_decrypt(
		substr($data, 28),
		"aes-256-gcm",
		$key,
		OPENSSL_RAW_DATA,
		substr($data, 0, 12),
		substr($data, 12, 16),
		"nms-ssh-rpc-v1:" . $context,
	);
	if ($plain === false) {
		throw new RuntimeException("Local RPC authentication failed.");
	}
	return $plain;
}
