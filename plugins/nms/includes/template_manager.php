<?php
/**
 * @file template_manager.php
 * Convert imported OID definitions into reusable native Cacti host/data/graph templates and retain import associations.
 * Recognized text identity OIDs are routed to inventory instead of numeric RRD data sources.
 */

require_once __DIR__ . "/functions.php";
require_once $config["base_path"] . "/lib/api_data_source.php";
require_once $config["base_path"] . "/lib/api_graph.php";
require_once $config["base_path"] . "/lib/template.php";

/** Strip markup, normalize whitespace, and bound a required Cacti template name. */
function nms_template_clean_name($value, $maximum = 150)
{
	$value = trim(preg_replace("/\s+/", " ", strip_tags((string) $value)));
	if ($value === "") {
		throw new InvalidArgumentException("A Cacti template name is required.");
	}
	return substr($value, 0, $maximum);
}

/** Return an existing host template by name or create a new Cacti host-template record. */
function nms_template_host($name)
{
	$name = nms_template_clean_name($name);
	$existing = (int) db_fetch_cell_prepared("SELECT id FROM host_template WHERE name = ?", [$name]);
	if ($existing > 0) {
		return $existing;
	}
	$id = sql_save(["id" => 0, "hash" => get_hash_host_template(0), "name" => $name], "host_template");
	if (!$id) {
		throw new RuntimeException("Cacti could not create the host template.");
	}
	return (int) $id;
}

/** Recognize supported chassis and indexed entity or printer serial-number OIDs. */
function nms_template_is_serial_number_oid($oid)
{
	$oid = ltrim(trim((string) $oid), ".");
	if ($oid === "1.3.6.1.4.1.9.3.6.3.0") {
		return true;
	} // OLD-CISCO-CHASSIS-MIB::chassisId
	$indexed_serial_columns = [
		"1.3.6.1.2.1.47.1.1.1.1.11", // ENTITY-MIB::entPhysicalSerialNum
		"1.3.6.1.2.1.43.5.1.1.17", // Printer-MIB::prtGeneralSerialNumber
	];
	foreach ($indexed_serial_columns as $base_oid) {
		if ($oid === $base_oid || strpos($oid, $base_oid . ".") === 0) {
			return true;
		}
	}
	return false;
}

/** Return a readable label for recognized inventory/system OIDs, or an empty string. */
function nms_template_known_oid_label($oid)
{
	if (nms_template_is_serial_number_oid($oid)) {
		return "Chassis serial number";
	}
	$labels = [
		"1.3.6.1.2.1.1.3.0" => "System uptime",
		"1.3.6.1.2.1.1.5.0" => "Device name",
	];
	return $labels[$oid] ?? "";
}

/**
 * Identify the small set of text OIDs that must be retained as live inventory.
 * Numeric values remain wholly owned by Cacti data sources and RRDtool.  The
 * imported value is never used as a live fallback when SNMP is unavailable.
 */
function nms_template_inventory_key($record)
{
	if (!empty($record["graphable"]) || (int) ($record["type"] ?? 0) !== 4) {
		return "";
	}
	$oid = ltrim(trim((string) ($record["oid"] ?? "")), ".");
	$section = strtolower(trim((string) ($record["section"] ?? "")));
	/* Do not match a product name such as "Serial Device Server CPU". */
	$serial_heading = preg_match(
		"/(?:serial[\s_-]*(?:number|no\.?|#)|entphysicalserialnum|service[\s_-]*tag)/",
		$section,
	);
	if (nms_template_is_serial_number_oid($oid) || $serial_heading) {
		return "serial_number";
	}
	return "";
}

/** Build a readable reading label from a known OID or section and reading index. */
function nms_template_record_label($record)
{
	$known_label = nms_template_known_oid_label((string) $record["oid"]);
	if ($known_label !== "") {
		return $known_label;
	}

	$section = trim(preg_replace("/[_-]+/", " ", (string) $record["section"]));
	$section = trim(preg_replace("/\s+/", " ", $section));
	if ($section === "") {
		$section = "SNMP reading";
	}
	if ((int) ($record["reading_total"] ?? 1) > 1) {
		$section .= " - Reading " . max(1, (int) ($record["reading_index"] ?? 1));
	}
	return substr($section, 0, 150);
}

