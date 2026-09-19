<?php
/** Read actual Cacti poller values and translate their state into operator-facing language. */

/** Return the latest captured RRD-backed readings for one visible Cacti device. */
function nms_device_readings($host_id)
{
	return db_fetch_assoc_prepared(
		"SELECT p.local_data_id, p.parameter_key, p.parameter_name, p.display_name,
		p.raw_value, p.numeric_value, p.last_seen, dl.rrd_path,
		h.status AS host_status, h.last_updated AS host_last_updated, h.status_last_error,
		dtd.name_cache AS data_source_name
		FROM plugin_nms_device_parameters AS p
		INNER JOIN host AS h ON h.id = p.host_id
		LEFT JOIN data_local AS dl ON dl.id = p.local_data_id
		LEFT JOIN data_template_data AS dtd ON dtd.local_data_id = p.local_data_id
		WHERE p.host_id = ?
		ORDER BY p.last_seen DESC, p.display_name, p.parameter_name",
		[(int) $host_id],
	);
}

/** Identify RRD's unknown tokens without treating zero as unavailable. */
function nms_reading_is_unknown($value)
{
	return in_array(strtolower(trim((string) $value)), ["", "u", "unknown", "nan", "null"], true);
}

/** Format a numeric sample with readable scale while preserving the exact raw value separately. */
function nms_reading_human_number($value)
{
	$number = (float) $value;
	$absolute = abs($number);
	foreach ([1e12 => "T", 1e9 => "G", 1e6 => "M", 1e3 => "K"] as $divisor => $suffix) {
		if ($absolute >= $divisor) {
			return number_format($number / $divisor, 2) . " " . $suffix;
		}
	}
	return number_format($number, $absolute > 0 && $absolute < 1 ? 4 : 2);
}

/** Return a plain-language status and practical next check for one captured reading. */
function nms_reading_presentation($reading)
{
	$raw = trim((string) $reading["raw_value"]);
	$name = trim((string) ($reading["display_name"] ?: $reading["parameter_name"]));
	if (nms_reading_is_unknown($raw)) {
		return [
			"tone" => "warning",
			"state" => "No numeric value",
			"value" => "Unknown (NaN)",
			"meaning" => $name . " did not receive a numeric sample during this poll.",
			"next" => "Check the Cacti device status and last error, then verify its SNMP credentials, OID, device-side SNMP view, and poller log.",
		];
	}
	if (!is_numeric($raw)) {
		return [
			"tone" => "warning",
			"state" => "Non-numeric",
			"value" => $raw,
			"meaning" => $name . " returned text where this RRD data source expects a number.",
			"next" => "Check the data input method and the OID's ASN.1 type; use inventory for text values.",
		];
	}
	if (!nms_parameter_is_fresh($reading["last_seen"])) {
		return [
			"tone" => "warning",
			"state" => "Stale",
			"value" => nms_reading_human_number($raw),
			"meaning" => $name . " has not been updated within two expected poll intervals.",
			"next" => "Check the Cacti poller schedule, device reachability, and the last poller error.",
		];
	}
	return [
		"tone" => "success",
		"state" => "Current",
		"value" => nms_reading_human_number($raw),
		"meaning" => $name . " is a current numeric value captured by the Cacti poller.",
		"next" => "No action needed. The exact RRD value is retained below for verification.",
	];
}
