<?php

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

	db_execute("CREATE TABLE IF NOT EXISTS plugin_nms_device_categories (
		id INT UNSIGNED NOT NULL AUTO_INCREMENT,
		name VARCHAR(100) NOT NULL,
		slug VARCHAR(100) NOT NULL,
		description VARCHAR(255) NOT NULL DEFAULT '',
		sort_order INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY (id),
		UNIQUE KEY slug (slug)
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
		graphable CHAR(2) NOT NULL DEFAULT '',
		data_template_id MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
		graph_template_id MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY (id),
		UNIQUE KEY import_oid (import_id, oid),
		KEY data_template_id (data_template_id),
		KEY graph_template_id (graph_template_id)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");

	nms_seed_fault_configuration();
}

function nms_seed_fault_configuration() {
	$categories = array(
		array('voice-video', 'Voice / Video', 'VoIP servers, IP phones and IP cameras'),
		array('security', 'Security', 'Encryption, data-diode and security systems'),
		array('network', 'Network', 'Core, edge, access and wireless network devices'),
		array('vsat', 'VSAT', 'Modems, BUCs, beacon trackers and antenna control'),
		array('los', 'LOS', 'LOS radios, antenna positioning and masts'),
		array('computers', 'Computers', 'Servers, workstations, storage and thin clients'),
		array('power', 'Power', 'UPS, PDU and power infrastructure'),
		array('timing', 'Timing', 'Time servers and timing clients'),
		array('fire-prevention', 'Fire Prevention', 'Fire and gas detection systems'),
		array('sensors-instrumentation', 'Sensors & Instrumentation', 'Environmental and instrumentation sensors')
	);

	$order = 10;
	foreach ($categories as $category) {
		db_execute_prepared('INSERT INTO plugin_nms_device_categories (slug, name, description, sort_order)
			VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), sort_order = VALUES(sort_order)',
			array($category[0], $category[1], $category[2], $order));
		$order += 10;
	}

	nms_sync_template_categories();

	$category_ids = db_fetch_assoc('SELECT id FROM plugin_nms_device_categories');
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

function nms_sync_template_categories() {
	$templates = db_fetch_assoc('SELECT id, name FROM host_template ORDER BY id');
	$category_rows = db_fetch_assoc('SELECT id, slug FROM plugin_nms_device_categories');
	$category_ids = array();
	foreach ($category_rows as $category) {
		$category_ids[$category['slug']] = (int) $category['id'];
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

function nms_drop_database() {
	db_execute('DROP TABLE IF EXISTS plugin_nms_snmprec_oids');
	db_execute('DROP TABLE IF EXISTS plugin_nms_snmprec_imports');
	db_execute('DROP TABLE IF EXISTS plugin_nms_events');
	db_execute('DROP TABLE IF EXISTS plugin_nms_incidents');
	db_execute('DROP TABLE IF EXISTS plugin_nms_meta');
	db_execute('DROP TABLE IF EXISTS plugin_nms_topology');
	db_execute('DROP TABLE IF EXISTS plugin_nms_fault_rules');
	db_execute('DROP TABLE IF EXISTS plugin_nms_device_parameters');
	db_execute('DROP TABLE IF EXISTS plugin_nms_category_templates');
	db_execute('DROP TABLE IF EXISTS plugin_nms_device_categories');
}
