<?php
/** Device-facing Syslog configuration helpers. */

function icct_nms_syslog_assignment($id)
{
    return icct_backend_syslog_assignment((int) $id);
}

function icct_nms_save_syslog($id, $host, $input)
{
    icct_backend_require_management(3);
    icct_backend_require_device_access($id);

    $source = trim((string) ($input['source_address'] ?? ''));
    if ($source === '') {
        $source = trim((string) ($host['hostname'] ?? ''));
    }
    if ($source === '' || strlen($source) > 255 || preg_match('/[\s\x00-\x1F]/', $source)) {
        throw new InvalidArgumentException('Enter a valid Syslog source address or hostname.');
    }
    $duplicate = (int) db_fetch_cell_prepared(
        'SELECT COUNT(*) FROM plugin_icct_nms_syslog_devices WHERE host_id<>? AND enabled=1 AND source_address=?',
        [(int) $id, $source]
    );
    if ($duplicate > 0) {
        throw new InvalidArgumentException('That Syslog source address is already assigned to another enabled device.');
    }

    $transport = strtolower((string) ($input['transport'] ?? 'both'));
    if (!in_array($transport, ['udp', 'tcp', 'both'], true)) {
        throw new InvalidArgumentException('Select UDP, TCP or Both for Syslog transport.');
    }

    $max_severity = filter_var(
        $input['max_severity'] ?? 6,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0, 'max_range' => 7]]
    );
    if ($max_severity === false) {
        throw new InvalidArgumentException('Select a valid Syslog severity threshold.');
    }

    $filters=icct_backend_syslog_filters($input);
    $enabled = icct_backend_protocol_enabled($id, 'syslog') ? 1 : 0;
    icct_backend_category_execute(
        'INSERT INTO plugin_icct_nms_syslog_devices(host_id,enabled,source_address,transport,max_severity,severity_codes,facility_codes,match_strings,updated_by,updated_at)
         VALUES(?,?,?,?,?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),source_address=VALUES(source_address),transport=VALUES(transport),
         max_severity=VALUES(max_severity),severity_codes=VALUES(severity_codes),facility_codes=VALUES(facility_codes),match_strings=VALUES(match_strings),updated_by=VALUES(updated_by),updated_at=NOW()',
        [
            (int) $id,
            $enabled,
            $source,
            $transport,
            (int) $max_severity,
            json_encode($filters['severity_codes'],JSON_THROW_ON_ERROR),
            json_encode($filters['facility_codes'],JSON_THROW_ON_ERROR),
            json_encode($filters['match_strings'],JSON_THROW_ON_ERROR),
            icct_backend_current_user_id(),
        ]
    );
}

function icct_nms_syslog_remove($id)
{
    icct_backend_require_management(3);
    icct_backend_require_device_access($id);
    icct_backend_category_execute(
        'DELETE FROM plugin_icct_nms_syslog_devices WHERE host_id=?',
        [(int) $id]
    );
}

function icct_nms_syslog_toggle($id, $enabled)
{
    icct_backend_category_execute(
        'UPDATE plugin_icct_nms_syslog_devices SET enabled=?,updated_by=?,updated_at=NOW() WHERE host_id=?',
        [$enabled ? 1 : 0, icct_backend_current_user_id(), (int) $id]
    );
}
