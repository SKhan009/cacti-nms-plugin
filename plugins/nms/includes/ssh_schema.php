<?php
/** SSH-only schema. Core host, auth, templates and RRDs remain authoritative. */
function nms_ssh_schema()
{
	$tables = [
		"presets" =>
			"id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, description VARCHAR(500) NOT NULL DEFAULT '', enabled TINYINT NOT NULL DEFAULT 1, username VARCHAR(128) NOT NULL, auth_method VARCHAR(12) NOT NULL, credential_ref CHAR(64) NOT NULL, port INT NOT NULL DEFAULT 22, connect_timeout INT NOT NULL DEFAULT 10, command_timeout INT NOT NULL DEFAULT 30, retries INT NOT NULL DEFAULT 1, keepalive INT NOT NULL DEFAULT 30, revision INT NOT NULL DEFAULT 1, updated_by INT UNSIGNED NOT NULL, updated_at DATETIME NOT NULL, UNIQUE KEY name (name)",
		"profiles" =>
			"id VARCHAR(40) NOT NULL PRIMARY KEY, name VARCHAR(80) NOT NULL, version INT NOT NULL, definition_hash CHAR(64) NOT NULL",
		"devices" =>
			"host_id MEDIUMINT UNSIGNED NOT NULL PRIMARY KEY, preset_id INT UNSIGNED NOT NULL, profile_id VARCHAR(40) NOT NULL DEFAULT 'linux-health-v1', monitoring TINYINT NOT NULL DEFAULT 0, interval_seconds INT NOT NULL DEFAULT 300, host_key TEXT NULL, verified_endpoint VARCHAR(512) NULL, verified_by INT UNSIGNED NULL, verified_at DATETIME NULL, observed_key TEXT NULL, observed_endpoint VARCHAR(512) NULL, observed_at DATETIME NULL, revision INT NOT NULL DEFAULT 1, updated_by INT UNSIGNED NOT NULL, updated_at DATETIME NOT NULL, KEY preset_id (preset_id)",
		"state" =>
			"host_id MEDIUMINT UNSIGNED NOT NULL PRIMARY KEY, status VARCHAR(20) NOT NULL DEFAULT 'unknown', last_attempt DATETIME NULL, last_success DATETIME NULL, last_error VARCHAR(250) NOT NULL DEFAULT '', sample_json TEXT NULL, preset_revision INT NULL, device_revision INT NULL, endpoint VARCHAR(512) NULL",
		"sessions" =>
			"id CHAR(64) NOT NULL PRIMARY KEY, ticket_hash CHAR(64) NOT NULL, session_hash CHAR(64) NOT NULL, session_cookie VARCHAR(64) NOT NULL, user_id INT UNSIGNED NOT NULL, host_id MEDIUMINT UNSIGNED NOT NULL DEFAULT 0, kind VARCHAR(24) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'issued', preset_revision INT NULL, device_revision INT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, lease_until DATETIME NOT NULL, started_at DATETIME NULL, ended_at DATETIME NULL, outcome VARCHAR(250) NOT NULL DEFAULT '', KEY active_user (user_id,status), KEY host_id (host_id)",
	];
	foreach ($tables as $suffix => $definition) {
		nms_category_execute(
			"CREATE TABLE IF NOT EXISTS plugin_nms_ssh_" .
				$suffix .
				" (" .
				$definition .
				") ENGINE=InnoDB ROW_FORMAT=Dynamic",
		);
	}
	require_once __DIR__ . "/ssh_linux.php";
	nms_category_execute(
		"INSERT INTO plugin_nms_ssh_profiles (id,name,version,definition_hash) VALUES ('linux-health-v1','Linux health',1,'" .
			hash("sha256", nms_ssh_linux_command()) .
			"') ON DUPLICATE KEY UPDATE name=VALUES(name), version=VALUES(version), definition_hash=VALUES(definition_hash)",
	);
	nms_category_execute(
		"INSERT INTO plugin_nms_meta (meta_key,meta_value,updated_at) VALUES ('ssh_schema_version','1',NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()",
	);
}

/**
 * Handles ssh require schema.
 */
function nms_ssh_require_schema()
{
	nms_require_database();
	if (
		db_fetch_cell_prepared("SELECT meta_value FROM plugin_nms_meta WHERE meta_key=?", ["ssh_schema_version"]) !==
		"1"
	) {
		throw new RuntimeException(
			"SSH schema upgrade required. Use Cacti Plugin Management; no fallback schema is used.",
		);
	}
}
