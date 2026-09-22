<?php
/** Pure parsing and reconciliation of typed SNMP evidence; no database, DNS or sample fallback. */

/** Require a typed ASN.1 value, retaining octets as bytes rather than guessing printed encodings. */
function nms_nd_value($values, $oid, $type, $required = true)
{
	if (!isset($values[$oid])) {
		if (!$required) {
			return null;
		}
		throw new RuntimeException("Required MIB object is absent or excluded by the SNMP view: " . $oid);
	}
	$v = $values[$oid];
	if ((int) $v["type"] !== $type) {
		throw new RuntimeException("Unexpected ASN.1 type at " . $oid);
	}
	return $v["value"];
}
/** Safely represent arbitrary advertised octets in JSON and HTML-facing evidence. */
function nms_nd_octets($value)
{
	if ($value === null) {
		return "";
	}
	return preg_match("//u", $value) && !preg_match('/[\x00-\x1f\x7f]/', $value) ? $value : "hex:" . bin2hex($value);
}
/** Decode one table column, enforcing the exact number of numeric index components. */
function nms_nd_column($values, $root, $column, $parts, $type)
{
	$out = [];
	$prefix = $root . "." . $column . ".";
	foreach ($values as $oid => $v) {
		if (strpos($oid, $prefix) === 0) {
			$index = substr($oid, strlen($prefix));
			if (!preg_match("/^[0-9]+(?:\.[0-9]+){" . ($parts - 1) . '}$/D', $index)) {
				throw new RuntimeException("Malformed MIB table index at " . $oid);
			}
			$out[$index] = nms_nd_value($values, $oid, $type);
		}
	}
	return $out;
}
/** Include every visible row, even when an SNMP view exposes only optional columns. */
function nms_nd_table_indices($values, $root, $parts)
{
	$indices = [];
	$prefix = $root . ".";
	foreach ($values as $oid => $v) {
		if (strpos($oid, $prefix) === 0) {
			$suffix = substr($oid, strlen($prefix));
			if (!preg_match("/^[0-9]+\.([0-9]+(?:\.[0-9]+){" . ($parts - 1) . '})$/D', $suffix, $match)) {
				throw new RuntimeException("Malformed table row: " . $oid);
			}
			$indices[$match[1]] = true;
		}
	}
	return array_keys($indices);
}
/** Parse real IF-MIB rows; the LLDP port number is never substituted for ifIndex. */
function nms_nd_interfaces($values)
{
	$root = "1.3.6.1.2.1.2.2.1";
	$ports = [];
	foreach (nms_nd_column($values, $root, 1, 1, 2) as $index => $value) {
		if ((string) $index !== (string) $value) {
			throw new RuntimeException("IF-MIB index does not match its row.");
		}
		$name = nms_nd_value($values, "1.3.6.1.2.1.31.1.1.1.1." . $index, 4, false);
		$alias = nms_nd_value($values, "1.3.6.1.2.1.31.1.1.1.18." . $index, 4, false);
		$mac = nms_nd_value($values, $root . ".6." . $index, 4, false);
		$speed = nms_nd_value($values, $root . ".5." . $index, 66, false);
		$high = nms_nd_value($values, "1.3.6.1.2.1.31.1.1.1.15." . $index, 66, false);
		$ports[$index] = [
			"index" => (int) $index,
			"name" => nms_nd_octets($name),
			"name_hex" => $name === null ? "" : bin2hex($name),
			"alias" => nms_nd_octets($alias),
            "in_octets" => nms_nd_value($values, "1.3.6.1.2.1.31.1.1.1.6." . $index, 70, false),
            "out_octets" => nms_nd_value($values, "1.3.6.1.2.1.31.1.1.1.10." . $index, 70, false),
            "discontinuity" => nms_nd_value($values, "1.3.6.1.2.1.31.1.1.1.19." . $index, 67, false),
			"alias_hex" => $alias === null ? "" : bin2hex($alias),
			"mac_hex" => $mac === null ? "" : bin2hex($mac),
			"description" => nms_nd_octets(nms_nd_value($values, $root . ".2." . $index, 4, false)),
			"description_hex" => bin2hex(nms_nd_value($values, $root . ".2." . $index, 4, false) ?? ""),
			"admin" => nms_nd_value($values, $root . ".7." . $index, 2, false),
			"oper" => nms_nd_value($values, $root . ".8." . $index, 2, false),
			"speed_bps" => $speed === null ? 0 : (int) $speed,
			"high_speed_mbps" => $high === null ? 0 : (int) $high,
		];
	}
	if (!$ports) {
		throw new RuntimeException("IF-MIB contains no readable ifIndex rows.");
	}
	return $ports;
}
/** Resolve an advertised LLDP port only by its specified identifier subtype and a unique exact match. */
function nms_nd_lldp_ifindex($subtype, $hex, $interfaces)
{
	$fields = [1 => ["alias_hex"], 3 => ["mac_hex"], 5 => ["name_hex"], 7 => ["name_hex", "description_hex"]];
	if (!isset($fields[$subtype]) || $hex === "") {
		return 0;
	}
	// Locally assigned IDs have no standard numeric relationship to ifIndex.
	// Accept only exact IF-MIB names/descriptions, unique across both columns.
	$matches = [];
	foreach ($interfaces as $i => $port) {
		foreach ($fields[$subtype] as $field) {
			if (($port[$field] ?? "") === $hex) {
				$matches[(int) $i] = true;
			}
		}
	}
	return count($matches) === 1 ? (int) array_key_first($matches) : 0;
}
/** Require an LLDP subtype and identifier without truncating malformed mandatory identities. */
function nms_nd_lldp_identity($subtype, $id)
{
	if (!preg_match('/^[1-7]$/D', (string) $subtype) || $id === "" || strlen($id) > 255) {
		throw new RuntimeException("Invalid mandatory LLDP identity.");
	}
	return (int) $subtype . ":" . bin2hex($id);
}
/** Display a typed LLDP MAC identity without altering its matching key. */
function nms_nd_lldp_label($subtype, $bytes, $macSubtype)
{
	return (int) $subtype === $macSubtype && strlen($bytes) === 6
		? implode(":", str_split(bin2hex($bytes), 2))
		: nms_nd_octets($bytes);
}
/** Parse the IEEE LLDP local and remote tables with their TimeMark/LocalPort/RemoteIndex keys. */
function nms_nd_parse_lldp($values, $interfaces)
{
	$local = "1.0.8802.1.1.2.1.3";
	$remote = "1.0.8802.1.1.2.1.4.1.1";
	$identity = nms_nd_lldp_identity(
		nms_nd_value($values, $local . ".1.0", 2),
		nms_nd_value($values, $local . ".2.0", 4),
	);
	$ports = [];
	$neighbors = [];
	foreach (nms_nd_table_indices($values, $local . ".7.1", 1) as $number) {
		$id = nms_nd_value($values, $local . ".7.1.3." . $number, 4);
		$sub = (int) nms_nd_value($values, $local . ".7.1.2." . $number, 2);
		$ifindex = nms_nd_lldp_ifindex($sub, bin2hex($id), $interfaces);
		$ports[$number] = [
			"key" => nms_nd_lldp_identity($sub, $id),
			"label" => $interfaces[$ifindex]["name"] ?? "" ?: nms_nd_lldp_label($sub, $id, 3),
			"ifindex" => $ifindex,
		];
	}
	// Detect incomplete rows as an error instead of silently dropping their evidence.
	foreach (nms_nd_table_indices($values, $remote, 3) as $index) {
		$parts = explode(".", $index);
		$number = $parts[1];
		if (!isset($ports[$number])) {
			throw new RuntimeException("LLDP neighbor references an unreadable local port.");
		}
		$chassis = nms_nd_value($values, $remote . ".5." . $index, 4);
		$peer = nms_nd_lldp_identity(nms_nd_value($values, $remote . ".4." . $index, 2), $chassis);
		$remotePort = nms_nd_value($values, $remote . ".7." . $index, 4);
		$remoteKey = nms_nd_lldp_identity(nms_nd_value($values, $remote . ".6." . $index, 2), $remotePort);
		$key = hash("sha256", $ports[$number]["key"] . "|" . $peer . "|" . $remoteKey);
		$neighbors[$key] = [
			"key" => $key,
			"local_key" => $ports[$number]["key"],
			"local_port" => $ports[$number]["label"],
			"local_ifindex" => $ports[$number]["ifindex"],
			"peer_key" => $peer,
			"peer_label" => nms_nd_lldp_label(nms_nd_value($values, $remote . ".4." . $index, 2), $chassis, 4),
			"remote_key" => $remoteKey,
			"remote_port" => nms_nd_lldp_label(nms_nd_value($values, $remote . ".6." . $index, 2), $remotePort, 3),
			"remote_name" => nms_nd_octets(nms_nd_value($values, $remote . ".9." . $index, 4, false)),
		];
	}
	return [
		"identity" => $identity,
		"name" => nms_nd_octets(nms_nd_value($values, $local . ".3.0", 4, false)),
		"ports" => array_values($ports),
		"interfaces" => $interfaces,
		"neighbors" => $neighbors,
	];
}
/** Parse Cisco CDP using its advertised global Device-ID and exact interface name identifiers. */
function nms_nd_parse_cdp($values, $interfaces)
{
	$root = "1.3.6.1.4.1.9.9.23.1";
	$cache = $root . ".2.1.1";
	if ((int) nms_nd_value($values, $root . ".3.1.0", 2) !== 1) {
		throw new RuntimeException("CDP is disabled on this device.");
	}
	$id = nms_nd_value($values, $root . ".3.4.0", 4);
	if ($id === "" || strlen($id) > 255) {
		throw new RuntimeException("CDP global Device-ID is invalid.");
	}
	$names = nms_nd_column($values, $root . ".1.1.1", 6, 1, 4);
	$ports = [];
	$neighbors = [];
	foreach ($interfaces as $index => $port) {
		$ids = [];
		if ($port["name_hex"] !== "") {
			$ids[$port["name_hex"]] = $port["name"];
		}
		if (isset($names[$index]) && $names[$index] !== "") {
			$ids[bin2hex($names[$index])] = nms_nd_octets($names[$index]);
		}
		foreach ($ids as $hex => $label) {
			$ports[] = ["key" => $hex, "label" => $label, "ifindex" => (int) $index];
		}
	}
	foreach (nms_nd_table_indices($values, $cache, 2) as $index) {
		$local = (int) explode(".", $index)[0];
		$peer = nms_nd_value($values, $cache . ".6." . $index, 4);
		$port = nms_nd_value($values, $cache . ".7." . $index, 4);
		if ($peer === "" || $port === "" || strlen($peer) > 255 || strlen($port) > 255) {
			throw new RuntimeException("CDP neighbor is missing a valid Device-ID or Port-ID.");
		}
		$key = hash("sha256", $local . "|" . bin2hex($peer) . "|" . bin2hex($port));
		$neighbors[$key] = [
			"key" => $key,
			"local_key" => (string) $local,
			"local_port" => "ifIndex " . $local,
			"local_ifindex" => isset($interfaces[$local]) ? $local : 0,
			"peer_key" => bin2hex($peer),
			"peer_label" => nms_nd_octets($peer),
			"remote_key" => bin2hex($port),
			"remote_port" => nms_nd_octets($port),
			"remote_name" => nms_nd_octets(nms_nd_value($values, $cache . ".17." . $index, 4, false)),
		];
	}
	return [
		"identity" => bin2hex($id),
		"name" => nms_nd_octets($id),
		"ports" => $ports,
		"interfaces" => $interfaces,
		"neighbors" => $neighbors,
	];
}
/** Retain missing observations for seven days, explicitly marking that they were not returned. */
function nms_nd_observation_history($previous, $current, $now)
{
	$merged = [];
	foreach ($previous as $key => $old) {
		if (($old["last_seen"] ?? 0) >= $now - 604800) {
			$old["present"] = false;
			$merged[$key] = $old;
		}
	}
	foreach ($current as $key => $row) {
		$row["first_seen"] = $previous[$key]["first_seen"] ?? $now;
		$row["last_seen"] = $now;
		$row["present"] = true;
		$merged[$key] = $row;
	}
	return $merged;
}
/** Resolve evidence only within the visible, configured node; preserve parallel ports and ambiguity. */
function nms_nd_evidence_fresh($timestamp, $now, $stale)
{
	return $timestamp > 0 && $now >= $timestamp && $now - $timestamp <= $stale;
}
/** Reconcile direction and protocol reports by exact native endpoint pairs. */
function nms_nd_reconcile($snapshots, $now, $stale)
{
	$identities = [];
	$links = [];
	$unresolved = [];
	foreach ($snapshots as $key => $s) {
		if (!empty($s["data"]["identity"])) {
			$identities[$s["protocol"] . "|" . $s["data"]["identity"]][] = $key;
		}
	}
	foreach ($snapshots as $sourceKey => $s) {
		foreach ($s["data"]["neighbors"] ?? [] as $observation) {
			if ($now - $observation["last_seen"] > 604800) {
				continue;
			}
			$e = $observation;
			$e["host_id"] = (int) $s["host_id"];
			$e["protocol"] = $s["protocol"];
			$e["current"] =
				$s["valid"] &&
				$s["status"] === "success" &&
				$observation["present"] &&
				nms_nd_evidence_fresh($observation["last_seen"], $now, $stale);
			$e["state"] = !$s["valid"]
				? "Configuration changed"
				: ($s["status"] !== "success"
					? "Collection " . $s["status"]
					: (!$observation["present"]
						? "Missing from latest table"
						: ($e["current"]
							? "Current"
							: "Stale")));
			$matches = $identities[$s["protocol"] . "|" . $observation["peer_key"]] ?? [];
			$reason = "";
			$target = null;
			$remoteIf = 0;
			if (count($matches) !== 1) {
				$reason = count($matches)
					? "Ambiguous advertised device identity"
					: "No unique configured device with this advertised identity";
			} else {
				$target = $snapshots[$matches[0]];
				$ports = [];
				foreach ($target["data"]["ports"] as $p) {
					if ($p["key"] === $observation["remote_key"] && $p["ifindex"]) {
						$ports[$p["ifindex"]] = true;
					}
				}
				if (count($ports) !== 1) {
					$reason = "Remote port does not map uniquely to IF-MIB";
				} else {
					$remoteIf = (int) array_key_first($ports);
				}
				if ((int) $target["host_id"] === (int) $s["host_id"]) {
					$reason = "Self-reported device identity requires review";
				}
			}
			if (!$observation["local_ifindex"]) {
				$reason = "Local port does not map uniquely to IF-MIB";
			}
			if ($reason) {
				$e["reason"] = $reason;
				$unresolved[] = $e;
				continue;
			}
			$a = [(int) $s["host_id"], (int) $observation["local_ifindex"]];
			$b = [(int) $target["host_id"], $remoteIf];
			if ($a > $b) {
				$swap = $a;
				$a = $b;
				$b = $swap;
			}
			$key = implode(":", $a) . "|" . implode(":", $b);
			if (!isset($links[$key])) {
				$links[$key] = [
					"a" => $a,
					"b" => $b,
					"evidence" => [],
					"directions" => [],
					"protocols" => [],
					"last_seen" => 0,
				];
			}
			// Stale identities may locate historical evidence, but cannot confirm a current connection.
			$targetFresh =
				$target["valid"] &&
				$target["status"] === "success" &&
				nms_nd_evidence_fresh($target["data"]["collected"] ?? 0, $now, $stale);
			if (!$targetFresh && $e["current"]) {
				$e["current"] = false;
				$e["state"] = "Peer identity is stale or collection failed";
			}
			$links[$key]["evidence"][] = $e;
			if ($e["current"]) {
				$links[$key]["directions"][$s["host_id"]] = true;
			}
			$links[$key]["protocols"][$s["protocol"]] = true;
			$links[$key]["last_seen"] = max($links[$key]["last_seen"], $e["last_seen"]);
		}
	}
	foreach ($links as &$link) {
		$n = count($link["directions"]);
		$link["state"] = $n === 2 ? "Reciprocal" : ($n === 1 ? "One-sided" : "Historical / stale");
		$link["current"] = $n > 0;
	}
	unset($link);
	ksort($links);
	return ["links" => array_values($links), "unresolved" => $unresolved];
}
