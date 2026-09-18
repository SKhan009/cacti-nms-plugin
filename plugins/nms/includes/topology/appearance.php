<?php
require_once __DIR__ . "/config.php";
/** Appearance catalogue uses existing NMS metadata and segment storage. */
function nms_appearance_read()
{
	$raw = db_fetch_cell_prepared("SELECT meta_value FROM plugin_nms_meta WHERE meta_key=?", ["topology_appearance"]);
	$data = json_decode((string) $raw, true);
	if (is_array($data)) {
		return $data;
	}
	$data = ["types" => []];
	foreach (
		db_fetch_assoc(
			"SELECT DISTINCT category_id,device_type FROM plugin_nms_device_classification WHERE device_type<>''",
		)
		as $row
	) {
		$name = $row["device_type"];
		$icon = "device";
		foreach (["switch", "router", "server", "workstation", "sensor", "ups"] as $candidate) {
			if (stripos($name, $candidate) !== false) {
				$icon = $candidate;
				break;
			}
		}
		$data["types"][substr(sha1($row["category_id"] . "|" . $name), 0, 16)] = [
			"name" => $name,
			"category_id" => (int) $row["category_id"],
			"icon" => $icon,
			"color" => "#334155",
		];
	}
	return $data;
}
/**
 * Handles appearance save.
 */
