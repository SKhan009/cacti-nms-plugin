<?php
/** Additive NMS-owned discovery storage; called only under NMS migration lock. */
function nms_discovery_schema()
{
	nms_category_execute(
		"CREATE TABLE IF NOT EXISTS plugin_nms_discovery_presets (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,protocol VARCHAR(8) NOT NULL,enabled TINYINT NOT NULL DEFAULT 1,interval_seconds INT NOT NULL DEFAULT 300,stale_seconds INT NOT NULL DEFAULT 900,refresh_seconds INT NOT NULL DEFAULT 30,updated_by INT UNSIGNED NOT NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
	);
	nms_category_execute(
		"CREATE TABLE IF NOT EXISTS plugin_nms_discovery_devices (host_id MEDIUMINT UNSIGNED PRIMARY KEY,preset_id INT UNSIGNED NOT NULL,last_attempt DATETIME NULL,KEY(preset_id)) ENGINE=InnoDB",
	);
	nms_category_execute(
		"CREATE TABLE IF NOT EXISTS plugin_nms_discovery_snapshots (host_id MEDIUMINT UNSIGNED NOT NULL,protocol VARCHAR(8) NOT NULL,status VARCHAR(16) NOT NULL,attempted_at DATETIME NOT NULL,succeeded_at DATETIME NULL,config_hash CHAR(64) NOT NULL,data_json MEDIUMTEXT NOT NULL,error VARCHAR(255) NOT NULL DEFAULT '',PRIMARY KEY(host_id,protocol)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
	);
	nms_category_execute(
		"CREATE TABLE IF NOT EXISTS plugin_nms_discovery_rules (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,preset_id INT UNSIGNED NOT NULL,site_id INT UNSIGNED NOT NULL,host_template_id INT UNSIGNED NOT NULL,poller_id INT UNSIGNED NOT NULL,enabled TINYINT NOT NULL DEFAULT 1,after_host_id INT UNSIGNED NOT NULL,created_by INT UNSIGNED NOT NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
	);
	nms_category_execute(
		"CREATE TABLE IF NOT EXISTS plugin_nms_discovery_network_jobs (network_id INT UNSIGNED PRIMARY KEY,methods VARCHAR(40) NOT NULL,ports VARCHAR(100) NOT NULL,snmp_item_id INT UNSIGNED NOT NULL DEFAULT 0,follow_schedule TINYINT NOT NULL DEFAULT 0,created_by INT UNSIGNED NOT NULL,revision INT UNSIGNED NOT NULL DEFAULT 1,status VARCHAR(16) NOT NULL DEFAULT 'queued',progress_cursor INT UNSIGNED NOT NULL DEFAULT 0,config_hash CHAR(64) NOT NULL DEFAULT '',results_json MEDIUMTEXT NOT NULL,requested_at DATETIME NOT NULL,finished_at DATETIME NULL,native_started VARCHAR(30) NOT NULL DEFAULT '',error VARCHAR(255) NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
	);
	nms_category_execute(
		"CREATE TABLE IF NOT EXISTS plugin_nms_diagnostic_profiles (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,tools VARCHAR(80) NOT NULL,ping_count TINYINT UNSIGNED NOT NULL DEFAULT 4,trace_hops TINYINT UNSIGNED NOT NULL DEFAULT 20,bandwidth_seconds TINYINT UNSIGNED NOT NULL DEFAULT 10,updated_by INT UNSIGNED NOT NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
	);
	nms_category_execute(
		"CREATE TABLE IF NOT EXISTS plugin_nms_diagnostic_devices (host_id MEDIUMINT UNSIGNED PRIMARY KEY,profile_id INT UNSIGNED NOT NULL,KEY(profile_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
	);
	foreach (
		[
			"chassis_id" => "VARCHAR(191) NOT NULL DEFAULT ''",
			"mac_address" => "VARCHAR(17) NOT NULL DEFAULT ''",
			"port_count" => "VARCHAR(5) NOT NULL DEFAULT ''",
		]
		as $column => $definition
	) {
		if (
			!db_fetch_cell_prepared(
				"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plugin_nms_device_metadata' AND COLUMN_NAME=?",
				[$column],
			)
		) {
			nms_category_execute("ALTER TABLE plugin_nms_device_metadata ADD COLUMN " . $column . " " . $definition);
		}
	}
	// Additive migration preserves existing LLDP/CDP assignments and snapshots.
	nms_category_execute("ALTER TABLE plugin_nms_discovery_presets MODIFY protocol VARCHAR(64) NOT NULL");
	foreach (
		["methods" => "VARCHAR(64) NOT NULL DEFAULT ''", "collection_enabled" => "TINYINT NOT NULL DEFAULT 1"]
		as $column => $definition
	) {
		if (
			!db_fetch_cell_prepared(
				"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plugin_nms_discovery_devices' AND COLUMN_NAME=?",
				[$column],
			)
		) {
			nms_category_execute("ALTER TABLE plugin_nms_discovery_devices ADD COLUMN " . $column . " " . $definition);
		}
	}
}
