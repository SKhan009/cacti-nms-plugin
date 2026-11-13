<?php

require_once __DIR__ . '/../../../fcaps/services/passive_fault_service.php';

/** The saved event is the outbox. Store its projection and cursor in the same transaction. */
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
            'SELECT e.*, h.hostname FROM plugin_icct_nms_syslog_events e JOIN host h ON h.id=e.host_id ' .
                "WHERE e.event_origin IN ('syslog','trap') AND h.deleted='' AND h.poller_id=? " .
                'AND e.synced_count<e.repeat_count ORDER BY e.id LIMIT ' .
                max(1, min(1000, (int) $limit)) .
                ' FOR UPDATE',
            [icct_backend_inventory_collector_id()],
        );
        if ($rows === false) {
            throw new IcctSyslogStorageException('Passive event outbox lookup failed.');
        }
        $rulesByDevice = [];
        foreach ($rows as $event) {
            $hostId = (int) $event['host_id'];
            $rulesByDevice[$hostId] ??= icct_nms_passive_fault_rules($hostId);
            $rule = icct_nms_passive_fault_match($event, $rulesByDevice[$hostId]);
            $key = $event['alarm_key'];
            if ($rule) {
                $group = $rule['group'];
                $grouped = $group !== '';
                if ($key === null) {
                    if ($grouped) {
                        // Reuse only the active incident. A new event can open another after closure.
                        $groupKey =
                            'passive_group_' .
                            $hostId .
                            '_' .
                            substr(hash('sha256', $group), 0, 40);
                        $key = db_fetch_cell_prepared(
                            'SELECT a.alarm_key FROM plugin_icct_nms_meta m JOIN plugin_icct_nms_alarms a ' .
                                "ON a.alarm_key=BINARY m.meta_value WHERE m.meta_key=? AND a.state='Active' FOR UPDATE",
                            [$groupKey],
                        );
                        if (!$key) {
                            $key = hash(
                                'sha256',
                                'passive-group:' . $hostId . ':' . $group . ':' . $event['id'],
                            );
                            icct_backend_syslog_execute(
                                'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ' .
                                    'ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
                                [$groupKey, $key],
                            );
                        }
                    } else {
                        $key = hash('sha256', $event['event_origin'] . ':' . $event['id']);
                    }
                }
                $observation = [
                    'rule' => $event['event_origin'],
                    'passive_key' => $key,
                    'graph_id' => 0,
                    'graph' => $rule['name'],
                    'source_address' => $event['source_ip'],
                    'event_count_delta' =>
                        (int) $event['repeat_count'] - (int) $event['synced_count'],
                    'sample_time' => strtotime($event['last_seen']),
                    'occurred_at' => $event['event_time'],
                    'observed_at' => $event['last_seen'],
                    'state' => $rule['severity'],
                ];
                $rule['source'] = $grouped ? 'passive' : $event['event_origin'];
                $rule['description'] = $grouped
                    ? 'Matched passive event rules for incident group ' . $group . '.'
                    : $event['message'];
                if (
                    !icct_nms_alarm_record(
                        ['id' => $hostId, 'hostname' => $event['hostname']],
                        $rule,
                        $observation,
                        false,
                    )
                ) {
                    throw new IcctSyslogStorageException(
                        'Passive alarm is busy. Its outbox was retained.',
                    );
                }
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
            }
            // An unmatched message is still visible in history. It creates no alarm.
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
