<?php
require_once __DIR__ . "/discovery_neighbors.php";
/** Validate all timing and protocol values before persistence. */
function nms_nd_preset_validate($input)
{
	if (
		!is_array($input) ||
		!is_string($input["name"] ?? null) ||
		(!isset($input["methods"]) && !is_string($input["protocol"] ?? null)) ||
		!in_array($input["enabled"] ?? 0, [0, 1, "0", "1"], true)
	) {
		throw new InvalidArgumentException("Invalid discovery preset fields.");
	}
	$name = trim($input["name"]);
	if ($name === "" || strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
		throw new InvalidArgumentException("Enter a preset name of 1–100 bytes without control characters.");
	}
	$protocol = implode(",", nms_nd_methods_validate($input["methods"] ?? nms_nd_protocols($input["protocol"] ?? "")));
	$out = ["name" => $name, "protocol" => $protocol, "enabled" => !empty($input["enabled"]) ? 1 : 0];
	foreach (
		["interval_seconds" => [300, 86400], "stale_seconds" => [600, 604800], "refresh_seconds" => [10, 300]]
		as $key => $range
	) {
		$v = $input[$key] ?? "";
		if (!is_scalar($v) || !preg_match('/^[0-9]+$/D', (string) $v) || $v < $range[0] || $v > $range[1]) {
			throw new InvalidArgumentException(
				$key . " must be an integer from " . $range[0] . " to " . $range[1] . ".",
			);
		}
		$out[$key] = (int) $v;
	}
	if ($out["stale_seconds"] < 2 * $out["interval_seconds"]) {
		throw new InvalidArgumentException("Stale threshold must be at least twice the collection interval.");
	}
	return $out;
}
/**
 * Handles nd method labels.
 */
function nms_nd_method_labels()
{
	return [
		"lldp" => "LLDP neighbours",
		"cdp" => "CDP neighbours",
		"arp" => "IP neighbours (IPv4 / IPv6)",
		"fdb" => "MAC/FDB table",
	];
}
/**
 * Handles nd protocols.
 */
function nms_nd_protocols($protocol)
{
	if ($protocol === "both") {
		return ["lldp", "cdp"];
	}
	if (!is_string($protocol)) {
		return [];
	}
	$parts = explode(",", $protocol);
	return count(array_diff($parts, array_keys(nms_nd_method_labels()))) ? [] : array_values(array_unique($parts));
}
/**
 * Handles nd methods validate.
 */
function nms_nd_methods_validate($methods)
{
	if (!is_array($methods) || !$methods || count($methods) > 4) {
		throw new InvalidArgumentException("Select at least one collection method.");
	}
	foreach ($methods as $method) {
		if (!is_string($method) || !isset(nms_nd_method_labels()[$method])) {
			throw new InvalidArgumentException("Unknown collection method.");
		}
	}
	return array_values(array_intersect(array_keys(nms_nd_method_labels()), $methods));
}
/**
 * Handles nd host methods.
 */
