<?php

require_once __DIR__ . '/../../protocols/ntp/services/ntp_service.php';

/** Device-linked alarm journal. Pollers own observations; operators own workflow actions. */
function icct_nms_alarm_transition($previous, $observation)
{
    $severity = $observation['state'];
    $active = in_array($severity, ['Critical', 'Major', 'Minor', 'Warning', 'Information'], true);
    if (!$active && !in_array($severity, ['Normal', 'Disabled'], true)) {
        return null;
    }
    if (!$previous && !$active) {
        return null;
    }
    $state = $active ? 'Active' : ($severity === 'Disabled' ? 'Disabled' : 'Resolved');
    $raised =
        $active && (!$previous || in_array($previous['state'], ['Resolved', 'Disabled'], true));
    // A manually closed alarm stays closed until an observed recovery and recurrence.
    if ($previous && $previous['state'] === 'Closed' && $active) {
        $state = 'Closed';
    }
    return [
        'state' => $state,
        'severity' => $active ? $severity : $previous['severity'],
        'raised' => $raised,
        'occurrences' => (int) ($previous['occurrences'] ?? 0) + ($raised ? 1 : 0),
        'event' => $raised
            ? 'Raised'
            : ($state !== $previous['state']
                ? $state
                : ($active && $previous['severity'] !== $severity
                    ? 'Severity changed'
                    : '')),
    ];
}
function icct_nms_alarm_key($host, $rule, $observation)
{
    if (isset($observation['passive_key'])) {
        return $observation['passive_key'];
    }
    if (in_array($rule['source'] ?? '', ['syslog', 'netflow'], true)) {
        return hash('sha256', $rule['source'] . ':' . (int) $observation['event_id']);
    }
    return hash(
        'sha256',
        json_encode(
            [
                (int) $host['id'],
                (string) ($host['hostname'] ?? ''),
                (string) ($rule['preset_key'] ?? ''),
                $rule['source'] ?? 'rrd',
                $rule['name'] ?? $observation['graph'],
                $rule['template_id'] ?? 0,
                ($rule['source'] ?? '') === 'ntp'
                    ? $rule['parameter'] . ':' . ($rule['expected_source'] ?? '')
                    : $rule['metric_id'] ?? 0,
                $rule['oid'] ?? '',
                (string) $observation['graph_id'],
                $rule['condition'] ?? '',
                $rule['minimum'] ?? null,
                $rule['maximum'] ?? null,
            ],
            JSON_THROW_ON_ERROR,
        ),
    );
}
function icct_nms_alarm_history_write($key, $event, $actor = 0, $note = '', $ticket = null)
{
    $row = db_fetch_row_prepared(
        'SELECT name,description,fix,severity,state,occurrences,acknowledged,escalated,ticket_state FROM plugin_icct_nms_alarms WHERE alarm_key=?',
        [$key],
    );
    if (!empty($row['acknowledged'])) {
        $ack = in_array($event, ['Acknowledged', 'Automatically acknowledged'], true)
            ? ['note' => $note, 'actor_id' => $actor]
            : db_fetch_row_prepared(
                "SELECT note,actor_id FROM plugin_icct_nms_alarm_history WHERE alarm_key=? AND event IN ('Acknowledged','Automatically acknowledged') AND JSON_VALID(snapshot_json) AND CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.occurrences')) AS UNSIGNED)=? ORDER BY id DESC LIMIT 1",
                [$key, (int) $row['occurrences']],
            );
        $row['ack_note'] = $ack['note'] ?? '';
        $row['ack_actor_id'] = (int) ($ack['actor_id'] ?? 0);
    }
    if ($ticket !== null) {
        $row['ticket'] = $ticket;
    }
    icct_backend_category_execute(
        'INSERT INTO plugin_icct_nms_alarm_history(alarm_key,event,actor_id,note,snapshot_json,created_at) VALUES(?,?,?,?,?,NOW())',
        [$key, $event, $actor, $note, json_encode($row, JSON_THROW_ON_ERROR)],
    );
}
function icct_nms_alarm_record($host, $rule, $observation, $transaction = true)
{
    $key = icct_nms_alarm_key($host, $rule, $observation);
    $lock = 'icct_alarm_' . substr($key, 0, 40);
    if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?,0)', [$lock]) !== 1) {
        return false;
    }
    try {
        if ($transaction) {
            icct_backend_category_execute('START TRANSACTION', []);
        }
        $previous = db_fetch_row_prepared(
            'SELECT * FROM plugin_icct_nms_alarms WHERE alarm_key=? FOR UPDATE',
            [$key],
        );
        $sample = (int) ($observation['sample_time'] ?? 0);
        if (isset($observation['event_count_delta']) && $previous && $sample < (int) $previous['sample_time']) {
            $sample = (int) $previous['sample_time'];
            $observation['observed_at'] = $previous['last_seen'];
        }
        $change =
            $previous && $sample > 0 && $sample < (int) $previous['sample_time']
                ? null
                : icct_nms_alarm_transition($previous, $observation);
        if ($change) {
            $name = mb_substr(trim($rule['name'] ?? '') ?: $observation['graph'], 0, 160);
            $source = str_starts_with((string) $observation['rule'], 'port:')
                ? 'Port'
                : [
                        'snmp' => 'SNMP',
                        'availability' => 'Availability',
                        'ntp' => 'NTP',
                        'syslog' => 'Syslog',
                        'trap' => 'SNMP Traps',
                        'passive' => 'Passive events',
                        'netflow' => 'NetFlow',
                    ][$rule['source'] ?? 'rrd'] ?? 'Cacti';
            $automaticAck =
                $source === 'Port' &&
                $sample > 0 &&
                !empty($observation['port_context']['auto_acknowledge']) &&
                icct_nms_port_alarm_severity($observation['state']) &&
                (!$previous || empty($previous['acknowledged']) || $change['raised']);
            if (isset($observation['event_count_delta'])) {
                $change['occurrences'] = (int) ($previous['occurrences'] ?? 0) + $observation['event_count_delta'];
                if ($previous && !$change['event']) {
                    $change['event'] = 'Occurrences received';
                }
            }
            $description = $rule['description'] ?? '';
            $fix = $rule['corrective_action'] ?? '';
            if (!$previous) {
                icct_backend_category_execute(
                    'INSERT INTO ' .
                        'plugin_icct_nms_alarms(alarm_key,host_id,source,graph_id,name,description,fix,severity,state,first_seen,last_seen,sample_time,occurrences,source_address,acknowledged) ' .
                        'VALUES(?,?,?,?,?,?,?,?,?,COALESCE(?,NOW()),COALESCE(?,NOW()),?,?,?,?)',
                    [
                        $key,
                        (int) $host['id'],
                        $source,
                        (int) $observation['graph_id'],
                        $name,
                        $description,
                        $fix,
                        $change['severity'],
                        $change['state'],
                        $observation['occurred_at'] ?? null,
                        $observation['observed_at'] ?? null,
                        $sample,
                        $change['occurrences'],
                        $observation['source_address'] ?? null,
                        $automaticAck ? 1 : 0,
                    ],
                );
            } else {
                icct_backend_category_execute(
                    'UPDATE plugin_icct_nms_alarms SET ' .
                        ('graph_id=?,name=?,description=?,fix=?,severity=?,state=?,last_seen=COALESCE(?,NOW()),sample_time=?,' .
                            'occurrences=?,acknowledged=?,escalated=?,ticket_state=?,revision=revision+1 ') .
                        'WHERE alarm_key=?',
                    [
                        (int) $observation['graph_id'],
                        $name,
                        $description,
                        $fix,
                        $change['severity'],
                        $change['state'],
                        $observation['observed_at'] ?? null,
                        $sample,
                        $change['occurrences'],
                        $automaticAck
                            ? 1
                            : ($change['raised']
                                ? 0
                                : (int) $previous['acknowledged']),
                        $change['raised'] ? 0 : (int) $previous['escalated'],
                        $change['raised'] ? '' : $previous['ticket_state'],
                        $key,
                    ],
                );
            }
            if (
                !$change['event'] &&
                $previous &&
                ($previous['description'] !== $description || $previous['fix'] !== $fix)
            ) {
                $change['event'] = 'Details updated';
            }
            if ($change['event']) {
                icct_nms_alarm_history_write($key, $change['event'], 0, $change['severity']);
            }
        }
        if (!empty($automaticAck)) {
            icct_nms_alarm_history_write(
                $key,
                'Automatically acknowledged',
                0,
                'Automatically acknowledged by the port monitor after a fresh down-state observation. This acknowledgement does not indicate a repair.',
            );
        }
        if ($transaction) {
            icct_backend_category_execute('COMMIT', []);
        }
        return true;
    } catch (Throwable $e) {
        if ($transaction) {
            icct_backend_category_execute('ROLLBACK', []);
        }
        throw $e;
    } finally {
        db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}
