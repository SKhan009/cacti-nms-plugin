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

    $source = icct_nms_syslog_ip($input['source_address'] ?? null);
    $transport = strtolower((string) ($input['transport'] ?? 'both'));
    if (!in_array($transport, ['udp', 'tcp', 'both'], true)) {
        throw new InvalidArgumentException('Select UDP, TCP or Both for Syslog transport.');
    }

    $filters = icct_backend_syslog_filters($input);
    $derived_max = $filters['severity_codes'] ? max($filters['severity_codes']) : 0;
    $max_severity = filter_var($input['max_severity'] ?? $derived_max, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 0, 'max_range' => 7],
    ]);
    if ($max_severity === false) {
        throw new InvalidArgumentException('Select a valid Syslog severity threshold.');
    }
    $enabled = icct_backend_protocol_enabled($id, 'syslog') ? 1 : 0;
    icct_nms_syslog_source_write($id, $source, static function () use (
        $id,
        $enabled,
        $source,
        $transport,
        $max_severity,
        $filters,
    ) {
        icct_backend_category_execute(
            'INSERT INTO plugin_icct_nms_syslog_devices(host_id,enabled,source_address,transport,' .
                "max_severity,severity_codes,facility_codes,match_strings,updated_by,updated_at)\n         " .
                "VALUES(?,?,?,?,?,?,?,?,?,NOW())\n         ON DUPLICATE KEY UPDATE enabled=VALUES(enabled)," .
                "source_address=VALUES(source_address),transport=VALUES(transport),\n         " .
                'max_severity=VALUES(max_severity),severity_codes=VALUES(severity_codes),' .
                'facility_codes=VALUES(facility_codes),match_strings=VALUES(match_strings),' .
                'updated_by=VALUES(updated_by),updated_at=NOW()',
            [
                (int) $id,
                $enabled,
                $source,
                $transport,
                (int) $max_severity,
                json_encode($filters['severity_codes'], JSON_THROW_ON_ERROR),
                json_encode($filters['facility_codes'], JSON_THROW_ON_ERROR),
                json_encode($filters['match_strings'], JSON_THROW_ON_ERROR),
                icct_backend_current_user_id(),
            ],
        );
    });
}

function icct_nms_syslog_remove($id)
{
    icct_backend_require_management(3);
    icct_backend_require_device_access($id);
    icct_backend_category_execute('DELETE FROM plugin_icct_nms_syslog_devices WHERE host_id=?', [
        (int) $id,
    ]);
}

/** Serialize source mapping changes so two devices cannot claim the same sender. */
function icct_nms_syslog_source_write($id, $source, callable $write)
{
    $lock = 'icct_syslog_source_' . hash('sha256', $source);
    $lock = substr($lock, 0, 64);
    if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?,5)', [$lock]) !== 1) {
        throw new RuntimeException('Syslog source mapping is busy. Retry the change.');
    }
    try {
        $duplicate = db_fetch_row_prepared(
            'SELECT d.host_id,h.description,h.hostname FROM plugin_icct_nms_syslog_devices d INNER ' .
                'JOIN host h ON h.id=d.host_id WHERE d.host_id<>? AND d.enabled=1 AND INET6_ATON(d.source_address)=INET6_ATON(?) ' .
                'AND h.deleted=\'\' ORDER BY d.host_id LIMIT 1',
            [(int) $id, $source],
        );
        if ($duplicate) {
            $label = trim((string) ($duplicate['description'] ?? ''));
            $endpoint = trim((string) ($duplicate['hostname'] ?? ''));
            $detail =
                $label !== '' ? ' “' . $label . '”' : ' device #' . (int) $duplicate['host_id'];
            if ($endpoint !== '') {
                $detail .= ' (' . $endpoint . ')';
            }
            throw new InvalidArgumentException(
                'Syslog source ' .
                    $source .
                    ' is already assigned to enabled device' .
                    $detail .
                    '. Change or disable that mapping before reusing the source address.',
            );
        }

        $write();
    } finally {
        db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}

function icct_nms_syslog_toggle($id, $enabled)
{
    icct_backend_require_management(3);
    icct_backend_require_device_access($id);
    $write = static function () use ($id, $enabled) {
        icct_backend_category_execute(
            'UPDATE plugin_icct_nms_syslog_devices SET enabled=?,updated_by=?,updated_at=NOW() WHERE host_id=?',
            [$enabled ? 1 : 0, icct_backend_current_user_id(), (int) $id],
        );
    };
    $binding = icct_nms_syslog_assignment($id);
    if ($enabled && $binding) {
        icct_nms_syslog_source_write($id, icct_nms_syslog_ip($binding['source_address']), $write);
    } else {
        $write();
    }
}
