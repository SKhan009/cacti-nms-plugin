<?php
/** Fixed read-only Linux profile and strict parsers; never execute user-authored code. */
function nms_ssh_linux_command()
{
	return "LC_ALL=C; export LC_ALL; printf 'NMS_STAT_A\\n'; head -n 1 /proc/stat; sleep 1; printf 'NMS_STAT_B\\n'; head -n 1 /proc/stat; printf 'NMS_MEMORY\\n'; cat /proc/meminfo; printf 'NMS_UPTIME\\n'; cat /proc/uptime; printf 'NMS_IDENTITY\\n'; hostname; uname -s; uname -r; printf 'NMS_END\\n'";
}

function nms_ssh_linux_parse($output)
{
	if (!is_string($output) || strlen($output) > 65536) {
		throw new RuntimeException("Incomplete Linux health response.");
	}
	$output = str_replace("\r", "", $output);
	if (strpos($output, "NMS_END\n") === false) {
		throw new RuntimeException("Incomplete Linux health response.");
	}
	if (!preg_match('/NMS_STAT_A\ncpu\s+([0-9 ]+)\nNMS_STAT_B\ncpu\s+([0-9 ]+)\n/', $output, $m)) {
		throw new RuntimeException("Invalid Linux CPU response.");
	}
	$a = preg_split("/\s+/", trim($m[1]));
	$b = preg_split("/\s+/", trim($m[2]));
	if (count($a) < 8 || count($b) < 8) {
		throw new RuntimeException("Linux CPU fields missing.");
	}
	// Guest counters are already included in user/nice and must not be counted twice.
	$total = 0;
	for ($i = 0; $i < 8; $i++) {
		$delta = (float) $b[$i] - (float) $a[$i];
		if ($delta < 0) {
			throw new RuntimeException("Linux CPU counters decreased.");
		}
		$total += $delta;
	}
	if ($total <= 0) {
		throw new RuntimeException("Linux CPU sample did not advance.");
	}
	$idle = (float) $b[3] - (float) $a[3] + (float) $b[4] - (float) $a[4];
	if (
		!preg_match('/^MemTotal:\s+(\d+) kB$/m', $output, $mt) ||
		!preg_match('/^MemAvailable:\s+(\d+) kB$/m', $output, $ma)
	) {
		throw new RuntimeException("MemTotal or MemAvailable missing.");
	}
	if ((float) $mt[1] <= 0 || (float) $ma[1] > (float) $mt[1]) {
		throw new RuntimeException("Invalid memory counters.");
	}
	if (
		!preg_match(
			'/NMS_UPTIME\n([0-9]+(?:\.[0-9]+)?)\s+[0-9.]+\nNMS_IDENTITY\n([^\n]+)\nLinux\n([^\n]+)\nNMS_END/',
			$output,
			$id,
		)
	) {
		throw new RuntimeException("Unsupported or malformed Linux identity/uptime.");
	}
	return [
		"cpu_percent" => round((100 * ($total - $idle)) / $total, 3),
		"memory_percent" => round(100 * (1 - (float) $ma[1] / (float) $mt[1]), 3),
		"uptime_seconds" => (float) $id[1],
		"hostname" => substr($id[2], 0, 255),
		"os" => "Linux",
		"kernel" => substr($id[3], 0, 255),
	];
}

/**
 * Handles ssh preset values.
 */
function nms_ssh_preset_values($input)
{
	$out = [];
	foreach (["name" => 80, "description" => 500, "username" => 128] as $field => $limit) {
		if (
			!isset($input[$field]) ||
			!is_string($input[$field]) ||
			strlen($input[$field]) > $limit ||
			preg_match('/[\x00-\x1f]/', $input[$field])
		) {
			throw new InvalidArgumentException("Invalid " . $field . ".");
		}
		$out[$field] = trim($input[$field]);
	}
	if ($out["name"] === "" || $out["username"] === "") {
		throw new InvalidArgumentException("Name and username are required.");
	}
	foreach (
		[
			"port" => [1, 65535],
			"connect_timeout" => [1, 120],
			"command_timeout" => [1, 300],
			"retries" => [0, 2],
			"keepalive" => [0, 300],
		]
		as $field => $range
	) {
		$value = $input[$field] ?? null;
		if (
			!is_scalar($value) ||
			filter_var($value, FILTER_VALIDATE_INT) === false ||
			(int) $value < $range[0] ||
			(int) $value > $range[1]
		) {
			throw new InvalidArgumentException("Invalid " . $field . ".");
		}
		$out[$field] = (int) $value;
	}
	if (!in_array($input["auth_method"] ?? "", ["key", "password"], true)) {
		throw new InvalidArgumentException("Select exactly one authentication method.");
	}
	if (isset($input["enabled"]) && !in_array($input["enabled"], ["1", 1], true)) {
		throw new InvalidArgumentException("Invalid enabled state.");
	}
	$out["auth_method"] = $input["auth_method"];
	$out["enabled"] = !empty($input["enabled"]) ? 1 : 0;
	return $out;
}

/** Quick format check only; the backend must still parse/decrypt the private key. */
function nms_ssh_key_envelope($key)
{
	if (!is_string($key) || strlen($key) > 65536 || strpos($key, "\0") !== false) {
		throw new InvalidArgumentException("Private key must be text and at most 64 KB.");
	}
	$text = trim($key);
	if (
		preg_match(
			'/\A-----BEGIN (OPENSSH PRIVATE KEY|RSA PRIVATE KEY|EC PRIVATE KEY|DSA PRIVATE KEY|PRIVATE KEY|ENCRYPTED PRIVATE KEY)-----\r?\n[\s\S]+\r?\n-----END \1-----\z/',
			$text,
		)
	) {
		return;
	}
	if (
		preg_match('/\APuTTY-User-Key-File-[23]: [^\r\n]+\r?\n/', $text) &&
		preg_match('/(?:^|\n)Private-Lines: [1-9][0-9]*\r?\n/', $text) &&
		preg_match('/(?:^|\n)Private-MAC: [a-fA-F0-9]+\z/', $text)
	) {
		return;
	}
	throw new InvalidArgumentException(
		"Choose a PEM, OpenSSH or PuTTY private key. Public keys, certificates, logs and other files are not accepted.",
	);
}
