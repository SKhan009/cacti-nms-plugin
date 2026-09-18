<?php
/**
 * @file functions.php
 * Shared NMS helpers for escaping, Tree validation, rule catalogs, sample freshness, severity ordering, and incident lifecycle.
 * Controllers and poller hooks reuse these functions so fault behavior stays consistent across views.
 */

require_once __DIR__ . "/categories.php";
require_once __DIR__ . "/rule_scope.php";

/** Require Cacti's existing management realm as well as the NMS page's view realm. */
function nms_require_management($realm = 3)
{
	if (!function_exists("is_realm_allowed") || !is_realm_allowed((int) $realm)) {
		throw new RuntimeException("Your Cacti account does not have permission to change this configuration.");
	}
}

/** Apply native per-device visibility before accepting a device-specific web action. */
function nms_require_device_access($host_id)
{
	if (!function_exists("is_device_allowed") || !is_device_allowed((int) $host_id)) {
		throw new RuntimeException("Your Cacti account cannot access this device.");
	}
}

/** Reuse Cacti's device ACL evaluation once per web request for all NMS summaries. */
function nms_visible_host_sql($column = "h.id")
{
	if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.id$/D', $column)) {
		throw new InvalidArgumentException("Invalid internal device column.");
	}
	static $ids = null;
	if ($ids === null) {
		if (!function_exists("get_allowed_devices")) {
			throw new RuntimeException("Cacti device permissions are unavailable.");
		}
		$total = 0;
		$devices = get_allowed_devices("", "", "", $total);
		if (!is_array($devices)) {
			throw new RuntimeException("Could not read Cacti device permissions.");
		}
		$ids = [];
		foreach ($devices as $device) {
			$ids[] = (int) $device["id"];
		}
	}
	return $ids ? $column . " IN (" . implode(",", $ids) . ")" : "1 = 0";
}

/** Escape a value for HTML using Cacti's shared escaping helper. */
function nms_h($value)
{
	return html_escape((string) $value);
}

/** Return the current server time in the database timestamp format. */
function nms_now()
{
	return date("Y-m-d H:i:s");
}

/** Summarize the same nondeleted core hosts displayed in Device Dashboard. */
function nms_device_inventory_counts($devices)
{
	$counts = ["total" => 0, "enabled" => 0, "up" => 0, "down" => 0];
	foreach ($devices as $device) {
		$counts["total"]++;
		if ($device["disabled"] === "on") {
			continue;
		}
		$counts["enabled"]++;
		if (!nms_parameter_is_fresh($device["last_updated"] ?? "")) {
			continue;
		}
		if ((int) $device["status"] === HOST_UP) {
			$counts["up"]++;
		}
		if ((int) $device["status"] === HOST_DOWN) {
			$counts["down"]++;
		}
	}
	return $counts;
}

/** Shared request user lookup for web, migration, and audit paths. */
function nms_current_user_id($fallback = 0)
{
	$user_id = isset($_SESSION["sess_user_id"]) ? (int) $_SESSION["sess_user_id"] : (int) $fallback;
	return $user_id > 0 ? $user_id : (int) $fallback;
}

/** Record NMS ownership of a validated Cacti object without replacing its original audit entry. */
function nms_managed_object_record($type, $object_id, $user_id = null)
{
	$type = (string) $type;
	$object_id = (int) $object_id;
	if (
		!in_array(
			$type,
			[
				"device",
				"tree",
				"graph_template",
				"data_template",
				"host_template",
				"data_input",
				"graph",
				"data_source",
			],
			true,
		) ||
		$object_id < 1
	) {
		throw new InvalidArgumentException("Invalid NMS-managed object.");
	}
	if ($user_id === null) {
		$user_id = nms_current_user_id();
	}
	db_execute_prepared(
		'INSERT IGNORE INTO plugin_nms_managed_objects
		(object_type, object_id, created_by, created_at) VALUES (?, ?, ?, NOW())',
		[$type, $object_id, (int) $user_id],
	);
}

/** Check whether the object ID and type are registered as NMS-managed. */
function nms_managed_object_exists($type, $object_id)
{
	return (int) db_fetch_cell_prepared(
		'SELECT COUNT(*) FROM plugin_nms_managed_objects
		WHERE object_type = ? AND object_id = ?',
		[(string) $type, (int) $object_id],
	) === 1;
}

/** Remove the NMS ownership entry without deleting the underlying Cacti object. */
function nms_managed_object_forget($type, $object_id)
{
	db_execute_prepared("DELETE FROM plugin_nms_managed_objects WHERE object_type = ? AND object_id = ?", [
		(string) $type,
		(int) $object_id,
	]);
}

/** Read and cache the plugin INFO metadata for the current request. */
function nms_plugin_info()
{
	static $info = null;
	if ($info !== null) {
		return $info;
	}
	$parsed = parse_ini_file(dirname(__DIR__) . "/INFO", true);
	$info = isset($parsed["info"]) && is_array($parsed["info"]) ? $parsed["info"] : ["version" => "dev"];
	return $info;
}

/** Read the plugin version once so every asset receives the same cache key. */
function nms_plugin_version()
{
	$info = nms_plugin_info();
	return isset($info["version"]) ? (string) $info["version"] : "dev";
}

/** Normalize Cacti's same-origin URL path for root and subdirectory installs. */
function nms_cacti_url_path()
{
	global $config;
	$value = isset($config["url_path"]) && is_string($config["url_path"]) ? trim($config["url_path"]) : "";
	if (preg_match("~^[A-Za-z][A-Za-z0-9+.-]*://~", $value)) {
		$parsed = parse_url($value, PHP_URL_PATH);
		$value = is_string($parsed) ? $parsed : "";
	}
	$value = str_replace("\\", "/", $value);
	$value = preg_replace("~/+~", "/", $value);
	$value = "/" . trim((string) $value, "/") . "/";
	return $value === "//" ? "/" : $value;
}

