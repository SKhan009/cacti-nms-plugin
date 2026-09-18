<?php
/** Typed standard-MIB endpoint observations. These do not prove a direct cable. */
require_once __DIR__ . "/discovery_neighbors.php";

function nms_nd_mac($bytes)
{
	if (strlen($bytes) !== 6) {
		throw new RuntimeException("Expected a six-octet Ethernet address.");
	}
	return implode(":", str_split(bin2hex($bytes), 2));
}

/** Canonical address keys; link-local zones belong to the reporting host, not the collector. */
function nms_nd_ip_key($ip)
{
	$bytes = @inet_pton($ip);
	return $bytes === false ? "" : inet_ntop($bytes);
}

/** Never match a link-local IPv6 address across hosts without a proven common scope. */
function nms_nd_ip_correlatable($ip)
{
	$bytes = @inet_pton($ip);
	return $bytes !== false && !(strlen($bytes) === 16 && ord($bytes[0]) === 254 && (ord($bytes[1]) & 192) === 128);
}

/** Decode RFC 4293 variable-length InetAddress indices, including explicitly zoned addresses. */
function nms_nd_physical_index($index)
{
	if (!preg_match('/^[0-9]+(?:\.[0-9]+)+$/D', $index)) {
		throw new RuntimeException("Invalid IP neighbour index.");
	}
	$arcs = explode(".", $index);
	$ifindex = (int) array_shift($arcs);
	$type = (int) array_shift($arcs);
	$length = (int) array_shift($arcs);
	if ($ifindex < 1 || $ifindex > 2147483647 || count($arcs) !== $length) {
		throw new RuntimeException("Invalid IP neighbour index length.");
	}
	$bytes = "";
	foreach ($arcs as $arc) {
		if ((float) $arc > 255) {
			throw new RuntimeException("Invalid IP neighbour address octet.");
		}
		$bytes .= chr((int) $arc);
	}
	$sizes = [1 => 4, 2 => 16, 3 => 8, 4 => 20];
	if (!isset($sizes[$type])) {
		return null;
	} // Unknown address families are not IP endpoints.
	if ($length !== $sizes[$type]) {
		throw new RuntimeException("IP neighbour address type and length differ.");
	}
	$zoned = $type === 3 || $type === 4;
	$zone = $zoned ? unpack("Nzone", substr($bytes, -4))["zone"] : 0;
	$ip = inet_ntop($zoned ? substr($bytes, 0, -4) : $bytes);
	return [
		"ifindex" => $ifindex,
		"ip" => $ip,
		"address_family" => $type === 1 || $type === 3 ? 4 : 6,
		"scope_id" => $zone,
	];
}

