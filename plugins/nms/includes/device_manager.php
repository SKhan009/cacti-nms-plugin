<?php
/**
 * @file device_manager.php
 * Validate device-management requests and coordinate native Cacti device, graph, and data-query operations.
 * Imported templates are activated through core graph creation so the Cacti poller, not import records, supplies readings.
 */

require_once __DIR__ . "/device_metadata.php";
require_once __DIR__ . "/topology_appearance.php";
require_once __DIR__ . "/core_form_options.php";
require_once $config["base_path"] . "/lib/api_device.php";
require_once $config["base_path"] . "/lib/api_automation.php";
// Native device automation can place a saved device on a graph tree.
require_once $config["base_path"] . "/lib/api_tree.php";
require_once $config["base_path"] . "/lib/api_graph.php";
require_once $config["base_path"] . "/lib/data_query.php";
require_once $config["base_path"] . "/lib/template.php";
// Load Cacti's native poller-cache refresh helper used after creating graph data sources.
require_once $config["base_path"] . "/lib/utility.php";

/** Return a validated, nondeleted Cacti device ID or raise an invalid-input exception. */
function nms_device_require($device_id)
{
	$device_id = (int) $device_id;
	if (
		$device_id < 1 ||
		!(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM host WHERE id = ? AND deleted = ''", [$device_id])
	) {
		throw new InvalidArgumentException("Select a valid Cacti device.");
	}
	return $device_id;
}

/** Validate and attach a reusable graph template to a Cacti device. */
function nms_device_add_graph_template($device_id, $graph_template_id)
{
	$device_id = nms_device_require($device_id);
	$graph_template_id = (int) $graph_template_id;
	if (
		$graph_template_id < 1 ||
		!(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM graph_templates WHERE id = ?", [$graph_template_id])
	) {
		throw new InvalidArgumentException("Select a valid Cacti graph template.");
	}

	db_execute_prepared("REPLACE INTO host_graph (host_id, graph_template_id) VALUES (?, ?)", [
		$device_id,
		$graph_template_id,
	]);
	automation_hook_graph_template($device_id, $graph_template_id);
	api_plugin_hook_function("add_graph_template_to_host", [
		"host_id" => $device_id,
		"graph_template_id" => $graph_template_id,
	]);
	return $graph_template_id;
}

/** Remove a validated device-to-graph-template association through Cacti's device API. */
function nms_device_remove_graph_template($device_id, $graph_template_id)
{
	$device_id = nms_device_require($device_id);
	$graph_template_id = (int) $graph_template_id;
	if (
		$graph_template_id < 1 ||
		!(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM host_graph WHERE host_id = ? AND graph_template_id = ?", [
			$device_id,
			$graph_template_id,
		])
	) {
		throw new InvalidArgumentException("Select a graph template associated with this device.");
	}
	api_device_gt_remove($device_id, $graph_template_id);
	return $graph_template_id;
}

/** Validate a compatible data query and reindex method, then attach it to the device. */
function nms_device_add_data_query($device_id, $data_query_id, $reindex_method)
{
	global $reindex_types;
	$device_id = nms_device_require($device_id);
	$data_query_id = (int) $data_query_id;
	$reindex_method = (int) $reindex_method;
	$snmp_version = (int) db_fetch_cell_prepared("SELECT snmp_version FROM host WHERE id = ? AND deleted = ''", [
		$device_id,
	]);
	// Input record IDs vary by installation; protocol is identified by native input type.
	$sql =
		"SELECT COUNT(*) FROM snmp_query AS sq INNER JOIN data_input AS di ON di.id = sq.data_input_id WHERE sq.id = ?";
	$params = [$data_query_id];
	if ($snmp_version === 0) {
		$sql .= " AND di.type_id NOT IN (?, ?)";
		$params[] = DATA_INPUT_TYPE_SNMP;
		$params[] = DATA_INPUT_TYPE_SNMP_QUERY;
	}
	if ($data_query_id < 1 || !(int) db_fetch_cell_prepared($sql, $params)) {
		throw new InvalidArgumentException("Select a valid Cacti data query for this device.");
	}
	if (!isset($reindex_types[$reindex_method])) {
		throw new InvalidArgumentException("Select a valid re-index method.");
	}

	api_device_dq_add($device_id, $data_query_id, $reindex_method);
	return $data_query_id;
}

/** Return validated device and data-query IDs only when their Cacti association exists. */
function nms_device_require_data_query($device_id, $data_query_id)
{
	$device_id = nms_device_require($device_id);
	$data_query_id = (int) $data_query_id;
	if (
		$data_query_id < 1 ||
		!(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM host_snmp_query WHERE host_id = ? AND snmp_query_id = ?", [
			$device_id,
			$data_query_id,
		])
	) {
		throw new InvalidArgumentException("Select a data query associated with this device.");
	}
	return [$device_id, $data_query_id];
}

