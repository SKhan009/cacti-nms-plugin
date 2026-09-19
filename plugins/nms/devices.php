<?php
/**
 * @file devices.php
 * Device Management controller: dispatch device, data-query, graph-template, and SNMP-record actions, then load the selected view.
 * Device facts and reusable templates belong to Cacti core; NMS stores import metadata and its own configuration.
 */

require __DIR__ . "/../../include/auth.php";
// Load at global scope so the native definitions retain access to Cacti's option arrays.
require_once $config["base_path"] . "/include/global_form.php";
require_once $config["base_path"] . "/plugins/nms/includes/functions.php";
require_once $config["base_path"] . "/plugins/nms/includes/database.php";
require_once $config["base_path"] . "/plugins/nms/includes/readings.php";
require_once $config["base_path"] . "/plugins/nms/includes/graphs.php";
require_once $config["base_path"] . "/plugins/nms/includes/snmprec.php";
require_once $config["base_path"] . "/plugins/nms/includes/template_manager.php";
require_once __DIR__ . "/includes/mib_import.php";
require_once __DIR__ . "/includes/discovery.php";
require_once __DIR__ . "/includes/diagnostics.php";
require_once $config["base_path"] . "/plugins/nms/includes/device_manager.php";
require_once $config["base_path"] . "/plugins/nms/includes/graph_template_manager.php";

// Require an explicit lifecycle upgrade; viewing devices never renames core templates.
nms_require_database();

if ($_SERVER["REQUEST_METHOD"] === "GET" && get_nfilter_request_var("nms_status") === "snmpsim") {
	header("Content-Type: application/json");
	header("Cache-Control: no-store");
	print json_encode(nms_snmpsim_status_badge(nms_snmpsim_health()));
	exit();
}

// Whitelist the view name before dispatching to its template and initializing page messages.
$allowed_tabs = ["inventory", "add", "edit", "readings", "graphs", "import"];
$tab = isset_request_var("tab") ? get_nfilter_request_var("tab") : "inventory";
$template_workspace = defined("NMS_TEMPLATE_WORKSPACE") && NMS_TEMPLATE_WORKSPACE;
$repository_workspace = defined("NMS_FILE_REPOSITORY") && NMS_FILE_REPOSITORY;
if ($repository_workspace) {
	$tab = "import";
}
if (!$repository_workspace && $tab === "import") {
	$query = $_GET;
	unset($query["tab"]);
	header(
		"Location: file_repository.php" . ($query ? "?" . http_build_query($query) : ""),
		true,
		$_SERVER["REQUEST_METHOD"] === "POST" ? 307 : 302,
	);
	exit();
}
if (
	$repository_workspace &&
	$_SERVER["REQUEST_METHOD"] === "POST" &&
	!in_array(
		get_nfilter_request_var("nms_action"),
		["import_snmprec", "mib_preview", "mib_create", "check_snmpsim", "control_snmpsim"],
		true,
	)
) {
	http_response_code(400);
	die("Unsupported repository action.");
}
if ($template_workspace) {
	$tab = "graphs";
}
if (
	!$template_workspace &&
	($tab === "graphs" ||
		in_array(get_nfilter_request_var("nms_action"), ["create_graph_template", "delete_graph_template"], true))
) {
	// Preserve legacy bookmarks and POST bodies while moving the workflow out of Devices.
	header(
		"Location: templates.php?section=graph&view=builder",
		true,
		$_SERVER["REQUEST_METHOD"] === "POST" ? 307 : 302,
	);
	exit();
}
if (!in_array($tab, $allowed_tabs, true)) {
	$tab = "inventory";
}
$page_error = "";
$simulator_message = "";

