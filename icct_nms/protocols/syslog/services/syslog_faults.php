<?php

/** A received event owns one stable journal key; reuse the existing alarm workflow and history. */
function icct_nms_syslog_fault_observation($event)
{
    $severity = icct_backend_syslog_nms_severity($event['severity_code']);
    return [
        'rule' => 'syslog',
        'event_id' => (int) $event['id'],
        'graph_id' => 0,
        'graph' => $event['program'] === '' ? 'Device Syslog' : $event['program'],
        'source_address' => $event['source_ip'],
        'event_count' => (int) $event['repeat_count'],
        'sample_time' => strtotime($event['last_seen']),
        'occurred_at' => $event['event_time'],
        'observed_at' => $event['last_seen'],
        'state' => [
            'critical' => 'Critical',
            'major' => 'Major',
            'warning' => 'Warning',
            'info' => 'Information',
            'debug' => 'Information',
        ][$severity],
    ];
}

/** Durable raw rows are the outbox. A failed projection is retried without replaying wire messages. */
function icct_backend_syslog_sync_faults($limit = 200)
{
    $lock =
        'icct_syslog_fault_' .
        substr(hash('sha256', (string) db_fetch_cell('SELECT DATABASE()')), 0, 32);
    if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?,0)', [$lock]) !== 1) {
        return 0;
    }
    try {
        icct_backend_syslog_execute('START TRANSACTION', []);
        $rows = db_fetch_assoc_prepared(
            'SELECT e.* FROM plugin_icct_nms_syslog_events e JOIN host h ON h.id=e.host_id ' .
                "WHERE e.event_origin='syslog' AND h.deleted='' AND h.poller_id=? " .
                'AND e.synced_count<e.repeat_count ORDER BY e.id LIMIT ' .
                max(1, min(1000, (int) $limit)) .
                ' FOR UPDATE',
            [icct_backend_inventory_collector_id()],
        );
        if ($rows === false) {
            throw new IcctSyslogStorageException('Syslog fault outbox lookup failed.');
        }
        foreach ($rows as $event) {
            $host = db_fetch_row_prepared('SELECT * FROM host WHERE id=?', [
                (int) $event['host_id'],
            ]);
            $observation = icct_nms_syslog_fault_observation($event);
            $rule = [
                'source' => 'syslog',
                'name' => $observation['graph'],
                'description' => $event['message'],
                'corrective_action' => '',
            ];
            if (!icct_nms_alarm_record($host, $rule, $observation, false)) {
                throw new IcctSyslogStorageException(
                    'Syslog alarm is busy; its outbox was retained.',
                );
            }
            $key = icct_nms_alarm_key($host, $rule, $observation);
            // Preserve acknowledgements from older received-event history on first normalization.
            if ($event['alarm_key'] === null && (int) $event['acknowledged']) {
                icct_backend_syslog_execute(
                    'UPDATE plugin_icct_nms_alarms SET acknowledged=1 WHERE alarm_key=?',
                    [$key],
                );
                icct_nms_alarm_history_write(
                    $key,
                    'Acknowledgement imported',
                    (int) $event['acknowledged_by'],
                    $event['ack_comment'],
                );
            }
            icct_backend_syslog_execute(
                'UPDATE plugin_icct_nms_syslog_events SET alarm_key=?,synced_count=? WHERE id=?',
                [$key, (int) $event['repeat_count'], (int) $event['id']],
            );
        }
        icct_backend_syslog_execute('COMMIT', []);
        return count($rows);
    } catch (Throwable $error) {
        db_execute_prepared('ROLLBACK', []);
        throw $error;
    } finally {
        db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}
