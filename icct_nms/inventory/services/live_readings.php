<?php

/** Device readings are independent of alarm rules and never start a collection. */
function icct_nms_device_live_readings($host)
{
    $id = (int) $host['id'];
    $rows = [];
    $rules = icct_nms_fault_rules($id);
    $observations = icct_nms_fault_observations($host);
    $rrdReadings = [];
    foreach ($rules as $index => $rule) {
        $readings = array_values(array_filter(
            $observations,
            static fn($item) => isset($item['rule']) && (string) $item['rule'] === (string) $index,
        ));
        if (($rule['source'] ?? 'rrd') === 'rrd') {
            $key = ($rule['template_id'] ?? 0) . ':' . ($rule['metric_id'] ?? 0);
            if (!isset($rrdReadings[$key])) {
                try {
                    $rrdReadings[$key] = icct_nms_fault_rrd_read(
                        $host,
                        (int) $rule['template_id'],
                        (int) $rule['metric_id'],
                    );
                } catch (Throwable $error) {
                    $rrdReadings[$key] = [['value' => null, 'reason' => 'Unavailable (no fresh sample)']];
                }
            }
            $readings = $rrdReadings[$key];
        }
        $fields = icct_nms_fault_summary_fields($rule, $readings);
        $rows[] = [
            'source' => ['rrd' => 'Cacti', 'snmp' => 'SNMP', 'ntp' => 'NTP'][
                $rule['source'] ?? 'rrd'
            ] ?? 'Measurement',
            'name' => (string) $rule['name'],
            'units' => $fields['units'],
            'reading' => $fields['reading'],
            'alarm_enabled' => !empty($rule['enabled']) ? 'Yes' : 'No',
        ];
    }
    $ntp = icct_nms_ntp_assignment($id) ? icct_nms_ntp_current($id) : null;
    if ($ntp !== null) {
        $ntp['last_success_display'] = icct_nms_display_time($ntp['last_success']);
        $ntp['last_attempt_display'] = icct_nms_display_time($ntp['last_attempt']);
        $sample = $ntp['sample'] ?? [];
        $rows[] = [
            'source' => 'NTP', 'name' => 'Collection status', 'units' => '—',
            'reading' => $ntp['status'], 'alarm_enabled' => '—',
        ];
        foreach ([
            'offset_ms' => 'Clock offset',
            'jitter_ms' => 'Jitter',
            'peer_delay_ms' => 'Peer delay',
        ] as $field => $label) {
            $rows[] = [
                'source' => 'NTP', 'name' => $label, 'units' => 'ms',
                'reading' => isset($sample[$field]) && is_numeric($sample[$field])
                    ? number_format((float) $sample[$field], 3, '.', '')
                    : '—',
                'alarm_enabled' => '—',
            ];
        }
    }
    if (icct_nms_netflow_assignment($id)) {
        $enabled = $host['disabled'] === '' && icct_backend_protocol_enabled($id, 'netflow');
        $live = $enabled ? icct_nms_netflow_live_readings($id) : null;
        $rows[] = [
            'source' => 'NetFlow / IPFIX', 'name' => 'Flow collection', 'units' => '—',
            'reading' => !$enabled ? 'Disabled' : ($live['flows']
                ? $live['flows'] . ' flows in the last 5 minutes; latest ' .
                    icct_nms_display_time((int) $live['latest_epoch']) . ' IST'
                : 'No flows in the last 5 minutes'),
            'alarm_enabled' => '—',
        ];
        foreach (icct_nms_netflow_rule_metrics() as $metric => [$label, $unit]) {
            $value = $live['values'][$metric] ?? null;
            $rows[] = [
                'source' => 'NetFlow / IPFIX', 'name' => $label, 'units' => $unit,
                'reading' => $value !== null && is_numeric($value)
                    ? number_format((float) $value, 3, '.', '')
                    : '—',
                'alarm_enabled' => '—',
            ];
        }
    }
    return ['rows' => $rows, 'ntp' => $ntp];
}
