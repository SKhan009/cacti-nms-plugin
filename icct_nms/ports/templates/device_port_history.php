<?php /** Authorized device history is read-only; no collection or acknowledgement starts here. */
if (!is_realm_allowed(3)): ?>
    <p>Device management permission is required to read port alarm history.</p>
<?php else:
    require_once __DIR__ . '/../services/port_history_service.php';
    require_once __DIR__ . '/../../shared/services/presentation.php';
    require __DIR__ . '/../../inventory/templates/device_record_pager.php';
    $portEventsCount = db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_icct_nms_port_alarm_events WHERE host_id=?', [(int) $id]);
    if ($portEventsCount === false) {
        throw new RuntimeException('Unable to count port alarm history.');
    }
    $portEventsTotal = (int) $portEventsCount;
    $portEventsPage = min($recordPage('port_events_page'), max(1, (int) ceil($portEventsTotal / 25)));
    // Compare hash bytes across the legacy port-table and shared journal collations.
    $portEvents = db_fetch_assoc_prepared(
        'SELECT e.*,last_event.details_json AS incident_details,ack.event AS ack_event,ack.actor_id AS ack_actor_id,ack.created_at AS ack_time,u.username AS ack_username ' .
        'FROM plugin_icct_nms_port_alarm_events e ' .
        'LEFT JOIN plugin_icct_nms_port_alarm_events last_event ON last_event.id=(' .
        'SELECT MAX(t.id) FROM plugin_icct_nms_port_alarm_events t WHERE t.host_id=e.host_id AND t.incident_key=e.incident_key) ' .
        'LEFT JOIN plugin_icct_nms_alarm_history ack ON ack.id=(' .
        'SELECT MAX(a.id) FROM plugin_icct_nms_alarm_history a WHERE a.alarm_key=BINARY e.alarm_key ' .
        "AND a.event IN ('Acknowledged','Automatically acknowledged') AND JSON_VALID(a.snapshot_json) " .
        "AND CAST(JSON_UNQUOTE(JSON_EXTRACT(a.snapshot_json,'$.occurrences')) AS UNSIGNED)=e.alarm_occurrence) " .
        'LEFT JOIN user_auth u ON u.id=ack.actor_id WHERE e.host_id=? ORDER BY e.id DESC LIMIT 25 OFFSET ' . ($portEventsPage - 1) * 25,
        [(int) $id]);
    if ($portEvents === false) {
        throw new RuntimeException('Unable to read port alarm history.');
    }
    $portCurrentEvidence = icct_nms_port_alarm_snapshot((int) $id);
    $portEvidenceTime = static fn ($time) => $time === null ? 'Not recorded' : date('Y-m-d H:i:s T', (int) $time);
    ?>
    <h3>Port alarm history</h3>
    <div
        class="device-record-list port-alarm-history"
        aria-label="Port alarm history">
        <?php foreach ($portEvents as $event):
            // NULL is intentional for older rows; current inventory must not rewrite past evidence.
            $event += ['details_json' => null, 'incident_details' => null, 'incident_key' => null];
            $evidence = icct_nms_port_history_context($event, $portCurrentEvidence);
            $recorded = $evidence['recorded'];
            $latest = $evidence['latest'];
            $hasIncident = !empty($event['incident_key']) || icct_nms_port_alarm_severity($event['severity_before']) || icct_nms_port_alarm_severity($event['severity_after']) || in_array($event['event'], ['Raised', 'Cleared'], true);
            $severity = icct_nms_port_history_severity($event['severity_after'], $event['state_after']);
            $acknowledgement = icct_nms_port_history_ack($event, $hasIncident);
            $duration = $hasIncident ? icct_nms_port_duration_text($latest['observed_down_seconds'] ?? null) : 'Not applicable';
            ?>
            <details
                class="device-record-item"
                name="port-alarm-records">
                <summary>
                    <span>
                        <small>Time</small><?= icct_nms_h($event['created_at']) ?>
                    </span>
                    <span>
                        <small>Port / interface group</small>
                        <strong><?= icct_nms_h($event['port_name']) ?></strong>
                        <span class="port-history-interface">ifIndex <?= (int) $event['if_index'] ?> · <?= icct_nms_h($recorded['kind'] ?? 'Not recorded') ?></span>
                    </span>
                    <span>
                        <small>Event</small><?= icct_nms_h($event['event']) ?>
                    </span>
                    <span>
                        <small>Severity</small><?= icct_nms_severity_badge($severity) ?>
                    </span>
                    <span>
                        <small>Observed down span</small><?= icct_nms_h($duration) ?>
                    </span>
                    <span>
                        <small>Acknowledgement</small><?= icct_nms_h($acknowledgement) ?>
                    </span>
                </summary>
                <div class="device-record-body">
                    <h4>Event details</h4>
                    <dl class="port-history-evidence">
                        <?php foreach ([
                            'Why this alarm or event was recorded' => icct_nms_port_history_story($event, $recorded),
                            'Collection source' => $recorded['source'] ?? 'Not recorded',
                            'Original observed issue' => $latest['issue'] ?? 'Not recorded',
                            'Measurement OIDs' => str_starts_with($recorded['source'] ?? '', 'SNMP') ? 'ifAdminStatus: 1.3.6.1.2.1.2.2.1.7.' . (int) $event['if_index'] . '; ifOperStatus: 1.3.6.1.2.1.2.2.1.8.' . (int) $event['if_index'] : 'Not recorded for this collection source',
                            'Previous administrative status' => icct_nms_port_state_label($recorded['previous_admin_status'] ?? null),
                            'Previous operational status' => icct_nms_port_state_label($recorded['previous_oper_status'] ?? null),
                            'Administrative status at this event' => icct_nms_port_state_label($event['admin_status'] === null ? null : (int) $event['admin_status']),
                            'Operational status at this event' => icct_nms_port_state_label($event['oper_status'] === null ? null : (int) $event['oper_status']),
                            'Previous alarm severity / state' => icct_nms_port_history_severity($event['severity_before'], $event['state_before']),
                            'Recorded alarm severity / state' => $severity,
                            'First observed down' => $portEvidenceTime($latest['down_since'] ?? null),
                            'Recovery observed' => $portEvidenceTime($latest['recovered_at'] ?? null),
                            'Observed down duration' => $duration,
                            'Duration evidence' => $hasIncident ? (!empty($latest['observation_gap']) ? 'Missing observations occurred during this span; continuous downtime is not confirmed.' : 'Measured between saved polling samples, not the exact physical failure or repair time.') : 'No outage episode was recorded for this event.',
                            'Recovery / repair information' => $latest['recovery'] ?? 'No recovery or repair action was recorded for this event.',
                            'Acknowledged by' => $acknowledgement,
                            'Acknowledgement time' => $event['ack_time'] ?? 'Not recorded',
                            'Reading time at this event' => $event['collected_at'] ?? 'Not recorded',
                            'Device-reported last change (ticks)' => isset($recorded['if_last_change_ticks']) ? (string) $recorded['if_last_change_ticks'] : 'Not recorded',
                            'Device-reported uptime (ticks)' => isset($recorded['uptime_ticks']) ? (string) $recorded['uptime_ticks'] : 'Not recorded',
                            'Device-reported interface type' => isset($recorded['if_type']) ? (string) $recorded['if_type'] : 'Not recorded',
                        ] as $label => $value): ?>
                            <div>
                                <dt><?= icct_nms_h($label) ?></dt>
                                <dd><?= in_array($label, ['Previous alarm severity / state', 'Recorded alarm severity / state'], true) ? icct_nms_severity_badge($value) : icct_nms_h($value) ?></dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                    <?php if (!empty($event['alarm_key'])): ?>
                        <a class="button" href="alarms/controllers/alarms.php?view=<?= icct_nms_h($event['alarm_key']) ?>">View shared alarm and
                            acknowledgement history</a>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
        <?php if (!$portEvents): ?>
            <p class="device-record-empty">No port alarm events recorded.</p>
        <?php endif; ?>
    </div>
    <?php $recordPager('port_events_page', $portEventsPage, $portEventsTotal, 'view-ports', ['ports_tab' => 'history']); ?>
<?php endif; ?>
