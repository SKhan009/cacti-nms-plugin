<?php

require_once __DIR__ . '/../../../../fcaps/services/passive_fault_service.php';

function icct_nms_trap_sender($id)
{
    return (string) db_fetch_cell_prepared(
        'SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
        ['trap_sender_' . (int) $id],
    );
}

/** Explain the notification using only its type and the evidence saved with it. */
function icct_nms_trap_explanation($oid, $source, array $payload)
{
    $oid = ltrim($oid, '.');
    if (
        $oid === '1.3.6.1.4.1.32473.20261105.705' &&
        in_array($source, ['127.0.0.1', '::1'], true) &&
        in_array('ICCT dashboard loopback test', (array) ($payload[$oid . '.1'] ?? []), true)
    ) {
        return [
            'title' => 'Dashboard trap test received',
            'what' =>
                'The RHEL server sent a test trap to its own receiver. Cacti received and stored it.',
            'why' =>
                'The test checked whether a local trap could create an alarm and appear on the dashboard.',
        ];
    }
    if (
        $oid === '1.3.6.1.4.1.32473.20261105.705' &&
        in_array($source, ['127.0.0.1', '::1'], true) &&
        in_array(
            'ICCT AgentX repair verification',
            (array) ($payload['1.3.6.1.4.1.32473.1.0'] ?? []),
            true,
        )
    ) {
        return [
            'title' => 'Local SNMP test received after the AgentX fix',
            'what' =>
                'The RHEL server sent itself a test notification, and Cacti received and stored it.',
            'why' =>
                'The test checked that the local trap receiver could still accept notifications after the AgentX fix.',
            'result' =>
                'This confirms local trap delivery for this test. Delivery from other devices is unknown.',
        ];
    }
    return match ($oid) {
        // SNMPv2-MIB (RFC 3418) and IF-MIB (RFC 2863).
        '1.3.6.1.6.3.1.1.5.1' => [
            'title' => 'Device SNMP service restarted',
            'what' => 'The device reported that its SNMP service was starting again.',
            'why' =>
                'The device sent a cold-start notification. Its SNMP settings may have changed. The reason for the restart is unknown.',
        ],
        '1.3.6.1.6.3.1.1.5.2' => [
            'title' => 'Device SNMP service restarted with the same settings',
            'what' =>
                'The device reported that its SNMP service was starting again with the same settings.',
            'why' =>
                'The device sent a warm-start notification. The reason for the restart is unknown.',
        ],
        '1.3.6.1.6.3.1.1.5.3' => [
            'title' => 'Device reported a link going down',
            'what' => 'The device reported that a network interface was going down.',
            'why' =>
                'The notification reports a change in link state. The cause of the change is unknown.',
        ],
        '1.3.6.1.6.3.1.1.5.4' => [
            'title' => 'Device reported a link leaving the down state',
            'what' =>
                'The device reported that a network interface was no longer in the down state.',
            'why' =>
                'The device detected a change in link state. The notification alone leaves end-to-end connectivity unknown.',
        ],
        '1.3.6.1.6.3.1.1.5.5' => [
            'title' => 'Device reported an SNMP authentication failure',
            'what' =>
                'The device reported an SNMP message that did not pass its authentication checks.',
            'why' =>
                'The device sent a notification because authentication failed. The exact cause is unknown.',
        ],
        default => [
            'title' => 'SNMP notification received',
            'what' => 'Cacti received and stored an SNMP notification from the sender shown above.',
            'why' =>
                'The cause is unknown from this record. The original notification type and values are shown below.',
        ],
    };
}