/** Build a same-origin URL below the configured Cacti web root. */
function nms_cacti_url($path = "")
{
	return nms_cacti_url_path() . ltrim((string) $path, "/");
}

/** Build a URL below this plugin without assuming Cacti is installed at /cacti. */
function nms_plugin_url($path = "")
{
	return nms_cacti_url("plugins/nms/" . ltrim((string) $path, "/"));
}

/** Build a Cacti-relative asset URL with the plugin version as its cache key. */
function nms_asset_url($path)
{
	$path = ltrim((string) $path, "/");
	$separator = strpos($path, "?") === false ? "?" : "&";
	// Refresh local assets after UI-only updates as well as versioned releases.
	$asset = realpath(dirname(__DIR__) . "/" . explode("?", $path, 2)[0]);
	$root = realpath(dirname(__DIR__)) . DIRECTORY_SEPARATOR;
	$revision =
		$asset && strpos($asset, $root) === 0 && is_file($asset)
			? substr(sha1_file($asset), 0, 12)
			: nms_plugin_version();
	return nms_plugin_url($path) . $separator . "v=" . rawurlencode($revision);
}

/** Initialize the shared variables consumed by the NMS header, footer, and forms. */
function nms_prepare_page($module, $title, $extra_css = "", $extra_js = "")
{
	global $config,
		$nms_csrf_token,
		$nms_backend_url,
		$nms_active_module,
		$nms_page_title,
		$nms_extra_css,
		$nms_extra_js;
	$nms_csrf_token = csrf_get_tokens();
	$nms_backend_url = nms_cacti_url("index.php");
	$nms_active_module = (string) $module;
	$nms_page_title = (string) $title;
	$nms_extra_css = (string) $extra_css;
	$nms_extra_js = (string) $extra_js;
}

/** Use the configured Cacti polling interval; an invalid setting is not a substitute interval. */
function nms_poller_interval()
{
	$interval = (int) read_config_option("poller_interval");
	if ($interval < 1) {
		throw new RuntimeException("Cacti poller_interval is not configured as a positive interval.");
	}
	return $interval;
}

/** Return the earliest accepted sample time, allowing two polling intervals or at least 120 seconds. */
function nms_parameter_fresh_after()
{
	return date("Y-m-d H:i:s", time() - max(120, nms_poller_interval() * 2));
}

/** Define the supported comparisons, display wording, and threshold-input requirements. */
function nms_fault_comparison_definitions()
{
	return [
		"greater_than" => [
			"label" => "Greater than (>)",
			"sentence" => "is greater than",
			"numeric" => true,
			"requires_value" => true,
		],
		"greater_or_equal" => [
			"label" => "Greater than or equal (≥)",
			"sentence" => "is greater than or equal to",
			"numeric" => true,
			"requires_value" => true,
		],
		"less_than" => [
			"label" => "Less than (<)",
			"sentence" => "is less than",
			"numeric" => true,
			"requires_value" => true,
		],
		"less_or_equal" => [
			"label" => "Less than or equal (≤)",
			"sentence" => "is less than or equal to",
			"numeric" => true,
			"requires_value" => true,
		],
		"equals" => ["label" => "Equals", "sentence" => "equals", "numeric" => false, "requires_value" => true],
		"not_equals" => [
			"label" => "Does not equal",
			"sentence" => "does not equal",
			"numeric" => false,
			"requires_value" => true,
		],
		"contains" => [
			"label" => "Contains text",
			"sentence" => "contains",
			"numeric" => false,
			"requires_value" => true,
		],
		"not_contains" => [
			"label" => "Does not contain text",
			"sentence" => "does not contain",
			"numeric" => false,
			"requires_value" => true,
		],
		"is_unknown" => [
			"label" => "Is unknown or empty",
			"sentence" => "is unknown or empty",
			"numeric" => false,
			"requires_value" => false,
		],
		"is_not_unknown" => [
			"label" => "Has a valid value",
			"sentence" => "has a valid value",
			"numeric" => false,
			"requires_value" => false,
		],
	];
}

/** Project comparison definitions into the key-to-label choices used by forms. */
function nms_fault_comparisons()
{
	$labels = [];
	foreach (nms_fault_comparison_definitions() as $key => $definition) {
		$labels[$key] = $definition["label"];
	}
	return $labels;
}

/** Return the shared severity weights, with critical ranked highest. */
function nms_fault_severity_ranks()
{
	return ["critical" => 3, "major" => 2, "warning" => 1];
}

/** Return supported severity names in descending priority order. */
function nms_fault_severities()
{
	return array_keys(nms_fault_severity_ranks());
}

/** Convert a severity name to its priority weight; unknown names have rank zero. */
function nms_severity_rank($severity)
{
	$ranks = nms_fault_severity_ranks();
	return $ranks[strtolower((string) $severity)] ?? 0;
}

/** Convert a known numeric priority into a severity name, or return an empty string. */
function nms_severity_from_rank($rank)
{
	$severity = array_search((int) $rank, nms_fault_severity_ranks(), true);
	return $severity === false ? "" : $severity;
}

