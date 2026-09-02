<?php
/**
 * @file database.php
 * Create and migrate NMS-owned tables, translate legacy categories to Cacti Tree IDs, and seed fault configuration.
 * The uninstall helper removes plugin storage without dropping Cacti core tables.
 */

require_once(__DIR__ . '/functions.php');

/** Create or migrate plugin-owned storage and seed configuration alongside existing Cacti core tables. */
function nms_setup_database() {
	db_execute("CREATE TABLE IF NOT EXISTS plugin_nms_incidents (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		fingerprint VARCHAR(191) NOT NULL,
		source_type VARCHAR(32) NOT NULL,
		source_key VARCHAR(191) NOT NULL,
		host_id INT UNSIGNED NOT NULL DEFAULT 0,
		poller_id INT UNSIGNED NOT NULL DEFAULT 0,
		local_data_id INT UNSIGNED NOT NULL DEFAULT 0,
		severity VARCHAR(16) NOT NULL DEFAULT 'warning',
		status VARCHAR(16) NOT NULL DEFAULT 'open',
		title VARCHAR(255) NOT NULL,
		message TEXT NOT NULL,
		first_seen DATETIME NOT NULL,
		last_seen DATETIME NOT NULL,
		acknowledged_by INT UNSIGNED NOT NULL DEFAULT 0,
		acknowledged_at DATETIME NULL DEFAULT NULL,
		resolved_at DATETIME NULL DEFAULT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY fingerprint (fingerprint),
		KEY status_severity (status, severity),
		KEY source_type (source_type),
		KEY host_id (host_id),
		KEY poller_id (poller_id),
		KEY local_data_id (local_data_id),
		KEY last_seen (last_seen)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");

	db_execute("CREATE TABLE IF NOT EXISTS plugin_nms_events (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		incident_id BIGINT UNSIGNED NOT NULL,
		event_type VARCHAR(24) NOT NULL,
		severity VARCHAR(16) NOT NULL,
		message TEXT NOT NULL,
		user_id INT UNSIGNED NOT NULL DEFAULT 0,
		created_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		KEY incident_id (incident_id),
		KEY created_at (created_at),
		KEY event_type (event_type)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");

	db_execute("CREATE TABLE IF NOT EXISTS plugin_nms_meta (
		meta_key VARCHAR(64) NOT NULL,
		meta_value TEXT NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (meta_key)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");

	/* Ownership is explicit: destructive controls are never inferred from a Cacti name at request time. */
	db_execute("CREATE TABLE IF NOT EXISTS plugin_nms_managed_objects (
		object_type VARCHAR(32) NOT NULL,
		object_id INT UNSIGNED NOT NULL,
		created_by INT UNSIGNED NOT NULL DEFAULT 0,
		created_at DATETIME NOT NULL,
		PRIMARY KEY (object_type, object_id),
		KEY created_by (created_by)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");
	/* Cacti remains the source of device data. This table stores presentation only. */
	db_execute("CREATE TABLE IF NOT EXISTS plugin_nms_topology (
		host_id INT UNSIGNED NOT NULL,
		site_id INT UNSIGNED NOT NULL DEFAULT 0,
		parent_host_id INT UNSIGNED NOT NULL DEFAULT 0,
		parent_snmp_index VARCHAR(191) NOT NULL DEFAULT '',
		pos_x DECIMAL(6,2) NOT NULL DEFAULT 50.00,
		pos_y DECIMAL(6,2) NOT NULL DEFAULT 50.00,
		locked CHAR(2) NOT NULL DEFAULT '',
		updated_by INT UNSIGNED NOT NULL DEFAULT 0,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (host_id),
		KEY site_id (site_id),
		KEY parent_host_id (parent_host_id)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");

	db_execute("CREATE TABLE IF NOT EXISTS plugin_nms_category_templates (
		host_template_id MEDIUMINT UNSIGNED NOT NULL,
		category_id INT UNSIGNED NOT NULL,
		assigned_at DATETIME NOT NULL,
		PRIMARY KEY (host_template_id),
		KEY category_id (category_id)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");

	db_execute("CREATE TABLE IF NOT EXISTS plugin_nms_fault_rules (
		id INT UNSIGNED NOT NULL AUTO_INCREMENT,
		category_id INT UNSIGNED NOT NULL,
		name VARCHAR(150) NOT NULL,
		metric VARCHAR(40) NOT NULL,
		threshold DECIMAL(12,3) NOT NULL DEFAULT 0,
		severity VARCHAR(16) NOT NULL DEFAULT 'warning',
		enabled CHAR(2) NOT NULL DEFAULT 'on',
		sort_order INT UNSIGNED NOT NULL DEFAULT 0,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		KEY category_id (category_id),
		KEY enabled (enabled),
		UNIQUE KEY category_metric (category_id, metric)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");

	if (!(int) db_fetch_cell("SELECT COUNT(*) FROM information_schema.COLUMNS
		WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_nms_fault_rules' AND COLUMN_NAME = 'parameter_key'")) {
		db_execute("ALTER TABLE plugin_nms_fault_rules
			ADD parameter_key VARCHAR(191) NOT NULL DEFAULT '' AFTER metric,
			ADD comparison VARCHAR(20) NOT NULL DEFAULT 'greater_than' AFTER parameter_key,
			ADD threshold_value VARCHAR(191) NOT NULL DEFAULT '' AFTER threshold,
			ADD unit VARCHAR(24) NOT NULL DEFAULT '' AFTER threshold_value");
	}
	if ((int) db_fetch_cell("SELECT COUNT(*) FROM information_schema.STATISTICS
		WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_nms_fault_rules' AND INDEX_NAME = 'category_metric'")) {
		db_execute('ALTER TABLE plugin_nms_fault_rules DROP INDEX category_metric');
	}
	if (!(int) db_fetch_cell("SELECT COUNT(*) FROM information_schema.STATISTICS
		WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_nms_fault_rules' AND INDEX_NAME = 'category_parameter'")) {
		db_execute('ALTER TABLE plugin_nms_fault_rules ADD KEY category_parameter (category_id, parameter_key)');
	}

	db_execute("CREATE TABLE IF NOT EXISTS plugin_nms_device_parameters (
		host_id MEDIUMINT UNSIGNED NOT NULL,
		local_data_id INT UNSIGNED NOT NULL,
		parameter_key VARCHAR(191) NOT NULL,
		parameter_name VARCHAR(100) NOT NULL,
		display_name VARCHAR(255) NOT NULL,
		raw_value VARCHAR(512) NOT NULL,
		numeric_value DECIMAL(30,8) NULL DEFAULT NULL,
		last_seen DATETIME NOT NULL,
		PRIMARY KEY (host_id, local_data_id, parameter_key),
		KEY parameter_key (parameter_key),
		KEY last_seen (last_seen)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");

	/*
	 * Cacti/RRDtool owns numeric time-series data.  This table exists only for
	 * live text inventory values (for example a chassis serial number) that
	 * RRDtool cannot store.  It deliberately keeps one current value per field,
	 * not a second history of device readings.
	 */
	db_execute("CREATE TABLE IF NOT EXISTS plugin_nms_device_inventory (
		host_id MEDIUMINT UNSIGNED NOT NULL,
		inventory_key VARCHAR(64) NOT NULL,
		oid VARCHAR(255) NOT NULL,
		display_name VARCHAR(150) NOT NULL,
		baseline_value VARCHAR(512) NOT NULL DEFAULT '',
		observed_value VARCHAR(512) NOT NULL DEFAULT '',
		status VARCHAR(16) NOT NULL DEFAULT 'unknown',
		last_attempt DATETIME NULL DEFAULT NULL,
		last_success DATETIME NULL DEFAULT NULL,
		last_error VARCHAR(255) NOT NULL DEFAULT '',
		PRIMARY KEY (host_id, inventory_key),
		KEY status (status),
		KEY last_success (last_success)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");

	/* Upload history and generated Cacti object links. Uploaded values remain in SNMPSim. */
	db_execute("CREATE TABLE IF NOT EXISTS plugin_nms_snmprec_imports (
		id INT UNSIGNED NOT NULL AUTO_INCREMENT,
		original_name VARCHAR(255) NOT NULL,
		community VARCHAR(100) NOT NULL,
		template_name VARCHAR(150) NOT NULL,
		host_template_id MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
		category_id INT UNSIGNED NOT NULL DEFAULT 0,
		record_count INT UNSIGNED NOT NULL DEFAULT 0,
		graphable_count INT UNSIGNED NOT NULL DEFAULT 0,
		file_hash CHAR(64) NOT NULL,
		deployed_path VARCHAR(512) NOT NULL,
		uploaded_by INT UNSIGNED NOT NULL DEFAULT 0,
		created_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY file_hash (file_hash),
		UNIQUE KEY community (community),
		KEY host_template_id (host_template_id),
		KEY category_id (category_id)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");

	db_execute("CREATE TABLE IF NOT EXISTS plugin_nms_snmprec_oids (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		import_id INT UNSIGNED NOT NULL,
		oid VARCHAR(255) NOT NULL,
		tag VARCHAR(64) NOT NULL,
		raw_value VARCHAR(1024) NOT NULL,
		section_name VARCHAR(255) NOT NULL DEFAULT '',
		inventory_key VARCHAR(64) NOT NULL DEFAULT '',
		graphable CHAR(2) NOT NULL DEFAULT '',
		data_template_id MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
		graph_template_id MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY (id),
		UNIQUE KEY import_oid (import_id, oid),
		KEY inventory_key (inventory_key),
		KEY data_template_id (data_template_id),
		KEY graph_template_id (graph_template_id)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");
	if (!(int) db_fetch_cell("SELECT COUNT(*) FROM information_schema.COLUMNS
		WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_nms_snmprec_oids' AND COLUMN_NAME = 'inventory_key'")) {
		db_execute("ALTER TABLE plugin_nms_snmprec_oids
			ADD inventory_key VARCHAR(64) NOT NULL DEFAULT '' AFTER section_name,
			ADD KEY inventory_key (inventory_key)");
	}
	/* Upgrade existing imports without copying their test values into live inventory. */
	db_execute("UPDATE plugin_nms_snmprec_oids SET inventory_key = 'serial_number'
		WHERE inventory_key = '' AND graphable != 'on' AND
		(oid = '1.3.6.1.4.1.9.3.6.3.0'
			OR oid = '1.3.6.1.2.1.47.1.1.1.1.11' OR oid LIKE '1.3.6.1.2.1.47.1.1.1.1.11.%'
			OR oid = '1.3.6.1.2.1.43.5.1.1.17' OR oid LIKE '1.3.6.1.2.1.43.5.1.1.17.%'
			OR LOWER(section_name) REGEXP 'serial[[:space:]_-]*(number|no\\.?|#)'
			OR LOWER(section_name) LIKE '%entphysicalserialnum%'
			OR LOWER(section_name) REGEXP 'service[[:space:]_-]*tag')");

	/* One-time compatibility ownership for objects produced by earlier NMS versions. */
	db_execute("INSERT IGNORE INTO plugin_nms_managed_objects (object_type, object_id, created_by, created_at)
		SELECT 'graph_template', id, 0, NOW() FROM graph_templates WHERE name LIKE 'NMS %'");
	db_execute("INSERT IGNORE INTO plugin_nms_managed_objects (object_type, object_id, created_by, created_at)
		SELECT DISTINCT 'graph_template', graph_template_id, 0, NOW() FROM plugin_nms_snmprec_oids WHERE graph_template_id > 0");
	db_execute("INSERT IGNORE INTO plugin_nms_managed_objects (object_type, object_id, created_by, created_at)
		SELECT 'device', id, 0, NOW() FROM host WHERE deleted = '' AND description LIKE 'NMS %'");
	db_execute("INSERT IGNORE INTO plugin_nms_managed_objects (object_type, object_id, created_by, created_at)
		SELECT DISTINCT 'tree', category_id, 0, NOW() FROM plugin_nms_category_templates WHERE category_id > 1");

	nms_migrate_categories_to_cacti_trees();
	nms_seed_fault_configuration();
}

/**
 * Move category names and all category relationships into Cacti Graph Trees.
 *
 * Older NMS releases owned a separate category table. Each legacy category is
 * created as a real Cacti tree (or matched to an existing tree by name), then
 * the plugin's relationships are translated in one statement so overlapping
 * old/new numeric IDs cannot corrupt assignments. The legacy table is removed
 * only after every relationship has been translated.
 */
function nms_migrate_categories_to_cacti_trees() {
	$legacy_exists = (int) db_fetch_cell("SELECT COUNT(*) FROM information_schema.TABLES
		WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_nms_device_categories'");
	if (!$legacy_exists) return;

	$legacy_categories = db_fetch_assoc('SELECT id, name, sort_order
		FROM plugin_nms_device_categories ORDER BY sort_order, id');
	if (!count($legacy_categories)) {
		db_execute('DROP TABLE plugin_nms_device_categories');
		return;
	}

	$mapping = array();
	$next_sequence = (int) db_fetch_cell('SELECT COALESCE(MAX(sequence), 0) + 1 FROM graph_tree');
	$user_id = nms_current_user_id(1);

	foreach ($legacy_categories as $category) {
		$tree_id = (int) db_fetch_cell_prepared('SELECT id FROM graph_tree WHERE name = ? ORDER BY id LIMIT 1',
			array($category['name']));
		if ($tree_id <= 0) {
				db_execute_prepared("INSERT INTO graph_tree
				(name, enabled, locked, locked_date, sort_type, sequence, user_id, last_modified, modified_by)
				VALUES (?, 'on', 0, NOW(), 1, ?, ?, NOW(), ?)",
				array($category['name'], $next_sequence, $user_id, $user_id));
			$tree_id = (int) db_fetch_cell_prepared('SELECT id FROM graph_tree WHERE name = ? ORDER BY id DESC LIMIT 1',
				array($category['name']));
			if ($tree_id <= 0) throw new RuntimeException('Could not migrate device category to a Cacti Tree.');
			nms_managed_object_record('tree', $tree_id, $user_id);
			$next_sequence++;
		}
		$mapping[(int) $category['id']] = $tree_id;
	}

	if (count($mapping)) {
		$cases = array();
		$ids = array();
		foreach ($mapping as $legacy_id => $tree_id) {
			$cases[] = 'WHEN ' . (int) $legacy_id . ' THEN ' . (int) $tree_id;
			$ids[] = (int) $legacy_id;
		}
		$case_sql = implode(' ', $cases);
		$id_sql = implode(',', $ids);
		foreach (array('plugin_nms_category_templates', 'plugin_nms_fault_rules', 'plugin_nms_snmprec_imports') as $table) {
			db_execute('UPDATE ' . $table . ' SET category_id = CASE category_id ' . $case_sql .
				' ELSE category_id END WHERE category_id IN (' . $id_sql . ')');
		}
	}

	db_execute('DROP TABLE plugin_nms_device_categories');
	if (function_exists('set_config_option')) set_config_option('time_last_change_tree', time());
}

/** Migrate legacy rule definitions and seed fault configuration for the current Cacti Trees. */
function nms_seed_fault_configuration() {
	nms_sync_template_categories();

	$category_ids = db_fetch_assoc('SELECT id FROM graph_tree');
	db_execute("UPDATE plugin_nms_fault_rules SET metric = 'core_status', parameter_key = 'core:status',
		comparison = 'not_equals', threshold_value = 'up', unit = '' WHERE metric = 'status_not_up'");
	db_execute("DELETE FROM plugin_nms_fault_rules WHERE metric IN
		('availability_below', 'response_above', 'rrd_stale_minutes', 'rrd_missing_count')");

	foreach ($category_ids as $category) {
		$exists = (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_nms_fault_rules
			WHERE category_id = ? AND parameter_key = 'core:status'", array($category['id']));
		if (!$exists) {
			db_execute_prepared("INSERT INTO plugin_nms_fault_rules
				(category_id, name, metric, parameter_key, comparison, threshold, threshold_value, unit,
				severity, enabled, sort_order, created_at, updated_at)
				VALUES (?, 'Device is not up', 'core_status', 'core:status', 'not_equals', 0, 'up', '',
				'critical', 'on', 10, NOW(), NOW())", array($category['id']));
		}
	}
}

/** Associate unassigned host templates with matching Cacti Trees using template-name families. */
function nms_sync_template_categories() {
	$templates = db_fetch_assoc('SELECT id, name FROM host_template ORDER BY id');
	$category_rows = db_fetch_assoc('SELECT id, name FROM graph_tree');
	$category_ids = array();
	foreach ($category_rows as $category) {
		$key = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $category['name']), '-'));
		$category_ids[$key] = (int) $category['id'];
	}

	foreach ($templates as $template) {
		$name = strtolower($template['name']);
		$slug = 'computers';
		if (preg_match('/voip|voice|video|camera|phone/', $name)) {
			$slug = 'voice-video';
		} elseif (preg_match('/fortigate|firewall|clearpass|security|encrypt|diode/', $name)) {
			$slug = 'security';
		} elseif (preg_match('/router|switch|aruba|mikrotik|wireless|access point|netscaler|motorola|generic snmp/', $name)) {
			$slug = 'network';
		} elseif (preg_match('/vsat|modem|buc|beacon|antenna control/', $name)) {
			$slug = 'vsat';
		} elseif (preg_match('/\blos\b|radio|mast/', $name)) {
			$slug = 'los';
		} elseif (preg_match('/ups|pdu|power|baytech/', $name)) {
			$slug = 'power';
		} elseif (preg_match('/timing|ntp|twstft/', $name)) {
			$slug = 'timing';
		} elseif (preg_match('/vesda|fire|hydrogen|gas detector/', $name)) {
			$slug = 'fire-prevention';
		} elseif (preg_match('/akcp|sensor|thermometer|hygro|gnss|compass|acme|crac/', $name)) {
			$slug = 'sensors-instrumentation';
		}

		if (isset($category_ids[$slug])) {
			db_execute_prepared('INSERT IGNORE INTO plugin_nms_category_templates
				(host_template_id, category_id, assigned_at) VALUES (?, ?, NOW())',
				array($template['id'], $category_ids[$slug]));
		}
	}
}

/** Remove plugin-owned tables during uninstall; Cacti core tables are not dropped. */
function nms_drop_database() {
	db_execute('DROP TABLE IF EXISTS plugin_nms_device_inventory');
	db_execute('DROP TABLE IF EXISTS plugin_nms_snmprec_oids');
	db_execute('DROP TABLE IF EXISTS plugin_nms_snmprec_imports');
	db_execute('DROP TABLE IF EXISTS plugin_nms_events');
	db_execute('DROP TABLE IF EXISTS plugin_nms_incidents');
	db_execute('DROP TABLE IF EXISTS plugin_nms_meta');
	db_execute('DROP TABLE IF EXISTS plugin_nms_topology');
	db_execute('DROP TABLE IF EXISTS plugin_nms_fault_rules');
	db_execute('DROP TABLE IF EXISTS plugin_nms_device_parameters');
	db_execute('DROP TABLE IF EXISTS plugin_nms_category_templates');
	/* Legacy NMS-owned storage only; Cacti graph_tree records are never removed. */
	db_execute('DROP TABLE IF EXISTS plugin_nms_device_categories');
}