/** Validate and update an associated query's reindex method through Cacti's API. */
function nms_device_change_data_query($device_id, $data_query_id, $reindex_method)
{
	global $reindex_types;
	[$device_id, $data_query_id] = nms_device_require_data_query($device_id, $data_query_id);
	$reindex_method = (int) $reindex_method;
	if (!isset($reindex_types[$reindex_method])) {
		throw new InvalidArgumentException("Select a valid re-index method.");
	}
	api_device_dq_change($device_id, $data_query_id, $reindex_method);
	return $data_query_id;
}

/** Run an associated Cacti data query immediately and return its ID. */
function nms_device_reload_data_query($device_id, $data_query_id)
{
	[$device_id, $data_query_id] = nms_device_require_data_query($device_id, $data_query_id);
	run_data_query($device_id, $data_query_id);
	return $data_query_id;
}

/** Force all device queries to reindex and return query, cache-item, and timing statistics. */
function nms_device_reindex($device_id)
{
	$device_id = nms_device_require($device_id);
	$queries = db_fetch_assoc_prepared(
		"SELECT snmp_query_id FROM host_snmp_query WHERE host_id = ? ORDER BY snmp_query_id",
		[$device_id],
	);
	$started_at = microtime(true);
	foreach ($queries as $query) {
		run_data_query($device_id, (int) $query["snmp_query_id"], false, true);
	}
	return [
		"query_count" => count($queries),
		"item_count" => (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM host_snmp_cache WHERE host_id = ?", [
			$device_id,
		]),
		"seconds" => microtime(true) - $started_at,
	];
}

/** Validate and remove the device's data-query association through Cacti's API. */
function nms_device_remove_data_query($device_id, $data_query_id)
{
	[$device_id, $data_query_id] = nms_device_require_data_query($device_id, $data_query_id);
	api_device_dq_remove($device_id, $data_query_id);
	return $data_query_id;
}

/** Validate imported simulator settings when present, then create the device through the shared save path. */
function nms_device_create($input)
{
	$category_id = nms_new_device_category($input["equipment_category_id"] ?? "", $input["host_template_id"]);
	$device_type = nms_classification_text($input["device_type"] ?? "", 150);
	if ($device_type === "") {
		$template_name = (string) db_fetch_cell_prepared("SELECT name FROM host_template WHERE id = ?", [
			(int) ($input["host_template_id"] ?? 0),
		]);
		$device_type = nms_device_type_auto($input["description"] ?? "", $template_name);
	}
	$device_role = nms_classification_text($input["device_role"] ?? "", 150);
	// Reject malformed metadata before creating a core device. It is never passed to Cacti's device API.
	$manual_serial = nms_manual_serial_validate($input["manual_serial_number"] ?? "");
	if (!empty($input["snmpsim_import_id"])) {
		require_once __DIR__ . "/snmpsim.php";
		$defaults = nms_snmpsim_import_defaults($input["snmpsim_import_id"]);
		foreach (
			["hostname", "snmp_port", "snmp_community", "host_template_id", "snmp_version", "poller_id"]
			as $field
		) {
			if ((string) $input[$field] !== (string) $defaults[$field]) {
				throw new InvalidArgumentException(
					"Simulator connection settings changed. Reopen Add device from the imported record. Use normal Add device for a real SNMP target.",
				);
			}
		}
		$input["proxy"] = true;
	}
	$device_id = nms_device_save(0, $input);
	nms_shared_endpoint_save($device_id, !empty($input["proxy"]));
	try {
		nms_device_classification_save($device_id, $category_id, $device_type, $device_role);
	} catch (Throwable $exception) {
		throw new RuntimeException(
			"Device " .
				$device_id .
				" was created, but its classification was not saved. Open Edit device for this ID; do not create a duplicate.",
			0,
			$exception,
		);
	}
	if ($manual_serial !== "") {
		try {
			nms_manual_serial_save($device_id, $manual_serial);
		} catch (Throwable $exception) {
			// The core API has already saved the host; do not encourage creating a duplicate on retry.
			throw new RuntimeException(
				"Device " .
					$device_id .
					" was created, but its manual serial was not saved. Open Edit device for this ID and save the serial there.",
				0,
				$exception,
			);
		}
	}
	return $device_id;
}

