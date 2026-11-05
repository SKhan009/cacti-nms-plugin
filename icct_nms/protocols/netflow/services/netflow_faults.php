<?php

/** Reuse saved anomaly episodes and the common workflow; never infer recovery from absent flows. */
function icct_nms_netflow_sync_faults($limit = 200)
{
    $desired =
        "CASE WHEN h.disabled<>'' OR d.host_id IS NULL OR d.enabled=0 OR p.meta_value='1' " .
        "THEN 'Disabled' WHEN e.state='Resolved' THEN 'Resolved' " .
        "WHEN a.state='Closed' THEN 'Closed' ELSE 'Active' END";
    $rows = db_fetch_assoc_prepared(
        'SELECT e.*,(' .
            $desired .
            ') AS desired_state FROM plugin_icct_nms_netflow_events e ' .
            'JOIN host h ON h.id=e.host_id LEFT JOIN plugin_icct_nms_netflow_devices d ON d.host_id=e.host_id ' .
            "LEFT JOIN plugin_icct_nms_meta p ON p.meta_key=CONCAT('protocol_disabled_netflow_',e.host_id) " .
            "LEFT JOIN plugin_icct_nms_alarms a ON a.alarm_key=SHA2(CONCAT('netflow:',e.id),256) " .
            "WHERE h.deleted='' AND h.poller_id=? AND ((a.alarm_key IS NULL AND e.state='Active' AND (" .
            $desired .
            ")='Active') OR " .
            '(a.alarm_key IS NOT NULL AND (a.sample_time<>UNIX_TIMESTAMP(e.updated_at) OR ' .
            'BINARY a.description<>BINARY e.message OR (a.severity<>e.severity AND (' .
            $desired .
            ") IN ('Active','Closed')) OR a.state<>(" .
            $desired .
            ')))) ' .
            'ORDER BY e.id LIMIT ' .
            max(1, min(1000, (int) $limit)),
        [icct_backend_inventory_collector_id()],
    );
    if ($rows === false) {
        throw new RuntimeException('Unable to read pending NetFlow faults.');
    }
    $processed = 0;
    foreach ($rows as $event) {
        if (
            !in_array(
                $event['severity'],
                ['Critical', 'Major', 'Minor', 'Warning', 'Information'],
                true,
            )
        ) {
            throw new RuntimeException('Invalid saved NetFlow anomaly severity.');
        }
        $host = db_fetch_row_prepared('SELECT * FROM host WHERE id=?', [(int) $event['host_id']]);
        $state =
            $event['desired_state'] === 'Disabled'
                ? 'Disabled'
                : ($event['state'] === 'Resolved'
                    ? 'Normal'
                    : $event['severity']);
        $rule = [
            'source' => 'netflow',
            'name' => 'NetFlow traffic anomaly',
            'description' => $event['message'],
            'corrective_action' =>
                'Review the exported flows, source endpoints and configured anomaly thresholds.',
        ];
        $observation = [
            'event_id' => (int) $event['id'],
            'rule' => 'netflow',
            'graph_id' => 0,
            'graph' => $rule['name'],
            'state' => $state,
            'sample_time' => strtotime($event['updated_at']),
            'occurred_at' => $event['opened_at'],
            'observed_at' => $event['updated_at'],
        ];
        // Raw events are durable. A busy/failed journal write stays pending for the next worker/poller run.
        if (icct_nms_alarm_record($host, $rule, $observation)) {
            $processed++;
        }
    }
    return $processed;
}
