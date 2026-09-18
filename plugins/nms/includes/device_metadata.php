<?php
/**
 * @file device_metadata.php
 * Operator-entered device identity stored exclusively in NMS-owned metadata.
 * Manual serial numbers never replace live SNMP observations or their fault baseline.
 */

require_once __DIR__ . "/inventory.php";

/** Validate optional serial text before any write; reject arrays, control characters, and oversized values. */
function nms_manual_serial_validate($value)
{
	if (!is_string($value) || preg_match("//u", $value) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
		throw new InvalidArgumentException("Enter a serial number as a single line of text.");
	}
	$value = trim($value);
	if (strlen($value) > 191) {
		throw new InvalidArgumentException("Serial number must be 191 bytes or fewer.");
	}
	return $value;
}

/** Read the operator-entered serial without substituting an SNMP value when metadata is absent. */
function nms_manual_serial_get($host_id)
{
	$value = db_fetch_cell_prepared("SELECT serial_number FROM plugin_nms_device_metadata WHERE host_id = ?", [
		(int) $host_id,
	]);
	return is_string($value) ? $value : "";
}

/**
 * Resolve the serial definition through the device's current imported host template.
 * Use the same newest-import/first-OID order as the inventory poller. Only an
 * observation for that exact OID can supply a suggestion; file samples are not selected.
 */
function nms_device_serial_reading($host_id)
{
	$reading = db_fetch_row_prepared(
		"SELECT h.status AS host_status, h.disabled, h.last_updated AS host_last_updated,
		o.oid, di.observed_value, di.status, di.last_success
		FROM host AS h
		INNER JOIN plugin_nms_snmprec_imports AS i ON i.host_template_id = h.host_template_id
		INNER JOIN plugin_nms_snmprec_oids AS o ON o.import_id = i.id AND o.inventory_key = 'serial_number'
		LEFT JOIN plugin_nms_device_inventory AS di ON di.host_id = h.id
			AND di.inventory_key = o.inventory_key AND di.oid = o.oid
		WHERE h.id = ? AND h.deleted = ''
		ORDER BY i.id DESC, o.id LIMIT 1",
		[(int) $host_id],
	);
	return is_array($reading) ? $reading : [];
}

/**
 * Prepare an editable suggestion without saving metadata or changing SNMP state.
 * A saved NMS value takes precedence. Otherwise require a current successful poll
 * from an enabled, Up host and a serial that fits the manual field unchanged.
 */
function nms_serial_form_prefill($manual_value, $reading, $allow_suggestion = true)
{
	$result = [
		"value" => $manual_value,
		"source" => $manual_value !== "" ? "saved" : "empty",
		"oid" => (string) ($reading["oid"] ?? ""),
		"last_success" => "",
	];
	if ($manual_value !== "" || !$allow_suggestion || !$result["oid"]) {
		return $result;
	}
	if (
		(int) ($reading["host_status"] ?? 0) !== HOST_UP ||
		($reading["disabled"] ?? "") !== "" ||
		!in_array($reading["status"] ?? "", ["ok", "changed"], true)
	) {
		return $result;
	}
	if (!nms_parameter_is_fresh($reading["host_last_updated"] ?? "")) {
		return $result;
	}
	$last_success = (string) ($reading["last_success"] ?? "");
	$timestamp = strtotime($last_success);
	if ($timestamp === false || $timestamp > time() || !nms_parameter_is_fresh($last_success)) {
		return $result;
	}
	try {
		$value = nms_manual_serial_validate($reading["observed_value"] ?? "");
	} catch (InvalidArgumentException $exception) {
		// Do not truncate an identity to fit the input or substitute malformed SNMP output.
		return $result;
	}
	if (nms_inventory_snmp_failed($value)) {
		return $result;
	}
	$result["value"] = $value;
	$result["source"] = "snmp";
	$result["last_success"] = $last_success;
	return $result;
}

