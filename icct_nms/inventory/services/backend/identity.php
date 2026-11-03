<?php

/** Protocol identity belongs to collector observations, never to manual metadata. */
function icct_backend_identity_mac($value)
{
    $value = preg_replace('/^Hex-STRING:\s*/i', '', trim((string) $value, " \t\r\n\""));
    $hex = preg_replace('/[:\s-]/', '', $value);
    if (
        !preg_match('/^[a-f0-9]{12}$/iD', $hex) ||
        $hex === '000000000000' ||
        hexdec(substr($hex, 0, 2)) & 1
    ) {
        return '';
    }
    return strtolower(implode(':', str_split($hex, 2)));
}

function icct_backend_identity_signature($host)
{
    return hash(
        'sha256',
        json_encode([
            'identity-v2',
            $host['hostname'],
            $host['snmp_version'],
            $host['snmp_port'],
            $host['snmp_context'],
            $host['host_template_id'],
            $host['poller_id'],
            $host['snmp_username'],
            hash(
                'sha256',
                $host['snmp_community'] .
                    "\0" .
                    $host['snmp_password'] .
                    "\0" .
                    $host['snmp_priv_passphrase'],
            ),
        ]),
    );
}

/** Loopback devices describe the assigned collector itself. */
function icct_backend_identity_local_endpoint($hostname, $port = 161)
{
    return (int) $port === 161 &&
        in_array(
            strtolower(trim((string) $hostname)),
            ['localhost', 'localhost.', '127.0.0.1', '::1'],
            true,
        );
}

/** Pick the interface on the lowest-metric active default route, not an arbitrary NIC. */
function icct_backend_identity_route_mac($routes, $address)
{
    $candidates = [];
    foreach (preg_split('/\r?\n/', trim((string) $routes)) as $line) {
        $fields = preg_split('/\s+/', trim($line));
        if (
            count($fields) < 8 ||
            $fields[1] !== '00000000' ||
            $fields[7] !== '00000000' ||
            !(hexdec($fields[3]) & 1) ||
            !preg_match('/^[a-zA-Z0-9_.:-]+$/D', $fields[0])
        ) {
            continue;
        }
        $mac = icct_backend_identity_mac($address($fields[0]));
        if ($mac !== '') {
            $candidates[] = ['metric' => (int) $fields[6], 'mac' => $mac];
        }
    }
    usort($candidates, function ($a, $b) {
        return $a['metric'] <=> $b['metric'];
    });
    if (!$candidates) {
        return '';
    }
    $best = array_filter($candidates, function ($row) use ($candidates) {
        return $row['metric'] === $candidates[0]['metric'];
    });
    $macs = array_unique(array_column($best, 'mac'));
    return count($macs) === 1 ? reset($macs) : '';
}

function icct_backend_identity_local_mac()
{
    if (!is_readable('/proc/net/route')) {
        return '';
    }
    return icct_backend_identity_route_mac(file_get_contents('/proc/net/route'), function (
        $interface,
    ) {
        $path = '/sys/class/net/' . $interface . '/address';
        return is_readable($path) ? file_get_contents($path) : '';
    });
}

/** Only current observations from the same configured endpoint may fill empty fields. */
function icct_backend_identity_observed($host)
{
    $row = db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?', [
        'identity_snapshot_' . (int) $host['id'],
    ]);
    return icct_backend_identity_snapshot_values($host, $row);
}

function icct_backend_identity_snapshot_values($host, $row)
{
    $snapshot = json_decode((string) $row, true);
    if (
        !is_array($snapshot) ||
        ($snapshot['signature'] ?? '') !== icct_backend_identity_signature($host) ||
        (int) ($snapshot['time'] ?? 0) > time() ||
        time() - (int) ($snapshot['time'] ?? 0) > 2 * (int) read_config_option('poller_interval')
    ) {
        return [];
    }
    return $snapshot['values'] ?? [];
}

