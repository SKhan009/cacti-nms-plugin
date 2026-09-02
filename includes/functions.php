<?php
/**
 * @file functions.php
 * Shared NMS helpers for escaping, Tree validation, rule catalogs, sample freshness, severity ordering, and incident lifecycle.
 * Controllers and poller hooks reuse these functions so fault behavior stays consistent across views.
 */

/** Escape a value for HTML using Cacti's shared escaping helper. */
function nms_h($value) {
	return html_escape((string) $value);
}

/** Return the current server time in the database timestamp format. */
function nms_now() {
	return date('Y-m-d H:i:s');
}

/** Summarize the same nondeleted core hosts displayed in Device Dashboard. */
function nms_device_inventory_counts($devices) {
	$counts = array('total' => 0, 'enabled' => 0, 'up' => 0, 'down' => 0);
	foreach ($devices as $device) {
		$counts['total']++;
		if ($device['disabled'] === 'on') continue;
		$counts['enabled']++;
		if ((int) $device['status'] === HOST_UP) $counts['up']++;
		if ((int) $device['status'] === HOST_DOWN) $counts['down']++;
	}
	return $counts;
}

/** Shared request user lookup for web, migration, and audit paths. */
function nms_current_user_id($fallback = 0) {
	$user_id = isset($_SESSION['sess_user_id']) ? (int) $_SESSION['sess_user_id'] : (int) $fallback;
	return $user_id > 0 ? $user_id : (int) $fallback;
}

/** Record NMS ownership of a validated Cacti object without replacing its original audit entry. */
function nms_managed_object_record($type, $object_id, $user_id = null) {
	$type = (string) $type;
	$object_id = (int) $object_id;
	if (!in_array($type, array('device', 'tree', 'graph_template', 'data_template', 'host_template'), true) || $object_id < 1) {
		throw new InvalidArgumentException('Invalid NMS-managed object.');
	}
	if ($user_id === null) $user_id = nms_current_user_id();
	db_execute_prepared('INSERT IGNORE INTO plugin_nms_managed_objects
		(object_type, object_id, created_by, created_at) VALUES (?, ?, ?, NOW())',
		array($type, $object_id, (int) $user_id));
}

/** Check whether the object ID and type are registered as NMS-managed. */
function nms_managed_object_exists($type, $object_id) {
	return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_managed_objects
		WHERE object_type = ? AND object_id = ?', array((string) $type, (int) $object_id)) === 1;
}

/** Remove the NMS ownership entry without deleting the underlying Cacti object. */
function nms_managed_object_forget($type, $object_id) {
	db_execute_prepared('DELETE FROM plugin_nms_managed_objects WHERE object_type = ? AND object_id = ?',
		array((string) $type, (int) $object_id));
}

/** Delete an NMS-created Cacti Tree and its plugin associations; reject unmanaged trees. */
function nms_tree_delete($tree_id) {
	$tree_id = (int) $tree_id;
	if ($tree_id < 1 || !nms_managed_object_exists('tree', $tree_id)) {
		throw new InvalidArgumentException('Only a Cacti Tree created by NMS can be deleted here.');
	}
	db_execute_prepared('DELETE FROM plugin_nms_category_templates WHERE category_id = ?', array($tree_id));
	db_execute_prepared('DELETE FROM plugin_nms_fault_rules WHERE category_id = ?', array($tree_id));
	db_execute_prepared('UPDATE plugin_nms_snmprec_imports SET category_id = 0 WHERE category_id = ?', array($tree_id));
	db_execute_prepared('DELETE FROM graph_tree_items WHERE graph_tree_id = ?', array($tree_id));
	db_execute_prepared('DELETE FROM graph_tree WHERE id = ?', array($tree_id));
	nms_managed_object_forget('tree', $tree_id);
	if (function_exists('set_config_option')) {
		set_config_option('time_last_change_tree', time());
		set_config_option('time_last_change_branch', time());
	}
	return $tree_id;
}

/** Read and cache the plugin INFO metadata for the current request. */
function nms_plugin_info() {
	static $info = null;
	if ($info !== null) return $info;
	$parsed = parse_ini_file(dirname(__DIR__) . '/INFO', true);
	$info = isset($parsed['info']) && is_array($parsed['info']) ? $parsed['info'] : array('version' => 'dev');
	return $info;
}

/** Read the plugin version once so every asset receives the same cache key. */
function nms_plugin_version() {
	$info = nms_plugin_info();
	return isset($info['version']) ? (string) $info['version'] : 'dev';
}

/** Build a Cacti-relative asset URL with the plugin version as its cache key. */
function nms_asset_url($path) {
	global $config;
	$path = ltrim((string) $path, '/');
	$separator = strpos($path, '?') === false ? '?' : '&';
	return $config['url_path'] . 'plugins/nms/' . $path . $separator . 'v=' . rawurlencode(nms_plugin_version());
}