/** Require an existing device before passing submitted fields to the shared Cacti save path. */
function nms_device_update($device_id, $input)
{
	$device_id = nms_device_require($device_id);
	$saved_id = nms_device_save($device_id, $input);
	nms_shared_endpoint_save($saved_id, !empty($input["proxy"]));
	return $saved_id;
}

/** Delete only an NMS-managed device through Cacti and clean up its plugin-owned records. */
function nms_device_delete($device_id)
{
	$device_id = nms_device_require($device_id);
	if (!nms_managed_object_exists("device", $device_id)) {
		throw new InvalidArgumentException("Only a device created through NMS can be deleted here.");
	}
	api_device_remove($device_id);
	// The native delete API owns Cacti records. Remove only transient NMS state,
	// retain incident/audit history, and archive connection evidence for review.
	nms_storage_execute("START TRANSACTION");
	try {
		foreach (
			[
				"plugin_nms_topology",
				"plugin_nms_rack_devices",
				"plugin_nms_group_members",
				"plugin_nms_device_classification",
				"plugin_nms_device_parameters",
				"plugin_nms_device_inventory",
				"plugin_nms_device_metadata",
			]
			as $table
		) {
			nms_storage_execute("DELETE FROM " . $table . " WHERE host_id = ?", [$device_id]);
		}
		nms_storage_execute(
			'UPDATE plugin_nms_relationships SET archived_at = COALESCE(archived_at, NOW()), updated_at = NOW()
			WHERE source_host_id = ? OR target_host_id = ?',
			[$device_id, $device_id],
		);
		nms_storage_execute("DELETE FROM plugin_nms_managed_objects WHERE object_type = ? AND object_id = ?", [
			"device",
			$device_id,
		]);
		nms_storage_execute("COMMIT");
	} catch (Throwable $error) {
		db_execute("ROLLBACK");
		throw new RuntimeException(
			"Cacti deleted device " .
				$device_id .
				", but NMS cleanup is incomplete. Run the reviewed cleanup before reusing this ID.",
			0,
			$error,
		);
	}
	return $device_id;
}

/**
 * Instantiate imported graph templates through Cacti's supported template API.
 *
 * A host-template association alone only makes a graph selectable; it does not
 * create the local data source or poller item that produces a live reading.
 * This function follows Cacti's own add_graphs.php path and never inserts a
 * reading into an NMS table.
 */
/**
 * Handles device activate imported templates.
 */
function nms_device_activate_imported_templates($device_id, $host_template_id)
{
	$device_id = nms_device_require($device_id);
	$host_template_id = (int) $host_template_id;
	$templates = db_fetch_assoc_prepared(
		"SELECT DISTINCT o.graph_template_id
		FROM plugin_nms_snmprec_imports AS i
		INNER JOIN plugin_nms_snmprec_oids AS o ON o.import_id = i.id
		WHERE i.host_template_id = ? AND o.graphable = 'on' AND o.graph_template_id > 0
		AND NOT EXISTS (SELECT 1 FROM graph_local AS gl
			WHERE gl.host_id = ? AND gl.graph_template_id = o.graph_template_id)
		ORDER BY o.graph_template_id",
		[$host_template_id, $device_id],
	);
	$created = 0;

	foreach ($templates as $template) {
		$graph_template_id = (int) $template["graph_template_id"];
		/* Cacti accepts suggested values by reference, so pass a real variable. */
		$suggested_values = [];
		$result = create_complete_graph_from_template($graph_template_id, $device_id, null, $suggested_values);
		if (!is_array($result) || empty($result["local_graph_id"])) {
			throw new RuntimeException("Cacti could not activate imported graph template " . $graph_template_id . ".");
		}
		$local_data_ids = isset($result["local_data_id"]) ? (array) $result["local_data_id"] : [];
		foreach ($local_data_ids as $local_data_id) {
			if ((int) $local_data_id > 0) {
				push_out_host($device_id, (int) $local_data_id);
			}
		}
		db_execute_prepared("REPLACE INTO host_graph (host_id, graph_template_id) VALUES (?, ?)", [
			$device_id,
			$graph_template_id,
		]);
		$created++;
	}

	if ($created > 0) {
		set_config_option("time_last_change_graph", time());
		set_config_option("time_last_change_data_source", time());
	}
	return $created;
}

