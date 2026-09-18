<?php
/** Bounded supplemental reachability probes; native Automation remains inventory/discovery owner. */
function nms_nd_network_targets($network)
{
	global $config;
	require_once $config["base_path"] . "/lib/api_automation.php";
	$targets = [];
	foreach (explode(",", (string) $network["subnet_range"]) as $range) {
		$range = trim($range);
		$total = automation_calculate_total_ips($range);
		$start = automation_calculate_start($range);
		if (!$total || $total > 256 || !$start) {
			throw new InvalidArgumentException(
				"Supplemental probes require valid native IPv4 ranges of at most 256 addresses total.",
			);
		}
		for ($i = 0; $i < $total; $i++) {
			$ip = $i === 0 ? $start : automation_get_next_host($start, $total, $i, $range);
			if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
				throw new InvalidArgumentException("Native range produced an invalid address.");
			}
			$targets[$ip] = $ip;
			if (count($targets) > 256) {
				throw new InvalidArgumentException("Supplemental probe limit is 256 addresses.");
			}
		}
	}
	return array_values($targets);
}
/**
 * Handles nd network ports.
 */
function nms_nd_network_ports($input)
{
	if (!is_string($input) || strlen($input) > 100) {
		throw new InvalidArgumentException("Enter up to 8 comma-separated ports.");
	}
	$ports = [];
	foreach (explode(",", $input) as $port) {
		$port = trim($port);
		if (!preg_match('/^[0-9]{1,5}$/D', $port) || (int) $port < 1 || (int) $port > 65535) {
			throw new InvalidArgumentException("Ports must be integers from 1 to 65535.");
		}
		$ports[(int) $port] = (int) $port;
	}
	if (count($ports) > 8) {
		throw new InvalidArgumentException("Select at most 8 ports.");
	}
	return array_values($ports);
}
/** Each invocation runs exactly one selected protocol. No credential or protocol fallback. */
function nms_nd_network_probe($ip, $method, $port, $timeout)
{
	global $config;
	if (
		!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ||
		!in_array($method, ["icmp", "tcp", "udp"], true) ||
		$timeout < 100 ||
		$timeout > 2000 ||
		$port < 1 ||
		$port > 65535
	) {
		throw new InvalidArgumentException("Invalid bounded probe.");
	}
	if (!extension_loaded("sockets")) {
		throw new RuntimeException("PHP sockets is required. No SNMP fallback was attempted.");
	}
	require_once $config["base_path"] . "/lib/ping.php";
	$ping = new Net_Ping();
	$ping->host = ["hostname" => $ip, "snmp_community" => "", "snmp_version" => 0];
	$ping->port = (int) $port;
	$types = ["icmp" => PING_ICMP, "tcp" => PING_TCP, "udp" => PING_UDP];
	$ok = $ping->ping(AVAIL_PING, $types[$method], (int) $timeout, 1);
	return [
		"method" => $method,
		"port" => $method === "icmp" ? null : (int) $port,
		"reachable" => (bool) $ok,
		"detail" =>
			$method === "udp"
				? "Native UDP reachability result; this does not prove an open UDP service."
				: ($ok
					? "Response received"
					: "No response; check reachability and collector permissions."),
	];
}

/**
 * Handles nd network profile.
 */
function nms_nd_network_profile($network, $item_id)
{
	$p = db_fetch_row_prepared("SELECT * FROM automation_snmp_items WHERE id=? AND snmp_id=?", [
		$item_id,
		$network["snmp_id"],
	]);
	if (!$p) {
		throw new InvalidArgumentException("Select a native SNMP option belonging to this network.");
	}
	if (!in_array((string) $p["snmp_version"], ["2", "3"], true)) {
		throw new InvalidArgumentException("Supplemental SNMP probes require v2c/v3; native Cacti retains v1 support.");
	}
	return $p;
}
/**
 * Handles nd network signature.
 */
function nms_nd_network_signature($network, $job)
{
	$p = in_array("snmp", explode(",", $job["methods"]), true)
		? nms_nd_network_profile($network, $job["snmp_item_id"])
		: [];
	return hash(
		"sha256",
		json_encode(
			[$network["subnet_range"], $network["poller_id"], $network["enabled"], $p, $job["methods"], $job["ports"]],
			JSON_THROW_ON_ERROR,
		),
	);
}
/**
 * Handles nd network queue.
 */
function nms_nd_network_queue($input)
{
	nms_require_management(23);
	$id = nms_topology_integer($input["network_id"] ?? 0, 1, 2147483647, "Native network");
	$lock = "nms_network_probe_" . $id;
	if ((int) db_fetch_cell_prepared("SELECT GET_LOCK(?,0)", [$lock]) !== 1) {
		throw new RuntimeException("This network is currently being probed. Try again when it finishes.");
	}
	try {
		return nms_nd_network_queue_locked($input);
	} finally {
		db_fetch_cell_prepared("SELECT RELEASE_LOCK(?)", [$lock]);
	}
}
/**
 * Handles nd network queue locked.
 */