function icct_nms_collect_alarms()
{
    foreach (
        db_fetch_assoc_prepared("SELECT * FROM host WHERE deleted='' AND poller_id=?", [
            icct_backend_inventory_collector_id(),
        ])
        as $host
    ) {
        $rules = icct_nms_fault_rules((int) $host['id']);
        $keys = [];
        $stamp = strtotime((string) ($host['last_updated'] ?? ''));
        $fresh =
            $stamp &&
            time() - $stamp >= 0 &&
            time() - $stamp <= 2 * max(60, (int) read_config_option('poller_interval'));
        $reachability = [
            'rule' => 'availability',
            'graph_id' => 0,
            'graph' => 'Host Unreachable / Poller Timeout',
            'sample_time' => $fresh ? $stamp : 0,
            'state' =>
                $host['disabled'] !== ''
                    ? 'Disabled'
                    : ($fresh
                        ? [1 => 'Major', 2 => 'Normal'][(int) $host['status']] ?? 'Unknown'
                        : 'Unknown'),
        ];
        $reachRule = [
            'source' => 'availability',
            'name' => 'Host Unreachable / Poller Timeout',
            'description' =>
                'Cacti reports this device as unreachable during its configured availability checks.',
            'corrective_action' =>
                'Check host reachability, saved availability settings, credentials, and poller timeout/retry settings.',
        ];
        $keys[] = icct_nms_alarm_key($host, $reachRule, $reachability);
        icct_nms_alarm_record($host, $reachRule, $reachability);
        foreach (icct_nms_fault_observations($host, true) as $observation) {
            $rule = $rules[$observation['rule']] ?? [];
            if (str_starts_with((string) $observation['rule'], 'port:')) {
                $context = $observation['port_context'] ?? [];
                $rule = [
                    'name' => $observation['graph'],
                    'source' => 'port',
                    'description' => $context['issue'] ?? '',
                    'corrective_action' => $context['recovery'] ?? '',
                ];
            }
            $keys[] = icct_nms_alarm_key($host, $rule, $observation);
            $recorded = icct_nms_alarm_record($host, $rule, $observation);
            if (
                $recorded &&
                ($rule['source'] ?? '') === 'port' &&
                !empty($observation['port_context']['incident_key'])
            ) {
                $key = icct_nms_alarm_key($host, $rule, $observation);
                $occurrence = db_fetch_cell_prepared(
                    'SELECT occurrences FROM plugin_icct_nms_alarms WHERE alarm_key=?',
                    [$key],
                );
                if ($occurrence) {
                    icct_backend_category_execute(
                        'UPDATE plugin_icct_nms_port_alarm_events SET alarm_key=?,alarm_occurrence=? WHERE host_id=? AND incident_key=? AND alarm_key IS NULL',
                        [
                            $key,
                            (int) $occurrence,
                            (int) $host['id'],
                            $observation['port_context']['incident_key'],
                        ],
                    );
                }
            }
        }
        $snapshot = json_decode(
            (string) db_fetch_cell_prepared(
                'SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
                ['fault_status_' . $host['id']],
            ),
            true,
        );
        $current =
            !$rules ||
            (($snapshot['rules_hash'] ?? '') ===
                hash('sha256', json_encode($rules, JSON_THROW_ON_ERROR)) &&
                time() - (int) ($snapshot['time'] ?? 0) >= 0 &&
                time() - (int) ($snapshot['time'] ?? 0) <=
                    2 * max(60, (int) read_config_option('poller_interval')));
        if ($current || $host['disabled'] !== '') {
            foreach (
                db_fetch_assoc_prepared(
                    "SELECT * FROM plugin_icct_nms_alarms WHERE host_id=? AND source NOT IN ('Syslog','SNMP Traps','Passive events','NetFlow') AND state IN ('Active','Closed')",
                    [(int) $host['id']],
                )
                as $saved
            ) {
                if (in_array($saved['alarm_key'], $keys, true) && $host['disabled'] === '') {
                    continue;
                }
                icct_backend_category_execute(
                    "UPDATE plugin_icct_nms_alarms SET state='Disabled',revision=revision+1 WHERE alarm_key=? AND state IN ('Active','Closed')",
                    [$saved['alarm_key']],
                );
                icct_nms_alarm_history_write(
                    $saved['alarm_key'],
                    'Disabled',
                    0,
                    'Device disabled or rule removed/changed.',
                );
            }
        }
    }
}
function icct_nms_alarm_rows()
{
    $devices = array_column(icct_nms_inventory(), null, 'id');
    if (!$devices) {
        return [];
    }
    $ids = array_map('intval', array_keys($devices));
    $rows = db_fetch_assoc(
        'SELECT *,UNIX_TIMESTAMP(first_seen) AS occurred_epoch,UNIX_TIMESTAMP(last_seen) AS ' .
            'observed_epoch FROM plugin_icct_nms_alarms WHERE host_id IN (' .
            implode(',', $ids) .
            ') ORDER BY first_seen DESC,alarm_key',
    );
    $graphs = [];
    $rows = array_values(
        array_filter($rows, static function ($row) use (&$graphs) {
            if (!(int) $row['graph_id']) {
                return true;
            }
            $id = (int) $row['host_id'];
            if (!isset($graphs[$id])) {
                $graphs[$id] = array_map(
                    'intval',
                    array_column(icct_nms_device_graphs($id), 'local_graph_id'),
                );
            }
            return in_array((int) $row['graph_id'], $graphs[$id], true);
        }),
    );
    foreach ($rows as &$row) {
        $host = $devices[$row['host_id']];
        $row['device_name'] = $host['description'];
        $row['short_name'] = $host['short_name'] ?? '';
        $row['address'] = in_array($row['source'], ['Syslog', 'SNMP Traps', 'Passive events'], true) ? $row['source_address'] : $host['hostname'];
        $row['network_type'] = $host['device_type'] ?? '';
    }
    unset($row);
    return $rows;
}
function icct_nms_alarm_require_access($row)
{
    icct_backend_require_device_access((int) $row['host_id']);
    if (
        (int) ($row['graph_id'] ?? 0) &&
        !in_array(
            (int) $row['graph_id'],
            array_map(
                'intval',
                array_column(icct_nms_device_graphs((int) $row['host_id']), 'local_graph_id'),
            ),
            true,
        )
    ) {
        throw new RuntimeException('Alarm unavailable.');
    }
}
function icct_nms_alarm_action($input)
{
    icct_backend_require_management(3);
    $key = $input['alarm_key'] ?? '';
    $action = $input['alarm_action'] ?? '';
    if (
        !is_string($key) ||
        !preg_match('/^[a-f0-9]{64}$/D', $key) ||
        !in_array(
            $action,
            [
                'ack',
                'escalate',
                'close',
                'ticket_close',
                'ticket_reopen',
                'ticket_progress',
                'ticket_resolve',
            ],
            true,
        )
    ) {
        throw new InvalidArgumentException('Choose a valid alarm action.');
    }
    $rawNote = $input['note'] ?? '';
    if (!is_string($rawNote)) {
        throw new InvalidArgumentException('Enter a text note.');
    }
    $note = trim($rawNote);
    if (strlen($note) > 1000) {
        throw new InvalidArgumentException('Use no more than 1000 characters.');
    }
    icct_backend_category_execute('START TRANSACTION', []);
    try {
        $row = db_fetch_row_prepared(
            'SELECT * FROM plugin_icct_nms_alarms WHERE alarm_key=? FOR UPDATE',
            [$key],
        );
        if (!$row) {
            throw new RuntimeException('Alarm unavailable.');
        }
        icct_nms_alarm_require_access($row);
        if ((int) ($input['revision'] ?? 0) !== (int) $row['revision']) {
            throw new RuntimeException('Alarm changed. Refresh before retrying.');
        }
        $ticket = null;
        if ($action === 'ack') {
            if ($row['acknowledged'] || $row['state'] === 'Closed') {
                throw new RuntimeException('This alarm cannot be acknowledged.');
            }
            $sql = 'acknowledged=1';
            $event = 'Acknowledged';
        } elseif ($action === 'escalate') {
            if (!$row['acknowledged'] || $row['escalated'] || $row['state'] === 'Closed') {
                throw new RuntimeException('Acknowledge this alarm before escalation.');
            }
            $severity =
                $input['ticket_severity'] ?? icct_nms_alarm_ticket_severity($row['severity']);
            $device = db_fetch_cell_prepared('SELECT description FROM host WHERE id=?', [
                (int) $row['host_id'],
            ]);
            $name = $input['ticket_name'] ?? $row['name'] . ' - ' . $device;
            if (!is_string($severity) || !in_array($severity, ['High', 'Medium', 'Low'], true)) {
                throw new InvalidArgumentException('Choose a valid ticket severity.');
            }
            if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 320) {
                throw new InvalidArgumentException('Enter a ticket name of up to 320 characters.');
            }
            $ticket = ['severity' => $severity, 'name' => trim($name), 'remarks' => $note];
            $sql = "escalated=1,ticket_state='Open'";
            $event = 'Escalated';
        } elseif ($action === 'close') {
            $sql = "state='Closed'";
            $event = 'Closed';
            if ($row['state'] === 'Closed') {
                throw new RuntimeException('Alarm already closed.');
            }
        } else {
            if (!$row['escalated']) {
                throw new RuntimeException('Escalate this alarm before changing its ticket.');
            }
            $current = $row['ticket_state'] ?: 'Open';
            if ($action === 'ticket_reopen') {
                if (!in_array($current, ['Closed', 'Resolved'], true)) {
                    throw new RuntimeException('Only a resolved ticket can be reopened.');
                }
                $sql = "ticket_state='Open'";
                $event = 'Ticket reopened';
            } elseif ($action === 'ticket_progress') {
                if ($current !== 'Open') {
                    throw new RuntimeException('Only an open ticket can move to in progress.');
                }
                $sql = "ticket_state='In Progress'";
                $event = 'Ticket progress';
            } else {
                if (!in_array($current, ['Open', 'In Progress'], true)) {
                    throw new RuntimeException('This ticket is already resolved.');
                }
                $sql = "ticket_state='Resolved'";
                $event = 'Ticket resolved';
            }
            $detail = icct_nms_alarm_detail($key);
            $ticket = $detail['ticket']['details'];
        }
        icct_backend_category_execute(
            'UPDATE plugin_icct_nms_alarms SET ' . $sql . ',revision=revision+1 WHERE alarm_key=?',
            [$key],
        );
        icct_nms_alarm_history_write($key, $event, icct_backend_current_user_id(), $note, $ticket);
        icct_backend_category_execute('COMMIT', []);
    } catch (Throwable $e) {
        icct_backend_category_execute('ROLLBACK', []);
        throw $e;
    }
}