/** Fixed SQL fragments for internal queries; column names never come from a request. */
function nms_severity_rank_sql($column = "severity")
{
	if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $column)) {
		throw new InvalidArgumentException("Invalid severity column.");
	}
	$cases = [];
	foreach (nms_fault_severity_ranks() as $severity => $rank) {
		$cases[] = "WHEN '$severity' THEN $rank";
	}
	return "CASE $column " . implode(" ", $cases) . " ELSE 0 END";
}

/** Build a priority-order SQL expression after validating the internal column identifier. */
function nms_severity_order_sql($column = "severity")
{
	if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $column)) {
		throw new InvalidArgumentException("Invalid severity column.");
	}
	return "FIELD($column, " . nms_sql_string_list(nms_fault_severities()) . ")";
}

/** Quote internal string values as a comma-separated SQL list, doubling embedded apostrophes. */
function nms_sql_string_list($values)
{
	return implode(
		",",
		array_map(
			/** Quote one internal SQL literal, escaping embedded apostrophes. */ function ($value) {
				return "'" . str_replace("'", "''", (string) $value) . "'";
			},
			$values,
		),
	);
}

/** List incident sources that participate in the current device-monitoring views. */
function nms_monitored_incident_sources()
{
	return ["device", "inventory"];
}

/** Return the monitored incident sources as quoted SQL literals. */
function nms_monitored_incident_sources_sql()
{
	return nms_sql_string_list(nms_monitored_incident_sources());
}

/** List incident states that remain active until the fault is resolved. */
function nms_active_incident_statuses()
{
	return ["open", "acknowledged"];
}

/** Return active incident states as quoted SQL literals for shared queries. */
function nms_active_incident_statuses_sql()
{
	return nms_sql_string_list(nms_active_incident_statuses());
}

/** Classify a parameter key as core device status, inventory status, or a sampled parameter. */
function nms_fault_metric_for_parameter($parameter_key)
{
	if ($parameter_key === "core:status") {
		return "core_status";
	}
	if (strpos((string) $parameter_key, "inventory_status:") === 0) {
		return "inventory_status";
	}
	return "parameter";
}

/** Validate category parameters against native objects; never reuse a legacy tree-namespace helper. */
function nms_fault_parameter_exists($category_id, $parameter_key)
{
	if (!is_string($parameter_key)) {
		return false;
	}
	$category_id = (int) $category_id;
	if (!nms_category_exists($category_id)) {
		return false;
	}
	if ($parameter_key === "core:status") {
		return true;
	}
	if (strpos((string) $parameter_key, "inventory_status:") === 0) {
		$inventory_key = substr($parameter_key, strlen("inventory_status:"));
		if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $inventory_key)) {
			return false;
		}
		return (int) db_fetch_cell_prepared(
			"SELECT COUNT(*)
			FROM plugin_nms_device_classification AS ct
			INNER JOIN host AS h ON h.id = ct.host_id AND h.deleted = '' AND h.disabled = ''
			INNER JOIN plugin_nms_snmprec_imports AS i ON i.host_template_id = h.host_template_id
			INNER JOIN plugin_nms_snmprec_oids AS o ON o.import_id = i.id
				AND o.inventory_key = ? AND o.inventory_key != ''
			WHERE ct.category_id = ?",
			[$inventory_key, $category_id],
		) > 0;
	}
	if (!preg_match('/^dtrr:([1-9][0-9]*)$/D', $parameter_key, $match)) {
		return false;
	}
	$template_item_id = (int) $match[1];
	return $template_item_id > 0 &&
		(int) db_fetch_cell_prepared(
			"SELECT COUNT(*)
		FROM plugin_nms_device_classification AS ct
		INNER JOIN host AS h ON h.id = ct.host_id AND h.deleted = '' AND h.disabled = ''
		INNER JOIN poller_item AS pi ON pi.host_id = h.id
		INNER JOIN data_template_rrd AS dtr ON dtr.local_data_id = pi.local_data_id
		WHERE ct.category_id = ? AND dtr.local_data_template_rrd_id = ?",
			[$category_id, $template_item_id],
		) > 0;
}

/** Turn an inventory key into its operator-facing label, recognizing chassis serial numbers. */
function nms_inventory_display_name($inventory_key)
{
	$labels = ["serial_number" => "Chassis serial number"];
	return $labels[$inventory_key] ?? ucwords(str_replace("_", " ", (string) $inventory_key));
}

/** Build the one shared parameter catalog used by the fault-rule interface. */
function nms_fault_parameter_catalog($category_id)
{
	$category_id = (int) $category_id;
	if (!nms_category_exists($category_id)) {
		return [];
	}
	$visible = nms_visible_host_sql();
	$parameters = db_fetch_assoc_prepared(
		"SELECT
		CONCAT('dtrr:', dtr.local_data_template_rrd_id) AS parameter_key,
		COALESCE(NULLIF(dt.name, ''), CONCAT('Cacti data template ', dtr.data_template_id)) AS template_name,
		dtr.data_source_name AS parameter_name,
		COUNT(DISTINCT h.id) AS device_count
		FROM plugin_nms_device_classification AS ct
		INNER JOIN host AS h ON h.id = ct.host_id AND h.deleted = '' AND h.disabled = ''
		INNER JOIN poller_item AS pi ON pi.host_id = h.id
		INNER JOIN data_template_rrd AS dtr ON dtr.local_data_id = pi.local_data_id
		LEFT JOIN data_template AS dt ON dt.id = dtr.data_template_id
		WHERE ct.category_id = ? AND dtr.local_data_template_rrd_id > 0 AND $visible
		GROUP BY dtr.local_data_template_rrd_id, dt.name, dtr.data_template_id, dtr.data_source_name
		ORDER BY dt.name, dtr.data_source_name",
		[$category_id],
	);

	$inventory = db_fetch_assoc_prepared(
		"SELECT o.inventory_key,
		COUNT(DISTINCT h.id) AS device_count
		FROM plugin_nms_device_classification AS ct
		INNER JOIN host AS h ON h.id = ct.host_id AND h.deleted = '' AND h.disabled = ''
		INNER JOIN plugin_nms_snmprec_imports AS i ON i.host_template_id = h.host_template_id
		INNER JOIN plugin_nms_snmprec_oids AS o ON o.import_id = i.id AND o.inventory_key != ''
		WHERE ct.category_id = ? AND $visible
		GROUP BY o.inventory_key ORDER BY o.inventory_key",
		[$category_id],
	);
	foreach ($inventory as $item) {
		$parameters[] = [
			"parameter_key" => "inventory_status:" . $item["inventory_key"],
			"template_name" => "Configured inventory",
			"parameter_name" => nms_inventory_display_name($item["inventory_key"]) . " status",
			"device_count" => $item["device_count"],
		];
	}
	return $parameters;
}

