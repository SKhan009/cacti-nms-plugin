<?php
require_once __DIR__ . "/discovery.php";
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
		$out["ready"] = true;
		$out["message"] =
			"NMS-owned discovery using existing Cacti device SNMP settings. Viewing this page does not start collection.";
	} catch (Throwable $e) {
		$out["message"] = "NMS discovery is unavailable. Complete the NMS upgrade and check configuration.";
	}
	return $out;
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