/** Enrich only authorized list rows; credentials never enter the returned UI data. */
function icct_backend_identity_inventory_rows($rows)
{
    if (!$rows) {
        return $rows;
    }
    $ids = array_map('intval', array_column($rows, 'id'));
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $hosts = array_column(
        db_fetch_assoc_prepared("SELECT * FROM host WHERE deleted='' AND id IN ($marks)", $ids),
        null,
        'id',
    );
    $keys = array_map(function ($id) {
        return 'identity_snapshot_' . $id;
    }, $ids);
    $snapshots = array_column(
        db_fetch_assoc_prepared(
            "SELECT meta_key,meta_value FROM plugin_icct_nms_meta WHERE meta_key IN ($marks)",
            $keys,
        ),
        'meta_value',
        'meta_key',
    );
    foreach ($rows as &$row) {
        $values = isset($hosts[$row['id']])
            ? icct_backend_identity_snapshot_values(
                $hosts[$row['id']],
                $snapshots['identity_snapshot_' . $row['id']] ?? '',
            )
            : [];
        $row['serial_number'] =
            trim((string) ($row['manual_serial_number'] ?? '')) ?: $values['serial_number'] ?? '';
        foreach (['mac_address', 'chassis_id'] as $field) {
            if (trim((string) ($row[$field] ?? '')) === '') {
                $row[$field] = $values[$field] ?? '';
            }
        }
    }
    unset($row);
    return $rows;
}