/** Explain standard fields without interpreting an unknown vendor value. */
function icct_nms_trap_payload_field($oid, $value)
{
    $oid = ltrim($oid, '.');
    if ($oid === '1.3.6.1.2.1.1.3.0') {
        $meaning = 'Time since the sender’s network management service was last initialized.';
        if (preg_match('/^(?:\(([0-9]{1,10})\)(?:\s|$)|([0-9]{1,10})$)/D', $value, $parts)) {
            $ticks = (int) ($parts[1] !== '' ? $parts[1] : $parts[2]);
            if ($ticks <= 4294967295) {
                $hours = intdiv($ticks, 360000);
                $minutes = intdiv($ticks % 360000, 6000);
                $seconds = ($ticks % 6000) / 100;
                $meaning = sprintf(
                    'The sender reported %d %s, %d %s and %s %s since its network management service started.',
                    $hours,
                    $hours === 1 ? 'hour' : 'hours',
                    $minutes,
                    $minutes === 1 ? 'minute' : 'minutes',
                    $seconds,
                    $seconds == 1 ? 'second' : 'seconds',
                );
            }
        }
        return ['SNMP uptime', $meaning];
    }
    if ($oid === '1.3.6.1.6.3.1.1.4.1.0') {
        return ['Notification type', 'This number identifies the kind of trap the sender sent.'];
    }
    if (
        ($oid === '1.3.6.1.4.1.32473.20261105.705.1' &&
            $value === 'ICCT dashboard loopback test') ||
        ($oid === '1.3.6.1.4.1.32473.1.0' && $value === 'ICCT AgentX repair verification')
    ) {
        return ['Test message', 'The sender included this text to identify the verification test.'];
    }
    return ['Sender-defined field', 'The field’s meaning depends on the sender’s SNMP definition.'];
}

function icct_nms_save_trap_sender($id, $input)
{
    icct_backend_require_management(3);
    icct_backend_require_device_access($id);
    $key = 'trap_sender_' . (int) $id;
    if (empty($input['trap_enabled'])) {
        icct_backend_category_execute('DELETE FROM plugin_icct_nms_meta WHERE meta_key=?', [$key]);
        return;
    }
    $source = $input['trap_source_address'] ?? '';
    if ($source === '' && ($input['trap_use_device_ip'] ?? '') === '1') {
        $source = db_fetch_cell_prepared('SELECT hostname FROM host WHERE id=? AND deleted=\'\'', [
            (int) $id,
        ]);
    }
    try {
        $address = icct_nms_syslog_ip($source);
    } catch (InvalidArgumentException $error) {
        throw new InvalidArgumentException(
            'Enter a numeric IP address for the trap sender.',
            0,
            $error,
        );
    }
    icct_backend_category_execute(
        'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ' .
            'ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
        [$key, $address],
    );
}