/** Merge legacy ARP and IP-MIB IPv4/IPv6 mappings; modern rows supersede legacy duplicates. */
function nms_nd_parse_arp($values, $interfaces)
{
	$root = "1.3.6.1.2.1.4.22.1";
	$rows = [];
	foreach (nms_nd_table_indices($values, $root, 5) as $index) {
		$parts = explode(".", $index);
		$ifindex = (int) array_shift($parts);
		$ip = implode(".", $parts);
		if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $ifindex < 1) {
			throw new RuntimeException("Invalid ARP table index.");
		}
		if (
			(int) nms_nd_value($values, $root . ".1." . $index, 2) !== $ifindex ||
			nms_nd_value($values, $root . ".3." . $index, 64) !== $ip
		) {
			throw new RuntimeException("ARP row identity differs from its index.");
		}
		$type = (int) nms_nd_value($values, $root . ".4." . $index, 2);
		if (!in_array($type, [1, 2, 3, 4], true)) {
			throw new RuntimeException("Invalid ARP entry type.");
		}
		$bytes = nms_nd_value($values, $root . ".2." . $index, 4);
		if ($type === 2) {
			continue;
		} // Invalidated rows are not current mappings.
		$mac = strlen($bytes) === 6 ? nms_nd_mac($bytes) : "";
		$rows[$ifindex . "|" . $ip . "|0"] = [
			"ip" => $ip,
			"mac" => $mac,
			"ifindex" => $ifindex,
			"interface" => $interfaces[$ifindex]["name"] ?? "",
			"state" => $mac !== "" ? "observed" : "unresolved",
			"type" => $type,
			"address_family" => 4,
			"scope_id" => 0,
			"source" => "ipNetToMediaTable",
		];
	}
	$root = "1.3.6.1.2.1.4.35.1";
	$indices = [];
	foreach ($values as $oid => $value) {
		if (strpos($oid, $root . ".") === 0) {
			$suffix = substr($oid, strlen($root) + 1);
			$dot = strpos($suffix, ".");
			if ($dot === false || !ctype_digit(substr($suffix, 0, $dot))) {
				throw new RuntimeException("Malformed IP neighbour column.");
			}
			$indices[substr($suffix, $dot + 1)] = true;
		}
	}
	$states = [
		1 => "Reachable",
		2 => "Stale neighbour cache",
		3 => "Delay",
		4 => "Probe",
		5 => "Invalid",
		6 => "Unknown",
		7 => "Incomplete",
	];
	foreach (array_keys($indices) as $index) {
		$row = nms_nd_physical_index($index);
		if ($row === null) {
			continue;
		}
		$key = $row["ifindex"] . "|" . $row["ip"] . "|" . $row["scope_id"];
		$bytes = nms_nd_value($values, $root . ".4." . $index, 4);
		$type = (int) nms_nd_value($values, $root . ".6." . $index, 2);
		$state = (int) nms_nd_value($values, $root . ".7." . $index, 2);
		$status = (int) nms_nd_value($values, $root . ".8." . $index, 2);
		if (!in_array($type, [1, 2, 3, 4, 5], true) || !isset($states[$state]) || $status < 1 || $status > 6) {
			throw new RuntimeException("Invalid IP neighbour type, state or row status.");
		}
		unset($rows[$key]);
		if ($type === 2 || $status !== 1) {
			continue;
		}
		$mac = strlen($bytes) === 6 && !in_array($state, [5, 7], true) ? nms_nd_mac($bytes) : "";
		$rows[$key] = $row + [
			"mac" => $mac,
			"interface" => $interfaces[$row["ifindex"]]["name"] ?? "",
			"state" => $mac !== "" ? "observed" : "unresolved",
			"type" => $type,
			"neighbor_state" => $states[$state],
			"source" => "ipNetToPhysicalTable",
		];
	}
	return [
		"interfaces" => $interfaces,
		"neighbors" => [],
		"endpoints" => array_values($rows),
		"scope" =>
			"IPv4 ARP and IPv4/IPv6 IP-MIB neighbour tables in the configured SNMP context. Empty tables may be unsupported, excluded by the SNMP view, or have no entries. Link-local addresses are scoped to the reporting interface.",
	];
}

