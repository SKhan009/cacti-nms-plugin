<?php
/**
 * @file inventory.php
 * Collect live text inventory, including serial identity, using Cacti SNMP connection settings.
 * Retain observed identity and its baseline separately from numeric RRD data; imported values are never live substitutes.
 */

require_once(__DIR__ . '/functions.php');

/**
 * Return true only for a failed live SNMP result.  Imported .snmprec values are
 * definitions for the simulator/template and are intentionally never returned
 * from this path as fallback readings.
 */
function nms_inventory_snmp_failed($value) {
	$value = trim((string) $value);
	return $value === '' || in_array(strtolower($value), array('u', 'unknown', 'null', 'nan'), true) ||
		stripos($value, 'no such') !== false || stripos($value, 'timeout') !== false;
}

/**
 * Poll the non-RRD inventory OIDs assigned to imported Cacti host templates.
 * Host connection details come from Cacti core and cacti_snmp_get() performs
 * the request.  Only the latest text identity and its baseline are retained.
 */
function nms_collect_inventory_values($only_host_id = 0) {
	global $config, $snmp_error;
	include_once($config['base_path'] . '/lib/snmp.php');

	$params = array();
	$host_filter = '';
	if ((int) $only_host_id > 0) {
		$host_filter = ' AND h.id = ?';
		$params[] = (int) $only_host_id;
	}
	$definitions = db_fetch_assoc_prepared("SELECT h.id AS host_id, h.hostname, h.status,
		h.snmp_community, h.snmp_version, h.snmp_username, h.snmp_password,
		h.snmp_auth_protocol, h.snmp_priv_passphrase, h.snmp_priv_protocol,
		h.snmp_context, h.snmp_engine_id, h.snmp_port, h.snmp_timeout,
		o.inventory_key, o.oid, i.id AS import_id
		FROM host AS h
		INNER JOIN plugin_nms_snmprec_imports AS i ON i.host_template_id = h.host_template_id
		INNER JOIN plugin_nms_snmprec_oids AS o ON o.import_id = i.id AND o.inventory_key != ''
		WHERE h.deleted = '' AND h.disabled = ''$host_filter
		ORDER BY h.id, o.inventory_key, i.id DESC, o.id", $params);
	$seen = array();
	$attempted = 0;
	$snmp_retries = max(0, (int) read_config_option('snmp_retries'));

	foreach ($definitions as $definition) {
		$key = (int) $definition['host_id'] . ':' . $definition['inventory_key'];
		if (isset($seen[$key])) continue;
		$seen[$key] = true;
		$attempted++;
		$display_name = nms_inventory_display_name($definition['inventory_key']);

		/* Do not add a second timeout when Cacti has already marked the host down. */
		if ((int) $definition['status'] !== HOST_UP) {
			$error = 'Cacti device is not Up; live inventory polling was not attempted.';
			db_execute_prepared("INSERT INTO plugin_nms_device_inventory
				(host_id, inventory_key, oid, display_name, status, last_attempt, last_error)
				VALUES (?, ?, ?, ?, 'failed', NOW(), ?)
				ON DUPLICATE KEY UPDATE oid = VALUES(oid), display_name = VALUES(display_name),
					status = 'failed', last_attempt = NOW(), last_error = VALUES(last_error)", array(
				$definition['host_id'], $definition['inventory_key'], $definition['oid'], $display_name, $error
			));
			continue;
		}

		$snmp_error = '';
		$value = cacti_snmp_get(
			$definition['hostname'], $definition['snmp_community'], $definition['oid'],
			$definition['snmp_version'], $definition['snmp_username'], $definition['snmp_password'],
			$definition['snmp_auth_protocol'], $definition['snmp_priv_passphrase'],
			$definition['snmp_priv_protocol'], $definition['snmp_context'],
			$definition['snmp_port'], $definition['snmp_timeout'], $snmp_retries,
			'NMS Inventory', $definition['snmp_engine_id'], SNMP_STRING_OUTPUT_ASCII
		);

		if (nms_inventory_snmp_failed($value)) {
			$error = trim((string) $snmp_error);
			if ($error === '') $error = 'SNMP returned no current value for ' . $definition['oid'] . '.';
			db_execute_prepared("INSERT INTO plugin_nms_device_inventory
				(host_id, inventory_key, oid, display_name, status, last_attempt, last_error)
				VALUES (?, ?, ?, ?, 'failed', NOW(), ?)
				ON DUPLICATE KEY UPDATE oid = VALUES(oid), display_name = VALUES(display_name),
					status = 'failed', last_attempt = NOW(), last_error = VALUES(last_error)", array(
				$definition['host_id'], $definition['inventory_key'], $definition['oid'], $display_name, substr($error, 0, 255)
			));
			continue;
		}

		$value = substr(trim((string) $value, " \t\n\r\0\x0B\""), 0, 512);
		$current = db_fetch_row_prepared('SELECT baseline_value, observed_value
			FROM plugin_nms_device_inventory WHERE host_id = ? AND inventory_key = ?', array(
			$definition['host_id'], $definition['inventory_key']
		));
		$baseline = cacti_sizeof($current) ? trim((string) $current['baseline_value']) : '';
		if ($baseline === '') $baseline = $value;
		$status = strcasecmp($baseline, $value) === 0 ? 'ok' : 'changed';
		db_execute_prepared("INSERT INTO plugin_nms_device_inventory
			(host_id, inventory_key, oid, display_name, baseline_value, observed_value,
			 status, last_attempt, last_success, last_error)
			VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), '')
			ON DUPLICATE KEY UPDATE oid = VALUES(oid), display_name = VALUES(display_name),
				baseline_value = VALUES(baseline_value), observed_value = VALUES(observed_value),
				status = VALUES(status), last_attempt = NOW(), last_success = NOW(), last_error = ''", array(
			$definition['host_id'], $definition['inventory_key'], $definition['oid'], $display_name,
			$baseline, $value, $status
		));
	}

	/* Inventory is plugin-owned only where core Cacti has no string-value store. */
	db_execute("DELETE di FROM plugin_nms_device_inventory AS di
		LEFT JOIN host AS h ON h.id = di.host_id
		WHERE h.id IS NULL OR h.deleted != ''");
	return $attempted;
}