/** Initialize the shared variables consumed by the NMS header, footer, and forms. */
function nms_prepare_page($module, $title, $extra_css = '', $extra_js = '') {
	global $config, $nms_csrf_token, $nms_backend_url,
		$nms_active_module, $nms_page_title, $nms_extra_css, $nms_extra_js;
	$nms_csrf_token = csrf_get_tokens();
	$nms_backend_url = $config['url_path'] . 'index.php';
	$nms_active_module = (string) $module;
	$nms_page_title = (string) $title;
	$nms_extra_css = (string) $extra_css;
	$nms_extra_js = (string) $extra_js;
}

/** Cacti Graph Trees are the only category source used by current NMS code. */
function nms_cacti_tree_exists($tree_id) {
	return (int) $tree_id > 0 && (int) db_fetch_cell_prepared(
		'SELECT COUNT(*) FROM graph_tree WHERE id = ?', array((int) $tree_id)
	) === 1;
}

/** Validate core template and Tree IDs, then persist their NMS category association. */
function nms_assign_template_tree($host_template_id, $tree_id) {
	$host_template_id = (int) $host_template_id;
	$tree_id = (int) $tree_id;
	if (!(int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host_template WHERE id = ?', array($host_template_id))) {
		throw new InvalidArgumentException('Select a valid Cacti host template.');
	}
	if (!nms_cacti_tree_exists($tree_id)) throw new InvalidArgumentException('Select a valid Cacti Tree.');
	db_execute_prepared('INSERT INTO plugin_nms_category_templates (host_template_id, category_id, assigned_at)
		VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), assigned_at = NOW()',
		array($host_template_id, $tree_id));
	return true;
}

/** Return Cacti's polling interval in seconds, using 300 when the setting is below 30. */
function nms_poller_interval() {
	$interval = (int) read_config_option('poller_interval');
	return $interval >= 30 ? $interval : 300;
}

/** Return the earliest accepted sample time, allowing two polling intervals or at least 120 seconds. */
function nms_parameter_fresh_after() {
	return date('Y-m-d H:i:s', time() - max(120, nms_poller_interval() * 2));
}

/** Define the supported comparisons, display wording, and threshold-input requirements. */
function nms_fault_comparison_definitions() {
	return array(
		'greater_than' => array('label' => 'Greater than (>)', 'sentence' => 'is greater than', 'numeric' => true, 'requires_value' => true),
		'greater_or_equal' => array('label' => 'Greater than or equal (≥)', 'sentence' => 'is greater than or equal to', 'numeric' => true, 'requires_value' => true),
		'less_than' => array('label' => 'Less than (<)', 'sentence' => 'is less than', 'numeric' => true, 'requires_value' => true),
		'less_or_equal' => array('label' => 'Less than or equal (≤)', 'sentence' => 'is less than or equal to', 'numeric' => true, 'requires_value' => true),
		'equals' => array('label' => 'Equals', 'sentence' => 'equals', 'numeric' => false, 'requires_value' => true),
		'not_equals' => array('label' => 'Does not equal', 'sentence' => 'does not equal', 'numeric' => false, 'requires_value' => true),
		'contains' => array('label' => 'Contains text', 'sentence' => 'contains', 'numeric' => false, 'requires_value' => true),
		'not_contains' => array('label' => 'Does not contain text', 'sentence' => 'does not contain', 'numeric' => false, 'requires_value' => true),
		'is_unknown' => array('label' => 'Is unknown or empty', 'sentence' => 'is unknown or empty', 'numeric' => false, 'requires_value' => false),
		'is_not_unknown' => array('label' => 'Has a valid value', 'sentence' => 'has a valid value', 'numeric' => false, 'requires_value' => false)
	);
}

/** Project comparison definitions into the key-to-label choices used by forms. */
function nms_fault_comparisons() {
	$labels = array();
	foreach (nms_fault_comparison_definitions() as $key => $definition) $labels[$key] = $definition['label'];
	return $labels;
}

/** Return the shared severity weights, with critical ranked highest. */
function nms_fault_severity_ranks() {
	return array('critical' => 3, 'major' => 2, 'warning' => 1);
}

/** Return supported severity names in descending priority order. */
function nms_fault_severities() {
	return array_keys(nms_fault_severity_ranks());
}

/** Convert a severity name to its priority weight; unknown names have rank zero. */
function nms_severity_rank($severity) {
	$ranks = nms_fault_severity_ranks();
	return $ranks[strtolower((string) $severity)] ?? 0;
}

/** Convert a known numeric priority into a severity name, or return an empty string. */
function nms_severity_from_rank($rank) {
	$severity = array_search((int) $rank, nms_fault_severity_ranks(), true);
	return $severity === false ? '' : $severity;
}

