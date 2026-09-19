<?php
/** Read actual Cacti poller values and translate their state into operator-facing language. */

/** Return the latest captured RRD-backed readings for one visible Cacti device. */
function nms_device_readings($host_id)
{
	return db_fetch_assoc_prepared(
		"SELECT p.local_data_id, p.parameter_key, p.parameter_name, p.display_name,
		p.raw_value, p.numeric_value, p.last_seen, dtd.data_source_path AS rrd_path,
		pi.arg1 AS stored_oid, h.status AS host_status, h.last_updated AS host_last_updated, h.status_last_error,
		dtd.name_cache AS data_source_name
		FROM plugin_nms_device_parameters AS p
		INNER JOIN host AS h ON h.id = p.host_id
		LEFT JOIN data_local AS dl ON dl.id = p.local_data_id
		LEFT JOIN data_template_data AS dtd ON dtd.local_data_id = p.local_data_id
		LEFT JOIN poller_item AS pi ON pi.local_data_id = p.local_data_id
			AND pi.host_id = p.host_id
			AND (pi.rrd_name = p.parameter_name OR pi.rrd_name = '')
		WHERE p.host_id = ?
		ORDER BY p.last_seen DESC, p.display_name, p.parameter_name",
		[(int) $host_id]
	);
}

/** Return the latest persisted discovery evidence for one Cacti device. */
function nms_device_discovery_readings($host_id)
{
	return db_fetch_assoc_prepared(
		"SELECT protocol,status,attempted_at,succeeded_at,data_json,error
		FROM plugin_nms_discovery_snapshots WHERE host_id=? ORDER BY protocol",
		[(int) $host_id]
	);
}

/** Classify a captured value for the device-reading filters without changing source data. */
function nms_reading_category($reading)
{
	$name = strtolower((string) ($reading['display_name'] . ' ' . $reading['parameter_name']));
	if (preg_match('/octet|bit|traffic|bandwidth|inbound|outbound/', $name)) {
		return 'traffic';
	}

	return preg_match('/if|interface|port|link|nonunicast|broadcast/', $name) ? 'interfaces' : 'all';
}

/** Extract the interface index retained in Cacti's data-source display name. */
function nms_reading_interface_index($reading)
{
	if (preg_match('/SNMP index\s+(\d+)/i', (string) $reading['display_name'], $matches)) {
		return $matches[1];
	}

	return '';
}

/** Extract a human-readable interface name from a Cacti data-source display name. */
function nms_reading_interface_name($reading)
{
	$display = (string) $reading['display_name'];
	if (preg_match('/ - (?:Traffic|Broadcast Packets) - ([^\/·]+)(?:\/\d+)?/i', $display, $matches)) {
		return trim($matches[1]);
	}

	return 'the interface';
}

/** Return a concise operator-facing name for a Cacti parameter. */
function nms_reading_label($reading)
{
	$name = strtolower((string) $reading['parameter_name']);
	$interface = nms_reading_interface_name($reading);
	$labels = [
		'uptime' => 'Device uptime',
		'traffic_in' => 'Incoming traffic — ' . $interface,
		'traffic_out' => 'Outgoing traffic — ' . $interface,
		'nonunicast_in' => 'Broadcast packets received — ' . $interface,
		'nonunicast_out' => 'Broadcast packets sent — ' . $interface,
		'sscpuidle' => 'CPU idle',
		'sscpusystem' => 'CPU system',
		'sscpuuser' => 'CPU user',
		'load_1min' => 'Load average (1 minute)',
		'load_5min' => 'Load average (5 minutes)',
		'load_15min' => 'Load average (15 minutes)',
		'proc' => 'Processes',
		'users' => 'Logged-in users',
		'mem_buffers' => 'Memory buffers',
		'mem_swap' => 'Free swap memory',
		'cpu_percent' => 'CPU utilization',
		'memory_percent' => 'Memory utilization',
		'uptime_seconds' => 'Device uptime',
	];

	return $labels[$name] ?? trim((string) ($reading['parameter_name'] ?: $reading['display_name']));
}