function nms_nd_network_queue_locked($input)
{
	nms_require_management(23);
	$id = nms_topology_integer($input["network_id"] ?? 0, 1, 2147483647, "Native network");
	$network = db_fetch_row_prepared("SELECT * FROM automation_networks WHERE id=?", [$id]);
	if (!$network || $network["enabled"] !== "on") {
		throw new InvalidArgumentException("Enable the selected network in Cacti Automation first.");
	}
	nms_nd_network_targets($network);
	$methods = $input["methods"] ?? [];
	if (!is_array($methods) || !$methods || count($methods) > 4) {
		throw new InvalidArgumentException("Select 1–4 methods.");
	}
	foreach ($methods as $m) {
		if (!is_string($m) || !in_array($m, ["icmp", "tcp", "udp", "snmp"], true)) {
			throw new InvalidArgumentException("Invalid probe method.");
		}
	}
	$methods = array_values(array_unique($methods));
	$ports = array_intersect($methods, ["tcp", "udp"]) ? implode(",", nms_nd_network_ports($input["ports"] ?? "")) : "";
	$item = in_array("snmp", $methods, true)
		? nms_topology_integer($input["snmp_item_id"] ?? 0, 1, 2147483647, "SNMP option")
		: 0;
	if ($item) {
		nms_nd_network_profile($network, $item);
	}
	$follow = $input["follow_schedule"] ?? 0;
	if (!in_array($follow, [0, 1, "0", "1"], true)) {
		throw new InvalidArgumentException("Invalid schedule setting.");
	}
	$existing = db_fetch_row_prepared("SELECT status FROM plugin_nms_discovery_network_jobs WHERE network_id=?", [$id]);
	if ($existing && in_array($existing["status"], ["queued", "running"], true)) {
		throw new RuntimeException("This network already has a pending probe. Wait for completion.");
	}
	nms_category_execute(
		"INSERT INTO plugin_nms_discovery_network_jobs(network_id,methods,ports,snmp_item_id,follow_schedule,created_by,status,results_json,requested_at,native_started) VALUES (?,?,?,?,?,?,'queued','[]',NOW(),?) ON DUPLICATE KEY UPDATE methods=VALUES(methods),ports=VALUES(ports),snmp_item_id=VALUES(snmp_item_id),follow_schedule=VALUES(follow_schedule),created_by=VALUES(created_by),revision=revision+1,status='queued',progress_cursor=0,config_hash='',results_json='[]',requested_at=NOW(),finished_at=NULL,error='',native_started=VALUES(native_started)",
		[$id, implode(",", $methods), $ports, $item, (int) $follow, nms_current_user_id(), $network["last_started"]],
	);
}
/**
 * Handles nd network snmp probe.
 */