function nms_appearance_save($input)
{
	nms_require_management();
	if (!is_array($input)) {
		throw new InvalidArgumentException("Invalid form.");
	}
	$lock = "nms_appearance_" . substr(sha1((string) db_fetch_cell("SELECT DATABASE()")), 0, 20);
	if ((int) db_fetch_cell_prepared("SELECT GET_LOCK(?,5)", [$lock]) !== 1) {
		throw new RuntimeException("Settings are busy; retry.");
	}
	try {
		$data = nms_appearance_read();
		$action = $input["nms_action"] ?? "";
		if ($action === "segment_save") {
			nms_category_save(
				nms_topology_integer($input["id"] ?? 0, 0, 2147483647, "Segment"),
				$input["name"] ?? "",
				$input["description"] ?? "",
			);
		} elseif ($action === "segment_delete") {
			$id = nms_topology_integer($input["id"] ?? 0, 1, 2147483647, "Segment");
			foreach ($data["types"] as $type) {
				if ($type["category_id"] === $id) {
					throw new InvalidArgumentException("Remove this segment’s type profiles first.");
				}
			}
			$tables = db_fetch_assoc(
				"SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='category_id' AND TABLE_NAME LIKE 'plugin_nms_%'",
			);
			foreach ($tables as $row) {
				$table = $row["TABLE_NAME"];
				if (!preg_match('/^plugin_nms_[a-z_]+$/D', $table)) {
					continue;
				}
				if ((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM `" . $table . "` WHERE category_id=?", [$id])) {
					throw new InvalidArgumentException(
						"This segment is in use. Reassign its devices, templates and rules before deleting.",
					);
				}
			}
			nms_category_execute("DELETE FROM plugin_nms_categories WHERE id=?", [$id]);
		} elseif ($action === "type_save") {
			$id = $input["id"] ?? "";
			if (!is_string($id) || ($id !== "" && !isset($data["types"][$id]))) {
				throw new InvalidArgumentException("Unknown type profile.");
			}
			$name = nms_classification_text($input["name"] ?? "", 150);
			$category = nms_topology_integer($input["category_id"] ?? 0, 0, 2147483647, "Segment");
			if ($name === "" || ($category && !nms_category_exists($category))) {
				throw new InvalidArgumentException("Enter a type and valid segment.");
			}
			$icon = $input["icon"] ?? "";
			$color = $input["color"] ?? "";
			if (
				!is_string($icon) ||
				!array_key_exists($icon, nms_appearance_icons()) ||
				!is_string($color) ||
				!preg_match('/^#[a-f0-9]{6}$/iD', $color)
			) {
				throw new InvalidArgumentException("Choose a built-in icon and six-digit colour.");
			}
			foreach ($data["types"] as $key => $type) {
				if ($key !== $id && $type["category_id"] === $category && strcasecmp($type["name"], $name) === 0) {
					throw new InvalidArgumentException("This segment already has that device type.");
				}
			}
			if ($id === "") {
				$id = bin2hex(random_bytes(8));
			}
			$old = $data["types"][$id] ?? null;
			if (
				$old &&
				($old["category_id"] !== $category || $old["name"] !== $name) &&
				(int) db_fetch_cell_prepared(
					"SELECT COUNT(*) FROM plugin_nms_device_classification WHERE category_id=? AND device_type=?",
					[$old["category_id"], $old["name"]],
				)
			) {
				throw new InvalidArgumentException(
					"This type is assigned. Edit its icon/colour, or reassign devices before renaming or moving it.",
				);
			}
			$data["types"][$id] = [
				"name" => $name,
				"category_id" => $category,
				"icon" => $icon,
				"color" => strtolower($color),
			];
		} elseif ($action === "type_delete") {
			$id = $input["id"] ?? "";
			if (!is_string($id) || !isset($data["types"][$id])) {
				throw new InvalidArgumentException("Unknown type profile.");
			}
			$type = $data["types"][$id];
			if (
				(int) db_fetch_cell_prepared(
					"SELECT COUNT(*) FROM plugin_nms_device_classification WHERE category_id=? AND device_type=?",
					[$type["category_id"], $type["name"]],
				)
			) {
				throw new InvalidArgumentException("Reassign devices using this type before deleting it.");
			}
			unset($data["types"][$id]);
		} else {
			throw new InvalidArgumentException("Unknown action.");
		}
		$json = json_encode($data, JSON_THROW_ON_ERROR);
		if (strlen($json) > 60000) {
			throw new InvalidArgumentException("The appearance catalogue is full.");
		}
		nms_category_execute("REPLACE INTO plugin_nms_meta (meta_key,meta_value,updated_at) VALUES (?,?,NOW())", [
			"topology_appearance",
			$json,
		]);
	} finally {
		db_fetch_cell_prepared("SELECT RELEASE_LOCK(?)", [$lock]);
	}
}

/** Local SVG paths shared by the picker and topology; no external icon service. */
function nms_appearance_icons()
{
	return [
		"switch" => ["Switch", "M2 7h28v14H2z M5 11h3v3H5z M11 11h3v3h-3z M17 11h3v3h-3z M23 11h3v3h-3z"],
		"router" => ["Router", "M3 10h26v16H3z M8 10V3 M24 10V3 M9 18h14 M9 18l4-3 M23 18l-4 3"],
		"server" => ["Server", "M7 2h18v28H7z M10 8h12 M10 14h12 M10 20h12 M20 25h2"],
		"workstation" => ["PC / desktop", "M2 3h28v20H2z M16 23v6 M9 29h14"],
		"laptop" => ["Laptop", "M6 3h20v20H6z M6 23l-4 6h28l-4-6"],
		"phone" => ["Phone", "M9 2h14v28H9z M13 5h6 M14 26h4"],
		"ipphone" => ["Desk phone", "M4 12h24v17H4z M11 14h13v7H11z M3 5h6v17H3z M13 25h2 M19 25h2"],
		"ups" => ["UPS / battery", "M8 4h16v26H8z M12 1h8v3 M18 9l-6 9h5l-2 6 7-10h-6z"],
		"sensor" => ["Sensor", "M16 3a13 13 0 1 0 0 26 13 13 0 0 0 0-26 M16 11a5 5 0 1 0 0 10 5 5 0 0 0 0-10"],
		"printer" => ["Printer", "M8 11V2h16v9 M8 23H3V11h26v12h-5 M8 19h16v11H8z M23 15h2"],
		"camera" => ["Camera", "M2 8h20v16H2z M22 13l8-5v16l-8-5"],
		"wireless" => ["Wireless AP", "M3 10q13-13 26 0 M7 15q9-9 18 0 M11 20q5-5 10 0 M16 25v3"],
		"firewall" => ["Firewall", "M2 5h28v23H2z M2 13h28 M2 21h28 M10 5v8 M22 5v8 M16 13v8 M10 21v7 M22 21v7"],
		"satellite" => ["Satellite / VSAT", "M5 5l22 22Q2 30 5 5 M16 16L28 4 M23 4h5v5 M15 25v5 M8 30h16"],
		"device" => ["Generic device", "M4 5h24v22H4z M8 10h16 M8 16h16 M22 23h2"],
	];
}

/**
 * Infer a stable display type from the Cacti device name/template when the
 * operator leaves Device type empty.  This is deliberately conservative and
 * only selects one of the built-in appearance profiles.
 */
function nms_device_type_auto($name, $template = "")
{
	$text = strtolower(trim((string) $name . " " . (string) $template));
	foreach (
		[
			"switch" => "Switch",
			"router" => "Router",
			"firewall" => "Firewall",
			"wireless|access point|wifi" => "Wireless AP",
			"ups|battery|power" => "UPS / battery",
			"sensor|probe|iot" => "Sensor",
			"satellite|vsat" => "Satellite / VSAT",
			"printer" => "Printer",
			"camera" => "Camera",
			"ip phone|desk phone|voip" => "Desk phone",
			"phone" => "Phone",
			"laptop|notebook" => "Laptop",
			"server|linux|windows" => "Server",
			"workstation|desktop|computer|pc" => "PC / desktop",
		]
		as $pattern => $label
	) {
		if (preg_match("/(?:" . $pattern . ")/i", $text)) {
			return $label;
		}
	}
	return "Generic device";
}

/** Return a built-in icon/colour fallback for every segment and inferred type. */
function nms_appearance_default($category_id, $device_type, $template = "")
{
	$type = trim((string) $device_type);
	if ($type === "") {
		$type = nms_device_type_auto("", $template);
	}
	$icon = "device";
	foreach (
		[
			"switch",
			"router",
			"server",
			"workstation",
			"pc / desktop",
			"laptop",
			"phone",
			"desk phone",
			"ups",
			"sensor",
			"printer",
			"camera",
			"wireless",
			"firewall",
			"satellite",
		]
		as $candidate
	) {
		if (stripos($type, $candidate) !== false) {
			$icon =
				$candidate === "pc / desktop"
					? "workstation"
					: ($candidate === "desk phone"
						? "ipphone"
						: ($candidate === "wireless ap"
							? "wireless"
							: ($candidate === "satellite / vsat"
								? "satellite"
								: $candidate)));
			break;
		}
	}
	return ["name" => $type, "category_id" => (int) $category_id, "icon" => $icon, "color" => "#334155"];
}
/**
 * Handles appearance icon svg.
 */
function nms_appearance_icon_svg($icon)
{
	$entry = nms_appearance_icons()[$icon] ?? nms_appearance_icons()["device"];
	return '<svg viewBox="0 0 32 32" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="' .
		$entry[1] .
		'"/></svg>';
}
