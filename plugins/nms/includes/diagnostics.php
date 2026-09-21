<?php
/**
 * @file diagnostics.php
 * Validate diagnostic profiles and run bounded, on-demand collector commands.
 * Diagnostics are deliberately separate from discovery polling.
 */

/** Return the supported diagnostic tools and their human-readable labels. */
function nms_diag_labels()
{
	return [
		"ping" => "Ping",
		"traceroute" => "Traceroute",
		"arp" => "Collector ARP lookup",
		"iperf3" => "iPerf3 bandwidth",
		"netperf" => "Netperf bandwidth",
		"pathchar" => "Pathchar capacity estimate",
	];
}

/** Normalize a saved comma-separated tool list or submitted tool array. */
function nms_diag_tools($value)
{
	$parts = is_array($value) ? $value : explode(",", (string) $value);
	$valid = [];

	foreach ($parts as $tool) {
		$tool = trim((string) $tool);
		if (isset(nms_diag_labels()[$tool])) {
			$valid[$tool] = $tool;
		}
	}

	return array_values($valid);
}

/** Validate profile data before any NMS configuration is written. */
function nms_diag_profile_validate($input)
{
	$name = trim((string) ($input["diagnostic_profile_name"] ?? ""));
	if ($name === "" || strlen($name) > 100) {
		throw new InvalidArgumentException("Enter a diagnostic profile name of up to 100 characters.");
	}

	$tools = nms_diag_tools($input["diagnostic_tools"] ?? []);
	if (!$tools) {
		throw new InvalidArgumentException("Select at least one diagnostic tool.");
	}

	$ping = (int) ($input["ping_count"] ?? 4);
	$hops = (int) ($input["trace_hops"] ?? 20);
	$seconds = (int) ($input["bandwidth_seconds"] ?? 10);
	if ($ping < 1 || $ping > 10 || $hops < 1 || $hops > 30 || $seconds < 1 || $seconds > 30) {
		throw new InvalidArgumentException("Use 1–10 ping packets, 1–30 hops, and a 1–30 second bandwidth test.");
	}

	return [
		"name" => $name,
		"tools" => implode(",", $tools),
		"ping_count" => $ping,
		"trace_hops" => $hops,
		"bandwidth_seconds" => $seconds,
	];
}

/** Create or update one reusable diagnostic profile. */
function nms_diag_profile_save($input)
{
	nms_require_management(3);
	$profile = nms_diag_profile_validate($input);
	$id = (int) ($input["diagnostic_profile_id"] ?? 0);

	if ($id) {
		if (!db_fetch_cell_prepared("SELECT id FROM plugin_nms_diagnostic_profiles WHERE id = ?", [$id])) {
			throw new InvalidArgumentException("Diagnostic profile no longer exists.");
		}

		db_execute_prepared(
			'UPDATE plugin_nms_diagnostic_profiles
			SET name = ?, tools = ?, ping_count = ?, trace_hops = ?, bandwidth_seconds = ?, updated_by = ?, updated_at = NOW()
			WHERE id = ?',
			[
				$profile["name"],
				$profile["tools"],
				$profile["ping_count"],
				$profile["trace_hops"],
				$profile["bandwidth_seconds"],
				nms_current_user_id(),
				$id,
			],
		);

		return $id;
	}

	db_execute_prepared(
		'INSERT INTO plugin_nms_diagnostic_profiles
		(name, tools, ping_count, trace_hops, bandwidth_seconds, updated_by, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, NOW())',
		[
			$profile["name"],
			$profile["tools"],
			$profile["ping_count"],
			$profile["trace_hops"],
			$profile["bandwidth_seconds"],
			nms_current_user_id(),
		],
	);

	return (int) db_fetch_cell("SELECT LAST_INSERT_ID()");
}