/** Read the authorized Net-SNMP receiver format. The UDP peer identifies the sender. */
function icct_nms_trap_parse_line($line)
{
    if (
        !preg_match(
            '/^ICCT-TRAP(-V1)? ([0-9]{1,12}) UDP(?:\/IPv6)?: \[([^\]]+)\]:[0-9]+->\[[^\]]+\]:[0-9]+\t(.*)$/D',
            $line,
            $fields,
        )
    ) {
        throw new InvalidArgumentException('Malformed SNMP notification receiver record.');
    }
    $address = icct_nms_syslog_ip($fields[3]);
    $payload = $fields[4];
    $varbinds = [];
    $trapOidField = '1.3.6.1.6.3.1.1.4.1.0';
    if ($fields[1] === '-V1') {
        [$enterprise, $generic, $specific, $agent, $payload] = array_pad(
            explode("\t", $payload, 5),
            5,
            '',
        );
        $enterprise = icct_nms_passive_oid($enterprise);
        $specific = ltrim($specific, '.');
        if (
            !preg_match('/^[0-6]$/D', $generic) ||
            !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $specific) ||
            !filter_var($agent, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        ) {
            throw new InvalidArgumentException('Invalid SNMPv1 notification header.');
        }
        // RFC 3584 maps the native v1 trap identity to the notification OID used by Fault rules.
        $varbinds[$trapOidField] = [
            $generic === '6'
                ? $enterprise . '.0.' . $specific
                : '1.3.6.1.6.3.1.1.5.' . ((int) $generic + 1),
        ];
        $varbinds['1.3.6.1.6.3.1.1.4.3.0'] = [$enterprise];
        $varbinds['1.3.6.1.6.3.18.1.3.0'] = [$agent];
    }
    $parts = $payload === '' ? [] : explode("\t", $payload);
    if (count($parts) > 256 || strlen($payload) > 65535) {
        throw new InvalidArgumentException('SNMP notification exceeds the supported payload size.');
    }
    foreach ($parts as $part) {
        if (!preg_match('/^(\.[0-9.]+) = (?:(?:([^:]+): ?(.*))|(NULL))$/D', $part, $binding)) {
            throw new InvalidArgumentException('Malformed SNMP notification field.');
        }
        $oid = icct_nms_passive_oid($binding[1]);
        if ($oid === '1.3.6.1.6.3.18.1.4.0') {
            continue; // snmpTrapCommunity.0 is security data, not event evidence.
        }
        $value = trim($binding[4] ?? $binding[3]);
        if ($binding[2] === 'Hex-STRING' && preg_match('/^(?:[0-9A-Fa-f]{2} ?)+$/D', $value)) {
            $bytes = hex2bin(str_replace(' ', '', $value));
            if (mb_check_encoding($bytes, 'UTF-8') && !preg_match('/[\x00-\x1f\x7f]/', $bytes)) {
                $value = $bytes;
            }
        }
        $varbinds[$oid][] = $value;
    }
    $trapOids = $varbinds[$trapOidField] ?? [];
    if (count($trapOids) !== 1) {
        throw new InvalidArgumentException('SNMP notification must supply exactly one trap OID.');
    }
    return [
        'source_ip' => $address,
        'time' => date('Y-m-d H:i:s', (int) $fields[2]),
        'oid' => icct_nms_passive_oid($trapOids[0]),
        'varbinds' => $varbinds,
    ];
}

function icct_nms_trap_ingest_line($line, &$reason)
{
    if (!str_starts_with($line, 'ICCT-TRAP ') && !str_starts_with($line, 'ICCT-TRAP-V1 ')) {
        $reason = 'receiver-status';
        return 'dropped';
    }
    $trap = icct_nms_trap_parse_line($line);
    $hosts = db_fetch_assoc_prepared(
        "SELECT h.id FROM host h JOIN plugin_icct_nms_meta m ON m.meta_key=CONCAT('trap_sender_',h.id) " .
            "WHERE h.deleted='' AND h.disabled='' AND h.poller_id=? AND INET6_ATON(m.meta_value)=INET6_ATON(?)",
        [icct_backend_inventory_collector_id(), $trap['source_ip']],
    );
    if ($hosts === false) {
        throw new IcctSyslogStorageException(
            'SNMP trap binding lookup failed. The spool cursor was retained.',
        );
    }
    if (count($hosts) !== 1) {
        $reason = 'unmapped-or-ambiguous-trap-source';
        return 'dropped';
    }
    $id = (int) $hosts[0]['id'];
    $varbinds = $trap['varbinds'];
    // Uptime changes with every datagram and is evidence, but does not define a distinct event.
    unset($varbinds['1.3.6.1.2.1.1.3.0']);
    ksort($varbinds);
    $fingerprint = hash(
        'sha256',
        json_encode(['trap', $id, $trap['source_ip'], $varbinds], JSON_THROW_ON_ERROR),
    );
    $now = date('Y-m-d H:i:s');
    return icct_backend_store_received_event(
        [
            $id,
            'trap',
            $trap['time'],
            $now,
            $now,
            $trap['source_ip'],
            '',
            'udp',
            null,
            null,
            null,
            null,
            null,
            $trap['oid'],
            'SNMP trap ' . $trap['oid'],
            $fingerprint,
            'receiver',
            $trap['time'],
            json_encode($trap['varbinds'], JSON_THROW_ON_ERROR),
        ],
        $reason,
    );
}
