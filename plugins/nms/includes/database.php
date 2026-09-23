<?php
/**
 * @file database.php
 * Create and migrate NMS-owned tables without modifying Cacti core schemas or trees.
 * The uninstall helper removes plugin storage without dropping Cacti core tables.
 */

require_once __DIR__ . "/functions.php";
require_once __DIR__ . "/categories.php";
require_once __DIR__ . "/groups.php";
require_once __DIR__ . "/relationships.php";
require_once __DIR__ . "/topology/connections.php";
require_once __DIR__ . "/topology/config.php";
require_once __DIR__ . "/ssh_schema.php";
require_once __DIR__ . "/discovery_schema.php";

/** Read schema readiness without DDL, process termination, or an implicit repair. */
function nms_database_ready()
{
	$exists = (int) db_fetch_cell("SELECT COUNT(*) FROM information_schema.TABLES
		WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_nms_meta'");
	return $exists &&
		db_fetch_cell_prepared("SELECT meta_value FROM plugin_nms_meta WHERE meta_key = ?", [
			"equipment_categories_v1",
		]) === "complete" &&
		db_fetch_cell_prepared("SELECT meta_value FROM plugin_nms_meta WHERE meta_key = ?", ["nms_schema_version"]) ===
			"1.10.82";
}

/** Ordinary page views never perform install DDL or silently repair a partial upgrade. */
function nms_require_database()
{
	if (!nms_database_ready()) {
		http_response_code(503);
		die(
			"NMS database upgrade is required. Back up the database and run the NMS upgrade from Cacti Plugin Management. No fallback schema is used."
		);
	}
}

/** Serialize all install/upgrade DDL; MariaDB DDL commits implicitly and is not rollback-safe. */
function nms_setup_database()
{
	$lock = "nms_schema_" . substr(hash("sha256", (string) db_fetch_cell("SELECT DATABASE()")), 0, 32);
	if ((int) db_fetch_cell_prepared("SELECT GET_LOCK(?, 10)", [$lock]) !== 1) {
		throw new RuntimeException("NMS schema upgrade is busy. Retry after the current upgrade completes.");
	}
	try {
		// An interrupted repair must not leave an old success marker visible to pollers.
		if (
			(int) db_fetch_cell("SELECT COUNT(*) FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_nms_meta'")
		) {
			nms_category_execute("DELETE FROM plugin_nms_meta WHERE meta_key = 'nms_schema_version'");
		}
		nms_apply_database_schema();
	} finally {
		db_fetch_cell_prepared("SELECT RELEASE_LOCK(?)", [$lock]);
	}
}

/** Apply checked, repeatable plugin DDL; publish readiness only after every operation succeeds. */
function nms_apply_database_schema()
{
	nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_incidents (
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

	nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_events (
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

	nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_meta (
		meta_key VARCHAR(64) NOT NULL,
		meta_value TEXT NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (meta_key)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");

	// Withdraw readiness before further DDL, including a repair of the same release.
	nms_category_execute("DELETE FROM plugin_nms_meta WHERE meta_key = 'nms_schema_version'");

	/* Ownership is explicit: destructive controls are never inferred from a Cacti name at request time. */
	nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_managed_objects (
		object_type VARCHAR(32) NOT NULL,
		object_id INT UNSIGNED NOT NULL,
		created_by INT UNSIGNED NOT NULL DEFAULT 0,
		created_at DATETIME NOT NULL,
		PRIMARY KEY (object_type, object_id),
		KEY created_by (created_by)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");
	/* Cacti remains the source of device data. This table stores presentation only. */
	nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_topology (
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

	nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_category_templates (
		host_template_id MEDIUMINT UNSIGNED NOT NULL,
		category_id INT UNSIGNED NOT NULL,
		assigned_at DATETIME NOT NULL,
		PRIMARY KEY (host_template_id),
		KEY category_id (category_id)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");

	nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_fault_rules (
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

	// Check each column separately so retrying a partially applied older upgrade is safe.
	foreach (
		[
			"parameter_key" => "VARCHAR(191) NOT NULL DEFAULT '' AFTER metric",
			"comparison" => "VARCHAR(20) NOT NULL DEFAULT 'greater_than' AFTER parameter_key",
			"threshold_value" => "VARCHAR(191) NOT NULL DEFAULT '' AFTER threshold",
			"unit" => "VARCHAR(24) NOT NULL DEFAULT '' AFTER threshold_value",
		]
		as $column => $definition
	) {
		if (
			!(int) db_fetch_cell_prepared(
				"SELECT COUNT(*) FROM information_schema.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_nms_fault_rules' AND COLUMN_NAME = ?",
				[$column],
			)
		) {
			nms_category_execute("ALTER TABLE plugin_nms_fault_rules ADD " . $column . " " . $definition);
		}
	}
	if (
		(int) db_fetch_cell("SELECT COUNT(*) FROM information_schema.STATISTICS
		WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_nms_fault_rules' AND INDEX_NAME = 'category_metric'")
	) {
		nms_category_execute("ALTER TABLE plugin_nms_fault_rules DROP INDEX category_metric");
	}
	if (
		!(int) db_fetch_cell("SELECT COUNT(*) FROM information_schema.STATISTICS
		WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_nms_fault_rules' AND INDEX_NAME = 'category_parameter'")
	) {
		nms_category_execute(
			"ALTER TABLE plugin_nms_fault_rules ADD KEY category_parameter (category_id, parameter_key)",
		);
	}

	nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_device_parameters (
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
	nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_device_inventory (
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

	/* Manual asset identity is NMS-only, separate from SNMP values and their comparison baseline. */
	nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_device_metadata (
		host_id MEDIUMINT UNSIGNED NOT NULL,
		serial_number VARCHAR(191) NOT NULL DEFAULT '',
		updated_by INT UNSIGNED NOT NULL DEFAULT 0,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (host_id)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");

	/* Upload history and generated Cacti object links. Uploaded values remain in SNMPSim. */
	nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_snmprec_imports (
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

	nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_snmprec_oids (
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
	if (
		!(int) db_fetch_cell("SELECT COUNT(*) FROM information_schema.COLUMNS
		WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_nms_snmprec_oids' AND COLUMN_NAME = 'inventory_key'")
	) {
		nms_category_execute("ALTER TABLE plugin_nms_snmprec_oids
			ADD inventory_key VARCHAR(64) NOT NULL DEFAULT '' AFTER section_name");
	}
	if (
		!(int) db_fetch_cell("SELECT COUNT(*) FROM information_schema.STATISTICS
		WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_nms_snmprec_oids' AND INDEX_NAME = 'inventory_key'")
	) {
		nms_category_execute("ALTER TABLE plugin_nms_snmprec_oids ADD KEY inventory_key (inventory_key)");
	}
	/* Upgrade existing imports without copying their test values into live inventory. */
	nms_category_execute("UPDATE plugin_nms_snmprec_oids SET inventory_key = 'serial_number'
		WHERE inventory_key = '' AND graphable != 'on' AND
		(oid = '1.3.6.1.4.1.9.3.6.3.0'
			OR oid = '1.3.6.1.2.1.47.1.1.1.1.11' OR oid LIKE '1.3.6.1.2.1.47.1.1.1.1.11.%'
			OR oid = '1.3.6.1.2.1.43.5.1.1.17' OR oid LIKE '1.3.6.1.2.1.43.5.1.1.17.%'
			OR LOWER(section_name) REGEXP 'serial[[:space:]_-]*(number|no\\.?|#)'
			OR LOWER(section_name) LIKE '%entphysicalserialnum%'
			OR LOWER(section_name) REGEXP 'service[[:space:]_-]*tag')");

	// No name-based ownership guesses, rule deletion, or core-tree writes during upgrade.
	nms_rule_scope_schema();
	nms_migrate_legacy_fault_rules();
	nms_category_schema();
	nms_category_migrate();
	nms_category_normalize_legacy_default();
	nms_group_schema();
	nms_relationship_schema();
	nms_connection_schema();
	nms_topology_config_schema();
	nms_ssh_schema();
	nms_discovery_schema();
	nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_mib_uploads (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 host_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
 files_json MEDIUMTEXT NOT NULL, modules_json MEDIUMTEXT NOT NULL,
 metric_count INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL,
 KEY host_id (host_id)
 ) ENGINE=InnoDB");
	nms_category_execute(
		"CREATE TABLE IF NOT EXISTS plugin_nms_mib_objects (host_id INT UNSIGNED NOT NULL, oid VARCHAR(191) NOT NULL, report_json MEDIUMTEXT NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(host_id,oid)) ENGINE=InnoDB",
	);
	nms_category_execute("INSERT INTO plugin_nms_meta (meta_key, meta_value, updated_at)
		VALUES ('nms_schema_version', '1.10.82', NOW()) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = NOW()");
}

/** Preserve the known legacy status rule; refuse to silently delete or stop evaluating other old metrics. */
function nms_migrate_legacy_fault_rules()
{
	$unsupported = db_fetch_assoc("SELECT id, metric FROM plugin_nms_fault_rules
		WHERE metric NOT IN ('core_status', 'parameter', 'inventory_status', 'status_not_up') ORDER BY id");
	if (!is_array($unsupported)) {
		throw new RuntimeException("Could not inspect existing NMS rule types before upgrading.");
	}
	if (count($unsupported)) {
		$ids = [];
		foreach ($unsupported as $rule) {
			$ids[] = (int) $rule["id"];
		}
		throw new RuntimeException(
			"NMS upgrade requires an explicit migration for legacy fault rule IDs: " .
				implode(", ", $ids) .
				". Their definitions and history have been retained. Review their metric semantics before proceeding; no rules were deleted or silently disabled.",
		);
	}
	// The previous evaluator defined status_not_up exactly as native device state != up.
	nms_category_execute("UPDATE plugin_nms_fault_rules SET metric = 'core_status', parameter_key = 'core:status',
		comparison = 'not_equals', threshold_value = 'up', unit = '' WHERE metric = 'status_not_up'");
}

/** Remove plugin-owned tables during uninstall; Cacti core tables are not dropped. */
function nms_drop_database()
{
	db_execute("DROP TABLE IF EXISTS plugin_nms_diagnostic_jobs");
	foreach (["sessions", "state", "devices", "profiles", "presets"] as $suffix) {
		db_execute("DROP TABLE IF EXISTS plugin_nms_ssh_" . $suffix);
	}
	db_execute("DROP TABLE IF EXISTS plugin_nms_rack_devices");
	db_execute("DROP TABLE IF EXISTS plugin_nms_racks");
	db_execute("DROP TABLE IF EXISTS plugin_nms_rack_nodes");
	db_execute("DROP TABLE IF EXISTS plugin_nms_port_profiles");
	db_execute("DROP TABLE IF EXISTS plugin_nms_relationships");
	db_execute("DROP TABLE IF EXISTS plugin_nms_manual_connections");
	db_execute("DROP TABLE IF EXISTS plugin_nms_connection_types");
	db_execute("DROP TABLE IF EXISTS plugin_nms_group_members");
	db_execute("DROP TABLE IF EXISTS plugin_nms_groups");
	db_execute("DROP TABLE IF EXISTS plugin_nms_device_classification");
	db_execute("DROP TABLE IF EXISTS plugin_nms_category_migration");
	db_execute("DROP TABLE IF EXISTS plugin_nms_categories");
	db_execute("DROP TABLE IF EXISTS plugin_nms_device_metadata");
	db_execute("DROP TABLE IF EXISTS plugin_nms_device_inventory");
	db_execute("DROP TABLE IF EXISTS plugin_nms_snmprec_oids");
	db_execute("DROP TABLE IF EXISTS plugin_nms_snmprec_imports");
	db_execute("DROP TABLE IF EXISTS plugin_nms_events");
	db_execute("DROP TABLE IF EXISTS plugin_nms_incidents");
	db_execute("DROP TABLE IF EXISTS plugin_nms_meta");
	db_execute("DROP TABLE IF EXISTS plugin_nms_topology");
	db_execute("DROP TABLE IF EXISTS plugin_nms_fault_rules");
	db_execute("DROP TABLE IF EXISTS plugin_nms_device_parameters");
	db_execute("DROP TABLE IF EXISTS plugin_nms_category_templates");
	/* Legacy NMS-owned storage only; Cacti graph_tree records are never removed. */
	db_execute("DROP TABLE IF EXISTS plugin_nms_device_categories");
}