function nms_nd_host_methods($host)
{
	$preset = nms_nd_protocols($host["protocol"]);
	return empty($host["methods"])
		? $preset
		: array_values(array_intersect($preset, nms_nd_protocols($host["methods"])));
}
/** Hash core connection settings and assignment so changes invalidate previous evidence. */
function nms_nd_hash($host)
{
	$fields = [
		"hostname",
		"site_id",
		"poller_id",
		"disabled",
		"deleted",
		"snmp_version",
		"snmp_community",
		"snmp_username",
		"snmp_password",
		"snmp_auth_protocol",
		"snmp_priv_passphrase",
		"snmp_priv_protocol",
		"snmp_context",
		"snmp_engine_id",
		"snmp_port",
		"snmp_timeout",
		"preset_id",
		"protocol",
		"enabled",
		"methods",
		"collection_enabled",
	];
	$values = [];
	foreach ($fields as $key) {
		$values[$key] = (string) ($host[$key] ?? "");
	}
	return hash("sha256", json_encode($values, JSON_THROW_ON_ERROR));
}
function nms_nd_hosts()
{
	return db_fetch_assoc(
		"SELECT h.*,d.preset_id,d.last_attempt,d.methods,d.collection_enabled,p.name AS preset_name,p.protocol,p.enabled,p.interval_seconds,p.stale_seconds,p.refresh_seconds FROM host h JOIN plugin_nms_discovery_devices d ON d.host_id=h.id JOIN plugin_nms_discovery_presets p ON p.id=d.preset_id WHERE h.deleted='' AND h.disabled=''",
	);
}
/** Poller-owned collection, one device at a time, no web-triggered SNMP or fallback. */
function nms_nd_poll()
{
	global $config;
	require_once __DIR__ . "/discovery_snmp.php";
	nms_nd_apply_rules();
	$deadline = PHP_FLOAT_MAX;
	$hosts = nms_nd_hosts();
	usort($hosts, function ($a, $b) {
		return strcmp($a["last_attempt"] ?? "", $b["last_attempt"] ?? "");
	});
	foreach ($hosts as $host) {
		if (
			!$host["enabled"] ||
			!$host["collection_enabled"] ||
			(int) $host["poller_id"] !== (int) $config["poller_id"]
		) {
			continue;
		}
		if ($host["last_attempt"] && time() - strtotime($host["last_attempt"]) < (int) $host["interval_seconds"]) {
			continue;
		}
		$lock = "nms_discovery_" . (int) $host["id"];
		if ((int) db_fetch_cell_prepared("SELECT GET_LOCK(?,0)", [$lock]) !== 1) {
			continue;
		}
		try {
			$last = db_fetch_cell_prepared("SELECT last_attempt FROM plugin_nms_discovery_devices WHERE host_id=?", [
				$host["id"],
			]);
			if ($last && time() - strtotime($last) < (int) $host["interval_seconds"]) {
				continue;
			}
			$fresh = null;
			foreach (nms_nd_hosts() as $candidate) {
				if ($candidate["id"] == $host["id"]) {
					$fresh = $candidate;
				}
			}
			if (
				!$fresh ||
				!$fresh["enabled"] ||
				!$fresh["collection_enabled"] ||
				(int) $fresh["poller_id"] !== (int) $config["poller_id"]
			) {
				continue;
			}
			$host = $fresh;
			nms_category_execute("UPDATE plugin_nms_discovery_devices SET last_attempt=NOW() WHERE host_id=?", [
				$host["id"],
			]);
			$hash = nms_nd_hash($host);
			nms_category_execute(
				"INSERT INTO plugin_nms_discovery_snapshots(host_id,protocol,status,attempted_at,config_hash,data_json) VALUES (?,'identity','running',NOW(),?,'{}') ON DUPLICATE KEY UPDATE status='running',attempted_at=NOW(),error=''",
				[$host["id"], $hash],
			);
			try {
				$identity = nms_nd_collect_identity($host, $deadline);
				nms_category_execute(
					"UPDATE plugin_nms_discovery_snapshots SET status='success',succeeded_at=NOW(),config_hash=?,data_json=?,error='' WHERE host_id=? AND protocol='identity'",
					[$hash, json_encode($identity, JSON_THROW_ON_ERROR), $host["id"]],
				);
			} catch (Throwable $e) {
				nms_category_execute(
					"UPDATE plugin_nms_discovery_snapshots SET status='failed',error=? WHERE host_id=? AND protocol='identity'",
					[
						$e instanceof RuntimeException
							? substr($e->getMessage(), 0, 255)
							: "Identity collection failed.",
						$host["id"],
					],
				);
			}
			foreach (nms_nd_host_methods($host) as $protocol) {
				// Recheck between methods: an administrator may pause/delete/change this
				// device while the previous SNMP table walk is in progress.
				$current = null;
				foreach (nms_nd_hosts() as $candidate) {
					if ($candidate["id"] == $host["id"]) {
						$current = $candidate;
					}
				}
				if (!$current || nms_nd_hash($current) !== $hash) {
					break;
				}
				$old = db_fetch_row_prepared(
					"SELECT * FROM plugin_nms_discovery_snapshots WHERE host_id=? AND protocol=?",
					[$host["id"], $protocol],
				);
				nms_category_execute(
					"INSERT INTO plugin_nms_discovery_snapshots(host_id,protocol,status,attempted_at,config_hash,data_json) VALUES (?,?,'running',NOW(),?,'{}') ON DUPLICATE KEY UPDATE status='running',attempted_at=NOW(),error=''",
					[$host["id"], $protocol, $hash],
				);
				try {
					$data = nms_nd_collect_direct($host, $protocol, $deadline);
					$current = null;
					foreach (nms_nd_hosts() as $candidate) {
						if ($candidate["id"] == $host["id"]) {
							$current = $candidate;
						}
					}
					if (!$current || nms_nd_hash($current) !== $hash) {
						throw new RuntimeException("Configuration changed during collection.");
					}
					$previous = $old && $old["config_hash"] === $hash ? json_decode($old["data_json"], true) : [];
					$data["neighbors"] = nms_nd_observation_history(
						$previous["neighbors"] ?? [],
						$data["neighbors"],
						time(),
					);
					nms_category_execute(
						"UPDATE plugin_nms_discovery_snapshots SET status='success',succeeded_at=NOW(),config_hash=?,data_json=?,error='' WHERE host_id=? AND protocol=?",
						[$hash, json_encode($data, JSON_THROW_ON_ERROR), $host["id"], $protocol],
					);
				} catch (Throwable $e) {
					nms_category_execute(
						"UPDATE plugin_nms_discovery_snapshots SET status='failed',error=? WHERE host_id=? AND protocol=?",
						[
							$e instanceof RuntimeException ? substr($e->getMessage(), 0, 255) : "Discovery failed.",
							$host["id"],
							$protocol,
						],
					);
				}
			}
		} finally {
			db_fetch_cell_prepared("SELECT RELEASE_LOCK(?)", [$lock]);
		}
	}
}
/** Authenticated form actions write only NMS storage. */
function nms_nd_save($action, $site_id, $input)
{
	nms_require_management();
	if ($action === "discovery_preset") {
		$p = nms_nd_preset_validate($input);
		$cadence = (int) read_config_option("poller_interval");
		if ($cadence < 1 || $p["interval_seconds"] < $cadence || $p["interval_seconds"] % $cadence !== 0) {
			throw new InvalidArgumentException(
				"Collection interval must be a multiple of the native poller interval (" . $cadence . " seconds).",
			);
		}
		$id = nms_topology_integer($input["preset_id"] ?? 0, 0, 2147483647, "Preset");
		if ($id && !db_fetch_cell_prepared("SELECT id FROM plugin_nms_discovery_presets WHERE id=?", [$id])) {
			throw new InvalidArgumentException("Preset does not exist.");
		}
		$values = array_values($p);
		$values[] = nms_current_user_id();
		if ($id) {
			$values[] = $id;
			nms_category_execute(
				"UPDATE plugin_nms_discovery_presets SET name=?,protocol=?,enabled=?,interval_seconds=?,stale_seconds=?,refresh_seconds=?,updated_by=?,updated_at=NOW() WHERE id=?",
				$values,
			);
		} else {
			nms_category_execute(
				"INSERT INTO plugin_nms_discovery_presets(name,protocol,enabled,interval_seconds,stale_seconds,refresh_seconds,updated_by,updated_at) VALUES (?,?,?,?,?,?,?,NOW())",
				$values,
			);
		}
	} elseif ($action === "discovery_assignment") {
		$id = nms_topology_integer($input["host_id"] ?? 0, 1, 2147483647, "Device");
		nms_require_device_access($id);
		if (
			!db_fetch_cell_prepared("SELECT id FROM host WHERE id=? AND site_id=? AND deleted='' AND disabled=''", [
				$id,
				$site_id,
			])
		) {
			throw new InvalidArgumentException("Select an enabled device in this site.");
		}
		$preset = nms_topology_integer($input["preset_id"] ?? 0, 0, 2147483647, "Preset");
		if (!$preset) {
			nms_category_execute("DELETE FROM plugin_nms_discovery_devices WHERE host_id=?", [$id]);
			return;
		}
		if (!db_fetch_cell_prepared("SELECT id FROM plugin_nms_discovery_presets WHERE id=?", [$preset])) {
			throw new InvalidArgumentException("Select an existing preset.");
		}
		nms_category_execute(
			"INSERT INTO plugin_nms_discovery_devices(host_id,preset_id) VALUES (?,?) ON DUPLICATE KEY UPDATE preset_id=VALUES(preset_id),last_attempt=NULL",
			[$id, $preset],
		);
	} else {
		throw new InvalidArgumentException("Unsupported discovery action.");
	}
}
/** Queue a selected-device test for its owning native poller; no web SNMP or alternate collector. */
function nms_nd_test_queue($host_id, $site_id)
{
	nms_require_management();
	nms_require_device_access($host_id);
	if (
		!db_fetch_cell_prepared("SELECT id FROM host WHERE id=? AND site_id=? AND deleted='' AND disabled=''", [
			$host_id,
			$site_id,
		])
	) {
		throw new InvalidArgumentException("Select an enabled device in this site.");
	}
	$preset = db_fetch_row_prepared(
		"SELECT p.enabled,d.collection_enabled FROM plugin_nms_discovery_devices d JOIN plugin_nms_discovery_presets p ON p.id=d.preset_id WHERE d.host_id=?",
		[$host_id],
	);
	if (!$preset || !$preset["enabled"] || !$preset["collection_enabled"]) {
		throw new InvalidArgumentException("Save an enabled discovery preset for this device before testing.");
	}
	$host = null;
	foreach (nms_nd_hosts() as $candidate) {
		if ((int) $candidate["id"] === (int) $host_id) {
			$host = $candidate;
		}
	}
	if (!$host) {
		throw new RuntimeException(
			"The enabled device could not be read from the discovery assignment. Save its discovery settings and retry.",
		);
	}
	nms_category_execute("UPDATE plugin_nms_discovery_devices SET last_attempt=NULL WHERE host_id=?", [$host_id]);
	$hash = nms_nd_hash($host);
	nms_category_execute(
		"INSERT INTO plugin_nms_discovery_snapshots(host_id,protocol,status,attempted_at,config_hash,data_json,error)
  VALUES (?,'identity','queued',NOW(),?,'{}',?) ON DUPLICATE KEY UPDATE status='queued',attempted_at=NOW(),config_hash=VALUES(config_hash),data_json='{}',succeeded_at=NULL,error=VALUES(error)",
		[
			$host_id,
			$hash,
			"Queued for the assigned Cacti collector. The next poll cycle will report identity values or the exact collection error.",
		],
	);
	foreach (nms_nd_host_methods($host) as $method) {
		nms_category_execute(
			"INSERT INTO plugin_nms_discovery_snapshots(host_id,protocol,status,attempted_at,config_hash,data_json,error)
   VALUES (?,?,'queued',NOW(),?,'{}',?) ON DUPLICATE KEY UPDATE status='queued',attempted_at=NOW(),config_hash=VALUES(config_hash),data_json='{}',succeeded_at=NULL,error=VALUES(error)",
			[
				$host_id,
				$method,
				$hash,
				"Queued for the assigned Cacti collector. The next poll cycle will report success or the exact collection error.",
			],
		);
	}
}

