<?php

/** Explicit per-register limits turn actual serial poll transitions into standard Syslog events. */
function icct_nms_serial_event_rules($id)
{
    $json = db_fetch_cell_prepared(
        'SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
        ['serial_event_rules_' . (int) $id],
    );
    return $json ? json_decode($json, true, 16, JSON_THROW_ON_ERROR) : [];
}

function icct_nms_serial_event_validate($rows, $fields)
{
    if (!is_array($rows) || count($rows) > 16) {
        throw new InvalidArgumentException('Enter valid serial event rules.');
    }
    $rules = [];
    foreach ($rows as $key => $row) {
        $field = $fields[$key] ?? null;
        if (!is_array($row) || !$field) {
            throw new InvalidArgumentException('Select a register from this device’s saved profile.');
        }
        $limits = [];
        foreach (['below', 'above'] as $bound) {
            $value = $row[$bound] ?? '';
            if ($value === '') {
                continue;
            }
            if (!is_string($value) || !preg_match('/^-?[0-9]{1,5}$/D', $value) ||
                (int) $value < $field['min'] || (int) $value > $field['max']) {
                throw new InvalidArgumentException('Enter limits within the range for ' . $field['label'] . '.');
            }
            $limits[$bound] = (int) $value;
        }
        if (!$limits) {
            continue;
        }
        if (isset($limits['below'], $limits['above']) && $limits['below'] > $limits['above']) {
            throw new InvalidArgumentException('The lower limit cannot exceed the upper limit.');
        }
        $rules[$key] = $limits + [
            'severity' => icct_backend_config_integer($row['severity'] ?? '', 0, 7, 'Syslog severity'),
        ];
    }
    return $rules;
}

function icct_nms_save_serial_event_rules($target, $input)
{
    if ($target['profile']['protocol'] !== 'modbus_rtu') {
        throw new InvalidArgumentException('Select a Modbus register profile for serial events.');
    }
    $rules = icct_nms_serial_event_validate($input, $target['fields']);
    icct_backend_category_execute(
        'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ' .
            'ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
        [
            'serial_event_rules_' . (int) $target['host']['id'],
            json_encode(['profile_id' => (int) $target['profile']['id'], 'rules' => $rules], JSON_THROW_ON_ERROR),
        ],
    );
}

function icct_nms_serial_event_state($value, $rule)
{
    if (!is_int($value)) {
        return null;
    }
    if (isset($rule['below']) && $value < $rule['below']) {
        return 'below';
    }
    if (isset($rule['above']) && $value > $rule['above']) {
        return 'above';
    }
    return 'within';
}

function icct_nms_serial_event_record($target, $key, $rule, $previous, $result)
{
    if (($result['status'] ?? '') !== 'read') {
        return;
    }
    $value = $result['value'];
    $state = icct_nms_serial_event_state($value, $rule);
    $oldValue = ($previous['status'] ?? '') === 'complete' &&
        hash_equals($target['signature'], (string) ($previous['signature'] ?? ''))
        ? json_decode($previous['value_json'], true, 8, JSON_THROW_ON_ERROR)
        : null;
    $oldState = icct_nms_serial_event_state($oldValue, $rule);
    if ($state === null || $state === $oldState || ($state === 'within' && $oldState === null)) {
        return;
    }
    $field = $target['fields'][$key];
    $label = preg_replace('/[\r\n\t]+/', ' ', $field['label']);
    $unit = $field['unit'] === '' ? '' : ' ' . preg_replace('/[\r\n\t]+/', ' ', $field['unit']);
    $reading = $value . $unit;
    $message = $state === 'within'
        ? "$label ($key) returned within its configured limits at $reading."
        : "$label ($key) read $reading, $state its configured limit of " . $rule[$state] . $unit . '.';
    $severity = $state === 'within' ? 5 : $rule['severity'];
    $facility = 16; // Local0 is reserved for the serial proxy's generated Syslog events.
    $program = 'icct-serial';
    $id = (int) $target['host']['id'];
    $now = date('Y-m-d H:i:s');
    $raw = '<' . ($facility * 8 + $severity) . '>1 ' . gmdate('Y-m-d\TH:i:s\Z') .
        ' - ' . $program . ' - SERIAL-REGISTER - ' . $message;
    $fingerprint = hash('sha256', json_encode(['serial-proxy', $id, $target['signature'], $key, $state, $value], JSON_THROW_ON_ERROR));

    // The authorized serial poll identifies the device directly. A serial register has no sender IP.
    // Persist in the existing Syslog outbox within the transaction that stores the measured reading.
    icct_backend_store_received_event([
        $id, 'syslog', $now, $now, $now, '', '', 'internal',
        $facility, icct_backend_syslog_facility_labels()[$facility],
        $severity, icct_backend_syslog_severity_labels()[$severity],
        icct_backend_syslog_nms_severity($severity), $program, $message,
        $fingerprint, 'receiver', $now, $raw,
    ], $reason);
}
