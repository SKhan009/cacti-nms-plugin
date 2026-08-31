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

function nms_poller_status_name($status) {
	$map = array(
		POLLER_STATUS_NEW => 'New',
		POLLER_STATUS_RUNNING => 'Running',
		POLLER_STATUS_IDLE => 'Idle',
		POLLER_STATUS_DOWN => 'Down',
		POLLER_STATUS_DISABLED => 'Disabled',
		POLLER_STATUS_RECOVERING => 'Recovering',
		POLLER_STATUS_HEARTBEAT => 'Heartbeat missed'
	);
	return isset($map[$status]) ? $map[$status] : 'Invalid state ' . $status;
}

function nms_sync_device_faults() {
	$rows = db_fetch_assoc("SELECT h.*, s.name AS site_name
		FROM host AS h
		LEFT JOIN sites AS s ON s.id = h.site_id
		WHERE h.deleted = '' AND h.disabled = '' AND h.status != " . HOST_UP);
	$active = array();

	foreach ($rows as $row) {
		$fingerprint = 'device:' . $row['id'] . ':status';
		$active[] = $fingerprint;
		$status = nms_host_status_name((int) $row['status']);
		$severity = (int) $row['status'] === HOST_DOWN || (int) $row['status'] === HOST_ERROR ? 'critical' : 'warning';
		$message = trim($row['status_last_error']) !== '' ? $row['status_last_error'] : 'Cacti reports device state ' . $status;
		if (!empty($row['site_name'])) {
			$message .= ' | Site: ' . $row['site_name'];
		}
		nms_open_incident(array(
			'fingerprint' => $fingerprint,
			'source_type' => 'device',
			'source_key' => (string) $row['id'],
			'host_id' => $row['id'],
			'severity' => $severity,
			'title' => $row['description'] . ' is ' . strtolower($status),
			'message' => $message
		));
	}

	nms_resolve_missing('device', $active);
}

function nms_sync_poller_faults() {
	$cron = (int) read_config_option('cron_interval');
	if ($cron < 60) {
		$cron = 300;
	}
	$stale_after = max(600, ($cron * 2) + 60);
	$rows = db_fetch_assoc('SELECT * FROM poller');
	$active = array();

	foreach ($rows as $row) {
		if ($row['disabled'] === 'on' || (int) $row['status'] === POLLER_STATUS_DISABLED) {
			continue;
		}

		$last = max((int) strtotime($row['last_status']), (int) strtotime($row['last_update']));
		$age = $last > 0 ? time() - $last : PHP_INT_MAX;
		if ($age > $stale_after) {
			$fingerprint = 'poller:' . $row['id'] . ':stale';
			$active[] = $fingerprint;
			nms_open_incident(array(
				'fingerprint' => $fingerprint,
				'source_type' => 'poller',
				'source_key' => (string) $row['id'],
				'poller_id' => $row['id'],
				'severity' => 'critical',
				'title' => $row['name'] . ' is not updating',
				'message' => 'Last collector update: ' . $row['last_update'] . ' (' . $age . ' seconds ago)'
			));
		}

		if (!in_array((int) $row['status'], array(POLLER_STATUS_NEW, POLLER_STATUS_RUNNING, POLLER_STATUS_IDLE), true)) {
			$fingerprint = 'poller:' . $row['id'] . ':status';
			$active[] = $fingerprint;
			$status = nms_poller_status_name((int) $row['status']);
			nms_open_incident(array(
				'fingerprint' => $fingerprint,
				'source_type' => 'poller',
				'source_key' => (string) $row['id'],
				'poller_id' => $row['id'],
				'severity' => (int) $row['status'] === POLLER_STATUS_RECOVERING ? 'warning' : 'critical',
				'title' => $row['name'] . ' status is ' . strtolower($status),
				'message' => 'Collector ' . $row['hostname'] . ' reports ' . $status
			));
		}
	}

	nms_resolve_missing('poller', $active);
}

function nms_sync_rrd_faults() {
	$rows = db_fetch_assoc("SELECT pi.local_data_id, pi.host_id, pi.rrd_path, MAX(pi.rrd_step) AS rrd_step,
		MAX(dtd.name_cache) AS name_cache, MAX(h.description) AS host_description
		FROM poller_item AS pi
		INNER JOIN host AS h ON h.id = pi.host_id AND h.deleted = '' AND h.disabled = ''
		LEFT JOIN data_template_data AS dtd ON dtd.local_data_id = pi.local_data_id
		GROUP BY pi.local_data_id, pi.host_id, pi.rrd_path");
	$active = array();

	foreach ($rows as $row) {
		$step = max(60, (int) $row['rrd_step']);
		$stale_after = max(600, ($step * 3));
		$name = trim($row['name_cache']) !== '' ? $row['name_cache'] : 'Data source ' . $row['local_data_id'];

		if (!is_file($row['rrd_path'])) {
			$fingerprint = 'rrd:' . $row['local_data_id'] . ':missing';
			$active[] = $fingerprint;
			nms_open_incident(array(
				'fingerprint' => $fingerprint,
				'source_type' => 'rrd',
				'source_key' => (string) $row['local_data_id'],
				'host_id' => $row['host_id'],
				'local_data_id' => $row['local_data_id'],
				'severity' => 'major',
				'title' => $name . ' RRD file is missing',
				'message' => 'Expected path: ' . $row['rrd_path']
			));
			continue;
		}

		$age = time() - filemtime($row['rrd_path']);
		if ($age > $stale_after) {
			$fingerprint = 'rrd:' . $row['local_data_id'] . ':stale';
			$active[] = $fingerprint;
			nms_open_incident(array(
				'fingerprint' => $fingerprint,
				'source_type' => 'rrd',
				'source_key' => (string) $row['local_data_id'],
				'host_id' => $row['host_id'],
				'local_data_id' => $row['local_data_id'],
				'severity' => 'major',
				'title' => $name . ' data is stale',
				'message' => 'RRD last changed ' . $age . ' seconds ago for ' . $row['host_description']
			));
		}
	}

	nms_resolve_missing('rrd', $active);
}

function nms_sync_all_faults($force = false) {
	$last = (int) db_fetch_cell_prepared("SELECT meta_value FROM plugin_nms_meta WHERE meta_key = 'last_sync'", array());
	if (!$force && $last > 0 && time() - $last < 30) {
		return;
	}

	nms_sync_device_faults();
	nms_sync_poller_faults();
	nms_sync_rrd_faults();

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

function nms_recent_core_log_events($limit = 15) {
	$path = read_config_option('path_cactilog');
	$events = array();
	if ($path === '' || !is_readable($path)) {
		return $events;
	}

	$size = filesize($path);
	$read = min($size, 262144);
	$handle = fopen($path, 'rb');
	if ($handle === false) {
		return $events;
	}
	if ($read < $size) {
		fseek($handle, -$read, SEEK_END);
	}
	$data = fread($handle, $read);
	fclose($handle);

	$seen = array();
	$lines = preg_split('/\r?\n/', $data);
	foreach (array_reverse($lines) as $line) {
		$line = trim($line);
		if ($line === '' || !preg_match('/\b(FATAL|ERROR|WARNING)\b/i', $line, $match)) {
			continue;
		}

		/* Cacti writes a second shutdown-handler line for the same PHP error. */
		if (strpos($line, 'CactiShutdownHandler()') !== false) {
			continue;
		}

		$time = '';
		$message = $line;
		if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}|\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2})\s*-\s*(.*)$/', $line, $parts)) {
			$time = $parts[1];
			$message = $parts[2];
		}

		$event = array(
			'severity' => strtolower($match[1]),
			'title' => ucfirst(strtolower($match[1])) . ' reported by Cacti',
			'detail' => preg_replace('/^(ERROR PHP ERROR:|AUTOM8 WARNING:|WARNING:|ERROR:)\s*/i', '', $message),
			'time' => $time,
			'state' => 'attention',
			'count' => 1,
			'key' => sha1(preg_replace('/\d+/', '#', $message))
		);

		if (strpos($line, 'csrf-magic.php') !== false && strpos($line, '/plugins/nms/nms.php') !== false) {
			$event['severity'] = 'resolved';
			$event['title'] = 'Older NMS page error — fixed';
			$event['detail'] = 'The previous NMS page footer could not load correctly. The footer was removed and this problem is no longer occurring.';
			$event['state'] = 'resolved';
			$event['key'] = 'resolved-nms-footer';
		} elseif (strpos($line, 'AUTOM8 WARNING:') !== false && strpos($line, 'SQL column ifIP') !== false) {
			$event['severity'] = 'resolved';
			$event['title'] = 'Older traffic rule warning — fixed';
			$event['detail'] = 'A traffic rule requested an IP field that this device does not provide. The unsupported check was removed.';
			$event['state'] = 'resolved';
			$event['key'] = 'resolved-automation-ifip';
		} else {
			$event['detail'] = preg_replace('/\s+Stack trace:.*/i', '', $event['detail']);
			if (strlen($event['detail']) > 240) {
				$event['detail'] = substr($event['detail'], 0, 237) . '...';
			}
		}

		if (isset($seen[$event['key']])) {
			$events[$seen[$event['key']]]['count']++;
			continue;
		}

		$seen[$event['key']] = count($events);
		$events[] = $event;
		if (count($events) >= $limit) break;
	}

	return $events;
}