/** Return the standard SNMP MIB object behind a stored Cacti reading when known. */
function nms_reading_source($reading)
{
	$stored_oid = trim((string) ($reading['stored_oid'] ?? ''));
	if (preg_match('/^\.?\d+(?:\.\d+)+$/', $stored_oid)) {
		return $stored_oid;
	}

	$name = strtolower((string) $reading['parameter_name']);
	$index = nms_reading_interface_index($reading);
	$index = $index === '' ? '' : '.' . $index;
	$sources = [
		'uptime' => 'SNMPv2-MIB · sysUpTime.0',
		'traffic_in' => 'IF-MIB · ifHCInOctets' . $index,
		'traffic_out' => 'IF-MIB · ifHCOutOctets' . $index,
		'nonunicast_in' => 'IF-MIB · ifInNUcastPkts' . $index,
		'nonunicast_out' => 'IF-MIB · ifOutNUcastPkts' . $index,
		'sscpuidle' => 'UCD-SNMP-MIB · ssCpuIdle.0',
		'sscpusystem' => 'UCD-SNMP-MIB · ssCpuSystem.0',
		'sscpuuser' => 'UCD-SNMP-MIB · ssCpuUser.0',
		'load_1min' => 'UCD-SNMP-MIB · laLoad.1',
		'load_5min' => 'UCD-SNMP-MIB · laLoad.2',
		'load_15min' => 'UCD-SNMP-MIB · laLoad.3',
		'proc' => 'HOST-RESOURCES-MIB · hrSystemProcesses.0',
		'users' => 'UCD-SNMP-MIB · ssNumUsers.0',
		'mem_buffers' => 'UCD-SNMP-MIB · memBuffer.0',
		'mem_swap' => 'UCD-SNMP-MIB · memAvailSwap.0',
	];

	return $sources[$name] ?? 'Cacti RRD · ' . $name;
}

/** Summarize a discovery snapshot while retaining complete data in the raw-evidence panel. */
function nms_reading_snapshot_summary($snapshot)
{
	$data = json_decode((string) $snapshot['data_json'], true);
	$data = is_array($data) ? $data : [];
	$parts = [];
	foreach (['interfaces' => 'interface', 'neighbors' => 'neighbour', 'endpoints' => 'endpoint'] as $key => $label) {
		$count = count($data[$key] ?? []);
		if ($count) {
			$parts[] = $count . ' ' . $label . ($count === 1 ? '' : 's');
		}
	}

	return $parts ? implode(', ', $parts) : 'No rows returned';
}

/** Identify RRD's unknown tokens without treating zero as unavailable. */
function nms_reading_is_unknown($value)
{
	return in_array(strtolower(trim((string) $value)), ['', 'u', 'unknown', 'nan', 'null'], true);
}

/** Identify optional legacy counters that some compliant SNMP agents do not implement. */
function nms_reading_is_optional($reading)
{
	return in_array(strtolower((string) $reading['parameter_name']), ['nonunicast_in', 'nonunicast_out'], true);
}

/** Count only collection failures that affect a supported device metric. */
function nms_reading_is_actionable_problem($reading)
{
	return nms_reading_is_unknown($reading['raw_value']) && !nms_reading_is_optional($reading);
}

/** Format a numeric sample with readable scale while preserving the exact raw value separately. */
function nms_reading_human_number($value)
{
	$number = (float) $value;
	$absolute = abs($number);
	foreach ([1e12 => 'T', 1e9 => 'G', 1e6 => 'M', 1e3 => 'K'] as $divisor => $suffix) {
		if ($absolute >= $divisor) {
			return number_format($number / $divisor, 2) . ' ' . $suffix;
		}
	}

	return number_format($number, $absolute > 0 && $absolute < 1 ? 4 : 2);
}

/** Convert an SNMP TimeTicks uptime value into a plain-language duration. */
function nms_reading_uptime($value)
{
	$seconds = (int) floor(((float) $value) / 100);
	$days = intdiv($seconds, 86400);
	$hours = intdiv($seconds % 86400, 3600);
	$minutes = intdiv($seconds % 3600, 60);
	$parts = [];
	if ($days) $parts[] = $days . ' day' . ($days === 1 ? '' : 's');
	if ($hours || $days) $parts[] = $hours . ' hour' . ($hours === 1 ? '' : 's');
	$parts[] = $minutes . ' minute' . ($minutes === 1 ? '' : 's');
	return implode(', ', $parts);
}

