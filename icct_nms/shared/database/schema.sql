-- ICCT NMS owns these tables. Core hosts remain in Cacti.

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `description` varchar(512) NOT NULL DEFAULT '',
  `seed_key` varchar(64) DEFAULT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `updated_by` int(10) unsigned NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `seed_key` (`seed_key`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_config_devices` (
  `host_id` mediumint(8) unsigned NOT NULL,
  `profile_id` int(10) unsigned NOT NULL,
  `credential_ref` varchar(100) NOT NULL DEFAULT '',
  `interval_seconds` int(10) unsigned NOT NULL DEFAULT 300,
  `revision` int(10) unsigned NOT NULL DEFAULT 1,
  `updated_by` int(10) unsigned NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`host_id`),
  KEY `profile_id` (`profile_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_config_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `host_id` mediumint(8) unsigned NOT NULL,
  `poller_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `operation` varchar(16) NOT NULL,
  `field_key` varchar(32) NOT NULL,
  `signature` char(64) NOT NULL,
  `before_json` text NOT NULL,
  `requested_json` text NOT NULL,
  `result_json` mediumtext NOT NULL,
  `status` varchar(16) NOT NULL,
  `requested_at` datetime NOT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `collector_queue` (`poller_id`,`status`,`id`),
  KEY `device_history` (`host_id`,`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_config_profiles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `manufacturer` varchar(120) NOT NULL,
  `model` varchar(120) NOT NULL,
  `manual_reference` varchar(512) NOT NULL,
  `protocol` varchar(32) NOT NULL,
  `fields_json` mediumtext NOT NULL,
  `revision` int(10) unsigned NOT NULL DEFAULT 1,
  `updated_by` int(10) unsigned NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_device_classification` (
  `host_id` mediumint(8) unsigned NOT NULL,
  `category_id` int(10) unsigned NOT NULL DEFAULT 0,
  `device_type` varchar(150) NOT NULL DEFAULT '',
  `device_role` varchar(150) NOT NULL DEFAULT '',
  `assignment_source` varchar(24) NOT NULL,
  `updated_by` int(10) unsigned NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`host_id`),
  KEY `category_id` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_device_inventory` (
  `host_id` mediumint(8) unsigned NOT NULL,
  `inventory_key` varchar(64) NOT NULL,
  `oid` varchar(255) NOT NULL,
  `display_name` varchar(150) NOT NULL,
  `baseline_value` varchar(512) NOT NULL DEFAULT '',
  `observed_value` varchar(512) NOT NULL DEFAULT '',
  `status` varchar(16) NOT NULL DEFAULT 'unknown',
  `last_attempt` datetime DEFAULT NULL,
  `last_success` datetime DEFAULT NULL,
  `last_error` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`host_id`,`inventory_key`),
  KEY `status` (`status`),
  KEY `last_success` (`last_success`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_device_metadata` (
  `host_id` mediumint(8) unsigned NOT NULL,
  `serial_number` varchar(191) NOT NULL DEFAULT '',
  `updated_by` int(10) unsigned NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL,
  `chassis_id` varchar(191) NOT NULL DEFAULT '',
  `mac_address` varchar(17) NOT NULL DEFAULT '',
  `port_count` varchar(5) NOT NULL DEFAULT '',
  PRIMARY KEY (`host_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_diagnostic_devices` (
  `host_id` mediumint(8) unsigned NOT NULL,
  `profile_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`host_id`),
  KEY `profile_id` (`profile_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_diagnostic_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `host_id` mediumint(8) unsigned NOT NULL,
  `poller_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `tool` varchar(16) NOT NULL,
  `config_hash` char(64) NOT NULL,
  `is_background` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'queued',
  `result_json` mediumtext NOT NULL,
  `requested_at` datetime NOT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `collector_queue` (`poller_id`,`status`,`id`),
  KEY `owner_jobs` (`user_id`,`id`)
) ENGINE=InnoDB AUTO_INCREMENT=73 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_diagnostic_profiles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `tools` varchar(255) NOT NULL,
  `ping_count` tinyint(3) unsigned NOT NULL DEFAULT 4,
  `mtr_cycles` tinyint(3) unsigned NOT NULL DEFAULT 4,
  `mtr_background` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `mtr_interval` smallint unsigned NOT NULL DEFAULT 300,
  `arp_interface` varchar(15) NOT NULL DEFAULT '',
  `pathchar_hops` tinyint unsigned NOT NULL DEFAULT 20,
  `pathchar_timeout` smallint unsigned NOT NULL DEFAULT 60,
  `trace_hops` tinyint(3) unsigned NOT NULL DEFAULT 20,
  `bandwidth_seconds` tinyint(3) unsigned NOT NULL DEFAULT 10,
  `updated_by` int(10) unsigned NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_discovery_devices` (
  `host_id` mediumint(8) unsigned NOT NULL,
  `preset_id` int(10) unsigned NOT NULL,
  `last_attempt` datetime DEFAULT NULL,
  `methods` varchar(64) NOT NULL DEFAULT '',
  `collection_enabled` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`host_id`),
  KEY `preset_id` (`preset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_discovery_presets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `protocol` varchar(64) NOT NULL,
  `enabled` tinyint(4) NOT NULL DEFAULT 1,
  `interval_seconds` int(11) NOT NULL DEFAULT 300,
  `stale_seconds` int(11) NOT NULL DEFAULT 900,
  `refresh_seconds` int(11) NOT NULL DEFAULT 30,
  `updated_by` int(10) unsigned NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_discovery_snapshots` (
  `host_id` mediumint(8) unsigned NOT NULL,
  `protocol` varchar(8) NOT NULL,
  `status` varchar(16) NOT NULL,
  `attempted_at` datetime NOT NULL,
  `succeeded_at` datetime DEFAULT NULL,
  `config_hash` char(64) NOT NULL,
  `is_background` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `data_json` mediumtext NOT NULL,
  `error` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`host_id`,`protocol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_managed_objects` (
  `object_type` varchar(32) NOT NULL,
  `object_id` int(10) unsigned NOT NULL,
  `created_by` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`object_type`,`object_id`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_meta` (
  `meta_key` varchar(64) NOT NULL,
  `meta_value` text NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`meta_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_rack_devices` (
  `host_id` mediumint(8) unsigned NOT NULL,
  `rack_id` int(10) unsigned NOT NULL,
  `start_unit` smallint(5) unsigned NOT NULL,
  `unit_height` smallint(5) unsigned NOT NULL,
  `updated_by` int(10) unsigned NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`host_id`),
  KEY `rack_id` (`rack_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_racks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `site_id` int(10) unsigned NOT NULL,
  `profile_id` varchar(16) NOT NULL DEFAULT '',
  `rack_number` smallint(5) unsigned NOT NULL,
  `name` varchar(150) NOT NULL,
  `unit_count` smallint(5) unsigned NOT NULL,
  `updated_by` int(10) unsigned NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `site_profile` (`site_id`,`profile_id`,`rack_number`)
) ENGINE=InnoDB AUTO_INCREMENT=77 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_serial_connections` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `poller_id` int(10) unsigned NOT NULL,
  `transport` varchar(32) NOT NULL,
  `endpoint` varchar(512) NOT NULL,
  `endpoint_key` char(64) NOT NULL,
  `profile_id` int(10) unsigned NOT NULL,
  `profile_revision` int(10) unsigned NOT NULL,
  `settings_json` text NOT NULL,
  `revision` int(10) unsigned NOT NULL DEFAULT 1,
  `enabled` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `updated_by` int(10) unsigned NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `endpoint_key` (`endpoint_key`),
  KEY `profile_id` (`profile_id`),
  KEY `poller_id` (`poller_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_serial_devices` (
  `host_id` mediumint(8) unsigned NOT NULL,
  `connection_id` int(10) unsigned NOT NULL,
  `device_address` smallint(5) unsigned NOT NULL,
  `revision` int(10) unsigned NOT NULL DEFAULT 1,
  `updated_by` int(10) unsigned NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`host_id`),
  UNIQUE KEY `bus_address` (`connection_id`,`device_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_serial_readings` (
  `host_id` mediumint(8) unsigned NOT NULL,
  `field_key` varchar(32) NOT NULL,
  `signature` char(64) NOT NULL,
  `value_json` text NOT NULL,
  `status` varchar(16) NOT NULL,
  `error_text` varchar(512) NOT NULL DEFAULT '',
  `observed_at` datetime NOT NULL,
  PRIMARY KEY (`host_id`,`field_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_snmprec_imports` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `original_name` varchar(255) NOT NULL,
  `community` varchar(100) NOT NULL,
  `template_name` varchar(150) NOT NULL,
  `host_template_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `category_id` int(10) unsigned NOT NULL DEFAULT 0,
  `record_count` int(10) unsigned NOT NULL DEFAULT 0,
  `graphable_count` int(10) unsigned NOT NULL DEFAULT 0,
  `file_hash` char(64) NOT NULL,
  `deployed_path` varchar(512) NOT NULL,
  `uploaded_by` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `file_hash` (`file_hash`),
  UNIQUE KEY `community` (`community`),
  KEY `host_template_id` (`host_template_id`),
  KEY `category_id` (`category_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_snmprec_oids` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `import_id` int(10) unsigned NOT NULL,
  `oid` varchar(255) NOT NULL,
  `tag` varchar(64) NOT NULL,
  `raw_value` varchar(1024) NOT NULL,
  `section_name` varchar(255) NOT NULL DEFAULT '',
  `inventory_key` varchar(64) NOT NULL DEFAULT '',
  `graphable` char(2) NOT NULL DEFAULT '',
  `data_template_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `graph_template_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `import_oid` (`import_id`,`oid`),
  KEY `data_template_id` (`data_template_id`),
  KEY `graph_template_id` (`graph_template_id`),
  KEY `inventory_key` (`inventory_key`)
) ENGINE=InnoDB AUTO_INCREMENT=90 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_ssh_devices` (
  `host_id` mediumint(8) unsigned NOT NULL,
  `preset_id` int(10) unsigned NOT NULL,
  `profile_id` varchar(40) NOT NULL DEFAULT 'linux-health-v1',
  `monitoring` tinyint(4) NOT NULL DEFAULT 0,
  `interval_seconds` int(11) NOT NULL DEFAULT 300,
  `host_key` text DEFAULT NULL,
  `verified_endpoint` varchar(512) DEFAULT NULL,
  `verified_by` int(10) unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `observed_key` text DEFAULT NULL,
  `observed_endpoint` varchar(512) DEFAULT NULL,
  `observed_at` datetime DEFAULT NULL,
  `revision` int(11) NOT NULL DEFAULT 1,
  `updated_by` int(10) unsigned NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`host_id`),
  KEY `preset_id` (`preset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_ssh_presets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `description` varchar(500) NOT NULL DEFAULT '',
  `enabled` tinyint(4) NOT NULL DEFAULT 1,
  `username` varchar(128) NOT NULL,
  `auth_method` varchar(12) NOT NULL,
  `credential_ref` char(64) NOT NULL,
  `port` int(11) NOT NULL DEFAULT 22,
  `connect_timeout` int(11) NOT NULL DEFAULT 10,
  `command_timeout` int(11) NOT NULL DEFAULT 30,
  `retries` int(11) NOT NULL DEFAULT 1,
  `keepalive` int(11) NOT NULL DEFAULT 30,
  `revision` int(11) NOT NULL DEFAULT 1,
  `updated_by` int(10) unsigned NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_ssh_sessions` (
  `id` char(64) NOT NULL,
  `ticket_hash` char(64) NOT NULL,
  `session_hash` char(64) NOT NULL,
  `session_cookie` varchar(64) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `host_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `kind` varchar(24) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'issued',
  `preset_revision` int(11) DEFAULT NULL,
  `device_revision` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `lease_until` datetime NOT NULL,
  `started_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `outcome` varchar(250) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `active_user` (`user_id`,`status`),
  KEY `host_id` (`host_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_ssh_state` (
  `host_id` mediumint(8) unsigned NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'unknown',
  `last_attempt` datetime DEFAULT NULL,
  `last_success` datetime DEFAULT NULL,
  `last_error` varchar(250) NOT NULL DEFAULT '',
  `sample_json` text DEFAULT NULL,
  `preset_revision` int(11) DEFAULT NULL,
  `device_revision` int(11) DEFAULT NULL,
  `endpoint` varchar(512) DEFAULT NULL,
  PRIMARY KEY (`host_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_syslog_devices` (
  `host_id` mediumint(8) unsigned NOT NULL,
  `enabled` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `source_address` varchar(255) NOT NULL DEFAULT '',
  `transport` varchar(8) NOT NULL DEFAULT 'both',
  `max_severity` tinyint(3) unsigned NOT NULL DEFAULT 6,
  `severity_codes` text DEFAULT NULL,
  `facility_codes` text DEFAULT NULL,
  `match_strings` text DEFAULT NULL,
  `updated_by` int(10) unsigned NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`host_id`),
  KEY `source_address` (`source_address`),
  KEY `enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_syslog_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `host_id` mediumint(8) unsigned NOT NULL,
  `event_time` datetime NOT NULL,
  `received_at` datetime NOT NULL,
  `last_seen` datetime NOT NULL,
  `source_ip` varchar(45) NOT NULL,
  `source_host` varchar(255) NOT NULL DEFAULT '',
  `transport` varchar(8) NOT NULL DEFAULT 'unknown',
  `facility_code` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `facility` varchar(32) NOT NULL DEFAULT '',
  `severity_code` tinyint(3) unsigned NOT NULL DEFAULT 6,
  `severity` varchar(16) NOT NULL DEFAULT 'info',
  `nms_severity` varchar(16) NOT NULL DEFAULT 'info',
  `program` varchar(128) NOT NULL DEFAULT '',
  `message` text NOT NULL,
  `fingerprint` char(64) NOT NULL,
  `repeat_count` int(10) unsigned NOT NULL DEFAULT 1,
  `acknowledged` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `acknowledged_by` int(10) unsigned NOT NULL DEFAULT 0,
  `acknowledged_at` datetime DEFAULT NULL,
  `ack_comment` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `host_time` (`host_id`,`event_time`),
  KEY `severity_time` (`nms_severity`,`event_time`),
  KEY `ack_time` (`acknowledged`,`event_time`),
  KEY `fingerprint_time` (`fingerprint`,`last_seen`),
  KEY `source_time` (`source_ip`,`event_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_syslog_ingest_state` (
  `source_key` char(64) NOT NULL,
  `source_path` varchar(512) NOT NULL,
  `source_inode` varchar(64) NOT NULL DEFAULT '',
  `source_offset` bigint(20) unsigned NOT NULL DEFAULT 0,
  `last_record_at` datetime DEFAULT NULL,
  `records_ingested` bigint(20) unsigned NOT NULL DEFAULT 0,
  `records_dropped` bigint(20) unsigned NOT NULL DEFAULT 0,
  `errors` bigint(20) unsigned NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`source_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `plugin_icct_nms_port_alarm_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `host_id` mediumint unsigned NOT NULL,
  `if_index` int unsigned NOT NULL,
  `port_name` varchar(255) NOT NULL,
  `event` varchar(32) NOT NULL,
  `state_before` varchar(16) NOT NULL,
  `state_after` varchar(16) NOT NULL,
  `severity_before` varchar(16) NOT NULL,
  `severity_after` varchar(16) NOT NULL,
  `admin_status` tinyint unsigned DEFAULT NULL,
  `oper_status` tinyint unsigned DEFAULT NULL,
  `collected_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `device_history` (`host_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