/** Fixed SQL fragments for internal queries; column names never come from a request. */
function nms_severity_rank_sql($column = 'severity') {
	if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $column)) throw new InvalidArgumentException('Invalid severity column.');
	$cases = array();
	foreach (nms_fault_severity_ranks() as $severity => $rank) $cases[] = "WHEN '$severity' THEN $rank";
	return "CASE $column " . implode(' ', $cases) . ' ELSE 0 END';
}

/** Build a priority-order SQL expression after validating the internal column identifier. */
function nms_severity_order_sql($column = 'severity') {
	if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $column)) throw new InvalidArgumentException('Invalid severity column.');
	return "FIELD($column, " . nms_sql_string_list(nms_fault_severities()) . ')';
}

/** Quote internal string values as a comma-separated SQL list, doubling embedded apostrophes. */
function nms_sql_string_list($values) {
	return implode(',', array_map(/** Quote one internal SQL literal, escaping embedded apostrophes. */ function($value) {
		return "'" . str_replace("'", "''", (string) $value) . "'";
	}, $values));
}

/** List incident sources that participate in the current device-monitoring views. */
function nms_monitored_incident_sources() {
	return array('device', 'inventory');
}

/** Return the monitored incident sources as quoted SQL literals. */
function nms_monitored_incident_sources_sql() {
	return nms_sql_string_list(nms_monitored_incident_sources());
}

/** List incident states that remain active until the fault is resolved. */
function nms_active_incident_statuses() {
	return array('open', 'acknowledged');
}

/** Return active incident states as quoted SQL literals for shared queries. */
function nms_active_incident_statuses_sql() {
	return nms_sql_string_list(nms_active_incident_statuses());
}

/** Classify a parameter key as core device status, inventory status, or a sampled parameter. */
function nms_fault_metric_for_parameter($parameter_key) {
	if ($parameter_key === 'core:status') return 'core_status';
	if (strpos((string) $parameter_key, 'inventory_status:') === 0) return 'inventory_status';
	return 'parameter';
}

/**
 * Validate a reusable rule parameter against current Cacti-owned objects.
 * The guard prevents a fatal redeclaration during a rolling deployment where
 * an older fault_config.php containing the former page-local helper is active.
 */
if (!function_exists('nms_fault_parameter_exists')) {
	/** Check that a rule parameter belongs to the selected Cacti Tree and current monitored objects. */
	function nms_fault_parameter_exists($tree_id, $parameter_key) {
		$tree_id = (int) $tree_id;
		if (!nms_cacti_tree_exists($tree_id)) return false;
		if ($parameter_key === 'core:status') return true;
		if (strpos((string) $parameter_key, 'inventory_status:') === 0) {
			$inventory_key = substr($parameter_key, strlen('inventory_status:'));
			if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $inventory_key)) return false;
			return (int) db_fetch_cell_prepared("SELECT COUNT(*)
				FROM plugin_nms_category_templates AS ct
				INNER JOIN plugin_nms_snmprec_imports AS i ON i.host_template_id = ct.host_template_id
				INNER JOIN plugin_nms_snmprec_oids AS o ON o.import_id = i.id
					AND o.inventory_key = ? AND o.inventory_key != ''
				WHERE ct.category_id = ?", array($inventory_key, $tree_id)) > 0;
		}
		if (strpos((string) $parameter_key, 'dtrr:') !== 0) return false;
		$template_item_id = (int) substr($parameter_key, 5);
		return $template_item_id > 0 && (int) db_fetch_cell_prepared("SELECT COUNT(*)
			FROM plugin_nms_category_templates AS ct
			INNER JOIN host AS h ON h.host_template_id = ct.host_template_id AND h.deleted = '' AND h.disabled = ''
			INNER JOIN poller_item AS pi ON pi.host_id = h.id
			INNER JOIN data_template_rrd AS dtr ON dtr.local_data_id = pi.local_data_id
			WHERE ct.category_id = ? AND dtr.local_data_template_rrd_id = ?",
			array($tree_id, $template_item_id)) > 0;
	}
}

/** Turn an inventory key into its operator-facing label, recognizing chassis serial numbers. */
function nms_inventory_display_name($inventory_key) {
	$labels = array('serial_number' => 'Chassis serial number');
	return $labels[$inventory_key] ?? ucwords(str_replace('_', ' ', (string) $inventory_key));
}

