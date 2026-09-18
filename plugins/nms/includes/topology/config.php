<?php
/** Physical planning metadata only. Never synthesize discovered ports or core devices. */
require_once __DIR__ . "/categories.php";

/** Create the plugin-owned tables for physical-port planning and rack placement metadata. */
function nms_topology_config_schema()
{
	nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_port_profiles (
		category_id INT UNSIGNED NOT NULL, device_type VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
		physical_ports SMALLINT UNSIGNED NOT NULL, updated_by INT UNSIGNED NOT NULL,
		updated_at DATETIME NOT NULL, PRIMARY KEY (category_id, device_type)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");
	nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_rack_nodes (
		id INT UNSIGNED NOT NULL AUTO_INCREMENT, site_id INT UNSIGNED NOT NULL,
		name VARCHAR(150) NOT NULL, node_kind VARCHAR(16) NOT NULL,
		updated_by INT UNSIGNED NOT NULL, updated_at DATETIME NOT NULL,
		PRIMARY KEY (id), UNIQUE KEY site_name (site_id, name)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");
	nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_racks (
		id INT UNSIGNED NOT NULL AUTO_INCREMENT, node_id INT UNSIGNED NOT NULL,
		rack_number SMALLINT UNSIGNED NOT NULL, name VARCHAR(150) NOT NULL,
		unit_count SMALLINT UNSIGNED NOT NULL, updated_by INT UNSIGNED NOT NULL,
		updated_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY node_rack (node_id, rack_number)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");
	nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_rack_devices (
		host_id MEDIUMINT UNSIGNED NOT NULL, rack_id INT UNSIGNED NOT NULL,
		start_unit SMALLINT UNSIGNED NOT NULL, unit_height SMALLINT UNSIGNED NOT NULL,
		updated_by INT UNSIGNED NOT NULL, updated_at DATETIME NOT NULL,
		PRIMARY KEY (host_id), KEY rack_id (rack_id)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");
}

/** Reject fractional, negative and oversized values instead of silently truncating input. */
function nms_topology_integer($value, $min, $max, $label)
{
	if (
		!is_scalar($value) ||
		!preg_match('/^[0-9]+$/D', (string) $value) ||
		(float) $value < $min ||
		(float) $value > $max
	) {
		throw new InvalidArgumentException($label . " must be a whole number from " . $min . " to " . $max . ".");
	}
	return (int) $value;
}

/** Save a reviewed physical-port count by device segment and optional exact device type. */
function nms_topology_port_save($category, $type, $ports)
{
	if (!nms_category_exists($category)) {
		throw new InvalidArgumentException("Select an existing device segment.");
	}
	$type = nms_classification_text($type, 150);
	$ports = nms_topology_integer($ports, 0, 4096, "Physical ports");
	nms_category_execute(
		'INSERT INTO plugin_nms_port_profiles (category_id, device_type, physical_ports, updated_by, updated_at)
		VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE physical_ports = VALUES(physical_ports), updated_by = VALUES(updated_by), updated_at = NOW()',
		[(int) $category, $type, $ports, nms_current_user_id()],
	);
}

/** Serialize capacity and placement changes together so concurrent operators cannot overlap devices. */
function nms_topology_config_write($action, $site_id, $input)
{
	$lock = "nms_racks_" . substr(hash("sha256", (string) db_fetch_cell("SELECT DATABASE()")), 0, 32);
	if ((int) db_fetch_cell_prepared("SELECT GET_LOCK(?, 10)", [$lock]) !== 1) {
		throw new RuntimeException("Rack configuration is busy. Please retry.");
	}
	nms_category_execute("START TRANSACTION");
	try {
		$id = nms_topology_config_apply($action, $site_id, $input);
		nms_category_execute("COMMIT");
		return $id;
	} catch (Throwable $error) {
		db_execute("ROLLBACK");
		throw $error;
	} finally {
		db_fetch_cell_prepared("SELECT RELEASE_LOCK(?)", [$lock]);
	}
}

/** Validate and apply one rack/node/placement action inside the caller's transaction and lock. */
function nms_topology_config_apply($action, $site_id, $input)
{
	$user = nms_current_user_id();
	if ($action === "save_node") {
		$id = nms_topology_integer($input["node_id"], 0, PHP_INT_MAX, "Node ID");
		$name = nms_classification_text($input["name"], 150);
		$kind = $input["node_kind"];
		$count = nms_topology_integer($input["rack_count"], 1, 100, "Rack count");
		$units = nms_topology_integer($input["unit_count"], 1, 100, "Units per new rack");
		if ($name === "" || !in_array($kind, ["node", "vehicle"], true)) {
			throw new InvalidArgumentException("Enter a node/vehicle name and valid kind.");
		}
		if (
			$id &&
			!(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_nms_rack_nodes WHERE id = ? AND site_id = ?", [
				$id,
				$site_id,
			])
		) {
			throw new InvalidArgumentException("Select a node at this site.");
		}
		if (
			(int) db_fetch_cell_prepared(
				"SELECT COUNT(*) FROM plugin_nms_rack_nodes WHERE site_id = ? AND name = ? AND id != ?",
				[$site_id, $name, $id],
			)
		) {
			throw new InvalidArgumentException("This site already has a node/vehicle with that name.");
		}
		if ($id) {
			if (
				(int) db_fetch_cell_prepared(
					"SELECT COUNT(*) FROM plugin_nms_rack_devices d INNER JOIN plugin_nms_racks r ON r.id = d.rack_id WHERE r.node_id = ? AND r.rack_number > ?",
					[$id, $count],
				)
			) {
				throw new InvalidArgumentException(
					"Move or unassign devices from the last racks before reducing the rack count.",
				);
			}
			nms_category_execute(
				"UPDATE plugin_nms_rack_nodes SET name = ?, node_kind = ?, updated_by = ?, updated_at = NOW() WHERE id = ?",
				[$name, $kind, $user, $id],
			);
			nms_category_execute("DELETE FROM plugin_nms_racks WHERE node_id = ? AND rack_number > ?", [$id, $count]);
		} else {
			nms_category_execute(
				"INSERT INTO plugin_nms_rack_nodes (site_id, name, node_kind, updated_by, updated_at) VALUES (?, ?, ?, ?, NOW())",
				[$site_id, $name, $kind, $user],
			);
			$id = (int) db_fetch_cell("SELECT LAST_INSERT_ID()");
		}
		for ($number = 1; $number <= $count; $number++) {
			if (
				!(int) db_fetch_cell_prepared(
					"SELECT COUNT(*) FROM plugin_nms_racks WHERE node_id = ? AND rack_number = ?",
					[$id, $number],
				)
			) {
				nms_category_execute(
					"INSERT INTO plugin_nms_racks (node_id, rack_number, name, unit_count, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, NOW())",
					[$id, $number, "Rack " . $number, $units, $user],
				);
			}
		}
		return $id;
	}
	$rack_id = nms_topology_integer($input["rack_id"], 1, PHP_INT_MAX, "Rack ID");
	$rack = db_fetch_row_prepared(
		"SELECT r.* FROM plugin_nms_racks r INNER JOIN plugin_nms_rack_nodes n ON n.id = r.node_id WHERE r.id = ? AND n.site_id = ?",
		[$rack_id, $site_id],
	);
	if (!$rack) {
		throw new InvalidArgumentException("Select a rack at this site.");
	}
	if ($action === "save_rack") {
		$name = nms_classification_text($input["name"], 150);
		$units = nms_topology_integer($input["unit_count"], 1, 100, "Rack units");
		if ($name === "") {
			throw new InvalidArgumentException("Enter a rack name.");
		}
		if (
			(int) db_fetch_cell_prepared(
				"SELECT COUNT(*) FROM plugin_nms_rack_devices WHERE rack_id = ? AND start_unit + unit_height - 1 > ?",
				[$rack_id, $units],
			)
		) {
			throw new InvalidArgumentException("The smaller rack would exclude installed devices. Move them first.");
		}
		nms_category_execute(
			"UPDATE plugin_nms_racks SET name = ?, unit_count = ?, updated_by = ?, updated_at = NOW() WHERE id = ?",
			[$name, $units, $user, $rack_id],
		);
	} elseif ($action === "place_device" || $action === "unplace_device") {
		$host_id = nms_topology_integer($input["host_id"], 1, PHP_INT_MAX, "Device ID");
		nms_require_device_access($host_id);
		if ($action === "unplace_device") {
			nms_category_execute("DELETE FROM plugin_nms_rack_devices WHERE host_id = ? AND rack_id = ?", [
				$host_id,
				$rack_id,
			]);
		} else {
			if (
				!(int) db_fetch_cell_prepared(
					"SELECT COUNT(*) FROM host WHERE id = ? AND site_id = ? AND deleted = ''",
					[$host_id, $site_id],
				)
			) {
				throw new InvalidArgumentException("Select a Cacti device at this site.");
			}
			$start = nms_topology_integer($input["start_unit"], 1, 100, "Start unit");
			$height = nms_topology_integer($input["unit_height"], 1, 100, "Device height");
			if ($start + $height - 1 > (int) $rack["unit_count"]) {
				throw new InvalidArgumentException("The device extends beyond the rack capacity.");
			}
			if (
				(int) db_fetch_cell_prepared(
					"SELECT COUNT(*) FROM plugin_nms_rack_devices WHERE rack_id = ? AND host_id != ? AND start_unit <= ? AND start_unit + unit_height - 1 >= ?",
					[$rack_id, $host_id, $start + $height - 1, $start],
				)
			) {
				throw new InvalidArgumentException("These rack units are already occupied.");
			}
			nms_category_execute(
				"INSERT INTO plugin_nms_rack_devices (host_id, rack_id, start_unit, unit_height, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE rack_id = VALUES(rack_id), start_unit = VALUES(start_unit), unit_height = VALUES(unit_height), updated_by = VALUES(updated_by), updated_at = NOW()",
				[$host_id, $rack_id, $start, $height, $user],
			);
		}
	} else {
		throw new InvalidArgumentException("Unsupported rack action.");
	}
	return (int) $rack["node_id"];
}

/** Exact device-type profile wins; an empty type provides the category default. */
function nms_topology_physical_ports($profiles, $category_id, $device_type)
{
	$default = null;
	foreach ($profiles as $profile) {
		if ((int) $profile["category_id"] !== (int) $category_id) {
			continue;
		}
		if ($profile["device_type"] === $device_type) {
			return (int) $profile["physical_ports"];
		}
		if ($profile["device_type"] === "") {
			$default = (int) $profile["physical_ports"];
		}
	}
	return $default;
}
