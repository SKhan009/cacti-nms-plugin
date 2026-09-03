<?php
/**
 * @file device_metadata.php
 * Operator-entered device identity stored exclusively in NMS-owned metadata.
 * Manual serial numbers never replace live SNMP observations or their fault baseline.
 */

require_once(__DIR__ . '/inventory.php');

/** Validate optional serial text before any write; reject arrays, control characters, and oversized values. */
function nms_manual_serial_validate($value) {
	if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
		throw new InvalidArgumentException('Enter a serial number as a single line of text.');
	}
	$value = trim($value);
	if (strlen($value) > 191) throw new InvalidArgumentException('Serial number must be 191 bytes or fewer.');
	return $value;
}

/** Read the operator-entered serial without substituting an SNMP value when metadata is absent. */
function nms_manual_serial_get($host_id) {
	$value = db_fetch_cell_prepared('SELECT serial_number FROM plugin_nms_device_metadata WHERE host_id = ?', array((int) $host_id));
	return is_string($value) ? $value : '';
}

/**
 * Resolve the serial definition through the device's current imported host template.
 * Use the same newest-import/first-OID order as the inventory poller. Only an
 * observation for that exact OID can supply a suggestion; file samples are not selected.
 */
function nms_device_serial_reading($host_id) {
	$reading = db_fetch_row_prepared("SELECT h.status AS host_status, h.disabled, h.last_updated AS host_last_updated,
		o.oid, di.observed_value, di.status, di.last_success
		FROM host AS h
		INNER JOIN plugin_nms_snmprec_imports AS i ON i.host_template_id = h.host_template_id
		INNER JOIN plugin_nms_snmprec_oids AS o ON o.import_id = i.id AND o.inventory_key = 'serial_number'
		LEFT JOIN plugin_nms_device_inventory AS di ON di.host_id = h.id
			AND di.inventory_key = o.inventory_key AND di.oid = o.oid
		WHERE h.id = ? AND h.deleted = ''
		ORDER BY i.id DESC, o.id LIMIT 1", array((int) $host_id));
	return is_array($reading) ? $reading : array();
}

/**
 * Prepare an editable suggestion without saving metadata or changing SNMP state.
 * A saved NMS value takes precedence. Otherwise require a current successful poll
 * from an enabled, Up host and a serial that fits the manual field unchanged.
 */
function nms_serial_form_prefill($manual_value, $reading, $allow_suggestion = true) {
	$result = array('value' => $manual_value, 'source' => $manual_value !== '' ? 'saved' : 'empty',
		'oid' => (string) ($reading['oid'] ?? ''), 'last_success' => '');
	if ($manual_value !== '' || !$allow_suggestion || !$result['oid']) return $result;
	if ((int) ($reading['host_status'] ?? 0) !== HOST_UP || ($reading['disabled'] ?? '') !== '' ||
		!in_array($reading['status'] ?? '', array('ok', 'changed'), true)) return $result;
	if (!nms_parameter_is_fresh($reading['host_last_updated'] ?? '')) return $result;
	$last_success = (string) ($reading['last_success'] ?? '');
	$timestamp = strtotime($last_success);
	if ($timestamp === false || $timestamp > time() || !nms_parameter_is_fresh($last_success)) return $result;
	try {
		$value = nms_manual_serial_validate($reading['observed_value'] ?? '');
	} catch (InvalidArgumentException $exception) {
		// Do not truncate an identity to fit the input or substitute malformed SNMP output.
		return $result;
	}
	if (nms_inventory_snmp_failed($value)) return $result;
	$result['value'] = $value;
	$result['source'] = 'snmp';
	$result['last_success'] = $last_success;
	return $result;
}

/** Save or clear an existing device's manual serial; write only plugin metadata, never core or live inventory. */
function nms_manual_serial_save($host_id, $value) {
	$value = nms_manual_serial_validate($value);
	$host_id = (int) $host_id;
	if ($host_id < 1 || !(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM host WHERE id = ? AND deleted = ''", array($host_id))) {
		throw new InvalidArgumentException('Select an existing Cacti device before saving its serial number.');
	}
	if ($value === '') {
		$saved = db_execute_prepared('DELETE FROM plugin_nms_device_metadata WHERE host_id = ?', array($host_id));
	} else {
		$saved = db_execute_prepared('INSERT INTO plugin_nms_device_metadata
			(host_id, serial_number, updated_by, updated_at) VALUES (?, ?, ?, NOW())
			ON DUPLICATE KEY UPDATE serial_number = VALUES(serial_number), updated_by = VALUES(updated_by), updated_at = NOW()',
			array($host_id, $value, nms_current_user_id()));
	}
	if ($saved === false) throw new RuntimeException('NMS could not save the manual serial number. Please retry.');
	return $value;
}