/** Create or update a category-scoped fault rule through one validation path. */
function nms_fault_rule_save($category_id, $rule_id, $input)
{
	nms_require_category_policy_access($category_id);
	$category_id = (int) $category_id;
	$rule_id = (int) $rule_id;
	$name = nms_classification_text($input["name"] ?? "", 150);
	$parameter_key = nms_classification_text($input["parameter_key"] ?? "", 191);
	$comparison = nms_classification_text($input["comparison"] ?? "", 20);
	$threshold_value = nms_classification_text($input["threshold_value"] ?? "", 191);
	$unit = nms_classification_text($input["unit"] ?? "", 24);
	$severity = nms_classification_text($input["severity"] ?? "", 16);
	$enabled = !empty($input["enabled"]) ? "on" : "";

	if ($rule_id > 0) {
		$existing = db_fetch_row_prepared("SELECT * FROM plugin_nms_fault_rules WHERE id = ? AND category_id = ?", [
			$rule_id,
			$category_id,
		]);
		if (!$existing || $existing["parameter_key"] !== $parameter_key) {
			throw new InvalidArgumentException(
				"Select the existing rule parameter. Create a separate rule to monitor a different parameter without changing incident identity.",
			);
		}
	}

	$parameter_exists = nms_fault_parameter_exists($category_id, $parameter_key);
	if (!$parameter_exists && $rule_id > 0 && nms_category_exists($category_id)) {
		/* An existing rule remains editable when its device is temporarily absent. */
		$parameter_exists =
			(int) db_fetch_cell_prepared(
				'SELECT COUNT(*) FROM plugin_nms_fault_rules
			WHERE id = ? AND category_id = ? AND parameter_key = ?',
				[$rule_id, $category_id, $parameter_key],
			) === 1;
	}
	$comparisons = nms_fault_comparison_definitions();
	$definition = $comparisons[$comparison] ?? null;
	$threshold_valid =
		$definition &&
		(!$definition["requires_value"] ||
			($definition["numeric"] ? is_numeric($threshold_value) : $threshold_value !== ""));
	if (
		$name === "" ||
		!$definition ||
		!in_array($severity, nms_fault_severities(), true) ||
		!$threshold_valid ||
		!$parameter_exists
	) {
		throw new InvalidArgumentException(
			"Enter a valid rule name, parameter, comparison, fault value, and severity.",
		);
	}

	$metric = nms_fault_metric_for_parameter($parameter_key);
	if ($rule_id > 0) {
		nms_category_execute(
			'UPDATE plugin_nms_fault_rules SET name = ?, metric = ?, comparison = ?,
			threshold_value = ?, unit = ?, severity = ?, enabled = ?, updated_at = NOW()
			WHERE id = ? AND category_id = ?',
			[$name, $metric, $comparison, $threshold_value, $unit, $severity, $enabled, $rule_id, $category_id],
		);
		return $rule_id;
	}

	$sort_order = (int) db_fetch_cell_prepared(
		'SELECT COALESCE(MAX(sort_order), 0) + 10
		FROM plugin_nms_fault_rules WHERE category_id = ?',
		[$category_id],
	);
	nms_category_execute(
		'INSERT INTO plugin_nms_fault_rules
		(category_id, name, metric, parameter_key, comparison, threshold, threshold_value, unit,
		severity, enabled, sort_order, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, NOW(), NOW())',
		[
			$category_id,
			$name,
			$metric,
			$parameter_key,
			$comparison,
			$threshold_value,
			$unit,
			$severity,
			$enabled,
			$sort_order,
		],
	);
	return (int) db_fetch_cell("SELECT LAST_INSERT_ID()");
}

/**
 * Append one incident audit event and fail if it cannot be stored.
 *
 * The caller owns lifecycle ordering.  In particular, an incident transition is
 * not considered successful when its required event insert fails.
 */
function nms_event($incident_id, $event_type, $severity, $message, $user_id = 0)
{
	nms_storage_execute(
		'INSERT INTO plugin_nms_events
		(incident_id, event_type, severity, message, user_id, created_at)
		VALUES (?, ?, ?, ?, ?, ?)',
		[$incident_id, $event_type, $severity, $message, $user_id, nms_now()],
	);
}

