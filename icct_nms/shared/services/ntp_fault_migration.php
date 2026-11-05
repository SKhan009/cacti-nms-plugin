<?php

/** Lifecycle-only transfer of alarm policy from protocol settings to the one FCAPS rule store. */
function icct_nms_schema_ntp_faults()
{
    require_once __DIR__ . '/../../fcaps/services/ntp_fault_service.php';
    require_once __DIR__ . '/../../presets/services/backend/categories.php';
    require_once __DIR__ . '/../../alarms/services/alarm_service.php';
    $lock = 'icct_ntp_fault_migration';
    if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?,5)', [$lock]) !== 1) {
        throw new RuntimeException('NTP fault migration is busy. Retry the upgrade.');
    }
    try {
        if (
            db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?', [
                'ntp_fault_rules_version',
            ]) === '1'
        ) {
            return;
        }
        $previous = icct_nms_protocol_presets()['ntp'] ?? [];
        $limit = $previous['maximum_offset'] ?? 100;
        $expected = $previous['expected_source'] ?? '';
        if (
            !is_numeric($limit) ||
            !is_finite((float) $limit) ||
            (float) $limit <= 0 ||
            !is_string($expected) ||
            ($expected !== '' && !filter_var($expected, FILTER_VALIDATE_IP))
        ) {
            throw new RuntimeException('Review the saved NTP alarm defaults before upgrading.');
        }
        // Keep legacy columns for upgrade compatibility, but they no longer configure a collector.
        icct_nms_schema_execute(
            'ALTER TABLE plugin_icct_nms_ntp_monitors MODIFY maximum_offset DOUBLE NULL DEFAULT NULL',
        );
        icct_backend_category_execute('START TRANSACTION');
        try {
            icct_backend_category_execute(
                'REPLACE INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',
                [
                    'ntp_fault_defaults',
                    json_encode(
                        icct_nms_ntp_fault_starters($limit, $expected),
                        JSON_THROW_ON_ERROR,
                    ),
                ],
            );
            foreach (
                db_fetch_assoc(
                    'SELECT host_id,maximum_offset,expected_source FROM plugin_icct_nms_ntp_monitors',
                )
                as $device
            ) {
                $id = (int) $device['host_id'];
                $json = db_fetch_cell_prepared(
                    'SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=? FOR UPDATE',
                    ['fault_rules_' . $id],
                );
                $rules = $json ? json_decode($json, true, 64, JSON_THROW_ON_ERROR) : [];
                if (!array_filter($rules, fn($rule) => ($rule['source'] ?? '') === 'ntp')) {
                    $deviceLimit =
                        $device['maximum_offset'] === null ? $limit : $device['maximum_offset'];
                    $deviceSource = $device['expected_source'];
                    if (
                        !is_numeric($deviceLimit) ||
                        !is_finite((float) $deviceLimit) ||
                        (float) $deviceLimit <= 0 ||
                        ($deviceSource !== '' && !filter_var($deviceSource, FILTER_VALIDATE_IP))
                    ) {
                        throw new RuntimeException(
                            'Review saved NTP alarm settings for device ' . $id . '.',
                        );
                    }
                    $rules = array_merge(
                        $rules,
                        icct_nms_ntp_fault_starters($deviceLimit, $deviceSource),
                    );
                    if (count($rules) > 100) {
                        throw new RuntimeException(
                            'Device ' .
                                $id .
                                ' exceeds the 100-rule FCAPS limit after NTP migration.',
                        );
                    }
                    icct_backend_category_execute(
                        'REPLACE INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',
                        ['fault_rules_' . $id, json_encode($rules, JSON_THROW_ON_ERROR)],
                    );
                }
            }
            // Retire the single automatic alarm without inventing a recovery observation.
            foreach (
                db_fetch_assoc(
                    "SELECT alarm_key FROM plugin_icct_nms_alarms WHERE source='NTP' AND name='NTP synchronization' AND state IN ('Active','Closed')",
                )
                as $old
            ) {
                icct_backend_category_execute(
                    "UPDATE plugin_icct_nms_alarms SET state='Disabled',revision=revision+1 WHERE alarm_key=?",
                    [$old['alarm_key']],
                );
                icct_nms_alarm_history_write(
                    $old['alarm_key'],
                    'Disabled',
                    0,
                    'NTP policy moved to explicit FCAPS rules.',
                );
            }
            icct_backend_category_execute('DELETE FROM plugin_icct_nms_ntp_monitor_state');
            icct_backend_category_execute(
                'REPLACE INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',
                ['ntp_fault_rules_version', '1'],
            );
            icct_backend_category_execute('COMMIT');
        } catch (Throwable $error) {
            icct_backend_category_execute('ROLLBACK');
            throw $error;
        }
    } finally {
        db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}
