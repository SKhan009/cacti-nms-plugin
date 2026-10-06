<?php
/** Independent encrypted broker support; no legacy plugin services are loaded. */

/** ICCT-owned SSH service: ssh current sample. */
function icct_backend_ssh_current_sample($host_id)
{
	$d = icct_backend_ssh_device($host_id);
	$s = db_fetch_row_prepared(
		"SELECT *, UNIX_TIMESTAMP(last_success) AS sample_time FROM plugin_icct_nms_ssh_state WHERE host_id=?",
		[(int) $host_id],
	);
	if (
		!(int) $d["monitoring"] ||
		!$s ||
		$s["status"] !== "ok" ||
		!$s["sample_time"] ||
		time() - (int) $s["sample_time"] > 2 * (int) $d["interval_seconds"] ||
		time() < (int) $s["sample_time"] ||
		(int) $s["preset_revision"] !== (int) $d["preset_revision"] ||
		(int) $s["device_revision"] !== (int) $d["device_revision"] ||
		$s["endpoint"] !== $d["endpoint"]
	) {
		throw new RuntimeException("SSH reading is unknown or stale.");
	}
	return json_decode($s["sample_json"], true, 16, JSON_THROW_ON_ERROR);
}

/** ICCT-owned SSH service: ssh enabled. */
function icct_backend_ssh_enabled()
{
	return (int) db_fetch_cell_prepared("SELECT status FROM plugin_config WHERE directory=?", ["icct_nms"], "", false) === 1;
}

/** ICCT-owned SSH service: ssh fingerprint. */
function icct_backend_ssh_fingerprint($key)
{
	$parts = explode(" ", trim($key));
	if (count($parts) < 2 || !($raw = base64_decode($parts[1], true))) {
		throw new RuntimeException("Invalid SSH host public key.");
	}
	return "SHA256:" . rtrim(base64_encode(hash("sha256", $raw, true)), "=");
}

/** ICCT-owned SSH service: ssh linux command. */
function icct_backend_ssh_linux_command()
{
	return "LC_ALL=C; export LC_ALL; printf 'NMS_STAT_A\\n'; head -n 1 /proc/stat; sleep 1; printf 'NMS_STAT_B\\n'; head -n 1 /proc/stat; printf 'NMS_MEMORY\\n'; cat /proc/meminfo; printf 'NMS_UPTIME\\n'; cat /proc/uptime; printf 'NMS_IDENTITY\\n'; hostname; uname -s; uname -r; printf 'NMS_END\\n'";
}

/** ICCT-owned SSH service: ssh linux parse. */
function icct_backend_ssh_linux_parse($output)
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

/** ICCT-owned SSH service: ssh private path. */
function icct_backend_ssh_private_path($path, $config, $directory = false)
{
	if (!file_exists($path) || is_link($path)) {
		throw new RuntimeException("SSH private path missing or a link.");
	}
	if (PHP_OS_FAMILY === "Windows") {
		$a = icct_backend_ssh_windows_identity($path);
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
