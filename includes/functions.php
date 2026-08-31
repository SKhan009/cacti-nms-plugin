<?php

function nms_h($value) {
	return html_escape((string) $value);
}

function nms_now() {
	return date('Y-m-d H:i:s');
}

function nms_event($incident_id, $event_type, $severity, $message, $user_id = 0) {
	db_execute_prepared('INSERT INTO plugin_nms_events
		(incident_id, event_type, severity, message, user_id, created_at)
		VALUES (?, ?, ?, ?, ?, ?)',
		array($incident_id, $event_type, $severity, $message, $user_id, nms_now()));
}

function nms_open_incident($fault) {
	$now = nms_now();
	$current = db_fetch_row_prepared('SELECT * FROM plugin_nms_incidents WHERE fingerprint = ?', array($fault['fingerprint']));

	if (!cacti_sizeof($current)) {
		db_execute_prepared('INSERT INTO plugin_nms_incidents
			(fingerprint, source_type, source_key, host_id, poller_id, local_data_id,
			severity, status, title, message, first_seen, last_seen)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', array(
				$fault['fingerprint'],
				$fault['source_type'],
				$fault['source_key'],
				isset($fault['host_id']) ? (int) $fault['host_id'] : 0,
				isset($fault['poller_id']) ? (int) $fault['poller_id'] : 0,
				isset($fault['local_data_id']) ? (int) $fault['local_data_id'] : 0,
				$fault['severity'],
				'open',
				$fault['title'],
				$fault['message'],
				$now,
				$now
			));

		$id = db_fetch_cell('SELECT LAST_INSERT_ID()');
		nms_event($id, 'opened', $fault['severity'], $fault['message']);
		cacti_log('Opened incident [' . $fault['fingerprint'] . '] ' . $fault['title'], false, 'NMS');
		return $id;
	}

	if ($current['status'] === 'resolved') {
		db_execute_prepared("UPDATE plugin_nms_incidents
			SET source_type = ?, source_key = ?, host_id = ?, poller_id = ?, local_data_id = ?,
				severity = ?, status = 'open', title = ?, message = ?, first_seen = ?, last_seen = ?,
				acknowledged_by = 0, acknowledged_at = NULL, resolved_at = NULL
			WHERE id = ?", array(
				$fault['source_type'],
				$fault['source_key'],
				isset($fault['host_id']) ? (int) $fault['host_id'] : 0,
				isset($fault['poller_id']) ? (int) $fault['poller_id'] : 0,
				isset($fault['local_data_id']) ? (int) $fault['local_data_id'] : 0,
				$fault['severity'],
				$fault['title'],
				$fault['message'],
				$now,
				$now,
				$current['id']
			));
		nms_event($current['id'], 'reopened', $fault['severity'], $fault['message']);
		cacti_log('Reopened incident [' . $fault['fingerprint'] . '] ' . $fault['title'], false, 'NMS');
	} else {
		db_execute_prepared('UPDATE plugin_nms_incidents
			SET severity = ?, title = ?, message = ?, last_seen = ?, host_id = ?, poller_id = ?, local_data_id = ?
			WHERE id = ?', array(
				$fault['severity'],
				$fault['title'],
				$fault['message'],
				$now,
				isset($fault['host_id']) ? (int) $fault['host_id'] : 0,
				isset($fault['poller_id']) ? (int) $fault['poller_id'] : 0,
				isset($fault['local_data_id']) ? (int) $fault['local_data_id'] : 0,
				$current['id']
			));
	}

	return $current['id'];
}

