<?php

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
                $rule['metric_id'] ?? 0,
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
        $ack =
            $event === 'Acknowledged'
                ? ['note' => $note, 'actor_id' => $actor]
                : db_fetch_row_prepared(
                    "SELECT note,actor_id FROM plugin_icct_nms_alarm_history WHERE alarm_key=? AND event='Acknowledged' ORDER BY id DESC LIMIT 1",
                    [$key],
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
function icct_nms_alarm_record($host, $rule, $observation)
{
    $key = icct_nms_alarm_key($host, $rule, $observation);
    $lock = 'icct_alarm_' . substr($key, 0, 40);
    if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?,0)', [$lock]) !== 1) {
        return;
    }
    try {
        icct_backend_category_execute('START TRANSACTION', []);
        $previous = db_fetch_row_prepared(
            'SELECT * FROM plugin_icct_nms_alarms WHERE alarm_key=? FOR UPDATE',
            [$key],
        );
        $sample = (int) ($observation['sample_time'] ?? 0);
        $change =
            $previous && $sample > 0 && $sample < (int) $previous['sample_time']
                ? null
                : icct_nms_alarm_transition($previous, $observation);
        if ($change) {
            $name = mb_substr(trim($rule['name'] ?? '') ?: $observation['graph'], 0, 160);
            $source = str_starts_with((string) $observation['rule'], 'port:')
                ? 'Port'
                : (($rule['source'] ?? 'rrd') === 'snmp'
                    ? 'SNMP'
                    : (($rule['source'] ?? '') === 'availability'
                        ? 'Availability'
                        : 'Cacti'));
            $description = $rule['description'] ?? '';
            $fix = $rule['corrective_action'] ?? '';
            if (!$previous) {
                icct_backend_category_execute(
                    'INSERT INTO plugin_icct_nms_alarms(alarm_key,host_id,source,graph_id,name,description,fix,severity,state,first_seen,last_seen,sample_time) VALUES(?,?,?,?,?,?,?,?,?,NOW(),NOW(),?)',
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
                        (int) ($observation['sample_time'] ?? 0),
                    ],
                );
            } else {
                icct_backend_category_execute(
                    'UPDATE plugin_icct_nms_alarms SET graph_id=?,name=?,description=?,fix=?,severity=?,state=?,last_seen=NOW(),sample_time=?,occurrences=?,acknowledged=?,escalated=?,ticket_state=?,revision=revision+1 WHERE alarm_key=?',
                    [
                        (int) $observation['graph_id'],
                        $name,
                        $description,
                        $fix,
                        $change['severity'],
                        $change['state'],
                        (int) ($observation['sample_time'] ?? 0),
                        $change['occurrences'],
                        $change['raised'] ? 0 : (int) $previous['acknowledged'],
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
        icct_backend_category_execute('COMMIT', []);
    } catch (Throwable $e) {
        icct_backend_category_execute('ROLLBACK', []);
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
        ]) as $host
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
                $rule = ['name' => $observation['graph'], 'source' => 'port'];
            }
            $keys[] = icct_nms_alarm_key($host, $rule, $observation);
            icct_nms_alarm_record($host, $rule, $observation);
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
                    "SELECT * FROM plugin_icct_nms_alarms WHERE host_id=? AND state IN ('Active','Closed')",
                    [(int) $host['id']],
                ) as $saved
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
        'SELECT *,UNIX_TIMESTAMP(first_seen) AS occurred_epoch,UNIX_TIMESTAMP(last_seen) AS observed_epoch FROM plugin_icct_nms_alarms WHERE host_id IN (' .
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
        $row['address'] = $host['hostname'];
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
        'SELECT *,UNIX_TIMESTAMP(first_seen) AS occurred_epoch,UNIX_TIMESTAMP(last_seen) AS observed_epoch FROM plugin_icct_nms_alarms WHERE alarm_key=?',
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
        'address' => $device['hostname'],
        'network_type' => $device['device_type'] ?? '',
        'device_status' => $device['status_label'] ?? 'Unknown',
    ];
    $history = db_fetch_assoc_prepared(
        "SELECT h.*,UNIX_TIMESTAMP(h.created_at) AS created_epoch,COALESCE(NULLIF(u.full_name,''),u.username) AS actor_name,u.username AS actor_username,u.email_address AS actor_email FROM plugin_icct_nms_alarm_history h LEFT JOIN user_auth u ON u.id=h.actor_id WHERE h.alarm_key=? ORDER BY h.id DESC",
        [$key],
    );
    $ack = null;
    $escalations = [];
    foreach ($history as &$event) {
        $snapshot = json_decode((string) ($event['snapshot_json'] ?? ''), true);
        $event['snapshot'] = is_array($snapshot) ? $snapshot : [];
        unset($event['snapshot_json']);
        if ($event['event'] === 'Acknowledged' && $ack === null) {
            $ack = $event;
        }
        if ($event['event'] === 'Escalated') {
            $escalations[] = $event;
        }
    }
    unset($event);
    $history = icct_nms_alarm_history_remarks($history);
    return [
        'alarm' => $row,
        'history' => $history,
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
        if ($event['event'] === 'Acknowledged') {
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