/** Use Cacti SNMP with saved host credentials on the assigned collector only. */
function icct_backend_collect_identity($only_host_id = 0)
{
    global $config;
    require_once $config['base_path'] . '/lib/snmp.php';
    $collector = icct_backend_inventory_collector_id();
    $hosts = db_fetch_assoc_prepared(
        "SELECT * FROM host WHERE deleted='' AND disabled='' AND poller_id=?" .
            ($only_host_id ? ' AND id=?' : ''),
        $only_host_id ? [$collector, (int) $only_host_id] : [$collector],
    );
    $count = 0;
    foreach ($hosts as $host) {
        $get = function ($oid, $format = SNMP_STRING_OUTPUT_ASCII) use ($host) {
            return cacti_snmp_get(
                $host['hostname'],
                $host['snmp_community'],
                $oid,
                $host['snmp_version'],
                $host['snmp_username'],
                $host['snmp_password'],
                $host['snmp_auth_protocol'],
                $host['snmp_priv_passphrase'],
                $host['snmp_priv_protocol'],
                $host['snmp_context'],
                $host['snmp_port'],
                $host['snmp_timeout'],
                (int) read_config_option('snmp_retries'),
                'ICCT Identity',
                $host['snmp_engine_id'],
                $format,
            );
        };
        $values = [];
        if (icct_backend_identity_local_endpoint($host['hostname'], $host['snmp_port'])) {
            $mac = icct_backend_identity_local_mac();
            if ($mac !== '') {
                $values['mac_address'] = $mac;
            }
        }
        // Test the configured endpoint first; an IP alone is not protocol configuration.
        if (
            (int) $host['snmp_version'] > 0 &&
            !icct_backend_inventory_snmp_failed($get('.1.3.6.1.2.1.1.3.0'))
        ) {
            $mapped = db_fetch_assoc_prepared(
                "SELECT inventory_key,observed_value FROM plugin_icct_nms_device_inventory WHERE host_id=? AND inventory_key IN ('mac_address','serial_number','chassis_id') AND status IN ('ok','changed') AND last_success>=DATE_SUB(NOW(), INTERVAL ? SECOND)",
                [$host['id'], max(120, 2 * (int) read_config_option('poller_interval'))],
            );
            foreach ($mapped as $row) {
                $value = trim((string) $row['observed_value']);
                if ($row['inventory_key'] === 'mac_address') {
                    $value = icct_backend_identity_mac($value);
                }
                if ($value !== '') {
                    $values[$row['inventory_key']] = $value;
                }
            }
            // Resolve the interface owning the configured IPv4 address, rather than guessing an interface.
            if (
                empty($values['mac_address']) &&
                !icct_backend_identity_local_endpoint($host['hostname'], $host['snmp_port'])
            ) {
                $addresses = filter_var($host['hostname'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                    ? [$host['hostname']]
                    : (filter_var($host['hostname'], FILTER_VALIDATE_IP)
                        ? []
                        : (gethostbynamel($host['hostname']) ?:
                        []));
                $macs = [];
                foreach (array_unique($addresses) as $ip) {
                    $index = trim((string) $get('.1.3.6.1.2.1.4.20.1.2.' . $ip));
                    if (ctype_digit($index)) {
                        $mac = icct_backend_identity_mac(
                            $get('.1.3.6.1.2.1.2.2.1.6.' . $index, SNMP_STRING_OUTPUT_HEX),
                        );
                        if ($mac !== '') {
                            $macs[$mac] = true;
                        }
                    }
                }
                if (count($macs) === 1) {
                    $values['mac_address'] = array_key_first($macs);
                }
            }
            // LLDP's local chassis identifier is used as a chassis ID, never a serial number.
            if (empty($values['chassis_id'])) {
                $chassis = $get('.1.0.8802.1.1.2.1.3.2.0');
                if (!icct_backend_inventory_snmp_failed($chassis)) {
                    $subtype = trim((string) $get('.1.0.8802.1.1.2.1.3.1.0'));
                    $chassis =
                        $subtype === '4'
                            ? icct_backend_identity_mac(
                                $get('.1.0.8802.1.1.2.1.3.2.0', SNMP_STRING_OUTPUT_HEX),
                            )
                            : trim((string) $chassis, " \t\r\n\"");
                    if ($chassis !== '') {
                        $values['chassis_id'] = $chassis;
                        // Subtype 4 explicitly identifies the device's chassis MAC.
                        if ($subtype === '4' && empty($values['mac_address'])) {
                            $values['mac_address'] = $chassis;
                        }
                    }
                }
            }
            // ENTITY-MIB can contain many component serials: accept exactly one physical chassis.
            if (empty($values['serial_number'])) {
                $classes = cacti_snmp_walk(
                    $host['hostname'],
                    $host['snmp_community'],
                    '.1.3.6.1.2.1.47.1.1.1.1.5',
                    $host['snmp_version'],
                    $host['snmp_username'],
                    $host['snmp_password'],
                    $host['snmp_auth_protocol'],
                    $host['snmp_priv_passphrase'],
                    $host['snmp_priv_protocol'],
                    $host['snmp_context'],
                    $host['snmp_port'],
                    $host['snmp_timeout'],
                    (int) read_config_option('snmp_retries'),
                    (int) $host['max_oids'],
                    'ICCT Identity',
                    $host['snmp_engine_id'],
                );
                $chassisRows = array_filter(is_array($classes) ? $classes : [], function ($row) {
                    return preg_match('/^(?:3|chassis(?:\(3\))?)$/i', trim((string) $row['value']));
                });
                if (count($chassisRows) === 1) {
                    $row = array_values($chassisRows)[0];
                    $index = substr($row['oid'], strrpos($row['oid'], '.') + 1);
                    if (ctype_digit($index)) {
                        $serial = $get('.1.3.6.1.2.1.47.1.1.1.1.11.' . $index);
                        if (
                            !icct_backend_inventory_snmp_failed($serial) &&
                            trim((string) $serial, " \t\r\n\"") !== ''
                        ) {
                            $values['serial_number'] = trim((string) $serial, " \t\r\n\"");
                        }
                    }
                }
            }
        }
        icct_backend_category_execute(
            'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
            [
                'identity_snapshot_' . $host['id'],
                json_encode(
                    [
                        'signature' => icct_backend_identity_signature($host),
                        'time' => time(),
                        'values' => $values,
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ],
        );
        $count++;
    }
    return $count;
}
