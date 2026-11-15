<?php

function icct_nms_netflow_migrate_execute($sql, $params = [])
{
    if (!db_execute_prepared($sql, $params)) {
        throw new RuntimeException('NetFlow alarm migration failed.');
    }
}

/** Merge legacy per-event NetFlow alarms into one journal per device and finding. */
function icct_nms_schema_netflow_alarm_migration()
{
    $groups = db_fetch_assoc(
        "SELECT e.host_id,e.event_key FROM plugin_icct_nms_netflow_events e " .
            "JOIN plugin_icct_nms_alarms a ON a.alarm_key=SHA2(CONCAT('netflow:',e.id),256) " .
            'GROUP BY e.host_id,e.event_key',
    );
    foreach ($groups as $group) {
        $hostId = (int) $group['host_id'];
        $eventKey = $group['event_key'];
        $newKey = hash('sha256', 'netflow:' . $hostId . ':' . $eventKey);
        icct_nms_netflow_migrate_execute('START TRANSACTION');
        try {
            $old = db_fetch_assoc_prepared(
                "SELECT a.* FROM plugin_icct_nms_netflow_events e JOIN plugin_icct_nms_alarms a " .
                    "ON a.alarm_key=SHA2(CONCAT('netflow:',e.id),256) " .
                    'WHERE e.host_id=? AND e.event_key=? ORDER BY e.id FOR UPDATE',
                [$hostId, $eventKey],
            );
            if (!$old) {
                icct_nms_netflow_migrate_execute('COMMIT');
                continue;
            }
            $latest = end($old);
            $canonical = db_fetch_row_prepared(
                'SELECT * FROM plugin_icct_nms_alarms WHERE alarm_key=? FOR UPDATE',
                [$newKey],
            );
            $total = (int) ($canonical['occurrences'] ?? 0);
            $acknowledged = (int) ($canonical['acknowledged'] ?? 0);
            foreach ($old as $row) {
                $total += (int) $row['occurrences'];
                $acknowledged = max($acknowledged, (int) $row['acknowledged']);
            }
            if (!$canonical) {
                icct_nms_netflow_migrate_execute(
                    'INSERT INTO plugin_icct_nms_alarms ' .
                        '(alarm_key,host_id,source,graph_id,name,description,fix,severity,state,first_seen,last_seen,sample_time,occurrences,acknowledged,escalated,ticket_state,revision,source_address) ' .
                        'VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $newKey, $hostId, 'NetFlow', (int) $latest['graph_id'],
                        $latest['name'], $latest['description'], $latest['fix'],
                        $latest['severity'], $latest['state'], $latest['first_seen'],
                        $latest['last_seen'], (int) $latest['sample_time'], $total,
                        $acknowledged, (int) $latest['escalated'],
                        $latest['ticket_state'], (int) $latest['revision'] + 1,
                        $latest['source_address'],
                    ],
                );
            } else {
                icct_nms_netflow_migrate_execute(
                    'UPDATE plugin_icct_nms_alarms SET occurrences=?,acknowledged=?,revision=revision+1 WHERE alarm_key=?',
                    [$total, $acknowledged, $newKey],
                );
            }
            foreach ($old as $row) {
                icct_nms_netflow_migrate_execute(
                    'UPDATE plugin_icct_nms_alarm_history SET alarm_key=? WHERE alarm_key=?',
                    [$newKey, $row['alarm_key']],
                );
                icct_nms_netflow_migrate_execute(
                    'DELETE FROM plugin_icct_nms_alarms WHERE alarm_key=?',
                    [$row['alarm_key']],
                );
            }
            $history = db_fetch_assoc_prepared(
                'SELECT id,event,snapshot_json FROM plugin_icct_nms_alarm_history WHERE alarm_key=? ORDER BY created_at,id',
                [$newKey],
            );
            $count = 0;
            foreach ($history as $entry) {
                if ($entry['event'] === 'Raised') {
                    $count++;
                }
                $snapshot = json_decode((string) $entry['snapshot_json'], true);
                if (is_array($snapshot) && array_key_exists('occurrences', $snapshot)) {
                    $snapshot['occurrences'] = max(1, $count);
                    icct_nms_netflow_migrate_execute(
                        'UPDATE plugin_icct_nms_alarm_history SET snapshot_json=? WHERE id=?',
                        [json_encode($snapshot, JSON_THROW_ON_ERROR), (int) $entry['id']],
                    );
                }
            }
            icct_nms_netflow_migrate_execute('COMMIT');
        } catch (Throwable $error) {
            db_execute('ROLLBACK');
            throw $error;
        }
    }
}
