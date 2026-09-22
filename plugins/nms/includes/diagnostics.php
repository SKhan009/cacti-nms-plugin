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
	foreach (["/usr/bin/", "/usr/sbin/", "/usr/local/bin/", "/usr/local/sbin/", "/bin/", "/sbin/"] as $directory) {
		$candidate = $directory . $name;
		if (is_file($candidate) && is_executable($candidate)) {
			return $candidate;
		}
	}

	return "";
}

/** Validate an IP literal or DNS name before it becomes a command argument. */
function nms_diag_target($value)
{
	$target = trim((string) $value);
	if (filter_var($target, FILTER_VALIDATE_IP)) {
		return $target;
	}
	if ($target === '' || strlen($target) > 253 || !preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\.?$/D', $target)) {
		throw new InvalidArgumentException('Enter a valid device hostname or IP address; command options are not targets.');
	}
	foreach (explode('.', rtrim($target, '.')) as $label) {
		if (strlen($label) > 63 || !preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/D', $label)) {
			throw new InvalidArgumentException('The device hostname contains an invalid DNS label.');
		}
	}
	return $target;
}

/** Keep each stream bounded while continuing to drain the child to avoid deadlocks. */
function nms_diag_drain($pipe, &$buffer, &$truncated, $limit)
{
	// Bound work per iteration too: a noisy child must not starve the timeout check.
	for ($i = 0; $i < 8; $i++) {
		$chunk = fread($pipe, 8192);
		if ($chunk === false || $chunk === '') {
			break;
		}
		$remaining = max(0, $limit - strlen($buffer));
		$buffer .= substr($chunk, 0, $remaining);
		$truncated = $truncated || strlen($chunk) > $remaining;
	}
}

/** Execute a direct argument array, preserve errors, and always reap the child. */
function nms_diag_run_command($command, $timeout = 40)
{
	if (!is_array($command) || !$command || !is_string($command[0]) || $command[0] === '') {
		throw new InvalidArgumentException('Invalid diagnostic command.');
	}
	if (!function_exists('proc_open')) {
		throw new RuntimeException('Process execution is unavailable in the Cacti poller PHP runtime.');
	}
	$timeout = max(1, min(75, (float) $timeout));
	$pipes = [];
	$process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
	if (!is_resource($process)) {
		throw new RuntimeException('Could not start the diagnostic executable on this collector.');
	}
	$stdout = $stderr = '';
	$truncated = $timed_out = false;
	$exit = -1;
	$limit = 262144; // Per stream; enough for bounded iPerf3 JSON, never unbounded memory.
	$started = hrtime(true);
	try {
		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		while (true) {
			nms_diag_drain($pipes[1], $stdout, $truncated, $limit);
			nms_diag_drain($pipes[2], $stderr, $truncated, $limit);
			$status = proc_get_status($process);
			if (!$status['running']) {
				$exit = (int) $status['exitcode'];
				if ($exit < 0 && !empty($status['signaled'])) $exit = 128 + (int) $status['termsig'];
				break;
			}
			if ((hrtime(true) - $started) / 1e9 >= $timeout) {
				$timed_out = true;
				proc_terminate($process);
				usleep(100000);
				$status = proc_get_status($process);
				if ($status['running']) {
					proc_terminate($process, 9);
				}
				break;
			}
			usleep(20000);
		}
		// Drain the finite pipe tail after exit/termination, still under a time bound.
		$drain_until = hrtime(true) + 200000000;
		do {
			nms_diag_drain($pipes[1], $stdout, $truncated, $limit);
			nms_diag_drain($pipes[2], $stderr, $truncated, $limit);
			if (feof($pipes[1]) && feof($pipes[2])) break;
			usleep(1000);
		} while (hrtime(true) < $drain_until);
	} finally {
		$status = proc_get_status($process);
		if ($status['running']) proc_terminate($process, 9);
		foreach ($pipes as $pipe) {
			if (is_resource($pipe)) fclose($pipe);
		}
		$closed = proc_close($process);
		if ($exit < 0) $exit = $closed;
	}
	$output = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));
	if ($timed_out) $output .= "\nTest exceeded its {$timeout}-second limit. Partial output is shown.";
	if ($truncated) $output .= "\nOutput truncated at the capture limit.";
	return ['exit' => $timed_out ? 124 : $exit, 'stdout' => $stdout, 'stderr' => $stderr,
		'output' => trim($output) ?: 'The executable returned no output.',
		'timed_out' => $timed_out, 'truncated' => $truncated];
}

