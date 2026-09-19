<?php
require_once __DIR__ . "/discovery.php";
require_once __DIR__ . "/../relationships.php";
require_once __DIR__ . "/appearance.php";
require_once __DIR__ . "/../device_metadata.php";
require_once __DIR__ . "/../discovery_endpoints.php";
require_once __DIR__ . "/../discovery_display.php";
/** Public canvas payload contains no core credentials or raw snapshots. */
function nms_canvas_data($site_id)
{
	$site_id = nms_single_topology_site() ?: null;
	if ($site_id && !(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM sites WHERE id=?", [$site_id])) {
		throw new RuntimeException(
			"The configured topology site no longer exists. Restore the Cacti site or update the topology site setting.",
		);
	}
	$d = nms_topology_discovery($site_id);
	$nodes = [];
	$links = [];
	$visible = nms_visible_host_sql();
	$site_filter = $site_id ? " AND h.site_id=" . (int) $site_id : "";
	$rows = db_fetch_assoc(
		"SELECT h.id,h.description,h.hostname,h.status,c.category_id,c.device_type,c.device_role,t.name AS template_name,l.pos_x,l.pos_y,dp.name AS diagnostic_profile,dp.tools AS diagnostic_tools FROM host h LEFT JOIN plugin_nms_device_classification c ON c.host_id=h.id LEFT JOIN host_template t ON t.id=h.host_template_id LEFT JOIN plugin_nms_topology l ON l.host_id=h.id LEFT JOIN plugin_nms_diagnostic_devices dd ON dd.host_id=h.id LEFT JOIN plugin_nms_diagnostic_profiles dp ON dp.id=dd.profile_id WHERE h.deleted='' AND h.disabled='' AND $visible $site_filter ORDER BY h.description",
	);
	$columns = max(1, (int) ceil(sqrt(count($rows))));
	$row_count = max(1, (int) ceil(count($rows) / $columns));
	$ids = [];
	foreach ($rows as $i => $h) {
		$ids[(int) $h["id"]] = true;
		$nodes[] = [
			"id" => (int) $h["id"],
			"name" => $h["description"],
			"address" => $h["hostname"],
			"status" => (int) $h["status"],
			"category_id" => (int) $h["category_id"],
			"device_role" => (string) $h["device_role"],
			"device_type" => (string) $h["device_type"],
			"template" => (string) $h["template_name"],
			"diagnostic_profile" => (string) ($h["diagnostic_profile"] ?? ""),
			"diagnostic_tools" => (string) ($h["diagnostic_tools"] ?? ""),
			"x" => $h["pos_x"] === null ? 10 + ($i % $columns) * (80 / max(1, $columns - 1)) : (float) $h["pos_x"],
			"y" =>
				$h["pos_y"] === null ? 10 + intdiv($i, $columns) * (80 / max(1, $row_count - 1)) : (float) $h["pos_y"],
		];
	}
	$appearance = nms_appearance_read();
	$identity_warnings = nms_nd_identity_warnings($d["snapshots"]);
	foreach ($nodes as &$node) {
		$appearance_type = (string) ($node["device_type"] ?: $node["template"]);
		$appearance_profile = null;
		foreach ($appearance["types"] as $profile) {
			if (
				$profile["category_id"] === $node["category_id"] &&
				strcasecmp($profile["name"], $appearance_type) === 0
			) {
				$appearance_profile = $profile;
				break;
			}
		}
		if (!$appearance_profile) {
			foreach ($appearance["types"] as $profile) {
				if (strcasecmp($profile["name"], $appearance_type) === 0) {
					$appearance_profile = $profile;
					break;
				}
			}
		}
		if (!$appearance_profile) {
			$appearance_profile = nms_appearance_default($node["category_id"], $node["device_type"], $node["template"]);
		}
		if ($appearance_profile) {
			$node["icon"] = $appearance_profile["icon"];
			$node["color"] = $appearance_profile["color"];
		}
		$node["icon_path"] = nms_appearance_icons()[$node["icon"] ?? "device"][1] ?? "";
		$node["short_name"] = nms_short_name_get($node["id"]) ?: nms_short_name_auto($node["name"], $node["id"]);
		$node["ports"] = [];
		$node["interfaces"] = [];
		foreach ($d["snapshots"] as $snapshot) {
			if (
				(int) $snapshot["host_id"] !== $node["id"] ||
				!$snapshot["valid"] ||
				$snapshot["status"] !== "success"
			) {
				continue;
			}
			foreach ($snapshot["data"]["interfaces"] ?? [] as $interface) {
				$node["interfaces"][] = [
					"index" => (int) $interface["index"],
					"name" => (string) $interface["name"],
					"speed_bps" => (int) ($interface["speed_bps"] ?? 0),
					"high_speed_mbps" => (int) ($interface["high_speed_mbps"] ?? 0),
					"admin" => (int) ($interface["admin"] ?? 0),
					"oper" => (int) ($interface["oper"] ?? 0),
				];
			}
			foreach ($snapshot["data"]["hardware"]["physical_ports"] ?? [] as $port) {
				$node["ports"][] = [
					"name" => (string) $port["name"],
					"position" => $port["position"],
					"entity_index" => (int) $port["entity_index"],
				];
			}
			if ($node["ports"] || $node["interfaces"]) {
				break;
			}
		}
		$manual_port_count = (int) db_fetch_cell_prepared(
			"SELECT port_count FROM plugin_nms_device_metadata WHERE host_id=?",
			[$node["id"]],
		);
		$observed_port_count = count($node["ports"]);
		$node["physical_port_count"] = max($manual_port_count, $observed_port_count);
		$node["physical_port_source"] =
			$manual_port_count > $observed_port_count
				? "Configured in Device management"
				: ($observed_port_count
					? "SNMP discovery"
					: "Not reported");
		$identity = nms_identity_observation($node["id"]);
		$node["identity"] = [
			"chassis_id" => (string) ($identity["chassis_id"] ?? ""),
			"serial" => (string) ($identity["serial"] ?? ""),
			"mac" => (string) ($identity["mac"] ?? ""),
		];
		$node["discovery_warnings"] = $identity_warnings[$node["id"]] ?? [];
	}
	unset($node);
	foreach ($d["links"] as $i => $l) {
		if (isset($ids[$l["a"][0]], $ids[$l["b"][0]])) {
			$ap = "ifIndex " . $l["a"][1];
			$bp = "ifIndex " . $l["b"][1];
			foreach ($l["evidence"] as $e) {
				if ((int) $e["host_id"] === $l["a"][0]) {
					$ap = $e["local_port"];
					$bp = $e["remote_port"];
					break;
				} else {
					$ap = $e["remote_port"];
					$bp = $e["local_port"];
				}
			}
			$an = $nodes[array_search($l["a"][0], array_column($nodes, "id"), true)] ?? [];
			$bn = $nodes[array_search($l["b"][0], array_column($nodes, "id"), true)] ?? [];
			$as = 0;
			$bs = 0;
			foreach ($an["interfaces"] ?? [] as $if) {
				if ((int) $if["index"] === (int) $l["a"][1]) {
					$as = max((int) ($if["high_speed_mbps"] ?? 0) * 1000000, (int) ($if["speed_bps"] ?? 0));
				}
			}
			foreach ($bn["interfaces"] ?? [] as $if) {
				if ((int) $if["index"] === (int) $l["b"][1]) {
					$bs = max((int) ($if["high_speed_mbps"] ?? 0) * 1000000, (int) ($if["speed_bps"] ?? 0));
				}
			}
			$speed = $as && $bs ? min($as, $bs) : max($as, $bs);
			$links[] = [
				"id" => "d" . $i,
				"a" => $l["a"][0],
				"b" => $l["b"][0],
				"a_port" => $ap,
				"b_port" => $bp,
				"a_ifindex" => $l["a"][1],
				"b_ifindex" => $l["b"][1],
				"speed" => $speed,
				"label" => strtoupper(implode("/", array_keys($l["protocols"]))) . " · " . $ap . " ↔ " . $bp,
				"state" => $l["state"],
				"current" => $l["current"],
				"manual" => false,
			];
		}
	}
	foreach ($links as &$link) {
		$protocol = strtok((string) ($link["label"] ?? ""), " · ");
		$link["protocols"] = $protocol ? [$protocol => true] : [];
	}
	unset($link);
	$endpoint = nms_nd_correlate_endpoints($d["snapshots"], $rows);
	foreach ($endpoint["links"] as $link) {
		if (isset($ids[$link["a"]], $ids[$link["b"]])) {
			$links[] = $link;
		}
	}
	// Add a factual per-interface state for the map. A link is marked connected only
	// when current LLDP/CDP evidence identifies both device ports.
	$node_names = [];
	foreach ($nodes as $node) {
		$node_names[(int) $node["id"]] = (string) $node["name"];
	}
	foreach ($nodes as &$node) {
		foreach ($node["interfaces"] as &$interface) {
			$connection = null;
			foreach ($links as $link) {
				if (empty($link["current"])) {
					continue;
				}
				if ((int) $link["a"] === (int) $node["id"] && (int) ($link["a_ifindex"] ?? 0) === (int) $interface["index"]) {
					$connection = ["peer" => (int) $link["b"], "port" => (string) $link["b_port"]];
					break;
				}
				if ((int) $link["b"] === (int) $node["id"] && (int) ($link["b_ifindex"] ?? 0) === (int) $interface["index"]) {
					$connection = ["peer" => (int) $link["a"], "port" => (string) $link["a_port"]];
					break;
				}
			}
			if ($connection) {
				$interface["availability"] = "In use — connected to " . ($node_names[$connection["peer"]] ?? "device") . " / " . $connection["port"];
				$interface["peer_name"] = $node_names[$connection["peer"]] ?? "Device";
				$interface["peer_port"] = $connection["port"];
			} elseif ((int) $interface["oper"] === 6) {
				$interface["availability"] = "Unavailable — interface not present";
			} elseif ((int) $interface["admin"] === 2) {
				$interface["availability"] = "Disabled by device configuration";
			} elseif ((int) $interface["oper"] === 2) {
				$interface["availability"] = "Link down — no active physical connection";
			} elseif ((int) $interface["oper"] === 1) {
				$interface["availability"] = "Link up — neighbour not advertised";
			} else {
				$interface["availability"] = "Status not reported by IF-MIB";
			}
		}
		unset($interface);
	}
	unset($node);

	$observations = [];
	foreach (array_merge($d["unresolved"] ?? [], $endpoint["observations"]) as $o) {
		if (isset($ids[(int) $o["host_id"]])) {
			// Whitelist display fields; raw snapshots and SNMP settings never enter the canvas response.
			$observations[] = [
				"host_id" => (int) $o["host_id"],
				"protocol" => $o["protocol"],
				"current" => !empty($o["current"]),
				"text" => nms_nd_observation_text($o),
				"last_seen" => (int) ($o["last_seen"] ?? 0),
			];
		}
	}
	$core_id = nms_canvas_core($nodes, $links);
	return [
		"site_name" => $site_id
			? (string) db_fetch_cell_prepared("SELECT name FROM sites WHERE id=?", [$site_id])
			: "All sites",
		"core_id" => $core_id,
		"observations" => $observations,
		"nodes" => $nodes,
		"links" => $links,
		"refresh" => $d["refresh"],
		"ready" => $d["ready"],
		"message" => $d["message"],
	];
}

/** Prefer the configured core; otherwise the switch with most discovered neighbours. */
function nms_canvas_core($nodes, $links)
{
	$best = 0;
	$score = -1;
	foreach ($nodes as $node) {
		if (!preg_match("/switch/i", $node["device_type"] . " " . $node["template"])) {
			continue;
		}
		$degree = 0;
		foreach ($links as $link) {
			if ($link["a"] === $node["id"] || $link["b"] === $node["id"]) {
				$degree++;
			}
		}
		$rank = $degree + (strcasecmp(trim($node["device_role"]), "core") === 0 ? 100000 : 0);
		if ($rank > $score) {
			$score = $rank;
			$best = $node["id"];
		}
	}
	return $best;
}

/** Validate site/ACL on every canvas write; preserve unrelated layout fields and discovered evidence. */
function nms_canvas_write($action, $site_id, $input)
{
	nms_require_management();
	if ($action !== "canvas_position") {
		throw new InvalidArgumentException("Connections come from LLDP/CDP discovery.");
	}
	$host = nms_topology_integer($input["host_id"] ?? 0, 1, 2147483647, "Device");
	nms_require_device_access($host);
	$site_id = db_fetch_cell_prepared("SELECT site_id FROM host WHERE id=? AND deleted='' AND disabled=''", [$host]);
	if ($site_id === false || $site_id === null) {
		throw new InvalidArgumentException("Device is unavailable.");
	}
	if (nms_single_topology_site() && (int) $site_id !== nms_single_topology_site()) {
		throw new InvalidArgumentException("Device is outside the configured topology site.");
	}
	if (!nms_topology_device_belongs_to_site($host, $site_id)) {
		throw new InvalidArgumentException("Device is not enabled in this site.");
	}
	if ($action === "canvas_position") {
		$canvas = nms_canvas_data(null);
		if ($host === $canvas["core_id"]) {
			throw new InvalidArgumentException("The core switch is fixed at the centre of the topology.");
		}
		foreach (["x", "y"] as $axis) {
			if (
				!isset($input[$axis]) ||
				!is_scalar($input[$axis]) ||
				!is_numeric($input[$axis]) ||
				!is_finite((float) $input[$axis]) ||
				$input[$axis] < 5 ||
				$input[$axis] > 95
			) {
				throw new InvalidArgumentException("Position must be between 5 and 95.");
			}
		}
		nms_category_execute(
			"INSERT INTO plugin_nms_topology(host_id,site_id,parent_host_id,parent_snmp_index,pos_x,pos_y,locked,updated_by,updated_at) VALUES (?,?,0,'',?,?,'',?,NOW()) ON DUPLICATE KEY UPDATE site_id=VALUES(site_id),pos_x=VALUES(pos_x),pos_y=VALUES(pos_y),updated_by=VALUES(updated_by),updated_at=NOW()",
			[$host, $site_id, $input["x"], $input["y"], nms_current_user_id()],
		);
	} else {
		throw new InvalidArgumentException("Unknown canvas operation.");
	}
}