/** Authorized full-page detail model, including immutable event-time content. */
function icct_nms_alarm_detail($key)
{
    if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D', $key)) {
        throw new InvalidArgumentException('Alarm unavailable.');
    }
    $row = db_fetch_row_prepared(
        'SELECT *,UNIX_TIMESTAMP(first_seen) AS occurred_epoch,UNIX_TIMESTAMP(last_seen) AS ' .
            'observed_epoch FROM plugin_icct_nms_alarms WHERE alarm_key=?',
        [$key],
    );
    if (!$row) {
        throw new RuntimeException('Alarm unavailable.');
    }
    icct_nms_alarm_require_access($row);
    $devices = array_column(icct_nms_inventory(), null, 'id');
    $device = $devices[$row['host_id']] ?? null;
    if (!$device) {
        throw new RuntimeException('Alarm unavailable.');
    }
    $row += [
        'device_name' => $device['description'],
        'short_name' => $device['short_name'] ?? '',
        'address' => in_array($row['source'], ['Syslog', 'SNMP Traps', 'Passive events'], true) ? $row['source_address'] : $device['hostname'],
        'model_number' => $device['model_number'] ?? '',
        'serial_number' => $device['serial_number'] ?? '',
        'network_type' => $device['device_type'] ?? '',
        'device_status' => $device['status_label'] ?? 'Unknown',
    ];
    $history = db_fetch_assoc_prepared(
        'SELECT h.*,UNIX_TIMESTAMP(h.created_at) AS ' .
            'created_epoch,COALESCE(NULLIF(u.full_name,\'\'),u.username) AS actor_name,u.username AS ' .
            'actor_username,u.email_address AS actor_email FROM plugin_icct_nms_alarm_history h LEFT ' .
            'JOIN user_auth u ON u.id=h.actor_id WHERE h.alarm_key=? ORDER BY h.id DESC',
        [$key],
    );
    $ack = null;
    $escalations = [];
    foreach ($history as &$event) {
        if ($event['event'] === 'Automatically acknowledged' && (int) $event['actor_id'] === 0) {
            $event['actor_name'] = 'Automatic (port monitor)';
            $event['actor_username'] = 'Automatic';
        }
        $snapshot = json_decode((string) ($event['snapshot_json'] ?? ''), true);
        $event['snapshot'] = is_array($snapshot) ? $snapshot : [];
        unset($event['snapshot_json']);
        if (
            in_array($event['event'], ['Acknowledged', 'Automatically acknowledged'], true) &&
            $ack === null
        ) {
            $ack = $event;
        }
        if ($event['event'] === 'Escalated') {
            $escalations[] = $event;
        }
    }
    unset($event);
    $eventOrigins = in_array($row['source'], ['Syslog', 'SNMP Traps', 'Passive events'], true)
        ? array_column(db_fetch_assoc_prepared(
            'SELECT DISTINCT event_origin FROM plugin_icct_nms_syslog_events WHERE host_id=? AND alarm_key=?',
            [(int) $row['host_id'], $key],
        ), 'event_origin')
        : [];
    $history = icct_nms_alarm_history_remarks($history);
    return [
        'alarm' => $row,
        'history' => $history,
        'event_origins' => $eventOrigins,
        'acknowledgement' => !empty($row['acknowledged']) ? $ack : null,
        'escalations' => $escalations,
        'ticket' => icct_nms_alarm_ticket($row, $history),
    ];
}
function icct_nms_alarm_format_time($epoch)
{
    if (!(int) $epoch) {
        return '-';
    }
    return (new DateTimeImmutable('@' . (int) $epoch))
        ->setTimezone(new DateTimeZone('Asia/Kolkata'))
        ->format('d-m-Y H:i:s');
}