/** Build the one shared parameter catalog used by the fault-rule interface. */
function nms_fault_parameter_catalog($tree_id) {
	$tree_id = (int) $tree_id;
	if (!nms_cacti_tree_exists($tree_id)) return array();
	$parameters = db_fetch_assoc_prepared("SELECT
		CONCAT('dtrr:', dtr.local_data_template_rrd_id) AS parameter_key,
		COALESCE(NULLIF(dt.name, ''), CONCAT('Cacti data template ', dtr.data_template_id)) AS template_name,
		dtr.data_source_name AS parameter_name,
		COUNT(DISTINCT h.id) AS device_count,
		GROUP_CONCAT(DISTINCT NULLIF(p.raw_value, '') ORDER BY p.last_seen DESC SEPARATOR ', ') AS latest_values
		FROM plugin_nms_category_templates AS ct
		INNER JOIN host AS h ON h.host_template_id = ct.host_template_id AND h.deleted = '' AND h.disabled = ''
		INNER JOIN poller_item AS pi ON pi.host_id = h.id
		INNER JOIN data_template_rrd AS dtr ON dtr.local_data_id = pi.local_data_id
		LEFT JOIN data_template AS dt ON dt.id = dtr.data_template_id
		LEFT JOIN plugin_nms_device_parameters AS p ON p.host_id = h.id
			AND p.local_data_id = dtr.local_data_id
			AND p.parameter_key = CONCAT('dtrr:', dtr.local_data_template_rrd_id)
			AND p.last_seen >= ? AND h.status = " . HOST_UP . "
		WHERE ct.category_id = ? AND dtr.local_data_template_rrd_id > 0
		GROUP BY dtr.local_data_template_rrd_id, dt.name, dtr.data_source_name
		ORDER BY dt.name, dtr.data_source_name", array(nms_parameter_fresh_after(), $tree_id));

	$inventory = db_fetch_assoc_prepared("SELECT o.inventory_key,
		COUNT(DISTINCT h.id) AS device_count,
		GROUP_CONCAT(DISTINCT NULLIF(di.status, '') ORDER BY di.last_attempt DESC SEPARATOR ', ') AS latest_values
		FROM plugin_nms_category_templates AS ct
		INNER JOIN plugin_nms_snmprec_imports AS i ON i.host_template_id = ct.host_template_id
		INNER JOIN plugin_nms_snmprec_oids AS o ON o.import_id = i.id AND o.inventory_key != ''
		LEFT JOIN host AS h ON h.host_template_id = ct.host_template_id AND h.deleted = '' AND h.disabled = ''
		LEFT JOIN plugin_nms_device_inventory AS di ON di.host_id = h.id AND di.inventory_key = o.inventory_key
		WHERE ct.category_id = ?
		GROUP BY o.inventory_key ORDER BY o.inventory_key", array($tree_id));
	foreach ($inventory as $item) {
		$parameters[] = array(
			'parameter_key' => 'inventory_status:' . $item['inventory_key'],
			'template_name' => 'Live inventory',
			'parameter_name' => nms_inventory_display_name($item['inventory_key']) . ' status',
			'device_count' => $item['device_count'],
			'latest_values' => $item['latest_values']
		);
	}
	return $parameters;
}

/** Create or update a tree-scoped fault rule through one validation path. */
function nms_fault_rule_save($tree_id, $rule_id, $input) {
	$tree_id = (int) $tree_id;
	$rule_id = (int) $rule_id;
	$name = substr(trim((string) ($input['name'] ?? '')), 0, 150);
	$parameter_key = substr(trim((string) ($input['parameter_key'] ?? '')), 0, 191);
	$comparison = (string) ($input['comparison'] ?? '');
	$threshold_value = substr(trim((string) ($input['threshold_value'] ?? '')), 0, 191);
	$unit = substr(trim((string) ($input['unit'] ?? '')), 0, 24);
	$severity = (string) ($input['severity'] ?? '');
	$enabled = !empty($input['enabled']) ? 'on' : '';

	if ($rule_id > 0 && (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_fault_rules
		WHERE id = ? AND category_id = ?', array($rule_id, $tree_id)) !== 1) {
		throw new InvalidArgumentException('Select a valid fault rule from this Cacti Tree.');
	}

	$parameter_exists = nms_fault_parameter_exists($tree_id, $parameter_key);
	if (!$parameter_exists && $rule_id > 0 && nms_cacti_tree_exists($tree_id)) {
		/* An existing rule remains editable when its device is temporarily absent. */
		$parameter_exists = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_fault_rules
			WHERE id = ? AND category_id = ? AND parameter_key = ?', array($rule_id, $tree_id, $parameter_key)) === 1;
	}
	$comparisons = nms_fault_comparison_definitions();
	$definition = $comparisons[$comparison] ?? null;
	$threshold_valid = $definition && (!$definition['requires_value'] ||
		($definition['numeric'] ? is_numeric($threshold_value) : $threshold_value !== ''));
	if ($name === '' || !$definition ||
		!in_array($severity, nms_fault_severities(), true) || !$threshold_valid || !$parameter_exists) {
		throw new InvalidArgumentException('Enter a valid rule name, parameter, comparison, fault value, and severity.');
	}

	$metric = nms_fault_metric_for_parameter($parameter_key);
	if ($rule_id > 0) {
		db_execute_prepared('UPDATE plugin_nms_fault_rules SET name = ?, metric = ?, comparison = ?,
			threshold_value = ?, unit = ?, severity = ?, enabled = ?, updated_at = NOW()
			WHERE id = ? AND category_id = ?', array(
			$name, $metric, $comparison, $threshold_value, $unit, $severity, $enabled, $rule_id, $tree_id
		));
		return $rule_id;
	}

	$sort_order = (int) db_fetch_cell_prepared('SELECT COALESCE(MAX(sort_order), 0) + 10
		FROM plugin_nms_fault_rules WHERE category_id = ?', array($tree_id));
	db_execute_prepared('INSERT INTO plugin_nms_fault_rules
		(category_id, name, metric, parameter_key, comparison, threshold, threshold_value, unit,
		severity, enabled, sort_order, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, NOW(), NOW())', array(
		$tree_id, $name, $metric, $parameter_key, $comparison, $threshold_value,
		$unit, $severity, 'on', $sort_order
	));
	return (int) db_fetch_cell('SELECT LAST_INSERT_ID()');
}

/** Append an incident audit event with severity, actor, message, and server timestamp. */
function nms_event($incident_id, $event_type, $severity, $message, $user_id = 0) {
	db_execute_prepared('INSERT INTO plugin_nms_events
		(incident_id, event_type, severity, message, user_id, created_at)
		VALUES (?, ?, ?, ?, ?, ?)',
		array($incident_id, $event_type, $severity, $message, $user_id, nms_now()));
}

/** Create or refresh an incident by fingerprint and record relevant lifecycle changes. */
function nms_open_incident($fault) {
	$now = nms_now();
	$current = db_fetch_row_prepared('SELECT * FROM plugin_nms_incidents WHERE fingerprint = ?', array($fault['fingerprint']));

	if (!cacti_sizeof($current)) {
		db_execute_prepared('INSERT INTO plugin_nms_incidents
			(fingerprint, source_type, source_key, host_id, poller_id, local_data_id,
			severity, status, title, message, first_seen, last_seen)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', array(
				$fault['fingerprint'],
				$fault['source_type'],
				$fault['source_key'],
				isset($fault['host_id']) ? (int) $fault['host_id'] : 0,
				isset($fault['poller_id']) ? (int) $fault['poller_id'] : 0,
				isset($fault['local_data_id']) ? (int) $fault['local_data_id'] : 0,
				$fault['severity'],
				'open',
				$fault['title'],
				$fault['message'],
				$now,
				$now
			));

		$id = db_fetch_cell('SELECT LAST_INSERT_ID()');
		nms_event($id, 'opened', $fault['severity'], $fault['message']);
		cacti_log('Opened incident [' . $fault['fingerprint'] . '] ' . $fault['title'], false, 'NMS');
		return $id;
	}

	if ($current['status'] === 'resolved') {
		db_execute_prepared("UPDATE plugin_nms_incidents
			SET source_type = ?, source_key = ?, host_id = ?, poller_id = ?, local_data_id = ?,
				severity = ?, status = 'open', title = ?, message = ?, first_seen = ?, last_seen = ?,
				acknowledged_by = 0, acknowledged_at = NULL, resolved_at = NULL
			WHERE id = ?", array(
				$fault['source_type'],
				$fault['source_key'],
				isset($fault['host_id']) ? (int) $fault['host_id'] : 0,
				isset($fault['poller_id']) ? (int) $fault['poller_id'] : 0,
				isset($fault['local_data_id']) ? (int) $fault['local_data_id'] : 0,
				$fault['severity'],
				$fault['title'],
				$fault['message'],
				$now,
				$now,
				$current['id']
			));
		nms_event($current['id'], 'reopened', $fault['severity'], $fault['message']);
		cacti_log('Reopened incident [' . $fault['fingerprint'] . '] ' . $fault['title'], false, 'NMS');
	} else {
		db_execute_prepared('UPDATE plugin_nms_incidents
			SET severity = ?, title = ?, message = ?, last_seen = ?, host_id = ?, poller_id = ?, local_data_id = ?
			WHERE id = ?', array(
				$fault['severity'],
				$fault['title'],
				$fault['message'],
				$now,
				isset($fault['host_id']) ? (int) $fault['host_id'] : 0,
				isset($fault['poller_id']) ? (int) $fault['poller_id'] : 0,
				isset($fault['local_data_id']) ? (int) $fault['local_data_id'] : 0,
				$current['id']
			));
	}

	return $current['id'];
}