/** Read both bridge MIB tables independently, retaining FDB ID separately from VLAN ID. */
function nms_nd_parse_fdb($values, $interfaces)
{
	$base = "1.3.6.1.2.1.17";
	$rows = [];
	$portMap = [];
	$vlans = [];
	foreach (nms_nd_column($values, $base . ".1.4.1", 2, 1, 2) as $port => $index) {
		if ((int) $port < 1 || (int) $index < 0) {
			throw new RuntimeException("Invalid bridge-port mapping.");
		}
		$portMap[$port] = (int) $index;
	}
	// dot1qVlanFdbId is indexed by TimeMark and VLAN; one FDB can serve several VLANs.
	foreach (nms_nd_column($values, $base . ".7.1.4.2.1", 3, 2, 66) as $index => $fdb) {
		$parts = explode(".", $index);
		$vlan = (int) $parts[1];
		if ($vlan < 1 || $vlan > 4094) {
			continue;
		}
		$vlans[(string) $fdb][$vlan] = $vlan;
	}
	foreach ([[$base . ".4.3.1", 6, "BRIDGE-MIB"], [$base . ".7.1.2.2.1", 7, "Q-BRIDGE-MIB"]] as $spec) {
		[$root, $parts, $source] = $spec;
		foreach (nms_nd_table_indices($values, $root, $parts) as $index) {
			$arcs = explode(".", $index);
			$fdb = $parts === 7 ? array_shift($arcs) : null;
			$bytes = "";
			foreach ($arcs as $arc) {
				if ((int) $arc > 255) {
					throw new RuntimeException("Invalid FDB MAC index.");
				}
				$bytes .= chr((int) $arc);
			}
			// Q-BRIDGE's address is a not-accessible index; derive it from the OID.
			$address = nms_nd_value($values, $root . ".1." . $index, 4, $parts === 6);
			if ($address !== null && $address !== $bytes) {
				throw new RuntimeException("FDB address differs from its index.");
			}
			$port = (int) nms_nd_value($values, $root . ".2." . $index, 2);
			$status = (int) nms_nd_value($values, $root . ".3." . $index, 2);
			if ($port < 0 || !in_array($status, [1, 2, 3, 4, 5], true)) {
				throw new RuntimeException("Invalid FDB port or status.");
			}
			if ($status === 2) {
				continue;
			}
			$ifindex = $portMap[$port] ?? 0;
			$rows[] = [
				"mac" => nms_nd_mac($bytes),
				"bridge_port" => $port,
				"ifindex" => $ifindex,
				"interface" => $interfaces[$ifindex]["name"] ?? "",
				"fdb_id" => $fdb === null ? null : (int) $fdb,
				"vlans" => array_values($vlans[(string) $fdb] ?? []),
				"source" => $source,
				"status" => $status,
				"state" => $status === 3 && $ifindex > 0 ? "inferred" : "unresolved",
			];
		}
	}
	$counts = [];
	foreach ($rows as $row) {
		if ($row["ifindex"] > 0) {
			$counts[$row["ifindex"]][$row["mac"]] = true;
		}
	}
	foreach ($rows as &$row) {
		$row["macs_on_interface"] = count($counts[$row["ifindex"]] ?? []);
		$row["direct_connection"] = false;
	}
	unset($row);
	return [
		"interfaces" => $interfaces,
		"neighbors" => [],
		"endpoints" => $rows,
		"scope" =>
			"Configured SNMP context only. Learned ports may be uplinks or aggregate interfaces; physical members are not inferred. Empty tables do not prove support.",
	];
}