/** Return a plain-language status and practical next check for one captured reading. */
function nms_reading_presentation($reading)
{
	$raw = trim((string) $reading['raw_value']);
	$label = nms_reading_label($reading);
	if (nms_reading_is_unknown($raw) && nms_reading_is_optional($reading)) {
		return [
			'tone' => 'info', 'state' => 'Not supported', 'value' => 'Not provided',
			'meaning' => $label . ' is an optional legacy SNMP counter that this device does not provide.',
			'next' => 'No action is required. Current traffic counters remain available through IF-MIB 64-bit octet counters.',
		];
	}
	if (nms_reading_is_unknown($raw)) {
		return [
			'tone' => 'warning', 'state' => 'Warning', 'value' => 'Unknown (NaN)',
			'meaning' => $label . ' did not receive a usable numeric sample during this poll.',
			'next' => 'Check the Cacti device status and last error, then verify its SNMP credentials, OID, device-side SNMP view, and poller log.',
		];
	}
	if (!is_numeric($raw)) {
		return [
			'tone' => 'warning', 'state' => 'Warning', 'value' => $raw,
			'meaning' => $label . ' returned text where this RRD data source expects a number.',
			'next' => 'Check the data input method and the OID ASN.1 type; use inventory for text values.',
		];
	}
	if (strtolower((string) $reading['parameter_name']) === 'uptime') {
		$uptime = nms_reading_uptime($raw);
	} else {
		$uptime = nms_reading_human_number($raw);
	}
	if (!nms_parameter_is_fresh($reading['last_seen'])) {
		return [
			'tone' => 'warning', 'state' => 'Stale', 'value' => $uptime,
			'meaning' => $label . ' has not been updated within two expected poll intervals.',
			'next' => 'Check the Cacti poller schedule, device reachability, and the last poller error.',
		];
	}
	return [
		'tone' => 'success', 'state' => 'OK', 'value' => $uptime,
		'meaning' => $label . ' is a current numeric value captured by the Cacti poller.',
		'next' => 'No action needed. The exact RRD value is retained below for verification.',
	];
}

/** Explain what the NMS does with a reading without changing the captured value. */
function nms_reading_action($reading)
{
	$name = strtolower((string) $reading['parameter_name']);
	if (in_array($name, ['traffic_in', 'traffic_out'], true)) return 'Compares consecutive counters to calculate traffic rate and graph it.';
	if (in_array($name, ['nonunicast_in', 'nonunicast_out'], true)) return 'Keeps the packet counter for trend and fault analysis.';
	if ($name === 'uptime') return 'Confirms the device is responding through SNMP.';
	if (str_starts_with($name, 'sscpu') || str_starts_with($name, 'load_')) return 'Stores the sample for device health monitoring.';
	return 'Retains the exact poller value and makes its condition visible.';
}

/** Replace stored parameter snapshots with the latest sample from each authorized Cacti RRD file. */
function nms_readings_load_live_rrd_values($readings)
{
	global $config;

	$rra_path = realpath((string) ($config['rra_path'] ?? ''));
	if (!$rra_path) {
		return $readings;
	}

	$sample_sets = [];
	foreach ($readings as $reading) {
		$path = str_replace('<path_rra>', $rra_path, (string) ($reading['rrd_path'] ?? ''));
		$resolved = realpath($path);
		if (!$resolved || strpos($resolved, $rra_path . DIRECTORY_SEPARATOR) !== 0 || isset($sample_sets[$resolved])) {
			continue;
		}
		$output = [];
		$status = 1;
		exec('/usr/bin/rrdtool lastupdate ' . escapeshellarg($resolved), $output, $status);
		if ($status !== 0 || count($output) < 2) {
			continue;
		}
		$names = preg_split('/\s+/', trim($output[0]));
		$sample = preg_split('/\s+/', trim($output[count($output) - 1]));
		$timestamp = rtrim((string) array_shift($sample), ':');
		if (!ctype_digit($timestamp) || count($names) !== count($sample)) {
			continue;
		}
		$sample_sets[$resolved] = ['seen' => date('Y-m-d H:i:s', (int) $timestamp), 'values' => array_combine($names, $sample)];
	}

	foreach ($readings as &$reading) {
		$path = str_replace('<path_rra>', $rra_path, (string) ($reading['rrd_path'] ?? ''));
		$resolved = realpath($path);
		$name = (string) $reading['parameter_name'];
		if (!$resolved || !isset($sample_sets[$resolved]['values'][$name])) {
			continue;
		}
		$reading['raw_value'] = $sample_sets[$resolved]['values'][$name];
		$reading['numeric_value'] = is_numeric($reading['raw_value']) ? (float) $reading['raw_value'] : null;
		$reading['last_seen'] = $sample_sets[$resolved]['seen'];
	}
	unset($reading);

	return $readings;
}