// Handle writes before page output; shared helpers perform validation and use Cacti's core object workflows.
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset_request_var("nms_action")) {
	$action = get_nfilter_request_var("nms_action");
	if ($template_workspace && !in_array($action, ["create_graph_template", "delete_graph_template"], true)) {
		http_response_code(400);
		die("Unsupported template action.");
	}
	try {
		// The NMS view realm is not write permission for native Cacti objects.
		$action_realm = in_array($action, ["create_graph_template", "delete_graph_template"], true) ? 10 : 3;
		nms_require_management($action_realm);
		if (!csrf_check_tokens($_POST["__csrf_magic"] ?? "")) {
			throw new RuntimeException("Invalid request token.");
		}
		if (in_array($action, ["add_device", "update_device"], true)) {
			$discovery_assignment = nms_nd_assignment_validate($_POST);
			$diagnostic_assignment = nms_diag_assignment_validate($_POST);
			$identity_manual = nms_identity_manual_validate($_POST);
			$short_name = nms_short_name_validate($_POST["short_name"] ?? "");
			nms_manual_serial_validate($_POST["manual_serial_number"] ?? "");
		}
		if ($action === "discovery_test") {
			$device_id = get_filter_request_var("id");
			$site = db_fetch_cell_prepared("SELECT site_id FROM host WHERE id=? AND deleted=''", [$device_id]);
			nms_nd_test_queue($device_id, $site);
			header(
				"Location: devices.php?tab=edit&id=" . (int) $device_id . "&discovery_queued=1#discovery-connections",
			);
			exit();
		}
		if ($action === "control_snmpsim") {
			$tab = "import";
			$simulator_message = nms_snmpsim_queue_control(get_nfilter_request_var("simulator_command"));
		}
		if (isset_request_var("id")) {
			nms_require_device_access(get_filter_request_var("id"));
		}
		if ($action === "save_classification") {
			$device_id = get_filter_request_var("id");
			nms_device_classification_save(
				$device_id,
				get_filter_request_var("equipment_category_id"),
				($submitted_type = get_nfilter_request_var("device_type")) !== ""
					? $submitted_type
					: nms_device_type_auto(
						get_nfilter_request_var("description"),
						(string) db_fetch_cell_prepared("SELECT name FROM host_template WHERE id=?", [
							(int) get_filter_request_var("host_template_id"),
						]),
					),
				(string) db_fetch_cell_prepared(
					"SELECT device_role FROM plugin_nms_device_classification WHERE host_id = ?",
					[$device_id],
				),
			);
			header("Location: devices.php?tab=edit&id=" . (int) $device_id . "&classification_saved=1#classification");
			exit();
		}
		if ($action === "save_manual_serial") {
			// Dedicated metadata action: no Cacti device-save API, SNMP poll, or fault-baseline write.
			$device_id = get_filter_request_var("id");
			if (!isset_request_var("manual_serial_number")) {
				throw new InvalidArgumentException("The serial number field was not submitted.");
			}
			nms_manual_serial_save($device_id, get_nfilter_request_var("manual_serial_number"));
			header("Location: devices.php?tab=edit&id=" . (int) $device_id . "&serial_saved=1#manual-serial");
			exit();
		}
		if ($action === "check_snmpsim") {
			$tab = "import";
			$simulator_message = nms_snmpsim_probe_import(get_filter_request_var("import_id"));
		}
		if ($action === "reindex_device") {
			$device_id = (int) get_filter_request_var("id");
			$reindex_result = nms_device_reindex($device_id);
			header(
				"Location: devices.php?tab=edit&id=" .
					$device_id .
					"&device_reindexed=1&reindex_queries=" .
					(int) $reindex_result["query_count"] .
					"&reindex_items=" .
					(int) $reindex_result["item_count"] .
					"&reindex_seconds=" .
					rawurlencode(number_format((float) $reindex_result["seconds"], 2, ".", "")),
			);
			exit();
		}

		if ($action === "delete_device") {
			$device_id = nms_device_delete(get_filter_request_var("id"));
			header("Location: devices.php?tab=inventory&device_deleted=" . $device_id);
			exit();
		}

		if ($action === "remove_graph_template") {
			$device_id = (int) get_filter_request_var("id");
			$template_id = nms_device_remove_graph_template($device_id, get_filter_request_var("graph_template_id"));
			header(
				"Location: devices.php?tab=edit&id=" .
					$device_id .
					"&graph_template_removed=" .
					$template_id .
					"#graph-templates",
			);
			exit();
		}

		if ($action === "delete_graph_template") {
			$template_id = nms_graph_template_delete(get_filter_request_var("graph_template_id"));
			header(
				"Location: templates.php?section=graph&view=builder&graph_template_deleted=" .
					$template_id .
					"#graph-template-list",
			);
			exit();
		}

		if ($action === "create_graph_template") {
			// Create a reusable graph definition, not a fabricated reading or a device-specific graph instance.
			$graph_template_id = nms_graph_template_create(
				get_filter_request_var("data_template_rrd_id"),
				get_nfilter_request_var("graph_name"),
				get_nfilter_request_var("vertical_label"),
				array_merge(nms_core_graph_request(), [
					"graph_style" => get_nfilter_request_var("graph_style"),
					"text_format" => get_nfilter_request_var("text_format"),
					"item_value" => get_nfilter_request_var("item_value"),
					"line_width" => get_nfilter_request_var("line_width"),
					"dashes" => get_nfilter_request_var("dashes"),
					"dash_offset" => get_nfilter_request_var("dash_offset"),
					"textalign" => get_nfilter_request_var("textalign"),
					"hard_return" => get_nfilter_request_var("hard_return") === "on",
					"shift" => get_nfilter_request_var("shift") === "on",
					"shift_seconds" => get_nfilter_request_var("shift_seconds"),
					"stack_source_id" => get_filter_request_var("stack_source_id"),
					"vdef_id" => isset_request_var("vdef_id")
						? get_nfilter_request_var("vdef_id")
						: $struct_graph_item["vdef_id"]["default"],
					"consolidation_function_id" => isset_request_var("consolidation_function_id")
						? get_nfilter_request_var("consolidation_function_id")
						: key($consolidation_functions),
					"color_id" => isset_request_var("color_id")
						? get_nfilter_request_var("color_id")
						: $struct_graph_item["color_id"]["default"],
					"alpha" => isset_request_var("alpha")
						? get_nfilter_request_var("alpha")
						: $struct_graph_item["alpha"]["default"],
					"cdef_id" => isset_request_var("cdef_id")
						? get_nfilter_request_var("cdef_id")
						: $struct_graph_item["cdef_id"]["default"],
					"gprint_id" => isset_request_var("gprint_id")
						? get_nfilter_request_var("gprint_id")
						: $struct_graph_item["gprint_id"]["default"],
					"show_current" => isset_request_var("show_current"),
					"show_minimum" => isset_request_var("show_minimum"),
					"show_average" => isset_request_var("show_average"),
					"show_maximum" => isset_request_var("show_maximum"),
				]),
			);
			header(
				"Location: templates.php?section=graph&view=builder&graph_template_created=" .
					$graph_template_id .
					"#graph-builder",
			);
			exit();
		}

		if ($action === "change_data_query") {
			$device_id = (int) get_filter_request_var("id");
			$query_id = nms_device_change_data_query(
				$device_id,
				get_filter_request_var("snmp_query_id"),
				get_filter_request_var("reindex_method"),
			);
			header(
				"Location: devices.php?tab=edit&id=" .
					$device_id .
					"&data_query_changed=" .
					$query_id .
					"#data-queries",
			);
			exit();
		}

		if ($action === "reload_data_query") {
			$device_id = (int) get_filter_request_var("id");
			$query_id = nms_device_reload_data_query($device_id, get_filter_request_var("snmp_query_id"));
			header(
				"Location: devices.php?tab=edit&id=" .
					$device_id .
					"&data_query_reloaded=" .
					$query_id .
					"#data-queries",
			);
			exit();
		}

		if ($action === "remove_data_query") {
			$device_id = (int) get_filter_request_var("id");
			$query_id = nms_device_remove_data_query($device_id, get_filter_request_var("snmp_query_id"));
			header(
				"Location: devices.php?tab=edit&id=" .
					$device_id .
					"&data_query_removed=" .
					$query_id .
					"#data-queries",
			);
			exit();
		}

		if ($action === "add_graph_template") {
			$device_id = (int) get_filter_request_var("id");
			$template_id = nms_device_add_graph_template($device_id, get_filter_request_var("graph_template_id"));
			header(
				"Location: devices.php?tab=edit&id=" .
					$device_id .
					"&graph_template_added=" .
					$template_id .
					"#graph-templates",
			);
			exit();
		}

		if ($action === "add_data_query") {
			$device_id = (int) get_filter_request_var("id");
			$query_id = nms_device_add_data_query(
				$device_id,
				get_filter_request_var("snmp_query_id"),
				get_filter_request_var("reindex_method"),
			);
			header(
				"Location: devices.php?tab=edit&id=" . $device_id . "&data_query_added=" . $query_id . "#data-queries",
			);
			exit();
		}

		if ($action === "add_device") {
			// Read protocol and availability settings for the shared save helper; simulator imports get explicit checks.
			$device_id = nms_device_create([
				"equipment_category_id" => get_nfilter_request_var("equipment_category_id"),
				"device_type" => get_nfilter_request_var("device_type"),
				"device_role" => get_nfilter_request_var("device_role"),
				"manual_serial_number" => isset_request_var("manual_serial_number")
					? get_nfilter_request_var("manual_serial_number")
					: "",
				"snmpsim_import_id" => isset_request_var("snmpsim_import_id")
					? get_filter_request_var("snmpsim_import_id")
					: 0,
				"description" => get_nfilter_request_var("description"),
				"hostname" => get_nfilter_request_var("hostname"),
				"host_template_id" => get_filter_request_var("host_template_id"),
				"site_id" => get_filter_request_var("site_id"),
				"poller_id" => get_filter_request_var("poller_id"),
				"snmp_version" => get_filter_request_var("snmp_version"),
				"snmp_community" => get_nfilter_request_var("snmp_community"),
				"snmp_port" => get_filter_request_var("snmp_port"),
				"snmp_timeout" => get_filter_request_var("snmp_timeout"),
				"snmp_username" => get_nfilter_request_var("snmp_username"),
				"snmp_password" => get_nfilter_request_var("snmp_password"),
				"snmp_auth_protocol" => get_nfilter_request_var("snmp_auth_protocol"),
				"snmp_priv_passphrase" => get_nfilter_request_var("snmp_priv_passphrase"),
				"snmp_priv_protocol" => get_nfilter_request_var("snmp_priv_protocol"),
				"snmp_context" => get_nfilter_request_var("snmp_context"),
				"snmp_engine_id" => get_nfilter_request_var("snmp_engine_id"),
				"availability_method" => get_filter_request_var("availability_method"),
				"ping_method" => get_filter_request_var("ping_method"),
				"ping_port" => get_filter_request_var("ping_port"),
				"ping_timeout" => get_filter_request_var("ping_timeout"),
				"ping_retries" => get_filter_request_var("ping_retries"),
				"max_oids" => get_filter_request_var("max_oids"),
				"device_threads" => get_filter_request_var("device_threads"),
				"notes" => get_nfilter_request_var("notes"),
				"location" => get_nfilter_request_var("location"),
				"external_id" => get_nfilter_request_var("external_id"),
				// Checkboxes are absent from POST when clear. Read the submitted form
				// directly so their state is not affected by Cacti request filtering.
				"proxy" => array_key_exists("proxy", $_POST),
				"disabled" => array_key_exists("disabled", $_POST),
			]);
			nms_nd_assignment_write($device_id, $discovery_assignment);
			nms_diag_assignment_write($device_id, $diagnostic_assignment);
			nms_identity_manual_save($device_id, $identity_manual);
			nms_short_name_save($device_id, $short_name);
			header(
				"Location: devices.php?tab=edit&id=" .
					$device_id .
					"&device_created=" .
					$device_id .
					"#graph-templates",
			);
			exit();
		}

		if ($action === "update_device") {
			$device_id = nms_device_update(get_filter_request_var("id"), [
				"description" => get_nfilter_request_var("description"),
				"hostname" => get_nfilter_request_var("hostname"),
				"host_template_id" => get_filter_request_var("host_template_id"),
				"site_id" => get_filter_request_var("site_id"),
				"poller_id" => get_filter_request_var("poller_id"),
				"snmp_version" => get_filter_request_var("snmp_version"),
				"snmp_community" => get_nfilter_request_var("snmp_community"),
				"snmp_port" => get_filter_request_var("snmp_port"),
				"snmp_timeout" => get_filter_request_var("snmp_timeout"),
				"snmp_username" => get_nfilter_request_var("snmp_username"),
				"snmp_password" => get_nfilter_request_var("snmp_password"),
				"snmp_auth_protocol" => get_nfilter_request_var("snmp_auth_protocol"),
				"snmp_priv_passphrase" => get_nfilter_request_var("snmp_priv_passphrase"),
				"snmp_priv_protocol" => get_nfilter_request_var("snmp_priv_protocol"),
				"snmp_context" => get_nfilter_request_var("snmp_context"),
				"snmp_engine_id" => get_nfilter_request_var("snmp_engine_id"),
				"availability_method" => get_filter_request_var("availability_method"),
				"ping_method" => get_filter_request_var("ping_method"),
				"ping_port" => get_filter_request_var("ping_port"),
				"ping_timeout" => get_filter_request_var("ping_timeout"),
				"ping_retries" => get_filter_request_var("ping_retries"),
				"max_oids" => get_filter_request_var("max_oids"),
				"device_threads" => get_filter_request_var("device_threads"),
				"notes" => get_nfilter_request_var("notes"),
				"location" => get_nfilter_request_var("location"),
				"external_id" => get_nfilter_request_var("external_id"),
				// Checkboxes are absent from POST when clear. Read the submitted form
				// directly so their state is not affected by Cacti request filtering.
				"proxy" => array_key_exists("proxy", $_POST),
				"disabled" => array_key_exists("disabled", $_POST),
			]);
			nms_nd_assignment_write($device_id, $discovery_assignment);
			nms_diag_assignment_write($device_id, $diagnostic_assignment);
			nms_identity_manual_save($device_id, $identity_manual);
			nms_short_name_save($device_id, $short_name);
			// Classification and the manually recorded serial belong to NMS metadata, but
			// are edited alongside the device identity so operators have one coherent form.
			nms_device_classification_save(
				$device_id,
				get_filter_request_var("equipment_category_id"),
				($submitted_type = get_nfilter_request_var("device_type")) !== ""
					? $submitted_type
					: nms_device_type_auto(
						get_nfilter_request_var("description"),
						(string) db_fetch_cell_prepared("SELECT name FROM host_template WHERE id=?", [
							(int) get_filter_request_var("host_template_id"),
						]),
					),
				(string) db_fetch_cell_prepared(
					"SELECT device_role FROM plugin_nms_device_classification WHERE host_id = ?",
					[$device_id],
				),
			);
			if (!isset_request_var("manual_serial_number")) {
				throw new InvalidArgumentException("The serial number field was not submitted.");
			}
			nms_manual_serial_save($device_id, get_nfilter_request_var("manual_serial_number"));
			// Suppress an immediate SNMP suggestion after a deliberate manual clear;
			// a later clean visit may offer the fresh value again as an unsaved suggestion.
			header(
				"Location: devices.php?tab=edit&id=" . $device_id . "&device_updated=1&serial_saved=1#nms-device-form",
			);
			exit();
		}

		if (in_array($action, ["mib_preview", "mib_create"], true)) {
			if (!csrf_check_tokens($_POST["__csrf_magic"] ?? "")) {
				throw new RuntimeException("Invalid form token.");
			}
			nms_require_management(10);
			if ($action === "mib_preview") {
				unset($_SESSION["nms_mib_preview"]);
				$_SESSION["nms_mib_preview"] = nms_mib_preview(
					$_FILES["mib_files"] ?? [],
					(int) get_filter_request_var("mib_host_id"),
				);
			} else {
				$preview = $_SESSION["nms_mib_preview"] ?? null;
				if (
					!$preview ||
					time() - $preview["created"] > 900 ||
					!hash_equals($preview["token"], (string) ($_POST["mib_token"] ?? ""))
				) {
					throw new RuntimeException("Preview expired; upload and test again.");
				}
				$selected = $_POST["metrics"] ?? [];
				if (!is_array($selected)) {
					throw new InvalidArgumentException("Select metrics.");
				}
				$mib_report = nms_mib_create($preview, $selected);
				unset($_SESSION["nms_mib_preview"]);
				$_SESSION["nms_mib_success"] = ["preview" => $preview, "rows" => $mib_report];
			}
			header("Location: file_repository.php?kind=mib");
			exit();
		}

		if ($action === "import_snmprec") {
			// A record file defines simulated OIDs and native templates; only subsequent SNMP polls produce live values.
			if (!isset($_FILES["snmprec_file"]) || $_FILES["snmprec_file"]["error"] !== UPLOAD_ERR_OK) {
				throw new InvalidArgumentException("Select a readable .snmprec file.");
			}
			$original_name = basename((string) $_FILES["snmprec_file"]["name"]);
			if (strtolower(pathinfo($original_name, PATHINFO_EXTENSION)) !== "snmprec") {
				throw new InvalidArgumentException("Only .snmprec files can be imported.");
			}
			$content = file_get_contents($_FILES["snmprec_file"]["tmp_name"]);
			if ($content === false) {
				throw new RuntimeException("NMS could not read the uploaded file.");
			}
			$records = nms_snmprec_parse($content);
			$community = nms_snmprec_community(get_nfilter_request_var("community"));
			$result = nms_template_import(
				$original_name,
				$community,
				nms_template_clean_name(get_nfilter_request_var("template_name")),
				get_filter_request_var("category_id"),
				$content,
				$records,
				nms_current_user_id(),
			);
			$_SESSION["nms_record_import_success"] = $result;
			header("Location: file_repository.php?imported=" . (int) $result["import_id"]);
			exit();
		}
	} catch (Throwable $exception) {
		// Surface helper failures on the relevant form so invalid configuration does not crash the page.
		$page_error = $exception->getMessage();
		$tab = in_array($action, ["import_snmprec", "mib_preview", "mib_create"], true)
			? "import"
			: (in_array($action, ["create_graph_template", "delete_graph_template"], true)
				? "graphs"
				: (in_array(
					$action,
					[
						"reindex_device",
						"update_device",
						"add_graph_template",
						"remove_graph_template",
						"add_data_query",
						"change_data_query",
						"reload_data_query",
						"remove_data_query",
					],
					true,
				)
					? "edit"
					: "inventory"));
	}
	if ($page_error !== "" && in_array($action, ["check_snmpsim", "control_snmpsim"], true)) {
		$tab = "import";
	}
	if ($page_error !== "" && $action === "add_device") {
		$tab = "add";
	}
	if ($page_error !== "" && $action === "save_manual_serial") {
		$tab = "edit";
	}
}