/** Validate before saving core fields, so a malformed assignment cannot cause a partial core write. */
function nms_nd_assignment_validate($input)
{
	$preset = nms_topology_integer($input["discovery_preset_id"] ?? 0, 0, 2147483647, "Discovery preset");
	$enabled = $input["discovery_enabled"] ?? 0;
	if (!in_array($enabled, [0, 1, "0", "1"], true)) {
		throw new InvalidArgumentException("Invalid collection state.");
	}
	$mode = $input["discovery_method_mode"] ?? "preset";
	if (!in_array($mode, ["preset", "selected"], true)) {
		throw new InvalidArgumentException("Invalid collection method mode.");
	}
	if (!$preset) {
		return ["preset_id" => 0, "methods" => "", "collection_enabled" => 0];
	}
	$p = db_fetch_row_prepared("SELECT * FROM plugin_nms_discovery_presets WHERE id=?", [$preset]);
	if (!$p) {
		throw new InvalidArgumentException("Discovery preset no longer exists.");
	}
	$methods = $mode === "preset" ? "" : implode(",", nms_nd_methods_validate($input["discovery_methods"] ?? []));
	if ($methods !== "" && array_diff(nms_nd_protocols($methods), nms_nd_protocols($p["protocol"]))) {
		throw new InvalidArgumentException("Selected methods must be enabled in the shared preset.");
	}
	return ["preset_id" => $preset, "methods" => $methods, "collection_enabled" => (int) $enabled];
}
/**
 * Handles nd assignment write.
 */