/** Correlate only permitted core addresses/observed interface MACs. Never resolve DNS or invent inventory. */
function nms_nd_correlate_endpoints($snapshots, $hosts)
{
	$macHosts = [];
	$ipHosts = [];
	$observations = [];
	$links = [];
	foreach ($hosts as $h) {
		$ip = nms_nd_ip_key($h["hostname"]);
		if ($ip !== "" && nms_nd_ip_correlatable($ip)) {
			$ipHosts[$ip][] = (int) $h["id"];
		}
	}
	foreach ($snapshots as $s) {
		if (empty($s["valid"]) || $s["status"] !== "success") {
			continue;
		}
		foreach ($s["data"]["interfaces"] ?? [] as $i) {
			$hex = $i["mac_hex"] ?? "";
			if (strlen($hex) === 12 && ctype_xdigit($hex) && $hex !== "000000000000") {
				$macHosts[nms_nd_mac(hex2bin($hex))][(int) $s["host_id"]][(int) $i["index"]] = true;
			}
		}
		if ($s["protocol"] === "arp") {
			foreach ($s["data"]["endpoints"] ?? [] as $e) {
				$ip = nms_nd_ip_key($e["ip"]);
				if (
					$e["mac"] === "" ||
					($e["type"] ?? 0) === 5 ||
					!empty($e["scope_id"]) ||
					count($ipHosts[$ip] ?? []) !== 1
				) {
					continue;
				}
				$id = $ipHosts[$ip][0];
				if (!isset($macHosts[$e["mac"]][$id])) {
					$macHosts[$e["mac"]][$id] = [];
				}
			}
		}
	}
	foreach ($snapshots as $s) {
		if ($s["protocol"] === "fdb") {
			foreach ($s["data"]["endpoints"] ?? [] as $e) {
				$current = !empty($s["valid"]) && $s["status"] === "success";
				$matches = $macHosts[$e["mac"]] ?? [];
				$reason =
					count($matches) === 0
						? "No unique permitted core device matches this MAC"
						: (count($matches) > 1
							? "MAC matches multiple core devices"
							: "");
				$id = count($matches) === 1 ? (int) array_key_first($matches) : 0;
				if ($id === (int) $s["host_id"]) {
					$reason = "Bridge or local-interface MAC";
				}
				if ($e["state"] !== "inferred") {
					$reason = "FDB entry is not a learned, mapped interface";
				}
				$ports = $id ? array_keys($matches[$id]) : [];
				$remote = count($ports) === 1 ? (int) $ports[0] : 0;
				$o = $e;
				$o["protocol"] = "fdb";
				$o["host_id"] = (int) $s["host_id"];
				$o["peer_id"] = $reason ? 0 : $id;
				$o["current"] = $current;
				$o["reason"] = $current
					? ($reason ?:
					"Inferred location; may be behind an uplink or aggregate")
					: "Historical / stale collection";
				$observations[] = $o;
				if ($reason || !$current) {
					continue;
				}
				$key = $s["host_id"] . ":" . $e["ifindex"] . ":" . $id . ":" . ($e["fdb_id"] ?? "bridge");
				$links[$key] = [
					"id" => "f" . hash("sha256", $key),
					"a" => (int) $s["host_id"],
					"b" => $id,
					"a_port" => $e["interface"] ?: "ifIndex " . $e["ifindex"],
					"b_port" => $remote ? "ifIndex " . $remote : "Unknown port",
					"a_ifindex" => $e["ifindex"],
					"b_ifindex" => $remote,
					"state" => "Inferred endpoint location",
					"current" => true,
					"manual" => false,
					"inferred" => true,
					"label" =>
						"FDB · " .
						($e["interface"] ?: "ifIndex " . $e["ifindex"]) .
						" → " .
						($remote ? "ifIndex " . $remote : "Unknown port") .
						($e["vlans"] ? " · VLAN " . implode("/", $e["vlans"]) : ""),
				];
			}
		}
	}
	// ARP-only observations remain inspectable even when no FDB can locate an endpoint.
	// An ARP interface is the reporting router's interface, not a remote switch port.
	foreach ($snapshots as $s) {
		if ($s["protocol"] === "arp") {
			foreach ($s["data"]["endpoints"] ?? [] as $e) {
				$current = !empty($s["valid"]) && $s["status"] === "success";
				$matches = empty($e["scope_id"]) ? $ipHosts[nms_nd_ip_key($e["ip"])] ?? [] : [];
				$reason =
					$e["mac"] === ""
						? "No resolved MAC address"
						: (count($matches) > 1
							? "IP matches multiple permitted core devices"
							: (count($matches) === 0
								? "No permitted core device matches this IP"
								: "Observed IP-to-MAC mapping; physical attachment is unresolved"));
				if (!nms_nd_ip_correlatable($e["ip"]) || !empty($e["scope_id"])) {
					$reason =
						"Scoped neighbour address; reporting interface retained, cross-device IP matching unavailable";
				}
				if (($e["type"] ?? 0) === 5) {
					$matches = [];
					$reason = "Address belongs to the reporting device";
				}
				$o = $e;
				$o["protocol"] = "arp";
				$o["host_id"] = (int) $s["host_id"];
				$o["current"] = $current;
				$o["peer_id"] = $current && $e["mac"] !== "" && count($matches) === 1 ? (int) $matches[0] : 0;
				$o["reason"] = $current ? $reason : "Historical / stale ARP observation";
				$observations[] = $o;
			}
		}
	}
	return ["links" => array_values($links), "observations" => $observations];
}