function nms_nd_network_snmp_probe($ip, $network, $job)
{
	require_once __DIR__ . "/discovery_snmp.php";
	$p = nms_nd_network_profile($network, $job["snmp_item_id"]);
	$p["hostname"] = $ip;
	$p["disabled"] = "";
	$p["poller_id"] = $network["poller_id"];
	$p["nms_snmp_retries"] = $p["snmp_retries"];
	foreach (
		[
			"snmp_username",
			"snmp_password",
			"snmp_auth_protocol",
			"snmp_priv_passphrase",
			"snmp_priv_protocol",
			"snmp_context",
			"snmp_engine_id",
		]
		as $k
	) {
		$p[$k] = (string) ($p[$k] ?? "");
	}
	$s = nms_nd_discovery_session($p);
	try {
		$value = nms_nd_snmp_scalar($s, "1.3.6.1.2.1.1.1.0", microtime(true) + 10);
		return [
			"method" => "snmp",
			"port" => (int) $p["snmp_port"],
			"reachable" => true,
			"detail" => "sysDescr: " . nms_nd_octets($value["value"]),
		];
	} finally {
		$s->close();
	}
}
/** Resume bounded latest-result jobs on their native collector; no inventory/core discovery writes. */
function nms_nd_network_poll()
{
	global $config;
	require_once $config["base_path"] . "/lib/auth.php";
	$deadline = microtime(true) + 30;
	$jobs = db_fetch_assoc_prepared(
		"SELECT j.* FROM plugin_nms_discovery_network_jobs j JOIN automation_networks n ON n.id=j.network_id WHERE n.poller_id=? ORDER BY j.requested_at",
		[$config["poller_id"]],
	);
	foreach ($jobs as $job) {
		if (microtime(true) > $deadline) {
			break;
		}
		$lock = "nms_network_probe_" . $job["network_id"];
		if ((int) db_fetch_cell_prepared("SELECT GET_LOCK(?,0)", [$lock]) !== 1) {
			continue;
		}
		try {
			$job = db_fetch_row_prepared("SELECT * FROM plugin_nms_discovery_network_jobs WHERE network_id=?", [
				$job["network_id"],
			]);
			$network = db_fetch_row_prepared("SELECT * FROM automation_networks WHERE id=?", [$job["network_id"]]);
			if (!$network || $network["enabled"] !== "on") {
				throw new RuntimeException("Native network is disabled or unavailable.");
			}
			$user = (int) $job["created_by"];
			if (
				!db_fetch_cell_prepared("SELECT id FROM user_auth WHERE id=? AND enabled='on'", [$user]) ||
				!is_realm_allowed(23, $user)
			) {
				throw new RuntimeException("Probe owner no longer has native Automation permission.");
			}
			if (!in_array($job["status"], ["queued", "running"], true)) {
				if (!$job["follow_schedule"] || $network["last_started"] === $job["native_started"]) {
					continue;
				}
				nms_category_execute(
					"UPDATE plugin_nms_discovery_network_jobs SET status='queued',progress_cursor=0,config_hash='',results_json='[]',requested_at=NOW(),finished_at=NULL,error='',native_started=? WHERE network_id=?",
					[$network["last_started"], $job["network_id"]],
				);
				$job = db_fetch_row_prepared("SELECT * FROM plugin_nms_discovery_network_jobs WHERE network_id=?", [
					$job["network_id"],
				]);
			}
			if (time() - strtotime($job["requested_at"]) > 3600) {
				throw new RuntimeException("Probe exceeded one hour; partial results are incomplete.");
			}
			$hash = nms_nd_network_signature($network, $job);
			if ($job["config_hash"] !== "" && !hash_equals($job["config_hash"], $hash)) {
				throw new RuntimeException("Native network or SNMP settings changed. Queue a new probe.");
			}
			$tasks = [];
			$ports = $job["ports"] === "" ? [] : nms_nd_network_ports($job["ports"]);
			foreach (nms_nd_network_targets($network) as $ip) {
				foreach (explode(",", $job["methods"]) as $method) {
					foreach (in_array($method, ["tcp", "udp"], true) ? $ports : [1] as $port) {
						$tasks[] = [$ip, $method, $port];
					}
				}
			}
			$results = json_decode($job["results_json"], true, 512, JSON_THROW_ON_ERROR);
			$cursor = (int) $job["progress_cursor"];
			nms_category_execute(
				"UPDATE plugin_nms_discovery_network_jobs SET status='running',config_hash=? WHERE network_id=?",
				[$hash, $job["network_id"]],
			);
			while ($cursor < count($tasks) && microtime(true) < $deadline) {
				$latest = db_fetch_row_prepared("SELECT * FROM automation_networks WHERE id=?", [$job["network_id"]]);
				if (
					!$latest ||
					$latest["enabled"] !== "on" ||
					!hash_equals($hash, nms_nd_network_signature($latest, $job))
				) {
					throw new RuntimeException(
						"Native network settings changed during collection; partial results are incomplete.",
					);
				}
				[$ip, $method, $port] = $tasks[$cursor];
				try {
					$r =
						$method === "snmp"
							? nms_nd_network_snmp_probe($ip, $network, $job)
							: nms_nd_network_probe($ip, $method, $port, 500);
				} catch (Throwable $e) {
					$reportedPort = $method === "icmp" ? null : $port;
					if ($method === "snmp") {
						// The task marker is not a destination port. Report only the selected native option.
						$reportedPort = null;
						try {
							$profile = nms_nd_network_profile($latest, $job["snmp_item_id"]);
							$reportedPort = (int) $profile["snmp_port"];
						} catch (Throwable $ignored) {
						}
					}
					$r = [
						"method" => $method,
						"port" => $reportedPort,
						"reachable" => false,
						"detail" => $e instanceof RuntimeException ? $e->getMessage() : "Probe failed.",
					];
				}
				$r["ip"] = $ip;
				$r["checked_at"] = time();
				$results[] = $r;
				$cursor++;
				nms_category_execute(
					"UPDATE plugin_nms_discovery_network_jobs SET progress_cursor=?,results_json=? WHERE network_id=?",
					[$cursor, json_encode($results, JSON_THROW_ON_ERROR), $job["network_id"]],
				);
			}
			if ($cursor === count($tasks)) {
				nms_category_execute(
					"UPDATE plugin_nms_discovery_network_jobs SET status='complete',finished_at=NOW() WHERE network_id=?",
					[$job["network_id"]],
				);
			}
		} catch (Throwable $e) {
			nms_category_execute(
				"UPDATE plugin_nms_discovery_network_jobs SET status='failed',finished_at=NOW(),error=? WHERE network_id=?",
				[
					substr($e instanceof RuntimeException ? $e->getMessage() : "Network probe failed.", 0, 255),
					$job["network_id"],
				],
			);
		} finally {
			db_fetch_cell_prepared("SELECT RELEASE_LOCK(?)", [$lock]);
		}
	}
}