/** Validate an optional profile assignment from a device form. */
function nms_diag_assignment_validate($input)
{
	$id = (int) ($input["diagnostic_profile_id"] ?? 0);
	if (!$id) {
		return 0;
	}

	if (!(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_nms_diagnostic_profiles WHERE id = ?", [$id])) {
		throw new InvalidArgumentException("Select a valid diagnostic profile.");
	}

	return $id;
}

/** Persist one device-to-profile assignment, or remove it when no profile is selected. */
function nms_diag_assignment_write($host_id, $profile_id)
{
	$host_id = (int) $host_id;
	$profile_id = (int) $profile_id;

	if (!$profile_id) {
		db_execute_prepared("DELETE FROM plugin_nms_diagnostic_devices WHERE host_id = ?", [$host_id]);
		return;
	}

	db_execute_prepared(
		'INSERT INTO plugin_nms_diagnostic_devices(host_id, profile_id) VALUES (?, ?)
		ON DUPLICATE KEY UPDATE profile_id = VALUES(profile_id)',
		[$host_id, $profile_id],
	);
}

/** Return the first executable installed in an approved collector path. */
function nms_diag_program($name)
{
	foreach (["/usr/bin/", "/usr/sbin/", "/usr/local/bin/"] as $directory) {
		$candidate = $directory . $name;
		if (is_file($candidate) && is_executable($candidate)) {
			return $candidate;
		}
	}

	return "";
}

/** Run an argument-array command with bounded output and a fixed timeout. */
function nms_diag_run_command($command, $timeout = 40)
{
	$descriptors = [1 => ["pipe", "w"], 2 => ["pipe", "w"]];
	$process = proc_open($command, $descriptors, $pipes, null, null, ["bypass_shell" => true]);
	if (!is_resource($process)) {
		throw new RuntimeException("Could not start the diagnostic command on this collector.");
	}

	stream_set_blocking($pipes[1], false);
	stream_set_blocking($pipes[2], false);
	$output = "";
	$started = microtime(true);
	$exit = -1;

	do {
		$output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		$status = proc_get_status($process);
		if (!$status["running"] && isset($status["exitcode"]) && $status["exitcode"] >= 0) {
			$exit = (int) $status["exitcode"];
		}
		if (strlen($output) > 12000) {
			break;
		}
		usleep(100000);
	} while ($status["running"] && microtime(true) - $started < $timeout);

	if ($status["running"]) {
		proc_terminate($process);
		throw new RuntimeException("Diagnostic timed out on the collector.");
	}

	$output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$close_exit = proc_close($process);
	if ($exit < 0) {
		$exit = $close_exit;
	}

	return ["exit" => $exit, "output" => trim(substr($output, 0, 12000))];
}

/** Authorize and run one selected diagnostic using only its assigned profile. */
function nms_diag_run($host_id, $tool)
{
	nms_require_device_access($host_id);
	$tool = (string) $tool;
	if (!isset(nms_diag_labels()[$tool])) {
		throw new InvalidArgumentException("Unsupported diagnostic tool.");
	}

	$row = db_fetch_row_prepared(
		"SELECT h.id, h.hostname, h.description, p.*
		FROM host AS h
		JOIN plugin_nms_diagnostic_devices AS d ON d.host_id = h.id
		JOIN plugin_nms_diagnostic_profiles AS p ON p.id = d.profile_id
		WHERE h.id = ? AND h.deleted = ''",
		[(int) $host_id],
	);
	if (!$row) {
		throw new RuntimeException("Assign a diagnostic profile to this device before running a test.");
	}
	if (!in_array($tool, nms_diag_tools($row["tools"]), true)) {
		throw new RuntimeException("This tool is not enabled by the assigned diagnostic profile.");
	}

	$target = (string) $row["hostname"];
	if ($target === "" || strlen($target) > 253 || preg_match("/[^A-Za-z0-9.:-]/", $target)) {
		throw new RuntimeException("The Cacti hostname/IP is not safe for a diagnostic command.");
	}

	$binary = "";
	$command = [];
	if ($tool === "arp") {
		$binary = nms_diag_program("ip");
		// The selected device authorizes this on-demand check. Do not filter the
		// collector cache by its address: the cache describes every neighbour the
		// collector has learned, while loopback devices never have an ARP entry.
		$command = [$binary, "neigh", "show"];
	} elseif ($tool === "ping") {
		$binary = nms_diag_program("ping");
		$command = [$binary, "-n", "-c", (string) $row["ping_count"], "-W", "2", $target];
	} elseif ($tool === "traceroute") {
		$binary = nms_diag_program("traceroute");
		if (!$binary) {
			$binary = nms_diag_program("tracepath");
			$command = [$binary, "-n", $target];
		} else {
			$command = [$binary, "-n", "-m", (string) $row["trace_hops"], "-w", "2", $target];
		}
	} elseif ($tool === "iperf3") {
		$binary = nms_diag_program("iperf3");
		$command = [$binary, "-c", $target, "-p", "5201", "-t", (string) $row["bandwidth_seconds"], "-J"];
	} elseif ($tool === "netperf") {
		$binary = nms_diag_program("netperf");
		$command = [$binary, "-H", $target, "-l", (string) $row["bandwidth_seconds"]];
	} else {
		$binary = nms_diag_program("pathchar");
		$command = [$binary, "-n", $target];
	}

	if (!$binary) {
		throw new RuntimeException(
			ucfirst($tool) .
				" is not installed on the assigned collector. See the offline RHEL guide for the required RPM.",
		);
	}

	/* A bandwidth client needs a listening server before a measurement can
	 * begin. Check the TCP endpoint first so an unavailable server produces a
	 * useful explanation instead of iperf's partial JSON and bad-fd message. */
	if ($tool === "iperf3") {
		$socket_error = 0;
		$socket_message = "";
		$socket = @fsockopen($target, 5201, $socket_error, $socket_message, 2);
		if (!is_resource($socket)) {
			return [
				"exit" => 111,
				"output" => "iPerf3 server is not reachable at " . $target . ":5201. Start an authorised server on the remote endpoint, then run this check again.\n" . ($socket_message ?: "TCP connection refused."),
				"tool" => $tool,
				"target" => $target,
				"profile" => $row["name"],
			];
		}
		fclose($socket);
	}

	$result = nms_diag_run_command($command, $tool === "pathchar" ? 60 : 40);
	if ($tool === "arp") {
		if ($result["output"] === "") {
			$legacy_binary = nms_diag_program("arp");
			if ($legacy_binary) {
				$command = [$legacy_binary, "-an"];
				$result = nms_diag_run_command($command);
			}
		}
		$result["output"] = "$ " . implode(" ", $command) . "\n" . ($result["output"] ?: "No IPv4 or IPv6 neighbours are currently cached by this collector.");
		$result["target"] = "Collector neighbour cache";
	} else {
		$result["target"] = $target;
	}
	$result["tool"] = $tool;
	$result["profile"] = $row["name"];

	return $result;
}