// Reuse native device visibility for both inventory rows and their summary counts.
$visible_hosts = nms_visible_host_sql();
// Decorate core devices with independent classification and import metadata.
$devices = db_fetch_assoc("SELECT h.id, h.description, h.hostname, h.status, h.disabled, h.availability,
	(SELECT m.serial_number FROM plugin_nms_device_metadata AS m WHERE m.host_id = h.id) AS manual_serial_number,
	h.cur_time, h.avg_time, h.total_polls, h.failed_polls, h.status_last_error, h.last_updated,
	h.snmp_version, h.snmp_port, h.snmp_sysName, h.snmp_sysLocation, h.snmp_sysDescr,
	ht.name AS template_name, s.name AS site_name, p.name AS poller_name,
	c.name AS category_name,
	(SELECT di.observed_value FROM plugin_nms_device_inventory AS di
		WHERE di.host_id = h.id AND di.inventory_key = 'serial_number') AS serial_number,
	(SELECT di.status FROM plugin_nms_device_inventory AS di
		WHERE di.host_id = h.id AND di.inventory_key = 'serial_number') AS serial_status,
	(SELECT di.last_success FROM plugin_nms_device_inventory AS di
		WHERE di.host_id = h.id AND di.inventory_key = 'serial_number') AS serial_last_success,
	(SELECT di.oid FROM plugin_nms_device_inventory AS di
		WHERE di.host_id = h.id AND di.inventory_key = 'serial_number') AS serial_oid,
	EXISTS (SELECT 1 FROM plugin_nms_snmprec_imports AS si
		INNER JOIN plugin_nms_snmprec_oids AS so ON so.import_id = si.id AND so.inventory_key = 'serial_number'
		WHERE si.host_template_id = h.host_template_id) AS serial_configured,
	(SELECT COUNT(*) FROM graph_local AS gl WHERE gl.host_id = h.id) AS graph_count,
	(SELECT COUNT(*) FROM data_local AS dl WHERE dl.host_id = h.id) AS data_source_count,
		(SELECT COUNT(*) FROM poller_item AS pi WHERE pi.host_id = h.id) AS poller_item_count,
		EXISTS (SELECT 1 FROM plugin_nms_managed_objects AS mo WHERE mo.object_type = 'device' AND mo.object_id = h.id) AS nms_deletable
	FROM host AS h
	LEFT JOIN host_template AS ht ON ht.id = h.host_template_id
	LEFT JOIN sites AS s ON s.id = h.site_id
	LEFT JOIN poller AS p ON p.id = h.poller_id
	LEFT JOIN plugin_nms_device_classification AS ct ON ct.host_id = h.id
	LEFT JOIN plugin_nms_categories AS c ON c.id = ct.category_id
	WHERE h.deleted = '' AND $visible_hosts ORDER BY h.description");
$device_counts = nms_device_inventory_counts($devices);
foreach ($devices as &$device) {
	$device["serial_status"] = nms_serial_observation_state(
		$device["serial_status"],
		$device["serial_last_success"],
		$device["status"],
		$device["disabled"],
		$device["last_updated"],
	);
	if (!in_array($device["serial_status"], ["ok", "changed"], true)) {
		$device["serial_number"] = "";
	}
}
unset($device);

// Populate selection lists from core records; device segments use plugin IDs.
$host_templates = db_fetch_assoc("SELECT id, name FROM host_template ORDER BY name");
$sites = db_fetch_assoc("SELECT id, name FROM sites ORDER BY name");
$pollers = db_fetch_assoc("SELECT id, name FROM poller ORDER BY id");
$categories = nms_categories();
$imports = db_fetch_assoc("SELECT i.*, c.name AS category_name, ht.name AS host_template_name,
	u.username AS uploaded_by_name FROM plugin_nms_snmprec_imports AS i
	LEFT JOIN plugin_nms_categories AS c ON c.id = i.category_id
	LEFT JOIN host_template AS ht ON ht.id = i.host_template_id
	LEFT JOIN user_auth AS u ON u.id = i.uploaded_by ORDER BY i.id DESC");

// Seed normal device forms from Cacti settings before applying any explicit imported-simulator defaults.
// Reuse configured defaults from the installed device form, including site/template/poller.
$cacti_device_defaults = [];
foreach ($fields_host_edit as $field_name => $field) {
	if (array_key_exists("default", $field)) {
		$cacti_device_defaults[$field_name] = $field["default"];
	}
}

/* Only the explicit imported-record workflow uses simulator defaults. Real devices are untouched. */
// Only Add device links carrying an import ID opt into the configured simulator endpoint and record community.
$snmpsim_import_id =
	$tab === "add" && isset_request_var("snmpsim_import_id") ? (int) get_filter_request_var("snmpsim_import_id") : 0;
if ($snmpsim_import_id > 0) {
	try {
		$cacti_device_defaults = array_merge($cacti_device_defaults, nms_snmpsim_import_defaults($snmpsim_import_id));
	} catch (Throwable $exception) {
		$page_error = $exception->getMessage();
		$tab = "import";
	}
}

$edit_device = [];
$device_readings = [];
$device_discovery_readings = [];
$device_diagnostic_profile = [];
$device_graph_templates = [];
$device_graphs = [];
$device_data_queries = [];
$available_graph_templates = [];
$available_data_queries = [];
$graph_data_template_items = [];
$global_graph_templates = [];
// Preset selectors query their native form definitions only when the graph form is rendered.
$graph_colors =
	$tab === "graphs"
		? db_fetch_assoc("SELECT id, hex, CONCAT(COALESCE(NULLIF(name, ''), 'Cacti Color'), ' (', hex, ')') AS name
	FROM colors ORDER BY SUBSTRING(hex,1,2), SUBSTRING(hex,3,2), SUBSTRING(hex,5,2)")
		: [];
// Load the selected host and its existing core associations; offer only templates/queries not already attached.
if (in_array($tab, ["edit", "readings"], true)) {
	$edit_device_id = isset_request_var("id") ? (int) get_filter_request_var("id") : 0;
	if ($edit_device_id > 0) {
		$edit_device = db_fetch_row_prepared(
			"SELECT h.*, ht.name AS template_name, p.name AS poller_name,
		di.observed_value AS serial_number, di.status AS serial_status,
		EXISTS (SELECT 1 FROM plugin_nms_snmprec_imports AS si
			INNER JOIN plugin_nms_snmprec_oids AS so ON so.import_id = si.id AND so.inventory_key = 'serial_number'
			WHERE si.host_template_id = h.host_template_id) AS serial_configured,
		s.name AS site_name, (SELECT COUNT(*) FROM graph_local WHERE host_id = h.id) AS graph_count,
		(SELECT COUNT(*) FROM data_local WHERE host_id = h.id) AS data_source_count,
		(SELECT COUNT(*) FROM poller_item WHERE host_id = h.id) AS poller_item_count
		FROM host AS h LEFT JOIN host_template AS ht ON ht.id = h.host_template_id
		LEFT JOIN poller AS p ON p.id = h.poller_id LEFT JOIN sites AS s ON s.id = h.site_id
		LEFT JOIN plugin_nms_device_inventory AS di ON di.host_id = h.id AND di.inventory_key = 'serial_number'
		WHERE h.id = ? AND h.deleted = '' AND $visible_hosts",
			[$edit_device_id],
		);
	}
	if (!$edit_device) {
		$page_error = "The selected Cacti device was not found.";
		$tab = "inventory";
	} elseif ($edit_device) {
		nms_require_device_access($edit_device_id);
		if ($tab === "readings") {
			$device_readings = nms_readings_load_live_rrd_values(nms_device_readings($edit_device_id));
			$device_graphs = nms_device_graphs($edit_device_id);
			$device_discovery_readings = nms_device_discovery_readings($edit_device_id);
			$device_diagnostic_profile = db_fetch_row_prepared("SELECT p.name,p.tools FROM plugin_nms_diagnostic_devices d INNER JOIN plugin_nms_diagnostic_profiles p ON p.id=d.profile_id WHERE d.host_id=?", [$edit_device_id]);
		}
		$device_classification = db_fetch_row_prepared(
			"SELECT * FROM plugin_nms_device_classification WHERE host_id = ?",
			[$edit_device_id],
		);
		$native_template_class = db_fetch_cell_prepared("SELECT class FROM host_template WHERE id = ?", [
			$edit_device["host_template_id"],
		]);
		$edit_device["manual_serial_number"] = nms_manual_serial_get($edit_device_id);
		// Link suggestions through this host's current imported serial OID, never another device or file sample.
		$serial_reading = nms_device_serial_reading($edit_device_id);
		$edit_device["serial_status"] = nms_serial_observation_state(
			$serial_reading["status"] ?? "",
			$serial_reading["last_success"] ?? "",
			$edit_device["status"],
			$edit_device["disabled"],
			$edit_device["last_updated"],
		);
		$edit_device["serial_number"] = in_array($edit_device["serial_status"], ["ok", "changed"], true)
			? $serial_reading["observed_value"] ?? ""
			: "";
		$serial_prefill = nms_serial_form_prefill(
			$edit_device["manual_serial_number"],
			$serial_reading,
			!isset_request_var("serial_saved"),
		);
		$device_graph_templates = db_fetch_assoc_prepared(
			"SELECT gt.id, gt.name,
			MAX(gl.id) AS graph_local_id, COUNT(DISTINCT gl.id) AS graph_count
			FROM host_graph AS hg INNER JOIN graph_templates AS gt ON gt.id = hg.graph_template_id
			LEFT JOIN graph_local AS gl ON gl.graph_template_id = gt.id AND gl.host_id = hg.host_id
			WHERE hg.host_id = ? GROUP BY gt.id, gt.name ORDER BY gt.name",
			[$edit_device_id],
		);
		$device_data_queries = db_fetch_assoc_prepared(
			"SELECT sq.id, sq.name, hsq.reindex_method,
			COUNT(hsc.snmp_index) AS item_count, COUNT(DISTINCT hsc.snmp_index) AS row_count
			FROM host_snmp_query AS hsq INNER JOIN snmp_query AS sq ON sq.id = hsq.snmp_query_id
			LEFT JOIN host_snmp_cache AS hsc ON hsc.host_id = hsq.host_id AND hsc.snmp_query_id = hsq.snmp_query_id
			WHERE hsq.host_id = ? GROUP BY sq.id, sq.name, hsq.reindex_method ORDER BY sq.name",
			[$edit_device_id],
		);
		$available_graph_templates = db_fetch_assoc_prepared(
			"SELECT DISTINCT gt.id, gt.name
			FROM graph_templates AS gt LEFT JOIN snmp_query_graph AS sqg ON sqg.graph_template_id = gt.id
			INNER JOIN graph_templates_item AS gti ON gti.graph_template_id = gt.id
			INNER JOIN data_template_rrd AS dtr ON gti.task_item_id = dtr.id
			INNER JOIN data_template_data AS dtd ON dtd.data_template_id = dtr.data_template_id
			WHERE sqg.name IS NULL AND gti.local_graph_id = 0 AND dtr.local_data_id = 0
			AND gt.id NOT IN (SELECT graph_template_id FROM host_graph WHERE host_id = ?)
			ORDER BY gt.name",
			[$edit_device_id],
		);
		// Match the save-path protocol check using native input types, never a seeded row ID.
		$data_query_filter = "";
		$data_query_params = [$edit_device_id];
		if ((int) $edit_device["snmp_version"] === 0) {
			$data_query_filter = " AND di.type_id NOT IN (?, ?)";
			$data_query_params[] = DATA_INPUT_TYPE_SNMP;
			$data_query_params[] = DATA_INPUT_TYPE_SNMP_QUERY;
		}
		$available_data_queries = db_fetch_assoc_prepared(
			"SELECT sq.id, sq.name FROM snmp_query AS sq
			INNER JOIN data_input AS di ON di.id = sq.data_input_id
			WHERE sq.id NOT IN (SELECT snmp_query_id FROM host_snmp_query WHERE host_id = ?)$data_query_filter
			ORDER BY sq.name",
			$data_query_params,
		);
	}
}

// The graph builder lists reusable data-template items and graph definitions, not sampled device values.
if ($tab === "graphs") {
	$graph_data_template_items = db_fetch_assoc("SELECT dt.id AS data_template_id, dt.name AS data_template_name,
		dtr.id AS data_template_rrd_id, dtr.data_source_name, dtd.name AS data_source_title,
		COUNT(DISTINCT gti.graph_template_id) AS graph_template_count
		FROM data_template_rrd AS dtr
		INNER JOIN data_template AS dt ON dt.id = dtr.data_template_id
		INNER JOIN data_template_data AS dtd ON dtd.data_template_id = dt.id AND dtd.local_data_id = 0
		LEFT JOIN graph_templates_item AS gti ON gti.task_item_id = dtr.id AND gti.local_graph_id = 0
		WHERE dtr.local_data_id = 0
		GROUP BY dt.id, dt.name, dtr.id, dtr.data_source_name, dtd.name
		ORDER BY dt.name, dtr.data_source_name");
	$global_graph_templates = db_fetch_assoc("SELECT gt.id, gt.name, gt.hash, gtg.width, gtg.height,
			gtg.image_format_id, gtg.vertical_label, COUNT(DISTINCT gl.id) AS graph_count,
			EXISTS (SELECT 1 FROM plugin_nms_managed_objects AS mo
				WHERE mo.object_type = 'graph_template' AND mo.object_id = gt.id) AS nms_deletable
		FROM graph_templates AS gt
		INNER JOIN graph_templates_graph AS gtg ON gtg.graph_template_id = gt.id AND gtg.local_graph_id = 0
			LEFT JOIN graph_local AS gl ON gl.graph_template_id = gt.id
			GROUP BY gt.id, gt.name, gt.hash, gtg.width, gtg.height, gtg.image_format_id, gtg.vertical_label
		ORDER BY gt.name");
}

// Device readings is a Device Management submenu, with the current device preferred.
$reading_tab_device_id = $edit_device
	? (int) $edit_device["id"]
	: ($devices ? (int) $devices[0]["id"] : 0);

nms_prepare_page(
	$template_workspace ? "templates" : ($repository_workspace ? "repository" : "devices"),
	$template_workspace
		? "NMS · Graph Templates"
		: ($repository_workspace
			? "NMS · File Repository"
			: "NMS · Device Management"),
	"css/nms-devices.css",
	"js/nms-devices.js,js/nms-readings.js",
);
require $config["base_path"] . "/plugins/nms/templates/app_header.php";
?>
<main class="nms-shell nms-devices-shell">
	<div class="nms-heading">
		<?php if (
  	$template_workspace
  ) { ?><div><p class="nms-eyebrow">NMS / Templates</p><h1>Create graph template</h1><p>Use an existing data-source template item to create a reusable Cacti graph template.</p><a href="templates.php?section=graph">All graph templates and native editor</a></div><?php } else { ?>
		<?php if (
  	$repository_workspace
  ) { ?><div><p class="nms-eyebrow">NMS / File repository</p><h1>File repository</h1><p>Browse uploaded files and their linked Cacti objects.</p></div><?php } else { ?><div><p class="nms-eyebrow">NMS / Device Management</p><h1>Device management</h1><p>Manage existing Cacti devices and their connection settings.</p></div><?php } ?>
		<?php } ?>
	</div>

	<?php if (
 	$page_error !== ""
 ) { ?><div class="nms-form-message error"><strong>Could not complete the request</strong><span><?php print nms_h(
	$page_error,
); ?></span></div><?php } ?>
	<?php if (
 	isset_request_var("device_created")
 ) { ?><div class="nms-form-message success"><strong>Device created</strong><span>Cacti device <?php print (int) get_filter_request_var(
	"device_created",
); ?> was saved. Review its graph templates and data queries below.</span></div><?php } ?>
	<?php if (
 	isset_request_var("device_deleted")
 ) { ?><div class="nms-form-message success"><strong>Device deleted</strong><span>The NMS-created Cacti device and its local graphs and data sources were removed.</span></div><?php } ?>
	<?php if (
 	isset_request_var("device_updated")
 ) { ?><div class="nms-form-message success"><strong>Device updated</strong><span>The live Cacti device settings were saved successfully.</span></div><?php } ?>
	<?php if (isset_request_var("device_reindexed")) {

 	$reindex_query_count = (int) get_filter_request_var("reindex_queries");
 	$reindex_item_count = (int) get_filter_request_var("reindex_items");
 	$reindex_seconds = max(0, (float) get_nfilter_request_var("reindex_seconds"));
 	?><div class="nms-form-message success"><strong>Device re-index completed</strong><span>Cacti refreshed <?php print $reindex_item_count; ?> indexed item<?php print $reindex_item_count ===
 1
 	? ""
 	: "s"; ?> from <?php print $reindex_query_count; ?> associated data quer<?php print $reindex_query_count === 1
 	? "y"
 	: "ies"; ?> in <?php print nms_h(number_format($reindex_seconds, 2)); ?> seconds.</span></div><?php
 } ?>
	<?php if (
 	isset_request_var("graph_template_added")
 ) { ?><div class="nms-form-message success"><strong>Graph template added</strong><span>The Cacti graph-template association is now active for this device.</span></div><?php } ?>
	<?php if (
 	isset_request_var("graph_template_removed")
 ) { ?><div class="nms-form-message success"><strong>Graph template removed</strong><span>The association was removed from this device; the reusable Cacti template was not deleted.</span></div><?php } ?>
	<?php if (
 	isset_request_var("graph_template_created")
 ) { ?><div class="nms-form-message success"><strong>Graph template created</strong><span>Cacti graph template <?php print (int) get_filter_request_var(
	"graph_template_created",
); ?> is ready to associate with devices from Manage Device.</span></div><?php } ?>
	<?php if (
 	isset_request_var("graph_template_deleted")
 ) { ?><div class="nms-form-message success"><strong>Graph template deleted</strong><span>The unused NMS-created template was removed from Cacti.</span></div><?php } ?>
	<?php if (
 	isset_request_var("data_query_added")
 ) { ?><div class="nms-form-message success"><strong>Data query added</strong><span>The Cacti data query is now associated with this device.</span></div><?php } ?>
	<?php if (
 	isset_request_var("data_query_changed")
 ) { ?><div class="nms-form-message success"><strong>Re-index method updated</strong><span>The Cacti data-query setting was saved.</span></div><?php } ?>
	<?php if (
 	isset_request_var("data_query_reloaded")
 ) { ?><div class="nms-form-message success"><strong>Data query reloaded</strong><span>Cacti refreshed the indexed data for this device.</span></div><?php } ?>
	<?php if (
 	isset_request_var("data_query_removed")
 ) { ?><div class="nms-form-message success"><strong>Data query removed</strong><span>The association and its indexed cache were removed from this device.</span></div><?php } ?>
	<?php if (isset($_SESSION["nms_record_import_success"])) {
 	require_once __DIR__ . "/includes/import_summary.php";
 	nms_record_import_summary($_SESSION["nms_record_import_success"]);
 	unset($_SESSION["nms_record_import_success"]);
 } ?>

	<?php if (
 	!$template_workspace &&
 	!$repository_workspace
 ) { ?><div class="nms-page-tabs" role="tablist" aria-label="Device management views">
		<a class="<?php print $tab === "inventory"
  	? "selected"
  	: ""; ?>" href="?tab=inventory" data-nms-tip="View live Cacti device status, polling totals, data-source counts, graph counts, and management actions.">Device dashboard</a>
		<a class="<?php print $tab === "add"
  	? "selected"
  	: ""; ?>" href="?tab=add" data-nms-tip="Create a real device in Cacti using the same core fields and defaults.">Add device</a>
		<?php if ($tab === "edit") { ?><a class="selected" href="?tab=edit&id=<?php print (int) $edit_device[
	"id"
]; ?>" data-nms-tip="Edit this live Cacti device and manage its graph templates and data queries.">Edit device</a><?php } ?>
		<?php if ($reading_tab_device_id) { ?><a class="<?php print $tab === "readings" ? "selected" : ""; ?>" href="?tab=readings&amp;id=<?php print $reading_tab_device_id; ?>" data-nms-tip="See actual RRD readings, discovery evidence, diagnosis, and raw device responses.">Device readings</a><?php } ?>
	</div><?php } ?>

	<?php require $config["base_path"] .
 	($repository_workspace
 		? "/plugins/nms/templates/repository/import.php"
 		: "/plugins/nms/templates/devices/" . $tab . ".php"); ?>
</main>
<?php require $config["base_path"] . "/plugins/nms/templates/app_footer.php"; ?>
