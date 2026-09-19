<?php
require_once __DIR__ . "/../discovery.php";
/** Read only NMS-owned evidence for permitted core devices in the selected site. */
function nms_topology_discovery($site_id)
{
	$out = [
		"ready" => false,
		"message" => "Sign in to Cacti.",
		"hosts" => [],
		"snapshots" => [],
		"links" => [],
		"unresolved" => [],
		"policy" => [],
		"ports" => [],
		"refresh" => 300,
	];
	if (empty($_SESSION["sess_user_id"])) {
		return $out;
	}
	try {
		$stale = 0;
		foreach (nms_nd_hosts() as $host) {
			if (
				($site_id === null || (int) $host["site_id"] === (int) $site_id) &&
				is_device_allowed((int) $host["id"])
			) {
				$out["hosts"][$host["id"]] = $host;
				$stale = max($stale, (int) $host["stale_seconds"]);
				$out["refresh"] = min($out["refresh"], (int) $host["refresh_seconds"]);
			}
		}
		foreach (db_fetch_assoc("SELECT * FROM plugin_nms_discovery_snapshots") as $s) {
			$h = $out["hosts"][$s["host_id"]] ?? null;
			if (!$h || ($s["protocol"] !== "identity" && !in_array($s["protocol"], nms_nd_host_methods($h), true))) {
				continue;
			}
			$s["valid"] = $h["enabled"] && $h["collection_enabled"] && hash_equals($s["config_hash"], nms_nd_hash($h));
			$s["data"] = json_decode($s["data_json"], true, 512, JSON_THROW_ON_ERROR);
			if (
				$s["status"] === "success" &&
				!nms_nd_evidence_fresh($s["data"]["collected"] ?? 0, time(), (int) $h["stale_seconds"])
			) {
				$s["status"] = "stale";
			}
			$out["snapshots"][$s["host_id"] . "|" . $s["protocol"]] = $s;
		}
		if (!$out["hosts"]) {
			$out["refresh"] = 30;
		}
		$resolved = nms_nd_reconcile($out["snapshots"], time(), $stale);
		$out["links"] = $resolved["links"];
		$out["unresolved"] = $resolved["unresolved"];
		$out["ports"] = nms_topology_discovery_ports($out["hosts"], $out["snapshots"], $out["links"]);
		$out["ready"] = true;
		$out["message"] =
			"NMS-owned discovery using existing Cacti device SNMP settings. Viewing this page does not start collection.";
	} catch (Throwable $e) {
		$out["message"] = "NMS discovery is unavailable. Complete the NMS upgrade and check configuration.";
	}
	return $out;
}
/** Build a current port matrix from IF-MIB state and only resolved LLDP/CDP links. */
function nms_topology_discovery_ports($hosts, $snapshots, $links)
{
	$rows = [];
	$states = [1 => "Up", 2 => "Down", 3 => "Testing", 4 => "Unknown", 5 => "Dormant", 6 => "Not present", 7 => "Lower layer down"];
	foreach ($hosts as $host_id => $host) {
		$snapshot = $snapshots[$host_id . "|identity"] ?? null;
		if (!$snapshot || empty($snapshot["valid"]) || $snapshot["status"] !== "success") {
			$rows[] = ["host_id" => (int) $host_id, "device" => $host["description"], "port" => "—", "if_mib" => "Not collected", "state" => "No current IF-MIB interface data", "peer" => "—", "evidence" => "Assign discovery and wait for the collector poll."];
			continue;
		}
		foreach ($snapshot["data"]["interfaces"] ?? [] as $interface) {
			$connection = null;
			foreach ($links as $link) {
				if (empty($link["current"])) {
					continue;
				}
				if ((int) $link["a"][0] === (int) $host_id && (int) $link["a"][1] === (int) $interface["index"]) {
					$connection = ["host_id" => (int) $link["b"][0], "ifindex" => (int) $link["b"][1], "from_a" => true, "link" => $link];
					break;
				}
				if ((int) $link["b"][0] === (int) $host_id && (int) $link["b"][1] === (int) $interface["index"]) {
					$connection = ["host_id" => (int) $link["a"][0], "ifindex" => (int) $link["a"][1], "from_a" => false, "link" => $link];
					break;
				}
			}
			$oper = (int) ($interface["oper"] ?? 0);
			$admin = (int) ($interface["admin"] ?? 0);
			$peer = "—";
			if ($connection) {
				$evidence = $connection["link"]["evidence"][0] ?? [];
				$remote_port = $connection["from_a"] ? ($evidence["remote_port"] ?? "ifIndex " . $connection["ifindex"]) : ($evidence["local_port"] ?? "ifIndex " . $connection["ifindex"]);
				$peer = ($hosts[$connection["host_id"]]["description"] ?? "Device") . " / " . $remote_port;
				$state = "In use — connected";
			} elseif ($oper === 6) {
				$state = "Unavailable — interface not present";
			} elseif ($admin === 2) {
				$state = "Disabled by device configuration";
			} elseif ($oper === 2) {
				$state = "Link down — no active physical connection";
			} elseif ($oper === 1) {
				$state = "Link up — neighbour not advertised";
			} else {
				$state = "Status not reported by IF-MIB";
			}
			$rows[] = [
				"host_id" => (int) $host_id,
				"device" => $host["description"],
				"port" => ($interface["name"] ?: $interface["description"] ?: "ifIndex " . $interface["index"]) . " / ifIndex " . $interface["index"],
				"if_mib" => ($states[$admin] ?? "Unknown") . " admin / " . ($states[$oper] ?? "Unknown") . " oper",
				"state" => $state,
				"peer" => $peer,
				"evidence" => $connection ? strtoupper(implode(", ", array_keys($connection["link"]["protocols"]))) . " current neighbour evidence" : "IF-MIB interface state",
			];
		}
	}
	return $rows;
}

/**
 * Handles topology discovery state.
 */
function nms_topology_discovery_state($snapshot, $stale, $now)
{
	if (!$snapshot) {
		return "Not collected";
	}
	if (!$snapshot["valid"]) {
		return "Disabled / configuration changed";
	}
	if ($snapshot["status"] !== "success") {
		return ucfirst($snapshot["status"]);
	}
	return nms_nd_evidence_fresh($snapshot["data"]["collected"] ?? 0, $now, $stale) ? "Current" : "Stale";
}