/** Save or clear an existing device's manual serial; write only plugin metadata, never core or live inventory. */
function nms_manual_serial_save($host_id, $value)
{
	$value = nms_manual_serial_validate($value);
	$host_id = (int) $host_id;
	if (
		$host_id < 1 ||
		!(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM host WHERE id = ? AND deleted = ''", [$host_id])
	) {
		throw new InvalidArgumentException("Select an existing Cacti device before saving its serial number.");
	}
	if ($value === "") {
		$saved = db_execute_prepared("UPDATE plugin_nms_device_metadata SET serial_number='' WHERE host_id = ?", [
			$host_id,
		]);
	} else {
		$saved = db_execute_prepared(
			'INSERT INTO plugin_nms_device_metadata
			(host_id, serial_number, updated_by, updated_at) VALUES (?, ?, ?, NOW())
			ON DUPLICATE KEY UPDATE serial_number = VALUES(serial_number), updated_by = VALUES(updated_by), updated_at = NOW()',
			[$host_id, $value, nms_current_user_id()],
		);
	}
	if ($saved === false) {
		throw new RuntimeException("NMS could not save the manual serial number. Please retry.");
	}
	return $value;
}

/** Manual identity is separate from observed data, and is validated before core writes. */
function nms_identity_manual_validate($input)
{
	$out = [];
	foreach (["manual_chassis_id", "manual_mac_address", "manual_port_count"] as $key) {
		if (!array_key_exists($key, $input)) {
			continue;
		}
		$value = nms_manual_serial_validate($input[$key]);
		if ($key === "manual_mac_address" && $value !== "") {
			if (!preg_match('/^(?:[0-9a-f]{2}[:-]){5}[0-9a-f]{2}$/iD', $value)) {
				throw new InvalidArgumentException(
					"MAC address must contain six hexadecimal pairs, for example 02:00:00:00:00:01.",
				);
			}
			$value = strtolower(str_replace("-", ":", $value));
			if ($value === "00:00:00:00:00:00" || hexdec(substr($value, 0, 2)) & 1) {
				throw new InvalidArgumentException("Enter a nonzero unicast device MAC address.");
			}
		}
		if (
			$key === "manual_port_count" &&
			$value !== "" &&
			(!preg_match('/^[0-9]{1,5}$/D', $value) || (int) $value > 65535)
		) {
			throw new InvalidArgumentException("Physical port count must be blank or an integer from 0 to 65535.");
		}
		$out[$key] = $value;
	}
	return $out;
}
/**
 * Handles identity manual save.
 */
function nms_identity_manual_save($host_id, $values)
{
	$values = nms_identity_manual_validate($values);
	if (!$values) {
		return;
	}
	foreach ($values as $key => $value) {
		$column = substr($key, 7); // Keys are restricted by the validator.
		if (
			db_execute_prepared(
				"INSERT INTO plugin_nms_device_metadata (host_id," .
					$column .
					",updated_by,updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE " .
					$column .
					"=VALUES(" .
					$column .
					"),updated_by=VALUES(updated_by),updated_at=NOW()",
				[(int) $host_id, $value, nms_current_user_id()],
			) === false
		) {
			throw new RuntimeException(
				"Device saved but manual identity could not be saved. Reopen this device and retry.",
			);
		}
	}
}
/** Select only current, successful observations for the current core connection and preset. */
function nms_identity_observation($host_id)
{
	$host = null;
	foreach (nms_nd_hosts() as $candidate) {
		if ((int) $candidate["id"] === (int) $host_id) {
			$host = $candidate;
		}
	}
	if (!$host || !$host["enabled"] || !$host["collection_enabled"]) {
		return nms_identity_record_for_host($host_id);
	}
	$rows = db_fetch_assoc_prepared(
		"SELECT * FROM plugin_nms_discovery_snapshots WHERE host_id=? ORDER BY succeeded_at DESC",
		[$host_id],
	);
	$result = [];
	foreach ($rows as $row) {
		if (
			($row["protocol"] !== "identity" && !in_array($row["protocol"], nms_nd_host_methods($host), true)) ||
			$row["status"] !== "success" ||
			!hash_equals($row["config_hash"], nms_nd_hash($host))
		) {
			continue;
		}
		$data = json_decode($row["data_json"], true);
		if (!is_array($data) || !nms_nd_evidence_fresh($data["collected"] ?? 0, time(), $host["stale_seconds"])) {
			continue;
		}
		if (!$result) {
			$result = [
				"interfaces" => $data["interfaces"] ?? [],
				"hardware" => $data["hardware"] ?? [],
				"time" => $row["succeeded_at"],
				"chassis_id" => "",
				"serial" => "",
				"mac" => "",
				"neighbors" => [],
			];
		}
		if ($row["protocol"] === "lldp" && preg_match('/^([1-7]):([a-f0-9]+)$/D', $data["identity"] ?? "", $m)) {
			$bytes = hex2bin($m[2]);
			if ($bytes !== false) {
				$result["chassis_id"] =
					(int) $m[1] === 4 && strlen($bytes) === 6
						? implode(":", str_split($m[2], 2))
						: nms_nd_octets($bytes);
			}
		}
		foreach ($data["neighbors"] ?? [] as $key => $neighbor) {
			if (!empty($neighbor["present"])) {
				$result["neighbors"][$row["protocol"] . ":" . $key] = $neighbor;
			}
		}
	}
	if (!$result) {
		return nms_identity_record_for_host($host_id);
	}
	$serials = [];
	foreach ($result["hardware"]["chassis"] ?? [] as $chassis) {
		if ($chassis["serial"] !== "") {
			$serials[] = $chassis["serial"];
		}
	}
	$serials = array_unique($serials);
	if (count($serials) === 1) {
		$result["serial"] = reset($serials);
	}
	$macs = [];
	foreach ($result["interfaces"] as $port) {
		if (
			preg_match('/^[a-f0-9]{12}$/D', $port["mac_hex"] ?? "") &&
			$port["mac_hex"] !== "000000000000" &&
			!(hexdec(substr($port["mac_hex"], 0, 2)) & 1)
		) {
			$macs[] = implode(":", str_split($port["mac_hex"], 2));
		}
	}
	$macs = array_unique($macs);
	if (count($macs) === 1) {
		$result["mac"] = reset($macs);
	}
	return $result;
}

/** A selected SNMPSim record can provide saved identity before its first poll. */
function nms_identity_record_for_host($host_id)
{
	$import_id = (int) db_fetch_cell_prepared(
		'SELECT i.id FROM host AS h
        INNER JOIN plugin_nms_snmprec_imports AS i ON i.host_template_id=h.host_template_id AND i.community=h.snmp_community
        WHERE h.id=? AND h.deleted=\'\' ORDER BY i.id DESC LIMIT 1',
		[(int) $host_id],
	);
	if (!$import_id) {
		return [];
	}
	try {
		return nms_identity_record_ports($import_id);
	} catch (Throwable $exception) {
		return [];
	}
}

/** Read port evidence from a validated simulator import, never from MIB definitions alone. */
function nms_identity_record_ports($import_id)
{
	require_once __DIR__ . "/snmprec.php";
	require_once __DIR__ . "/snmpsim.php";
	require_once __DIR__ . "/discovery_snmp.php";
	$defaults = nms_snmpsim_import_defaults((int) $import_id);
	$settings = nms_snmpsim_config();
	$path = $settings["data_dir"] . "/" . $defaults["snmp_community"] . ".snmprec";
	$content = @file_get_contents($path, false, null, 0, 2097153);
	if ($content === false) {
		throw new RuntimeException("Cannot read the selected SNMP recording.");
	}
	$values = [];
	foreach (nms_snmprec_parse($content) as $record) {
		$value = $record["value"];
		if ($record["tag"] === "4x") {
			$value = hex2bin($value);
		}
		$values[$record["oid"]] = ["type" => $record["type"], "value" => $value];
	}
	$interfaces = [];
	if (
		isset($values["1.3.6.1.2.1.2.2.1.1.1"]) ||
		count(preg_grep("/^1\.3\.6\.1\.2\.1\.2\.2\.1\.1\./", array_keys($values)))
	) {
		try {
			$interfaces = nms_nd_interfaces($values);
		} catch (RuntimeException $exception) {
			$interfaces = [];
		}
	}
	try {
		$hardware = nms_nd_hardware($values);
	} catch (RuntimeException $exception) {
		$hardware = ["chassis" => [], "physical_ports" => [], "error" => $exception->getMessage()];
	}
	$chassis_id = "";
	$mac = "";
	/* LLDP's local chassis ID is the best device identity in an SNMP record. */
	$chassis_type = $values["1.0.8802.1.1.2.1.3.1.0"] ?? null;
	$chassis_value = $values["1.0.8802.1.1.2.1.3.2.0"] ?? null;
	if ($chassis_type && $chassis_value && (int) $chassis_type["type"] === 2 && (int) $chassis_value["type"] === 4) {
		$bytes = (string) $chassis_value["value"];
		$hex = bin2hex($bytes);
		if (
			(int) $chassis_type["value"] === 4 &&
			strlen($bytes) === 6 &&
			$hex !== "000000000000" &&
			!(hexdec(substr($hex, 0, 2)) & 1)
		) {
			$mac = implode(":", str_split($hex, 2));
			$chassis_id = $mac;
		} else {
			$chassis_id = nms_nd_octets($bytes);
		}
	}
	if ($mac === "") {
		$macs = [];
		foreach ($interfaces as $port) {
			$hex = $port["mac_hex"] ?? "";
			if (preg_match('/^[a-f0-9]{12}$/D', $hex) && $hex !== "000000000000" && !(hexdec(substr($hex, 0, 2)) & 1)) {
				$macs[] = $hex;
			}
		}
		$macs = array_values(array_unique($macs));
		if (count($macs) === 1) {
			$mac = implode(":", str_split($macs[0], 2));
		}
	}
	$serials = [];
	foreach ($hardware["chassis"] ?? [] as $chassis) {
		if (($chassis["serial"] ?? "") !== "") {
			$serials[] = $chassis["serial"];
		}
	}
	$serials = array_values(array_unique($serials));
	return [
		"hardware" => $hardware,
		"interfaces" => $interfaces,
		"chassis_id" => $chassis_id,
		"mac" => $mac,
		"serial" => count($serials) === 1 ? $serials[0] : "",
		"source" => "SNMP recording (saved values, not a live check)",
	];
}

/** Persist whether this NMS device may intentionally share an SNMP endpoint. */
function nms_shared_endpoint_get($host_id)
{
	return (int) db_fetch_cell_prepared("SELECT meta_value FROM plugin_nms_meta WHERE meta_key=?", [
		"device_shared_endpoint_" . (int) $host_id,
	]) === 1;
}
function nms_shared_endpoint_save($host_id, $enabled)
{
	nms_category_execute(
		"INSERT INTO plugin_nms_meta(meta_key,meta_value,updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value), updated_at=VALUES(updated_at)",
		["device_shared_endpoint_" . (int) $host_id, $enabled ? "1" : "0"],
	);
}

/** Short labels are display metadata; blank follows the current Cacti device name. */
function nms_short_name_validate($value)
{
	if (
		!is_string($value) ||
		strlen($value) > 24 ||
		($value !== "" && !preg_match('/^[A-Za-z0-9][A-Za-z0-9 _-]*$/D', $value))
	) {
		throw new InvalidArgumentException(
			"Short name must be at most 24 characters: letters, numbers, spaces, hyphens or underscores. Leave blank to generate it from the device name.",
		);
	}
	return trim($value);
}
function nms_short_name_auto($name, $id = 0)
{
	$prefix = "DEV";
	foreach (
		[
			"switch" => "SW",
			"router" => "RTR",
			"server" => "SRV",
			"sensor" => "SNS",
			"ups" => "UPS",
			"phone" => "PH",
			"laptop" => "LAP",
			"linux" => "PC",
			"workstation" => "PC",
			"rhel" => "PC",
			"camera" => "CAM",
		]
		as $word => $label
	) {
		if (stripos((string) $name, $word) !== false) {
			$prefix = $label;
			break;
		}
	}
	return $prefix . "-" . str_pad((string) max(1, (int) $id), 2, "0", STR_PAD_LEFT);
}

/**
 * Handles short name get.
 */
function nms_short_name_get($id)
{
	return (string) db_fetch_cell_prepared("SELECT meta_value FROM plugin_nms_meta WHERE meta_key=?", [
		"device_short_name_" . (int) $id,
	]);
}
/**
 * Handles short name save.
 */
function nms_short_name_save($id, $value)
{
	nms_require_device_access((int) $id);
	$value = nms_short_name_validate($value);
	nms_category_execute("REPLACE INTO plugin_nms_meta(meta_key,meta_value,updated_at) VALUES (?,?,NOW())", [
		"device_short_name_" . (int) $id,
		$value,
	]);
}
/**
 * Handles single topology site.
 */
function nms_single_topology_site()
{
	return (int) db_fetch_cell_prepared("SELECT meta_value FROM plugin_nms_meta WHERE meta_key=?", [
		"topology_site_id",
	]);
}