/** Create a normalized, indexed RRD data-source name within the 19-character limit. */
function nms_template_data_source_name($record)
{
	$label = strtolower(nms_template_record_label($record));
	$label = trim(preg_replace("/[^a-z0-9]+/", "_", $label), "_");
	if ($label === "") {
		$label = "reading";
	}
	$index = max(1, (int) ($record["reading_index"] ?? 1));
	$suffix = "_" . $index;
	return "nms_" . substr($label, 0, 19 - 4 - strlen($suffix)) . $suffix;
}

/** Duplicate native Generic OID templates into a data/graph template pair for one imported numeric reading. */
function nms_template_pair($template_name, $record)
{
	$base_data_template_id = (int) db_fetch_cell(
		"SELECT id FROM data_template WHERE name = 'SNMP - Generic OID Template'",
	);
	$base_graph_template_id = (int) db_fetch_cell(
		"SELECT id FROM graph_templates WHERE name = 'SNMP - Generic OID Template'",
	);
	if (!$base_data_template_id || !$base_graph_template_id) {
		throw new RuntimeException("Cacti Generic OID templates are not installed.");
	}

	$label = nms_template_record_label($record);
	$prefix = preg_match("/^NMS\b/i", $template_name) ? $template_name : "NMS " . $template_name;
	$object_name = substr($prefix . " - " . $label, 0, 190);
	$data_template_id = (int) api_duplicate_data_source(0, $base_data_template_id, $object_name);
	if (!$data_template_id) {
		throw new RuntimeException("Cacti could not create data template for " . $record["oid"] . ".");
	}

	$data_template_data_id = (int) db_fetch_cell_prepared(
		"SELECT id FROM data_template_data WHERE data_template_id = ? AND local_data_id = 0",
		[$data_template_id],
	);
	$data_template_rrd_id = (int) db_fetch_cell_prepared(
		"SELECT id FROM data_template_rrd WHERE data_template_id = ? AND local_data_id = 0",
		[$data_template_id],
	);
	$oid_field_id = (int) db_fetch_cell(
		"SELECT id FROM data_input_fields WHERE data_input_id = 1 AND data_name = 'oid'",
	);
	if (!$data_template_data_id || !$data_template_rrd_id || !$oid_field_id) {
		throw new RuntimeException("The duplicated Cacti data template is incomplete.");
	}

	/* Cacti/RRDtool data-source types: 1 GAUGE, 2 COUNTER. */
	$data_source_type_id = in_array((int) $record["type"], [65, 70], true) ? 2 : 1;
	db_execute_prepared("UPDATE data_template_data SET name = ? WHERE id = ?", [
		"|host_description| - " . $label,
		$data_template_data_id,
	]);
	db_execute_prepared("UPDATE data_template_rrd SET data_source_name = ?, data_source_type_id = ? WHERE id = ?", [
		nms_template_data_source_name($record),
		$data_source_type_id,
		$data_template_rrd_id,
	]);
	db_execute_prepared(
		'UPDATE data_input_data SET t_value = ?, value = ?
		WHERE data_template_data_id = ? AND data_input_field_id = ?',
		["", $record["oid"], $data_template_data_id, $oid_field_id],
	);

	$graph_template_id = (int) api_duplicate_graph(0, $base_graph_template_id, $object_name, false);
	if (!$graph_template_id) {
		throw new RuntimeException("Cacti could not create graph template for " . $record["oid"] . ".");
	}
	db_execute_prepared(
		'UPDATE graph_templates_graph SET title = ?, vertical_label = ?
		WHERE graph_template_id = ? AND local_graph_id = 0',
		["|host_description| - " . $label, substr($label, 0, 20), $graph_template_id],
	);
	db_execute_prepared(
		'UPDATE graph_templates_item SET task_item_id = ?
		WHERE graph_template_id = ? AND local_graph_id = 0',
		[$data_template_rrd_id, $graph_template_id],
	);
	db_execute_prepared(
		"UPDATE graph_template_input SET name = ?
		WHERE graph_template_id = ? AND column_name = 'task_item_id'",
		["Data Source [" . $label . "]", $graph_template_id],
	);
	$linked_graph_items = (int) db_fetch_cell_prepared(
		'SELECT COUNT(*) FROM graph_templates_item
		WHERE graph_template_id = ? AND local_graph_id = 0 AND task_item_id = ?',
		[$graph_template_id, $data_template_rrd_id],
	);
	if ($linked_graph_items === 0) {
		throw new RuntimeException("Cacti created the graph template but did not link its data-source item.");
	}

	return ["data_template_id" => $data_template_id, "graph_template_id" => $graph_template_id];
}