/** Carry forward only known acknowledgement remarks within the same activation. */
function icct_nms_alarm_history_remarks($history)
{
    $ack = null;
    foreach (array_reverse(array_keys($history)) as $index) {
        $event = &$history[$index];
        $snapshot = $event['snapshot'];
        if (
            $event['event'] === 'Raised' ||
            (isset($snapshot['acknowledged']) && !(int) $snapshot['acknowledged'])
        ) {
            $ack = null;
        }
        if (in_array($event['event'], ['Acknowledged', 'Automatically acknowledged'], true)) {
            $ack = ['note' => $event['note'], 'actor_id' => (int) $event['actor_id']];
        }
        if (!empty($snapshot['acknowledged']) && !array_key_exists('ack_note', $snapshot) && $ack) {
            $event['snapshot']['ack_note'] = $ack['note'];
            $event['snapshot']['ack_actor_id'] = $ack['actor_id'];
        }
        unset($event);
    }
    return $history;
}

/** Ticket metadata and audit trail are kept in the existing immutable journal. */
function icct_nms_alarm_ticket_severity($severity)
{
    return [
        'Critical' => 'High',
        'Major' => 'High',
        'Minor' => 'Medium',
        'Warning' => 'Low',
        'Information' => 'Low',
    ][$severity] ?? 'Low';
}
function icct_nms_alarm_ticket($alarm, $history)
{
    $labels = [
        'Escalated' => ['OPENED', 'opened'],
        'Ticket progress' => ['IN PROGRESS', 'progress'],
        'Ticket resolved' => ['RESOLVED', 'resolved'],
        'Ticket closed' => ['RESOLVED', 'resolved'],
        'Ticket reopened' => ['REOPEN', 'reopen'],
    ];
    $timeline = [];
    $details = null;
    foreach ($history as $event) {
        if ($event['event'] === 'Raised') {
            break;
        }
        if (!isset($labels[$event['event']])) {
            continue;
        }
        if ($details === null && isset($event['snapshot']['ticket'])) {
            $details = $event['snapshot']['ticket'];
        }
        [$label, $kind] = $labels[$event['event']];
        $timeline[] = [
            'label' => $label,
            'kind' => $kind,
            'created_epoch' => $event['created_epoch'],
            'note' => $event['note'],
        ];
        if ($event['event'] === 'Escalated') {
            if ($details === null) {
                $details = [
                    'severity' => icct_nms_alarm_ticket_severity(
                        $event['snapshot']['severity'] ?? $alarm['severity'],
                    ),
                    'name' => $alarm['name'] . ' - ' . $alarm['device_name'],
                    'remarks' => $event['note'],
                ];
            }
            break;
        }
    }
    return ['details' => $details, 'timeline' => $timeline];
}