/** Read the current assignment; never execute a stale saved command from a queue. */
function nms_diag_assignment($host_id, $tool)
{
	if (!isset(nms_diag_labels()[$tool])) throw new InvalidArgumentException('Unsupported diagnostic tool.');
	$row = db_fetch_row_prepared(
		"SELECT h.id AS host_id, h.hostname, h.description, h.poller_id, h.disabled, p.*
		FROM host h JOIN plugin_nms_diagnostic_devices d ON d.host_id=h.id
		JOIN plugin_nms_diagnostic_profiles p ON p.id=d.profile_id
		WHERE h.id=? AND h.deleted=''", [(int) $host_id]);
	if (!$row || $row['disabled'] !== '') throw new RuntimeException('Select an enabled device with a diagnostic profile.');
	if (!in_array($tool, nms_diag_tools($row['tools']), true)) throw new RuntimeException('This tool is not enabled by the assigned profile.');
	$row['hostname'] = nms_diag_target($row['hostname']);
	// Revalidate saved bounds as well as form input.
	nms_diag_profile_validate(['diagnostic_profile_name' => $row['name'], 'diagnostic_tools' => $row['tools'],
		'ping_count' => $row['ping_count'], 'trace_hops' => $row['trace_hops'], 'bandwidth_seconds' => $row['bandwidth_seconds']]);
	return $row;
}

/** Fail closed if device, profile or collector changed while the request was queued. */
function nms_diag_signature($row)
{
	return hash('sha256', json_encode(array_intersect_key($row, array_flip([
		'host_id', 'hostname', 'poller_id', 'id', 'name', 'tools', 'ping_count', 'trace_hops', 'bandwidth_seconds'
	])), JSON_THROW_ON_ERROR));
}

/** Build only approved executable arguments; no socket preflight or shell interpolation. */
function nms_diag_command($row, $tool)
{
	$target = nms_diag_target($row['hostname']);
	$name = $tool === 'arp' ? 'ip' : $tool;
	$binary = nms_diag_program($name);
	if ($tool === 'traceroute' && !$binary) {
		$name = 'tracepath';
		$binary = nms_diag_program($name);
	}
	if (!$binary) throw new RuntimeException($name . ' was not found or is not executable by this collector. Install the matching tool for its OS and architecture.');
	$timeout = 40;
	switch ($tool) {
		case 'arp': $args = ['neigh', 'show']; $timeout = 5; break;
		case 'ping': $args = ['-n', '-c', (string) $row['ping_count'], '-W', '2', '-w', (string) (3 * $row['ping_count'] + 2), $target]; $timeout = 3 * $row['ping_count'] + 5; break;
		case 'traceroute':
			$args = $name === 'tracepath' ? ['-n', '-m', (string) $row['trace_hops'], $target] : ['-n', '-q', '1', '-m', (string) $row['trace_hops'], '-w', '2', $target];
			$timeout = 2 * $row['trace_hops'] + 5; break;
		case 'iperf3': $args = ['-c', $target, '-p', '5201', '-t', (string) $row['bandwidth_seconds'], '-J']; $timeout = $row['bandwidth_seconds'] + 10; break;
		case 'netperf': $args = ['-H', $target, '-p', '12865', '-t', 'TCP_STREAM', '-l', (string) $row['bandwidth_seconds']]; $timeout = $row['bandwidth_seconds'] + 10; break;
		case 'pathchar': $args = ['-n', $target]; $timeout = 60; break;
		default: throw new InvalidArgumentException('Unsupported diagnostic tool.');
	}
	return [array_merge([$binary], $args), $timeout];
}

/** Convert actual executable errors into explanations without inventing an exit status. */
function nms_diag_result($row, $tool, $command, $result)
{
	if ($tool === 'iperf3' && !empty($result['truncated'])) {
		$result['protocol_error'] = true;
		$result['output'] .= "\nThe iPerf3 response was truncated; no complete bandwidth measurement is available.";
	}
	if ($tool === 'iperf3' && empty($result['truncated'])) {
		$data = json_decode($result['stdout'], true);
		if (is_array($data) && !empty($data['error'])) {
			$result['output'] = 'iPerf3: ' . (string) $data['error'] . "\n" . $result['output'];
			$result['protocol_error'] = true;
		} elseif ($result['exit'] === 0 && is_array($data) && isset($data['end']['sum_received']['bits_per_second'])) {
			$result['output'] = 'Receiver throughput: ' . number_format((float) $data['end']['sum_received']['bits_per_second'] / 1000000, 2) . " Mbit/s\n\n" . $result['output'];
		} elseif ($result['exit'] === 0) {
			$result['protocol_error'] = true;
			$result['output'] .= "\nThe client returned no complete iPerf3 receiver measurement.";
		}
	}
	if (preg_match('/permission denied|operation not permitted/i', $result['output'])) {
		$result['output'] .= "\nThe collector runtime denied this operation. NMS cannot override host permissions; no OS settings were changed.";
	} elseif ($result['exit'] !== 0 && in_array($tool, ['iperf3', 'netperf'], true)) {
		$result['output'] .= $tool === 'iperf3'
			? "\nVerify an authorised iperf3 server at the target on TCP 5201 and the network path."
			: "\nVerify netserver at the target on TCP 12865 and permit its separate negotiated TCP data connection.";
	}
	if ($tool === 'arp' && $result['exit'] === 0 && trim($result['stdout']) === '' && trim($result['stderr']) === '') {
		$result['output'] = 'No IPv4 or IPv6 neighbours are cached by this collector.';
	}
	if (in_array($tool, ['iperf3', 'netperf'], true) && !empty($result['self_test'])) {
		$result['output'] = "Collector loopback self-test — this is not network-link bandwidth.\nTemporary local server stopped after the test.\n\n" . $result['output'];
	} elseif ($tool === 'iperf3' && stripos($result['output'], 'Bad file descriptor') !== false) {
		$result['output'] = "No bandwidth measurement completed. iPerf3 could not establish its control connection. Check that the target runs an iPerf3 server on TCP 5201.\n\n" . $result['output'];
	}
	$result['output'] = '$ ' . implode(' ', array_map('escapeshellarg', $command)) . "\n" . $result['output'];
	$result['tool'] = $tool;
	$result['target'] = $tool === 'arp' ? 'Collector neighbour cache' : $row['hostname'];
	$result['profile'] = $row['name'];
	$result['collector_id'] = (int) $row['poller_id'];
	$result['execution_host'] = gethostname() ?: 'Unknown';
	// Stream buffers are used for parsing only; store one bounded combined result.
	unset($result['stdout'], $result['stderr']);
	return $result;
}

/** Commands may only execute in the existing native collector CLI process. */
function nms_diag_execute($row, $tool)
{
	if (PHP_SAPI !== 'cli') throw new RuntimeException('Diagnostics must run through the Cacti poller queue.');
	if (PHP_OS_FAMILY !== 'Linux') throw new RuntimeException('These diagnostic command options require a Linux collector.');
	require_once __DIR__ . '/inventory.php';
	if (nms_inventory_collector_id() !== (int) $row['poller_id']) throw new RuntimeException('Diagnostic belongs to another collector.');
	[$command, $timeout] = nms_diag_command($row, $tool);
	require_once __DIR__ . '/diagnostic_iperf.php';
	if ($tool === 'iperf3' && nms_diag_iperf_loopback($row['hostname'])) {
		[$command, $result] = nms_diag_iperf_self_test($command, $timeout);
	} elseif ($tool === 'netperf' && nms_diag_iperf_loopback($row['hostname'])) {
		require_once __DIR__ . '/diagnostic_netperf.php';
		[$command, $result] = nms_diag_netperf_self_test($command, $timeout);
	} else {
		$result = nms_diag_run_command($command, $timeout);
	}
	return nms_diag_result($row, $tool, $command, $result);
}