/** Bring devices created before this fix onto the same Cacti-core polling path. */
function nms_device_reconcile_imported_templates()
{
	$devices = db_fetch_assoc("SELECT DISTINCT h.id, h.host_template_id
		FROM host AS h
		INNER JOIN plugin_nms_snmprec_imports AS i ON i.host_template_id = h.host_template_id
		WHERE h.deleted = '' AND h.disabled = '' AND EXISTS (
			SELECT 1 FROM plugin_nms_snmprec_oids AS o
			WHERE o.import_id = i.id AND o.graphable = 'on' AND o.graph_template_id > 0
			AND NOT EXISTS (SELECT 1 FROM graph_local AS gl
				WHERE gl.host_id = h.id AND gl.graph_template_id = o.graph_template_id)
		)");
	$created = 0;
	foreach ($devices as $device) {
		try {
			$created += nms_device_activate_imported_templates($device["id"], $device["host_template_id"]);
		} catch (Throwable $exception) {
			/* Never abort the Cacti poller because one imported template is invalid. */
			cacti_log(
				"Could not activate imported readings for device " .
					(int) $device["id"] .
					": " .
					$exception->getMessage(),
				false,
				"NMS",
			);
		}
	}
	return $created;
}

/** Validate device, collector, and protocol settings, then persist them using Cacti's device workflow. */
function nms_device_save($device_id, $input)
{
	global $snmp_versions,
		$snmp_auth_protocols,
		$snmp_priv_protocols,
		$availability_options,
		$ping_methods,
		$fields_host_edit;
	$device_id = (int) $device_id;
	$is_new_device = $device_id === 0;
	$description = trim((string) $input["description"]);
	$hostname = trim((string) $input["hostname"]);
	$template_id = (int) $input["host_template_id"];
	$site_id = (int) $input["site_id"];
	$poller_id = (int) $input["poller_id"];
	$snmp_version = (int) $input["snmp_version"];
	$snmp_port = (int) $input["snmp_port"];
	$snmp_timeout = (int) $input["snmp_timeout"];
	$community = trim((string) $input["snmp_community"]);
	$snmp_username = trim((string) $input["snmp_username"]);
	$snmp_password = (string) $input["snmp_password"];
	$snmp_auth_protocol = trim((string) $input["snmp_auth_protocol"]);
	$snmp_priv_protocol = trim((string) $input["snmp_priv_protocol"]);
	$snmp_priv_passphrase = (string) $input["snmp_priv_passphrase"];
	$proxy = !empty($input["proxy"]);
	foreach (["max_oids", "device_threads"] as $field_name) {
		$input[$field_name] = nms_core_field_value($field_name, $fields_host_edit[$field_name], $input[$field_name]);
	}

	if ($description === "" || $hostname === "") {
		throw new InvalidArgumentException("Device name and hostname are required.");
	}
	if (!array_key_exists($snmp_version, $snmp_versions)) {
		throw new InvalidArgumentException("Select an SNMP version supported by Cacti.");
	}
	if ($snmp_port < 1 || $snmp_port > 65535) {
		throw new InvalidArgumentException("SNMP port must be between 1 and 65535.");
	}
	if ($snmp_timeout < 1) {
		throw new InvalidArgumentException("SNMP timeout must be positive.");
	}
	if (in_array($snmp_version, [1, 2], true) && $community === "") {
		throw new InvalidArgumentException("Enter the SNMP community for version 1 or 2c.");
	}
	if ($snmp_version === 3) {
		$allowed_auth_protocols = array_keys($snmp_auth_protocols);
		$allowed_priv_protocols = array_keys($snmp_priv_protocols);
		if ($snmp_username === "") {
			throw new InvalidArgumentException("Enter the SNMP v3 username.");
		}
		if (!in_array($snmp_auth_protocol, $allowed_auth_protocols, true)) {
			throw new InvalidArgumentException("Select a valid SNMP v3 authentication method.");
		}
		if (!in_array($snmp_priv_protocol, $allowed_priv_protocols, true)) {
			throw new InvalidArgumentException("Select a valid SNMP v3 privacy method.");
		}
		if ($snmp_auth_protocol !== "[None]" && strlen($snmp_password) < 8) {
			throw new InvalidArgumentException("SNMP v3 authentication password must contain at least 8 characters.");
		}
		if ($snmp_auth_protocol === "[None]" && $snmp_priv_protocol !== "[None]") {
			throw new InvalidArgumentException("SNMP v3 privacy requires authentication.");
		}
		if ($snmp_priv_protocol !== "[None]" && strlen($snmp_priv_passphrase) < 8) {
			throw new InvalidArgumentException("SNMP v3 privacy passphrase must contain at least 8 characters.");
		}
		$community = "";
	} else {
		$snmp_username = "";
		$snmp_password = "";
		$snmp_auth_protocol = "[None]";
		$snmp_priv_protocol = "[None]";
		$snmp_priv_passphrase = "";
	}
	if (
		$site_id < 0 ||
		($site_id !== 0 && !(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM sites WHERE id=?", [$site_id]))
	) {
		throw new InvalidArgumentException("Select an existing Cacti site.");
	}
	$current_site = nms_single_topology_site();
	if ($current_site && $site_id !== $current_site) {
		throw new InvalidArgumentException(
			"This topology currently uses one location. Select its site for this device.",
		);
	}
	if (
		$template_id !== 0 &&
		!(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM host_template WHERE id = ?", [$template_id])
	) {
		throw new InvalidArgumentException("Select a valid Cacti host template.");
	}
	if (!(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM poller WHERE id = ?", [$poller_id])) {
		throw new InvalidArgumentException("Select a valid data collector.");
	}
	if (
		(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM host WHERE description = ? AND id != ? AND deleted = ''", [
			$description,
			$device_id,
		])
	) {
		throw new InvalidArgumentException("A Cacti device already uses this name.");
	}
	if (
		!$proxy &&
		(int) db_fetch_cell_prepared(
			"SELECT COUNT(*) FROM host WHERE hostname = ? AND snmp_port = ? AND snmp_community = ? AND id != ? AND deleted = ''",
			[$hostname, $snmp_port, $community, $device_id],
		)
	) {
		throw new InvalidArgumentException(
			"This SNMP endpoint already exists. Enable proxy/simulator mode to share an address.",
		);
	}

	$availability = (int) $input["availability_method"];
	if (!array_key_exists($availability, $availability_options)) {
		throw new InvalidArgumentException("Select an availability method from Cacti.");
	}
	$ping_method = (int) $input["ping_method"];
	if (!array_key_exists($ping_method, $ping_methods)) {
		throw new InvalidArgumentException("Select a ping method from Cacti.");
	}
	if (
		(int) $input["ping_port"] < 0 ||
		(int) $input["ping_port"] > 65535 ||
		(int) $input["ping_timeout"] < 1 ||
		(int) $input["ping_retries"] < 0
	) {
		throw new InvalidArgumentException("Invalid ping port, timeout, or retries.");
	}

	$saved_device_id = api_device_save(
		$device_id,
		$template_id,
		$description,
		$hostname,
		$community,
		$snmp_version,
		$snmp_username,
		$snmp_password,
		$snmp_port,
		$snmp_timeout,
		!empty($input["disabled"]) ? "on" : "",
		$availability,
		$ping_method,
		(int) $input["ping_port"],
		(int) $input["ping_timeout"],
		(int) $input["ping_retries"],
		trim((string) $input["notes"]),
		$snmp_auth_protocol,
		$snmp_priv_passphrase,
		$snmp_priv_protocol,
		trim((string) $input["snmp_context"]),
		trim((string) $input["snmp_engine_id"]),
		(int) $input["max_oids"],
		(int) $input["device_threads"],
		$poller_id,
		$site_id,
		trim((string) $input["external_id"]),
		trim((string) $input["location"]),
		-1,
	);
	if (!$saved_device_id) {
		throw new RuntimeException("Cacti could not save the device. Check the submitted SNMP settings.");
	}
	if ($is_new_device) {
		nms_managed_object_record("device", (int) $saved_device_id);
	}
	if (empty($input["disabled"])) {
		/*
		 * Imported numeric OIDs become real Cacti poller items immediately. If
		 * one template is temporarily invalid, keep the already-saved Cacti host;
		 * the poller reconciliation path retries activation on its next cycle.
		 */
		try {
			nms_device_activate_imported_templates((int) $saved_device_id, $template_id);
		} catch (Throwable $exception) {
			cacti_log(
				"Device " .
					(int) $saved_device_id .
					" was saved, but imported readings could not be activated: " .
					$exception->getMessage(),
				false,
				"NMS",
			);
		}
	}
	return (int) $saved_device_id;
}
