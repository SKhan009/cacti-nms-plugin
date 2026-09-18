<?php
/** Small, credential-free views of discovery evidence, including older stored snapshots. */
require_once __DIR__ . "/discovery_neighbors.php";

/** Format a chassis MAC from its typed key even when an old snapshot stored a hex label. */
function nms_nd_peer_label($observation)
{
	if (
		($observation["protocol"] ?? "") === "lldp" &&
		preg_match('/^4:([0-9a-f]{12})$/iD', $observation["peer_key"] ?? "", $match)
	) {
		return implode(":", str_split(strtolower($match[1]), 2));
	}
	return $observation["peer_label"] ?? "Unknown chassis";
}

/** Detect duplicate collected identities without exposing other sites, hidden devices, or credentials. */
function nms_nd_identity_warnings($snapshots)
{
	$groups = [];
	$warnings = [];
	foreach ($snapshots as $s) {
		if (
			!empty($s["valid"]) &&
			$s["status"] === "success" &&
			!empty($s["data"]["identity"]) &&
			in_array($s["protocol"], ["lldp", "cdp"], true)
		) {
			$groups[$s["protocol"] . "|" . $s["data"]["identity"]][(int) $s["host_id"]] = true;
		}
	}
	foreach ($groups as $group) {
		if (count($group) > 1) {
			foreach (array_keys($group) as $id) {
				$warnings[$id]["identity"] =
					"Duplicate collected identity among visible devices. Check each SNMP address, port and simulator dataset/community; multiple entries may be querying the same agent.";
			}
		}
	}
	return array_map("array_values", $warnings);
}

/** Human-readable observations do not claim that an unresolved or inferred endpoint is a verified cable. */
function nms_nd_observation_text($o)
{
	if (in_array($o["protocol"], ["lldp", "cdp"], true)) {
		$peer =
			($o["remote_name"] ?? "") !== ""
				? $o["remote_name"] . " / " . nms_nd_peer_label($o)
				: nms_nd_peer_label($o);
		return strtoupper($o["protocol"]) .
			" · " .
			($o["local_port"] ?: "Unknown local port") .
			" → " .
			$peer .
			" / " .
			($o["remote_port"] ?: "Unknown remote port") .
			" · " .
			($o["state"] ?? "Observed") .
			" · " .
			$o["reason"];
	}
	if ($o["protocol"] === "arp") {
		return "IP neighbour · " .
			$o["ip"] .
			" → " .
			($o["mac"] ?: "Unresolved MAC") .
			" · Reporting interface: " .
			($o["interface"] ?: "ifIndex " . $o["ifindex"]) .
			(!empty($o["scope_id"]) ? " · Zone " . $o["scope_id"] : "") .
			(!empty($o["neighbor_state"]) ? " · " . $o["neighbor_state"] : "") .
			" · " .
			$o["reason"];
	}
	return "FDB · " .
		$o["mac"] .
		" · " .
		($o["interface"] ?: "Unknown interface") .
		" · " .
		$o["reason"] .
		" · " .
		($o["macs_on_interface"] ?? 0) .
		" MACs learned on this interface";
}