/** Create or refresh an incident by fingerprint and record relevant lifecycle changes. */
function nms_open_incident($fault)
{
	$now = nms_now();
	$log_message = "";
	nms_storage_execute("START TRANSACTION");
	try {
		// The unique fingerprint lookup is locked so concurrent poller processes
		// cannot interleave reopen/refresh state with its required audit event.
		$current = db_fetch_row_prepared("SELECT * FROM plugin_nms_incidents WHERE fingerprint = ? FOR UPDATE", [
			$fault["fingerprint"],
		]);

		if (!cacti_sizeof($current)) {
			nms_storage_execute(
				'INSERT INTO plugin_nms_incidents
				(fingerprint, source_type, source_key, host_id, poller_id, local_data_id,
				severity, status, title, message, first_seen, last_seen)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
				[
					$fault["fingerprint"],
					$fault["source_type"],
					$fault["source_key"],
					isset($fault["host_id"]) ? (int) $fault["host_id"] : 0,
					isset($fault["poller_id"]) ? (int) $fault["poller_id"] : 0,
					isset($fault["local_data_id"]) ? (int) $fault["local_data_id"] : 0,
					$fault["severity"],
					"open",
					$fault["title"],
					$fault["message"],
					$now,
					$now,
				],
			);
			$id = (int) db_fetch_cell("SELECT LAST_INSERT_ID()");
			if ($id < 1) {
				throw new RuntimeException("NMS could not identify the stored incident.");
			}
			nms_event($id, "opened", $fault["severity"], $fault["message"]);
			$log_message = "Opened incident [" . $fault["fingerprint"] . "] " . $fault["title"];
		} else {
			$id = (int) $current["id"];
			if ($current["status"] === "resolved") {
				nms_storage_execute(
					"UPDATE plugin_nms_incidents
					SET source_type = ?, source_key = ?, host_id = ?, poller_id = ?, local_data_id = ?,
						severity = ?, status = 'open', title = ?, message = ?, first_seen = ?, last_seen = ?,
						acknowledged_by = 0, acknowledged_at = NULL, resolved_at = NULL
					WHERE id = ?",
					[
						$fault["source_type"],
						$fault["source_key"],
						isset($fault["host_id"]) ? (int) $fault["host_id"] : 0,
						isset($fault["poller_id"]) ? (int) $fault["poller_id"] : 0,
						isset($fault["local_data_id"]) ? (int) $fault["local_data_id"] : 0,
						$fault["severity"],
						$fault["title"],
						$fault["message"],
						$now,
						$now,
						$id,
					],
				);
				nms_event($id, "reopened", $fault["severity"], $fault["message"]);
				$log_message = "Reopened incident [" . $fault["fingerprint"] . "] " . $fault["title"];
			} else {
				nms_storage_execute(
					'UPDATE plugin_nms_incidents
					SET severity = ?, title = ?, message = ?, last_seen = ?, host_id = ?, poller_id = ?, local_data_id = ?
					WHERE id = ?',
					[
						$fault["severity"],
						$fault["title"],
						$fault["message"],
						$now,
						isset($fault["host_id"]) ? (int) $fault["host_id"] : 0,
						isset($fault["poller_id"]) ? (int) $fault["poller_id"] : 0,
						isset($fault["local_data_id"]) ? (int) $fault["local_data_id"] : 0,
						$id,
					],
				);
			}
		}
		nms_storage_execute("COMMIT");
	} catch (Throwable $error) {
		db_execute("ROLLBACK");
		throw $error;
	}
	if ($log_message !== "") {
		cacti_log($log_message, false, "NMS");
	}
	return $id;
}

/** Clear an active incident and record its resolution; return false when no active match exists. */
function nms_resolve_incident($fingerprint, $message = "Fault condition cleared automatically", $user_id = 0)
{
	$active_statuses_sql = nms_active_incident_statuses_sql();
	nms_storage_execute("START TRANSACTION");
	try {
		$current = db_fetch_row_prepared(
			"SELECT * FROM plugin_nms_incidents
			WHERE fingerprint = ? AND status IN ($active_statuses_sql) FOR UPDATE",
			[$fingerprint],
		);
		if (!cacti_sizeof($current)) {
			nms_storage_execute("COMMIT");
			return false;
		}
		$now = nms_now();
		nms_storage_execute(
			"UPDATE plugin_nms_incidents
			SET status = 'resolved', resolved_at = ?, last_seen = ? WHERE id = ?",
			[$now, $now, $current["id"]],
		);
		nms_event($current["id"], "resolved", $current["severity"], $message, $user_id);
		nms_storage_execute("COMMIT");
	} catch (Throwable $error) {
		db_execute("ROLLBACK");
		throw $error;
	}
	cacti_log("Resolved incident [" . $fingerprint . "] " . $current["title"], false, "NMS");
	return true;
}

/** Resolve only conditions actually evaluated with fresh evidence; missing configuration/data is not recovery. */
function nms_resolve_missing($source_type, $active_fingerprints, $evaluated_fingerprints)
{
	if (!$evaluated_fingerprints) {
		return;
	}
	$active_statuses_sql = nms_active_incident_statuses_sql();
	$rows = db_fetch_assoc_prepared(
		"SELECT fingerprint FROM plugin_nms_incidents
		WHERE source_type = ? AND status IN ($active_statuses_sql)",
		[$source_type],
	);

	$active = array_fill_keys($active_fingerprints, true);
	$evaluated = array_fill_keys($evaluated_fingerprints, true);
	foreach ($rows as $row) {
		if (isset($evaluated[$row["fingerprint"]]) && !isset($active[$row["fingerprint"]])) {
			nms_resolve_incident($row["fingerprint"]);
		}
	}
}

