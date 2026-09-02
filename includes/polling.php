<?php
/**
 * @file polling.php
 * Cacti poller hooks that retain latest indexed readings, reconcile imported templates, collect text inventory, and synchronize faults.
 * The poller-output hook returns the original Cacti payload unchanged.
 */

/** Reconcile imported native templates, collect live text inventory, and synchronize faults after polling. */
function nms_poller_bottom() {
	global $config;

	include_once($config['base_path'] . '/plugins/nms/includes/functions.php');
	include_once($config['base_path'] . '/plugins/nms/includes/inventory.php');
	include_once($config['base_path'] . '/plugins/nms/includes/device_manager.php');
	db_execute("DELETE p FROM plugin_nms_device_parameters AS p
		LEFT JOIN host AS h ON h.id = p.host_id
		LEFT JOIN poller_item AS pi ON pi.host_id = p.host_id AND pi.local_data_id = p.local_data_id
		WHERE h.id IS NULL OR h.deleted != '' OR h.disabled != '' OR pi.local_data_id IS NULL");
	/* Existing and new imported devices receive native Cacti poller items. */
	nms_device_reconcile_imported_templates();
	/* Cacti owns numeric polling; query only text inventory that RRDtool cannot hold. */
	nms_collect_inventory_values();
	nms_sync_all_faults(true);
}

/** Store latest observed values with device and indexed-source identity; return Cacti's poller payload unchanged. */
function nms_poller_output($rrd_update_array) {
	global $config;

	include_once($config['base_path'] . '/plugins/nms/includes/functions.php');

	foreach ($rrd_update_array as $rrd_path => $rrd_data) {
		$item = db_fetch_row_prepared("SELECT pi.local_data_id, pi.host_id, dtd.data_template_id,
			dl.snmp_index,
			COALESCE(NULLIF(dtd.name_cache, ''), CONCAT('Device parameter ', pi.local_data_id)) AS name_cache
			FROM poller_item AS pi
			LEFT JOIN data_local AS dl ON dl.id = pi.local_data_id
			LEFT JOIN data_template_data AS dtd ON dtd.local_data_id = pi.local_data_id
			WHERE pi.rrd_path = ? LIMIT 1", array($rrd_path));

		if (!cacti_sizeof($item) || !isset($rrd_data['times'])) {
			continue;
		}

		foreach ($rrd_data['times'] as $fields) {
			foreach ($fields as $field => $value) {
				$definition = db_fetch_row_prepared("SELECT dtr.local_data_template_rrd_id,
					dtr.data_template_id, dtr.data_source_name, dt.name AS template_name
					FROM data_template_rrd AS dtr
					LEFT JOIN data_template AS dt ON dt.id = dtr.data_template_id
					WHERE dtr.local_data_id = ? AND dtr.data_source_name = ? LIMIT 1",
					array($item['local_data_id'], $field));
				$template_item_id = cacti_sizeof($definition) ? (int) $definition['local_data_template_rrd_id'] : 0;
				$parameter_key = $template_item_id > 0
					? 'dtrr:' . $template_item_id
					: 'dt:' . (int) $item['data_template_id'] . ':' . preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) $field);
				/*
				 * Cacti's data-source name cache normally contains the interface,
				 * sensor, or disk identity. Preserve it so an incident identifies
				 * the affected port instead of only naming the shared template.
				 */
				$display_name = trim((string) $item['name_cache']);
				if (trim((string) $item['snmp_index']) !== '') {
					$display_name .= ' · SNMP index ' . $item['snmp_index'];
				}
				$display_name = substr($display_name . ' · ' . $field, 0, 255);
				$raw_value = trim((string) $value);
				$numeric_value = is_numeric($raw_value) ? $raw_value : null;

				/* This hook records only values returned by this Cacti poller run. */
				db_execute_prepared("INSERT INTO plugin_nms_device_parameters
					(host_id, local_data_id, parameter_key, parameter_name, display_name,
					raw_value, numeric_value, last_seen)
					VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
					ON DUPLICATE KEY UPDATE parameter_name = VALUES(parameter_name),
						display_name = VALUES(display_name), raw_value = VALUES(raw_value),
						numeric_value = VALUES(numeric_value), last_seen = VALUES(last_seen)", array(
					$item['host_id'], $item['local_data_id'], $parameter_key, $field,
					$display_name, $raw_value, $numeric_value
				));
			}
		}
	}

	return $rrd_update_array;
}
