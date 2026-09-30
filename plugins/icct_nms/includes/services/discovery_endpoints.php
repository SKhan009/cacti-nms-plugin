<?php
/** ICCT-owned discovery endpoints services, derived from the existing ICCT NMS implementation. */

/** Reused Inventory service: nd mac. */
function icct_backend_nd_mac($bytes)
{
    if (strlen($bytes) !== 6) {
        throw new RuntimeException('Expected a six-octet Ethernet address.');
    }
    return implode(':', str_split(bin2hex($bytes), 2));
}

/** Reused Inventory service: nd parse arp. */
function icct_backend_nd_parse_arp($values, $interfaces)
{
    $root = '1.3.6.1.2.1.4.22.1';
    $rows = [];
    foreach (icct_backend_nd_table_indices($values, $root, 5) as $index) {
        $parts = explode('.', $index);
        $ifindex = (int) array_shift($parts);
        $ip = implode('.', $parts);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $ifindex < 1) {
            throw new RuntimeException('Invalid ARP table index.');
        }
        if (
            (int) icct_backend_nd_value($values, $root . '.1.' . $index, 2) !== $ifindex ||
            icct_backend_nd_value($values, $root . '.3.' . $index, 64) !== $ip
        ) {
            throw new RuntimeException('ARP row identity differs from its index.');
        }
        $type = (int) icct_backend_nd_value($values, $root . '.4.' . $index, 2);
        if (!in_array($type, [1, 2, 3, 4], true)) {
            throw new RuntimeException('Invalid ARP entry type.');
        }
        $bytes = icct_backend_nd_value($values, $root . '.2.' . $index, 4);
        if ($type === 2) {
            continue;
        } // Invalidated rows are not current mappings.
        $mac = strlen($bytes) === 6 ? icct_backend_nd_mac($bytes) : '';
        $rows[$ifindex . '|' . $ip . '|0'] = [
            'ip' => $ip,
            'mac' => $mac,
            'ifindex' => $ifindex,
            'interface' => $interfaces[$ifindex]['name'] ?? '',
            'state' => $mac !== '' ? 'observed' : 'unresolved',
            'type' => $type,
            'address_family' => 4,
            'scope_id' => 0,
            'source' => 'ipNetToMediaTable'
        ];
    }
    $root = '1.3.6.1.2.1.4.35.1';
    $indices = [];
    foreach ($values as $oid => $value) {
        if (strpos($oid, $root . '.') === 0) {
            $suffix = substr($oid, strlen($root) + 1);
            $dot = strpos($suffix, '.');
            if ($dot === false || !ctype_digit(substr($suffix, 0, $dot))) {
                throw new RuntimeException('Malformed IP neighbour column.');
            }
            $indices[substr($suffix, $dot + 1)] = true;
        }
    }
    $states = [
        1 => 'Reachable',
        2 => 'Stale neighbour cache',
        3 => 'Delay',
        4 => 'Probe',
        5 => 'Invalid',
        6 => 'Unknown',
        7 => 'Incomplete'
    ];
    foreach (array_keys($indices) as $index) {
        $row = icct_backend_nd_physical_index($index);
        if ($row === null) {
            continue;
        }
        $key = $row['ifindex'] . '|' . $row['ip'] . '|' . $row['scope_id'];
        $bytes = icct_backend_nd_value($values, $root . '.4.' . $index, 4);
        $type = (int) icct_backend_nd_value($values, $root . '.6.' . $index, 2);
        $state = (int) icct_backend_nd_value($values, $root . '.7.' . $index, 2);
        $status = (int) icct_backend_nd_value($values, $root . '.8.' . $index, 2);
        if (
            !in_array($type, [1, 2, 3, 4, 5], true) ||
            !isset($states[$state]) ||
            $status < 1 ||
            $status > 6
        ) {
            throw new RuntimeException('Invalid IP neighbour type, state or row status.');
        }
        unset($rows[$key]);
        if ($type === 2 || $status !== 1) {
            continue;
        }
        $mac =
            strlen($bytes) === 6 && !in_array($state, [5, 7], true)
                ? icct_backend_nd_mac($bytes)
                : '';
        $rows[$key] = $row + [
            'mac' => $mac,
            'interface' => $interfaces[$row['ifindex']]['name'] ?? '',
            'state' => $mac !== '' ? 'observed' : 'unresolved',
            'type' => $type,
            'neighbor_state' => $states[$state],
            'source' => 'ipNetToPhysicalTable'
        ];
    }
    return [
        'interfaces' => $interfaces,
        'neighbors' => [],
        'endpoints' => array_values($rows),
        'scope' =>
            'IPv4 ARP and IPv4/IPv6 IP-MIB neighbour tables in the configured SNMP context. Empty tables may be unsupported, excluded by the SNMP view, or have no entries. Link-local addresses are scoped to the reporting interface.'
    ];
}