/** Acknowledge an open incident for the given user and append its audit event. */
function nms_acknowledge_incident($id, $user_id)
{
	nms_storage_execute("START TRANSACTION");
	try {
		$current = db_fetch_row_prepared(
			"SELECT * FROM plugin_nms_incidents WHERE id = ? AND status = 'open' FOR UPDATE",
			[$id],
		);
		if (!cacti_sizeof($current)) {
			nms_storage_execute("COMMIT");
			return false;
		}
		$now = nms_now();
		nms_storage_execute(
			"UPDATE plugin_nms_incidents
			SET status = 'acknowledged', acknowledged_by = ?, acknowledged_at = ? WHERE id = ?",
			[$user_id, $now, $id],
		);
		nms_event($id, "acknowledged", $current["severity"], "Incident acknowledged", $user_id);
		nms_storage_execute("COMMIT");
	} catch (Throwable $error) {
		db_execute("ROLLBACK");
		throw $error;
	}
	return true;
}

/** Translate Cacti host-status constants into labels while exposing unrecognized states. */
function nms_host_status_name($status)
{
	$map = [
		HOST_UNKNOWN => "Unknown",
		HOST_DOWN => "Down",
		HOST_RECOVERING => "Recovering",
		HOST_UP => "Up",
		HOST_ERROR => "Error",
	];
	return isset($map[$status]) ? $map[$status] : "Invalid state " . $status;
}

/** Display the age/disabled state before a retained core status, never presenting a stale Up as live. */
function nms_device_status_name($device)
{
	if (($device["disabled"] ?? "") !== "") {
		return "Disabled";
	}
	$updated = (string) ($device["last_updated"] ?? "");
	if ($updated === "" || $updated === "0000-00-00 00:00:00") {
		return "Pending";
	}
	if (!nms_parameter_is_fresh($updated)) {
		return "Stale";
	}
	return nms_host_status_name((int) $device["status"]);
}

/** Evaluate a raw reading against a comparison and threshold, including explicit unknown-value rules. */
function nms_parameter_matches($raw_value, $comparison, $threshold_value)
{
	$raw = trim((string) $raw_value);
	$threshold = trim((string) $threshold_value);
	$raw_lower = strtolower($raw);
	$threshold_lower = strtolower($threshold);
	$is_unknown = $raw === "" || in_array($raw_lower, ["u", "unknown", "nan", "null"], true);

	if ($comparison === "is_unknown") {
		return $is_unknown;
	}
	if ($comparison === "is_not_unknown") {
		return !$is_unknown;
	}
	if ($comparison === "contains") {
		return $threshold !== "" && strpos($raw_lower, $threshold_lower) !== false;
	}
	if ($comparison === "not_contains") {
		return $threshold !== "" && strpos($raw_lower, $threshold_lower) === false;
	}
	if ($comparison === "equals") {
		return $raw_lower === $threshold_lower;
	}
	if ($comparison === "not_equals") {
		return $raw_lower !== $threshold_lower;
	}

	if (!is_numeric($raw) || !is_numeric($threshold)) {
		return false;
	}
	$current = (float) $raw;
	$limit = (float) $threshold;
	if ($comparison === "greater_than") {
		return $current > $limit;
	}
	if ($comparison === "greater_or_equal") {
		return $current >= $limit;
	}
	if ($comparison === "less_than") {
		return $current < $limit;
	}
	if ($comparison === "less_or_equal") {
		return $current <= $limit;
	}

	return false;
}

/** Return sentence wording for a comparison, preserving an unrecognized key for display. */
function nms_comparison_label($comparison)
{
	$definitions = nms_fault_comparison_definitions();
	return isset($definitions[$comparison]) ? $definitions[$comparison]["sentence"] : (string) $comparison;
}

/**
 * A retained value is historical once Cacti has missed more than two expected
 * poll intervals.  Historical values must not be evaluated as current faults
 * or displayed as a fallback when the SNMP endpoint is unavailable.
 */
function nms_parameter_is_fresh($last_seen)
{
	$timestamp = strtotime((string) $last_seen);
	return $timestamp !== false && $timestamp <= time() && time() - $timestamp <= max(120, nms_poller_interval() * 2);
}

/** Require an Up host, a fresh sample, and a nonempty known value before treating a reading as current. */
function nms_parameter_has_current_value($parameter)
{
	if (isset($parameter["host_status"]) && (int) $parameter["host_status"] !== HOST_UP) {
		return false;
	}
	if (array_key_exists("host_last_updated", $parameter) && !nms_parameter_is_fresh($parameter["host_last_updated"])) {
		return false;
	}
	if (!nms_parameter_is_fresh($parameter["last_seen"] ?? "")) {
		return false;
	}
	$value = strtolower(trim((string) ($parameter["raw_value"] ?? "")));
	return $value !== "" && !in_array($value, ["u", "unknown", "nan", "null"], true);
}

/** Serial displays must not present a retained observation as a current SNMP reading. */
function nms_serial_observation_state($status, $last_success, $host_status, $disabled = "", $host_last_updated = null)
{
	if ($disabled !== "") {
		return "disabled";
	}
	if ($host_last_updated !== null && !nms_parameter_is_fresh($host_last_updated)) {
		return "stale";
	}
	if ($status === "unconfigured") {
		return "unconfigured";
	}
	if ($status === "failed" || (int) $host_status !== HOST_UP) {
		return "failed";
	}
	if (!in_array($status, ["ok", "changed"], true)) {
		return "pending";
	}
	if (!nms_parameter_is_fresh($last_success)) {
		return "stale";
	}
	return $status;
}