function nms_resolve_incident($fingerprint, $message = 'Fault condition cleared automatically', $user_id = 0) {
	$current = db_fetch_row_prepared("SELECT * FROM plugin_nms_incidents
		WHERE fingerprint = ? AND status IN ('open', 'acknowledged')", array($fingerprint));

	if (!cacti_sizeof($current)) {
		return false;
	}

	$now = nms_now();
	db_execute_prepared("UPDATE plugin_nms_incidents
		SET status = 'resolved', resolved_at = ?, last_seen = ? WHERE id = ?", array($now, $now, $current['id']));
	nms_event($current['id'], 'resolved', $current['severity'], $message, $user_id);
	cacti_log('Resolved incident [' . $fingerprint . '] ' . $current['title'], false, 'NMS');

	return true;
}

function nms_resolve_missing($source_type, $active_fingerprints) {
	$rows = db_fetch_assoc_prepared("SELECT fingerprint FROM plugin_nms_incidents
		WHERE source_type = ? AND status IN ('open', 'acknowledged')", array($source_type));

	$active = array_fill_keys($active_fingerprints, true);
	foreach ($rows as $row) {
		if (!isset($active[$row['fingerprint']])) {
			nms_resolve_incident($row['fingerprint']);
		}
	}
}

function nms_acknowledge_incident($id, $user_id) {
	$current = db_fetch_row_prepared("SELECT * FROM plugin_nms_incidents WHERE id = ? AND status = 'open'", array($id));
	if (!cacti_sizeof($current)) {
		return false;
	}

	$now = nms_now();
	db_execute_prepared("UPDATE plugin_nms_incidents
		SET status = 'acknowledged', acknowledged_by = ?, acknowledged_at = ? WHERE id = ?",
		array($user_id, $now, $id));
	nms_event($id, 'acknowledged', $current['severity'], 'Incident acknowledged', $user_id);

	return true;
}

function nms_host_status_name($status) {
	$map = array(
		HOST_UNKNOWN => 'Unknown',
		HOST_DOWN => 'Down',
		HOST_RECOVERING => 'Recovering',
		HOST_UP => 'Up',
		HOST_ERROR => 'Error'
	);
	return isset($map[$status]) ? $map[$status] : 'Invalid state ' . $status;
}

function nms_parameter_matches($raw_value, $comparison, $threshold_value) {
	$raw = trim((string) $raw_value);
	$threshold = trim((string) $threshold_value);
	$raw_lower = strtolower($raw);
	$threshold_lower = strtolower($threshold);
	$is_unknown = $raw === '' || in_array($raw_lower, array('u', 'unknown', 'nan', 'null'), true);

	if ($comparison === 'is_unknown') return $is_unknown;
	if ($comparison === 'is_not_unknown') return !$is_unknown;
	if ($comparison === 'contains') return $threshold !== '' && strpos($raw_lower, $threshold_lower) !== false;
	if ($comparison === 'not_contains') return $threshold !== '' && strpos($raw_lower, $threshold_lower) === false;
	if ($comparison === 'equals') return $raw_lower === $threshold_lower;
	if ($comparison === 'not_equals') return $raw_lower !== $threshold_lower;

	if (!is_numeric($raw) || !is_numeric($threshold)) return false;
	$current = (float) $raw;
	$limit = (float) $threshold;
	if ($comparison === 'greater_than') return $current > $limit;
	if ($comparison === 'greater_or_equal') return $current >= $limit;
	if ($comparison === 'less_than') return $current < $limit;
	if ($comparison === 'less_or_equal') return $current <= $limit;

	return false;
}

function nms_comparison_label($comparison) {
	$labels = array(
		'greater_than' => 'is greater than',
		'greater_or_equal' => 'is greater than or equal to',
		'less_than' => 'is less than',
		'less_or_equal' => 'is less than or equal to',
		'equals' => 'equals',
		'not_equals' => 'does not equal',
		'contains' => 'contains',
		'not_contains' => 'does not contain',
		'is_unknown' => 'is unknown or empty',
		'is_not_unknown' => 'has a valid value'
	);
	return isset($labels[$comparison]) ? $labels[$comparison] : $comparison;
}

function nms_sync_device_faults() {
	$core_rows = db_fetch_assoc("SELECT h.*, ht.name AS template_name,
		c.id AS category_id, c.name AS category_name,
		r.id AS rule_id, r.name AS rule_name, r.parameter_key, r.comparison,
		r.threshold_value, r.unit, r.severity
		FROM host AS h
		INNER JOIN host_template AS ht ON ht.id = h.host_template_id
		INNER JOIN plugin_nms_category_templates AS ct ON ct.host_template_id = h.host_template_id
		INNER JOIN plugin_nms_device_categories AS c ON c.id = ct.category_id
		INNER JOIN plugin_nms_fault_rules AS r ON r.category_id = c.id
			AND r.enabled = 'on' AND r.metric = 'core_status'
		WHERE h.deleted = '' AND h.disabled = ''
		ORDER BY h.id, r.sort_order, r.id");
	$active = array();

	foreach ($core_rows as $row) {
		$current = strtolower(nms_host_status_name((int) $row['status']));
		if (!nms_parameter_matches($current, $row['comparison'], $row['threshold_value'])) continue;

		$fingerprint = 'device-rule:' . $row['rule_id'] . ':host:' . $row['id'];
		$active[] = $fingerprint;
		$message = 'Device state is ' . $current . '. The configured healthy value is ' .
			$row['threshold_value'] . '. Category: ' . $row['category_name'] . '. Template: ' . $row['template_name'] . '.';
		nms_open_incident(array(
			'fingerprint' => $fingerprint,
			'source_type' => 'device',
			'source_key' => $row['id'] . ':' . $row['rule_id'],
			'host_id' => $row['id'],
			'severity' => $row['severity'],
			'title' => $row['description'] . ' - ' . $row['rule_name'],
			'message' => $message
		));
	}

	$parameter_rows = db_fetch_assoc("SELECT h.id, h.description, ht.name AS template_name,
		c.name AS category_name, r.id AS rule_id, r.name AS rule_name, r.parameter_key,
		r.comparison, r.threshold_value, r.unit, r.severity,
		p.local_data_id, p.parameter_name, p.display_name, p.raw_value, p.last_seen
		FROM host AS h
		INNER JOIN host_template AS ht ON ht.id = h.host_template_id
		INNER JOIN plugin_nms_category_templates AS ct ON ct.host_template_id = h.host_template_id
		INNER JOIN plugin_nms_device_categories AS c ON c.id = ct.category_id
		INNER JOIN plugin_nms_fault_rules AS r ON r.category_id = c.id
			AND r.enabled = 'on' AND r.metric = 'parameter'
		INNER JOIN plugin_nms_device_parameters AS p ON p.host_id = h.id
			AND p.parameter_key = r.parameter_key
		WHERE h.deleted = '' AND h.disabled = ''
		ORDER BY h.id, r.sort_order, r.id, p.local_data_id");

	foreach ($parameter_rows as $row) {
		if (!nms_parameter_matches($row['raw_value'], $row['comparison'], $row['threshold_value'])) continue;
		$fingerprint = 'device-rule:' . $row['rule_id'] . ':host:' . $row['id'] . ':data:' . $row['local_data_id'];
		$active[] = $fingerprint;
		$unit = trim($row['unit']) !== '' ? ' ' . trim($row['unit']) : '';
		$message = $row['display_name'] . ' is ' . $row['raw_value'] . $unit . '. Rule: ' .
			nms_comparison_label($row['comparison']) .
			(in_array($row['comparison'], array('is_unknown', 'is_not_unknown'), true) ? '' : ' ' . $row['threshold_value'] . $unit) .
			'. Category: ' . $row['category_name'] . '. Template: ' . $row['template_name'] . '.';
		nms_open_incident(array(
			'fingerprint' => $fingerprint,
			'source_type' => 'device',
			'source_key' => $row['id'] . ':' . $row['rule_id'] . ':' . $row['local_data_id'],
			'host_id' => $row['id'],
			'local_data_id' => $row['local_data_id'],
			'severity' => $row['severity'],
			'title' => $row['description'] . ' - ' . $row['rule_name'],
			'message' => $message
		));
	}

	nms_resolve_missing('device', $active);
}

function nms_sync_all_faults($force = false) {
	$last = (int) db_fetch_cell_prepared("SELECT meta_value FROM plugin_nms_meta WHERE meta_key = 'last_sync'", array());
	if (!$force && $last > 0 && time() - $last < 30) {
		return;
	}

	nms_sync_device_faults();
	nms_resolve_missing('poller', array());
	nms_resolve_missing('rrd', array());
	nms_resolve_missing('output', array());

	db_execute_prepared("INSERT INTO plugin_nms_meta (meta_key, meta_value, updated_at)
		VALUES ('last_sync', ?, ?)
		ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = VALUES(updated_at)",
		array((string) time(), nms_now()));
}

function nms_time_ago($date) {
	$timestamp = strtotime($date);
	$seconds = max(0, time() - $timestamp);
	if ($seconds < 60) return $seconds . 's ago';
	if ($seconds < 3600) return floor($seconds / 60) . 'm ago';
	if ($seconds < 86400) return floor($seconds / 3600) . 'h ago';
	return floor($seconds / 86400) . 'd ago';
}