/** Reused Inventory service: nd parse fdb. */
function icct_backend_nd_parse_fdb($values, $interfaces)
{
    $base = '1.3.6.1.2.1.17';
    $rows = [];
    $portMap = [];
    $vlans = [];
    foreach (icct_backend_nd_column($values, $base . '.1.4.1', 2, 1, 2) as $port => $index) {
        if ((int) $port < 1 || (int) $index < 0) {
            throw new RuntimeException('Invalid bridge-port mapping.');
        }
        $portMap[$port] = (int) $index;
    }
    // dot1qVlanFdbId is indexed by TimeMark and VLAN; one FDB can serve several VLANs.
    foreach (icct_backend_nd_column($values, $base . '.7.1.4.2.1', 3, 2, 66) as $index => $fdb) {
        $parts = explode('.', $index);
        $vlan = (int) $parts[1];
        if ($vlan < 1 || $vlan > 4094) {
            continue;
        }
        $vlans[(string) $fdb][$vlan] = $vlan;
    }
    foreach (
        [[$base . '.4.3.1', 6, 'BRIDGE-MIB'], [$base . '.7.1.2.2.1', 7, 'Q-BRIDGE-MIB']]
        as $spec
    ) {
        [$root, $parts, $source] = $spec;
        foreach (icct_backend_nd_table_indices($values, $root, $parts) as $index) {
            $arcs = explode('.', $index);
            $fdb = $parts === 7 ? array_shift($arcs) : null;
            $bytes = '';
            foreach ($arcs as $arc) {
                if ((int) $arc > 255) {
                    throw new RuntimeException('Invalid FDB MAC index.');
                }
                $bytes .= chr((int) $arc);
            }
            // Q-BRIDGE's address is a not-accessible index; derive it from the OID.
            $address = icct_backend_nd_value($values, $root . '.1.' . $index, 4, $parts === 6);
            if ($address !== null && $address !== $bytes) {
                throw new RuntimeException('FDB address differs from its index.');
            }
            $port = (int) icct_backend_nd_value($values, $root . '.2.' . $index, 2);
            $status = (int) icct_backend_nd_value($values, $root . '.3.' . $index, 2);
            if ($port < 0 || !in_array($status, [1, 2, 3, 4, 5], true)) {
                throw new RuntimeException('Invalid FDB port or status.');
            }
            if ($status === 2) {
                continue;
            }
            $ifindex = $portMap[$port] ?? 0;
            $rows[] = [
                'mac' => icct_backend_nd_mac($bytes),
                'bridge_port' => $port,
                'ifindex' => $ifindex,
                'interface' => $interfaces[$ifindex]['name'] ?? '',
                'fdb_id' => $fdb === null ? null : (int) $fdb,
                'vlans' => array_values($vlans[(string) $fdb] ?? []),
                'source' => $source,
                'status' => $status,
                'state' => $status === 3 && $ifindex > 0 ? 'inferred' : 'unresolved'
            ];
        }
    }
    $counts = [];
    foreach ($rows as $row) {
        if ($row['ifindex'] > 0) {
            $counts[$row['ifindex']][$row['mac']] = true;
        }
    }
    foreach ($rows as &$row) {
        $row['macs_on_interface'] = count($counts[$row['ifindex']] ?? []);
        $row['direct_connection'] = false;
    }
    unset($row);
    return [
        'interfaces' => $interfaces,
        'neighbors' => [],
        'endpoints' => $rows,
        'scope' =>
            'Configured SNMP context only. Learned ports may be uplinks or aggregate interfaces; physical members are not inferred. Empty tables do not prove support.'
    ];
}

/** Reused Inventory service: nd physical index. */
function icct_backend_nd_physical_index($index)
{
    if (!preg_match('/^[0-9]+(?:\.[0-9]+)+$/D', $index)) {
        throw new RuntimeException('Invalid IP neighbour index.');
    }
    $arcs = explode('.', $index);
    $ifindex = (int) array_shift($arcs);
    $type = (int) array_shift($arcs);
    $length = (int) array_shift($arcs);
    if ($ifindex < 1 || $ifindex > 2147483647 || count($arcs) !== $length) {
        throw new RuntimeException('Invalid IP neighbour index length.');
    }
    $bytes = '';
    foreach ($arcs as $arc) {
        if ((float) $arc > 255) {
            throw new RuntimeException('Invalid IP neighbour address octet.');
        }
        $bytes .= chr((int) $arc);
    }
    $sizes = [1 => 4, 2 => 16, 3 => 8, 4 => 20];
    if (!isset($sizes[$type])) {
        return null;
    } // Unknown address families are not IP endpoints.
    if ($length !== $sizes[$type]) {
        throw new RuntimeException('IP neighbour address type and length differ.');
    }
    $zoned = $type === 3 || $type === 4;
    $zone = $zoned ? unpack('Nzone', substr($bytes, -4))['zone'] : 0;
    $ip = inet_ntop($zoned ? substr($bytes, 0, -4) : $bytes);
    return [
        'ifindex' => $ifindex,
        'ip' => $ip,
        'address_family' => $type === 1 || $type === 3 ? 4 : 6,
        'scope_id' => $zone
    ];
}
