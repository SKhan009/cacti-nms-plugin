<?php

function nms_poller_bottom() {
	global $config;

	include_once($config['base_path'] . '/plugins/nms/includes/functions.php');
	nms_sync_all_faults(true);
}

function nms_poller_output($rrd_update_array) {
	global $config;

	include_once($config['base_path'] . '/plugins/nms/includes/functions.php');

	foreach ($rrd_update_array as $rrd_path => $rrd_data) {
		$item = db_fetch_row_prepared("SELECT pi.local_data_id, pi.host_id,
			COALESCE(NULLIF(dtd.name_cache, ''), CONCAT('Data source ', pi.local_data_id)) AS name_cache
			FROM poller_item AS pi
			LEFT JOIN data_template_data AS dtd ON dtd.local_data_id = pi.local_data_id
			WHERE pi.rrd_path = ? LIMIT 1", array($rrd_path));

		if (!cacti_sizeof($item) || !isset($rrd_data['times'])) {
			continue;
		}

		$field_states = array();
		foreach ($rrd_data['times'] as $fields) {
			foreach ($fields as $field => $value) {
				if (!isset($field_states[$field])) {
					$field_states[$field] = false;
				}
				if ((string) $value === 'U' || (string) $value === '') {
					$field_states[$field] = true;
				}
			}
		}

		foreach ($field_states as $field => $unknown) {
			$fingerprint = 'output:' . $item['local_data_id'] . ':' . sha1($field);
			if ($unknown) {
				nms_open_incident(array(
					'fingerprint' => $fingerprint,
					'source_type' => 'output',
					'source_key' => $item['local_data_id'] . ':' . $field,
					'host_id' => $item['host_id'],
					'local_data_id' => $item['local_data_id'],
					'severity' => 'major',
					'title' => $item['name_cache'] . ' returned unknown data',
					'message' => 'RRD field ' . $field . ' returned U during polling'
				));
			} else {
				nms_resolve_incident($fingerprint, 'Poller output returned to a valid value');
			}
		}
	}

	return $rrd_update_array;
}