/** Clear an active incident and record its resolution; return false when no active match exists. */
function nms_resolve_incident($fingerprint, $message = 'Fault condition cleared automatically', $user_id = 0) {
	$active_statuses_sql = nms_active_incident_statuses_sql();
	$current = db_fetch_row_prepared("SELECT * FROM plugin_nms_incidents
		WHERE fingerprint = ? AND status IN ($active_statuses_sql)", array($fingerprint));

	if (!cacti_sizeof($current)) {
		return false;
	}

	$now = nms_now();
	db_execute_prepared("UPDATE plugin_nms_incidents
		SET status = 'resolved', resolved_at = ?, last_seen = ? WHERE id = ?", array($now, $now, $current['id']));
	nms_event($current['id'], 'resolved', $current['severity'], $message, $user_id);
	cacti_log('Resolved incident [' . $fingerprint . '] ' . $current['title'], false, 'NMS');

	return true;
}

/** Resolve active incidents of this source that are absent from the current fault fingerprints. */
function nms_resolve_missing($source_type, $active_fingerprints) {
	$active_statuses_sql = nms_active_incident_statuses_sql();
	$rows = db_fetch_assoc_prepared("SELECT fingerprint FROM plugin_nms_incidents
		WHERE source_type = ? AND status IN ($active_statuses_sql)", array($source_type));

	$active = array_fill_keys($active_fingerprints, true);
	foreach ($rows as $row) {
		if (!isset($active[$row['fingerprint']])) {
			nms_resolve_incident($row['fingerprint']);
		}
	}
}

/** Acknowledge an open incident for the given user and append its audit event. */
function nms_acknowledge_incident($id, $user_id) {
	$current = db_fetch_row_prepared("SELECT * FROM plugin_nms_incidents WHERE id = ? AND status = 'open'", array($id));
	if (!cacti_sizeof($current)) {
		return false;
	}

	$now = nms_now();
	db_execute_prepared("UPDATE plugin_nms_incidents
		SET status = 'acknowledged', acknowledged_by = ?, acknowledged_at = ? WHERE id = ?",
		array($user_id, $now, $id));
	nms_event($id, 'acknowledged', $current['severity'], 'Incident acknowledged', $user_id);

	return true;
}

/** Translate Cacti host-status constants into labels while exposing unrecognized states. */
function nms_host_status_name($status) {
	$map = array(
		HOST_UNKNOWN => 'Unknown',
		HOST_DOWN => 'Down',
		HOST_RECOVERING => 'Recovering',
		HOST_UP => 'Up',
		HOST_ERROR => 'Error'
	);
	return isset($map[$status]) ? $map[$status] : 'Invalid state ' . $status;
}

/** Evaluate a raw reading against a comparison and threshold, including explicit unknown-value rules. */
function nms_parameter_matches($raw_value, $comparison, $threshold_value) {
	$raw = trim((string) $raw_value);
	$threshold = trim((string) $threshold_value);
	$raw_lower = strtolower($raw);
	$threshold_lower = strtolower($threshold);
	$is_unknown = $raw === '' || in_array($raw_lower, array('u', 'unknown', 'nan', 'null'), true);

	if ($comparison === 'is_unknown') return $is_unknown;
	if ($comparison === 'is_not_unknown') return !$is_unknown;
	if ($comparison === 'contains') return $threshold !== '' && strpos($raw_lower, $threshold_lower) !== false;
	if ($comparison === 'not_contains') return $threshold !== '' && strpos($raw_lower, $threshold_lower) === false;
	if ($comparison === 'equals') return $raw_lower === $threshold_lower;
	if ($comparison === 'not_equals') return $raw_lower !== $threshold_lower;

	if (!is_numeric($raw) || !is_numeric($threshold)) return false;
	$current = (float) $raw;
	$limit = (float) $threshold;
	if ($comparison === 'greater_than') return $current > $limit;
	if ($comparison === 'greater_or_equal') return $current >= $limit;
	if ($comparison === 'less_than') return $current < $limit;
	if ($comparison === 'less_or_equal') return $current <= $limit;

	return false;
}

/** Return sentence wording for a comparison, preserving an unrecognized key for display. */
function nms_comparison_label($comparison) {
	$definitions = nms_fault_comparison_definitions();
	return isset($definitions[$comparison]) ? $definitions[$comparison]['sentence'] : (string) $comparison;
}

/**
 * A retained value is historical once Cacti has missed more than two expected
 * poll intervals.  Historical values must not be evaluated as current faults
 * or displayed as a fallback when the SNMP endpoint is unavailable.
 */
function nms_parameter_is_fresh($last_seen) {
	$timestamp = strtotime((string) $last_seen);
	return $timestamp !== false && time() - $timestamp <= max(120, nms_poller_interval() * 2);
}

/** Require an Up host, a fresh sample, and a nonempty known value before treating a reading as current. */
function nms_parameter_has_current_value($parameter) {
	if (isset($parameter['host_status']) && (int) $parameter['host_status'] !== HOST_UP) return false;
	if (!nms_parameter_is_fresh($parameter['last_seen'] ?? '')) return false;
	$value = strtolower(trim((string) ($parameter['raw_value'] ?? '')));
	return $value !== '' && !in_array($value, array('u', 'unknown', 'nan', 'null'), true);
}

/** Evaluate Tree-scoped device and sampled-parameter rules against Cacti data and reconcile incidents. */
function nms_sync_device_faults() {
	$core_rows = db_fetch_assoc("SELECT h.*, ht.name AS template_name,
		c.id AS category_id, c.name AS category_name,
		r.id AS rule_id, r.name AS rule_name, r.parameter_key, r.comparison,
		r.threshold_value, r.unit, r.severity
		FROM host AS h
		INNER JOIN host_template AS ht ON ht.id = h.host_template_id
		INNER JOIN plugin_nms_category_templates AS ct ON ct.host_template_id = h.host_template_id
		INNER JOIN graph_tree AS c ON c.id = ct.category_id
		INNER JOIN plugin_nms_fault_rules AS r ON r.category_id = c.id
			AND r.enabled = 'on' AND r.metric = 'core_status'
		WHERE h.deleted = '' AND h.disabled = ''
		ORDER BY h.id, r.sort_order, r.id");
	$active = array();

	foreach ($core_rows as $row) {
		$current = strtolower(nms_host_status_name((int) $row['status']));
		if (!nms_parameter_matches($current, $row['comparison'], $row['threshold_value'])) continue;

		$fingerprint = 'device-rule:' . $row['rule_id'] . ':host:' . $row['id'];
		$active[] = $fingerprint;
		$message = 'Device state is ' . $current . '. The configured healthy value is ' .
			$row['threshold_value'] . '. Category: ' . $row['category_name'] . '. Template: ' . $row['template_name'] . '.';
		nms_open_incident(array(
			'fingerprint' => $fingerprint,
			'source_type' => 'device',
			'source_key' => $row['id'] . ':' . $row['rule_id'],
			'host_id' => $row['id'],
			'severity' => $row['severity'],
			'title' => $row['description'] . ' - ' . $row['rule_name'],
			'message' => $message
		));
	}

	$parameter_rows = db_fetch_assoc("SELECT h.id, h.description, ht.name AS template_name,
		c.name AS category_name, r.id AS rule_id, r.name AS rule_name, r.parameter_key,
		r.comparison, r.threshold_value, r.unit, r.severity,
		p.local_data_id, p.parameter_name, p.display_name, p.raw_value, p.last_seen
		FROM host AS h
		INNER JOIN host_template AS ht ON ht.id = h.host_template_id
		INNER JOIN plugin_nms_category_templates AS ct ON ct.host_template_id = h.host_template_id
		INNER JOIN graph_tree AS c ON c.id = ct.category_id
		INNER JOIN plugin_nms_fault_rules AS r ON r.category_id = c.id
			AND r.enabled = 'on' AND r.metric = 'parameter'
		INNER JOIN plugin_nms_device_parameters AS p ON p.host_id = h.id
			AND p.parameter_key = r.parameter_key
		WHERE h.deleted = '' AND h.disabled = '' AND h.status = " . HOST_UP . "
		ORDER BY h.id, r.sort_order, r.id, p.local_data_id");

	foreach ($parameter_rows as $row) {
		if (!nms_parameter_is_fresh($row['last_seen'])) continue;
		if (!nms_parameter_matches($row['raw_value'], $row['comparison'], $row['threshold_value'])) continue;
		$fingerprint = 'device-rule:' . $row['rule_id'] . ':host:' . $row['id'] . ':data:' . $row['local_data_id'];
		$active[] = $fingerprint;
		$unit = trim($row['unit']) !== '' ? ' ' . trim($row['unit']) : '';
		$message = $row['display_name'] . ' is ' . $row['raw_value'] . $unit . '. Rule: ' .
			nms_comparison_label($row['comparison']) .
			(in_array($row['comparison'], array('is_unknown', 'is_not_unknown'), true) ? '' : ' ' . $row['threshold_value'] . $unit) .
			'. Category: ' . $row['category_name'] . '. Template: ' . $row['template_name'] . '.';
		nms_open_incident(array(
			'fingerprint' => $fingerprint,
			'source_type' => 'device',
			'source_key' => $row['id'] . ':' . $row['rule_id'] . ':' . $row['local_data_id'],
			'host_id' => $row['id'],
			'local_data_id' => $row['local_data_id'],
			'severity' => $row['severity'],
			'title' => $row['description'] . ' - ' . $row['rule_name'],
			'message' => $message
		));
	}

	nms_resolve_missing('device', $active);
}

/**
 * Evaluate text-inventory health that Cacti/RRDtool cannot retain. A matching
 * tree rule makes severity configurable; until one exists, changed/failed
 * serial identity keeps the built-in safe monitoring behavior.
 */
function nms_sync_inventory_faults() {
	$rows = db_fetch_assoc("SELECT di.*, h.description, h.status AS host_status,
		c.id AS category_id, c.name AS category_name
		FROM plugin_nms_device_inventory AS di
		INNER JOIN host AS h ON h.id = di.host_id AND h.deleted = '' AND h.disabled = ''
		LEFT JOIN plugin_nms_category_templates AS ct ON ct.host_template_id = h.host_template_id
		LEFT JOIN graph_tree AS c ON c.id = ct.category_id");
	$active = array();

	foreach ($rows as $row) {
		/* Device-down is already represented by the Cacti core-status rule. */
		if ($row['status'] === 'failed' && (int) $row['host_status'] !== HOST_UP) continue;
		$parameter_key = 'inventory_status:' . $row['inventory_key'];
		$rules = (int) $row['category_id'] > 0 ? db_fetch_assoc_prepared("SELECT *
			FROM plugin_nms_fault_rules WHERE category_id = ? AND metric = 'inventory_status'
			AND parameter_key = ? ORDER BY sort_order, id", array($row['category_id'], $parameter_key)) : array();

		if (count($rules)) {
			foreach ($rules as $rule) {
				if ($rule['enabled'] !== 'on' || !nms_parameter_matches($row['status'], $rule['comparison'], $rule['threshold_value'])) continue;
				$fingerprint = 'inventory-rule:' . (int) $rule['id'] . ':host:' . (int) $row['host_id'];
				$active[] = $fingerprint;
				$message = $row['display_name'] . ' status is ' . $row['status'] . '. Rule: ' .
					nms_comparison_label($rule['comparison']) .
					(in_array($rule['comparison'], array('is_unknown', 'is_not_unknown'), true) ? '' : ' ' . $rule['threshold_value']) .
					'. Live SNMP OID: ' . $row['oid'] . '. Category: ' . ($row['category_name'] ?: 'Unmapped') . '.';
				nms_open_incident(array(
					'fingerprint' => $fingerprint,
					'source_type' => 'inventory',
					'source_key' => $row['host_id'] . ':' . $rule['id'] . ':' . $row['inventory_key'],
					'host_id' => $row['host_id'],
					'severity' => $rule['severity'],
					'title' => $row['description'] . ' - ' . $rule['name'],
					'message' => $message
				));
			}
			continue;
		}

		if (!in_array($row['status'], array('changed', 'failed'), true)) continue;
		$fingerprint = 'inventory:host:' . (int) $row['host_id'] . ':' . $row['inventory_key'];
		$active[] = $fingerprint;
		$severity = $row['status'] === 'changed' ? 'major' : 'warning';
		$title = $row['status'] === 'changed'
			? $row['description'] . ' - ' . $row['display_name'] . ' changed'
			: $row['description'] . ' - inventory reading failed';
		$message = $row['status'] === 'changed'
			? $row['display_name'] . ' changed from ' . $row['baseline_value'] . ' to ' . $row['observed_value'] .
				'. Live SNMP OID: ' . $row['oid'] . '.'
			: $row['last_error'] . ' Live SNMP OID: ' . $row['oid'] . '.';
		nms_open_incident(array(
			'fingerprint' => $fingerprint,
			'source_type' => 'inventory',
			'source_key' => $row['host_id'] . ':' . $row['inventory_key'],
			'host_id' => $row['host_id'],
			'severity' => $severity,
			'title' => $title,
			'message' => $message
		));
	}

	nms_resolve_missing('inventory', $active);
}

/** Reconcile device and inventory incidents, throttling calls to 30 seconds unless explicitly forced. */
function nms_sync_all_faults($force = false) {
	$last = (int) db_fetch_cell_prepared("SELECT meta_value FROM plugin_nms_meta WHERE meta_key = 'last_sync'", array());
	if (!$force && $last > 0 && time() - $last < 30) {
		return;
	}

	nms_sync_device_faults();
	nms_sync_inventory_faults();
	/* Unsupported fault sources are not synthesized or silently auto-resolved. */

	db_execute_prepared("INSERT INTO plugin_nms_meta (meta_key, meta_value, updated_at)
		VALUES ('last_sync', ?, ?)
		ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = VALUES(updated_at)",
		array((string) time(), nms_now()));
}

/** Format a timestamp as elapsed seconds, minutes, hours, or days, clamping future times to zero. */
function nms_time_ago($date) {
	$timestamp = strtotime($date);
	$seconds = max(0, time() - $timestamp);
	if ($seconds < 60) return $seconds . 's ago';
	if ($seconds < 3600) return floor($seconds / 60) . 'm ago';
	if ($seconds < 86400) return floor($seconds / 3600) . 'h ago';
	return floor($seconds / 86400) . 'd ago';
}