function nms_nd_assignment_write($host_id, $assignment)
{
	nms_require_management();
	nms_require_device_access($host_id);
	if (!db_fetch_cell_prepared("SELECT id FROM host WHERE id=? AND deleted=''", [$host_id])) {
		throw new InvalidArgumentException("Device no longer exists.");
	}
	// Keep explicit unassigned rows so future-assignment rules cannot override a deliberate choice.
	nms_category_execute(
		"INSERT INTO plugin_nms_discovery_devices(host_id,preset_id,methods,collection_enabled,last_attempt) VALUES (?,?,?,?,NULL) ON DUPLICATE KEY UPDATE last_attempt=IF(preset_id<>VALUES(preset_id) OR methods<>VALUES(methods) OR collection_enabled<>VALUES(collection_enabled),NULL,last_attempt),preset_id=VALUES(preset_id),methods=VALUES(methods),collection_enabled=VALUES(collection_enabled)",
		[$host_id, $assignment["preset_id"], $assignment["methods"], $assignment["collection_enabled"]],
	);
}

/**
 * Handles nd bulk assign.
 */
function nms_nd_bulk_assign($input)
{
	nms_require_management();
	$ids = $input["host_ids"] ?? [];
	if (!is_array($ids) || !$ids || count($ids) > 500) {
		throw new InvalidArgumentException("Select 1–500 devices.");
	}
	$assignment = nms_nd_assignment_validate($input);
	$result = ["saved" => 0, "excluded" => 0, "errors" => []];
	foreach (array_unique($ids, SORT_REGULAR) as $id) {
		try {
			$id = nms_topology_integer($id, 1, 2147483647, "Device");
			if (
				!is_device_allowed($id) ||
				!db_fetch_cell_prepared("SELECT id FROM host WHERE id=? AND deleted='' AND disabled=''", [$id])
			) {
				$result["excluded"]++;
				continue;
			}
			nms_nd_assignment_write($id, $assignment);
			$result["saved"]++;
		} catch (Throwable $e) {
			$result["errors"][] = "Device " . (is_scalar($id) ? (int) $id : 0) . ": " . $e->getMessage();
		}
	}
	return $result;
}