/** Evaluate Category-scoped device and sampled-parameter rules against Cacti data and reconcile incidents. */
function nms_sync_device_faults()
{
	$scope_columns = nms_rule_scope_select_sql();
	$core_rows = db_fetch_assoc("SELECT h.*, $scope_columns, ct.device_type, ht.name AS template_name,
		c.id AS category_id, c.name AS category_name,
		r.id AS rule_id, r.name AS rule_name, r.parameter_key, r.comparison,
		r.threshold_value, r.unit, r.severity
		FROM host AS h
		LEFT JOIN host_template AS ht ON ht.id = h.host_template_id
		INNER JOIN plugin_nms_device_classification AS ct ON ct.host_id = h.id
		INNER JOIN plugin_nms_categories AS c ON c.id = ct.category_id
		INNER JOIN plugin_nms_fault_rules AS r ON r.category_id = c.id
			AND r.enabled = 'on' AND r.metric = 'core_status'
		WHERE h.deleted = '' AND h.disabled = ''
		ORDER BY h.id, r.sort_order, r.id");
	$active = [];
	$evaluated = [];

	foreach ($core_rows as $row) {
		if (!nms_rule_scope_matches($row, array_merge($row, ["host_id" => $row["id"]]))) {
			continue;
		}
		$fingerprint = "device-rule:" . $row["rule_id"] . ":host:" . $row["id"];
		if (!nms_parameter_is_fresh($row["last_updated"])) {
			// Keep an existing incident unverified; lack of a poll is not recovery.
			$active[] = $fingerprint;
			continue;
		}
		$evaluated[] = $fingerprint;
		$current = strtolower(nms_host_status_name((int) $row["status"]));
		if (!nms_parameter_matches($current, $row["comparison"], $row["threshold_value"])) {
			continue;
		}

		$fingerprint = "device-rule:" . $row["rule_id"] . ":host:" . $row["id"];
		$active[] = $fingerprint;
		$message =
			"Device state is " .
			$current .
			". The configured healthy value is " .
			$row["threshold_value"] .
			". Category: " .
			$row["category_name"] .
			". Template: " .
			$row["template_name"] .
			".";
		nms_open_incident([
			"fingerprint" => $fingerprint,
			"source_type" => "device",
			"source_key" => $row["id"] . ":" . $row["rule_id"],
			"host_id" => $row["id"],
			"severity" => $row["severity"],
			"title" => $row["description"] . " - " . $row["rule_name"],
			"message" => $message,
		]);
	}

	$parameter_rows = db_fetch_assoc("SELECT $scope_columns, h.id, h.description, h.status AS host_status, h.last_updated AS host_last_updated, ht.name AS template_name,
		h.host_template_id, h.snmp_sysObjectID, pi.snmp_version, ct.device_type, dtd.data_input_id, di.type_id AS input_type_id,
		dl.snmp_query_id, dl.snmp_index,
		EXISTS (SELECT 1 FROM host_snmp_cache AS sc WHERE sc.host_id = h.id
			AND sc.snmp_query_id = dl.snmp_query_id AND sc.snmp_index = dl.snmp_index
			AND sc.field_name IN ('ifName', 'ifDescr')) AS is_interface,
		c.name AS category_name, r.id AS rule_id, r.name AS rule_name, r.parameter_key,
		r.comparison, r.threshold_value, r.unit, r.severity,
		dl.id AS local_data_id, p.parameter_name, p.display_name, p.raw_value, p.last_seen
		FROM host AS h
		LEFT JOIN host_template AS ht ON ht.id = h.host_template_id
		INNER JOIN plugin_nms_device_classification AS ct ON ct.host_id = h.id
		INNER JOIN plugin_nms_categories AS c ON c.id = ct.category_id
		INNER JOIN plugin_nms_fault_rules AS r ON r.category_id = c.id
			AND r.enabled = 'on' AND r.metric = 'parameter'
		INNER JOIN data_local AS dl ON dl.host_id = h.id
		LEFT JOIN data_template_data AS dtd ON dtd.local_data_id = dl.id
		LEFT JOIN data_input AS di ON di.id = dtd.data_input_id
		INNER JOIN data_template_rrd AS dtr ON dtr.local_data_id = dl.id
			AND CONCAT('dtrr:', dtr.local_data_template_rrd_id) = r.parameter_key
		LEFT JOIN poller_item AS pi ON pi.local_data_id = dl.id AND pi.host_id = h.id AND pi.rrd_name = dtr.data_source_name
		LEFT JOIN plugin_nms_device_parameters AS p ON p.host_id = h.id
			AND p.local_data_id = dl.id AND p.parameter_key = r.parameter_key
		WHERE h.deleted = '' AND h.disabled = ''
		ORDER BY h.id, r.sort_order, r.id, dl.id");

	foreach ($parameter_rows as $row) {
		if (!nms_rule_scope_matches($row, array_merge($row, ["host_id" => $row["id"]]))) {
			continue;
		}
		$fingerprint = "device-rule:" . $row["rule_id"] . ":host:" . $row["id"] . ":data:" . $row["local_data_id"];
		$tests_unknown = in_array($row["comparison"], ["is_unknown", "is_not_unknown"], true);
		if (
			(int) $row["host_status"] !== HOST_UP ||
			!nms_parameter_is_fresh($row["host_last_updated"]) ||
			!nms_parameter_is_fresh($row["last_seen"]) ||
			(!$tests_unknown && !nms_parameter_has_current_value($row))
		) {
			$active[] = $fingerprint;
			continue;
		}
		$evaluated[] = $fingerprint;
		if (!nms_parameter_matches($row["raw_value"], $row["comparison"], $row["threshold_value"])) {
			continue;
		}
		$active[] = $fingerprint;
		$unit = trim($row["unit"]) !== "" ? " " . trim($row["unit"]) : "";
		$message =
			$row["display_name"] .
			" is " .
			$row["raw_value"] .
			$unit .
			". Rule: " .
			nms_comparison_label($row["comparison"]) .
			(in_array($row["comparison"], ["is_unknown", "is_not_unknown"], true)
				? ""
				: " " . $row["threshold_value"] . $unit) .
			". Category: " .
			$row["category_name"] .
			". Template: " .
			$row["template_name"] .
			".";
		nms_open_incident([
			"fingerprint" => $fingerprint,
			"source_type" => "device",
			"source_key" => $row["id"] . ":" . $row["rule_id"] . ":" . $row["local_data_id"],
			"host_id" => $row["id"],
			"local_data_id" => $row["local_data_id"],
			"severity" => $row["severity"],
			"title" => $row["description"] . " - " . $row["rule_name"],
			"message" => $message,
		]);
	}

	nms_resolve_missing("device", $active, $evaluated);
}

/**
 * Evaluate explicit inventory-status rules only against current checks on an Up host.
 * Legacy implicit-policy incidents are retained for review, never auto-cleared by an
 * absent rule. Sample records and retained successful strings cannot mask a failed poll.
 */
function nms_sync_inventory_faults()
{
	$rows = db_fetch_assoc("SELECT di.*, h.description, h.status AS host_status, h.last_updated AS host_last_updated,
		h.host_template_id, h.snmp_sysObjectID, h.snmp_version, ct.device_type,
		c.id AS category_id, c.name AS category_name
		FROM plugin_nms_device_inventory AS di
		INNER JOIN host AS h ON h.id = di.host_id AND h.deleted = '' AND h.disabled = ''
		LEFT JOIN plugin_nms_device_classification AS ct ON ct.host_id = h.id
		LEFT JOIN plugin_nms_categories AS c ON c.id = ct.category_id");
	$active = [];
	$evaluated = [];

	foreach ($rows as $row) {
		if (
			(int) $row["host_status"] !== HOST_UP ||
			!nms_parameter_is_fresh($row["host_last_updated"]) ||
			!nms_parameter_is_fresh($row["last_attempt"])
		) {
			continue;
		}
		if (in_array($row["status"], ["ok", "changed"], true) && !nms_parameter_is_fresh($row["last_success"])) {
			continue;
		}
		$parameter_key = "inventory_status:" . $row["inventory_key"];
		$rules =
			(int) $row["category_id"] > 0
				? db_fetch_assoc_prepared(
					"SELECT *
			FROM plugin_nms_fault_rules WHERE category_id = ? AND metric = 'inventory_status'
			AND parameter_key = ? AND enabled = 'on' ORDER BY sort_order, id",
					[$row["category_id"], $parameter_key],
				)
				: [];
		foreach ($rules as $rule) {
			if (!nms_rule_scope_matches($rule, $row)) {
				continue;
			}
			$fingerprint = "inventory-rule:" . (int) $rule["id"] . ":host:" . (int) $row["host_id"];
			$matches = nms_parameter_matches($row["status"], $rule["comparison"], $rule["threshold_value"]);
			// A failed/unconfigured read can raise an explicitly matching policy, but
			// cannot prove that a previously observed identity fault has recovered.
			if (!$matches && !in_array($row["status"], ["ok", "changed"], true)) {
				continue;
			}
			$evaluated[] = $fingerprint;
			if (!$matches) {
				continue;
			}
			$active[] = $fingerprint;
			$message =
				$row["display_name"] .
				" status is " .
				$row["status"] .
				". Rule: " .
				nms_comparison_label($rule["comparison"]) .
				(in_array($rule["comparison"], ["is_unknown", "is_not_unknown"], true)
					? ""
					: " " . $rule["threshold_value"]) .
				". Live SNMP OID: " .
				$row["oid"] .
				". Category: " .
				$row["category_name"] .
				".";
			nms_open_incident([
				"fingerprint" => $fingerprint,
				"source_type" => "inventory",
				"source_key" => $row["host_id"] . ":" . $rule["id"] . ":" . $row["inventory_key"],
				"host_id" => $row["host_id"],
				"severity" => $rule["severity"],
				"title" => $row["description"] . " - " . $rule["name"],
				"message" => $message,
			]);
		}
	}
	nms_resolve_missing("inventory", $active, $evaluated);
}

/** Reconcile device and inventory incidents, throttling calls to 30 seconds unless explicitly forced. */
function nms_sync_all_faults($force = false)
{
	$last = (int) db_fetch_cell_prepared("SELECT meta_value FROM plugin_nms_meta WHERE meta_key = 'last_sync'", []);
	if (!$force && $last > 0 && time() - $last < 30) {
		return;
	}

	nms_sync_device_faults();
	nms_sync_inventory_faults();
	/* Unsupported fault sources are not synthesized or silently auto-resolved. */

	nms_storage_execute(
		"INSERT INTO plugin_nms_meta (meta_key, meta_value, updated_at)
		VALUES ('last_sync', ?, ?)
		ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = VALUES(updated_at)",
		[(string) time(), nms_now()],
	);
}

/** Format a timestamp as elapsed seconds, minutes, hours, or days, clamping future times to zero. */
function nms_time_ago($date)
{
	$timestamp = strtotime($date);
	$seconds = max(0, time() - $timestamp);
	if ($seconds < 60) {
		return $seconds . "s ago";
	}
	if ($seconds < 3600) {
		return floor($seconds / 60) . "m ago";
	}
	if ($seconds < 86400) {
		return floor($seconds / 3600) . "h ago";
	}
	return floor($seconds / 86400) . "d ago";
}