/** Validate a record import, create its Cacti templates and metadata, and deploy the simulator community. */
function nms_template_import($original_name, $community, $template_name, $category_id, $content, $records, $user_id)
{
	/* Fail before creating Cacti objects if this server has no usable simulator directory. */
	nms_snmprec_runtime_dir();
	if (!nms_category_exists($category_id)) {
		throw new InvalidArgumentException("Select a valid device segment.");
	}
	$hash = hash("sha256", $content);
	if (
		(int) db_fetch_cell_prepared(
			"SELECT COUNT(*) FROM plugin_nms_snmprec_imports WHERE file_hash = ? OR community = ?",
			[$hash, $community],
		)
	) {
		throw new InvalidArgumentException("This file or simulator community has already been imported.");
	}

	$graphable_count = 0;
	$section_totals = [];
	foreach ($records as $record) {
		if (!$record["graphable"]) {
			continue;
		}
		$graphable_count++;
		$section_key = strtolower(trim((string) $record["section"]));
		$section_totals[$section_key] = ($section_totals[$section_key] ?? 0) + 1;
	}
	if ($graphable_count === 0) {
		throw new InvalidArgumentException("The file has no numeric readings that Cacti can graph.");
	}
	if ($graphable_count > 64) {
		throw new InvalidArgumentException("A single import can create at most 64 graphable readings.");
	}

	$target_path = "";
	db_execute("START TRANSACTION");
	try {
		$host_template_created = !(int) db_fetch_cell_prepared("SELECT id FROM host_template WHERE name=?", [
			nms_template_clean_name($template_name),
		]);
		$host_template_id = nms_template_host($template_name);
		nms_assign_template_category($host_template_id, $category_id);
		db_execute_prepared(
			'INSERT INTO plugin_nms_snmprec_imports
			(original_name, community, template_name, host_template_id, category_id, record_count,
			graphable_count, file_hash, deployed_path, uploaded_by, created_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
			[
				$original_name,
				$community,
				$template_name,
				$host_template_id,
				$category_id,
				count($records),
				$graphable_count,
				$hash,
				"",
				$user_id,
			],
		);
		$import_id = (int) db_fetch_cell("SELECT LAST_INSERT_ID()");

		$section_positions = [];
		foreach ($records as $record) {
			$data_template_id = 0;
			$graph_template_id = 0;
			$inventory_key = nms_template_inventory_key($record);
			if ($record["graphable"]) {
				$section_key = strtolower(trim((string) $record["section"]));
				$section_positions[$section_key] = ($section_positions[$section_key] ?? 0) + 1;
				$record["reading_index"] = $section_positions[$section_key];
				$record["reading_total"] = $section_totals[$section_key];
				$pair = nms_template_pair($template_name, $record);
				$data_template_id = $pair["data_template_id"];
				$graph_template_id = $pair["graph_template_id"];
				nms_managed_object_record("data_template", $data_template_id);
				nms_managed_object_record("graph_template", $graph_template_id);
				db_execute_prepared(
					"REPLACE INTO host_template_graph (host_template_id, graph_template_id) VALUES (?, ?)",
					[$host_template_id, $graph_template_id],
				);
			}
			db_execute_prepared(
				'INSERT INTO plugin_nms_snmprec_oids
				(import_id, oid, tag, raw_value, section_name, inventory_key, graphable, data_template_id, graph_template_id)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
				[
					$import_id,
					$record["oid"],
					$record["tag"],
					$record["value"],
					$record["section"],
					$inventory_key,
					$record["graphable"] ? "on" : "",
					$data_template_id,
					$graph_template_id,
				],
			);
		}

		$target_path = nms_snmprec_deploy($community, $content);
		db_execute_prepared("UPDATE plugin_nms_snmprec_imports SET deployed_path = ? WHERE id = ?", [
			$target_path,
			$import_id,
		]);
		db_execute("COMMIT");
		return [
			"import_id" => $import_id,
			"host_template_created" => $host_template_created,
			"host_template_id" => $host_template_id,
			"graphable_count" => $graphable_count,
			"deployed_path" => $target_path,
		];
	} catch (Throwable $exception) {
		db_execute("ROLLBACK");
		if ($target_path !== "" && is_file($target_path)) {
			@unlink($target_path);
		}
		throw $exception;
	}
}

/** Retired automatic rename migration: preserve operator edits and RRD data-source identities. */
function nms_template_upgrade_readable_names()
{
	throw new LogicException(
		"Automatic imported-template renaming is retired. Review names in the native Cacti template editors; no template or data-source identity was changed.",
	);
}
