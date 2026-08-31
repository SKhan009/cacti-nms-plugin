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
}

function nms_drop_database() {
	db_execute('DROP TABLE IF EXISTS plugin_nms_events');
	db_execute('DROP TABLE IF EXISTS plugin_nms_incidents');
	db_execute('DROP TABLE IF EXISTS plugin_nms_meta');
}

