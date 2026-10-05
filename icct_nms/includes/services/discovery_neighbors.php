<?php
/** ICCT-owned discovery neighbors services, derived from the existing ICCT NMS implementation. */

/** Reused Inventory service: nd column. */
function icct_backend_nd_column($values, $root, $column, $parts, $type)
{
    $out = [];
    $prefix = $root . '.' . $column . '.';
    foreach ($values as $oid => $v) {
        if (strpos($oid, $prefix) === 0) {
            $index = substr($oid, strlen($prefix));
            if (!preg_match('/^[0-9]+(?:\.[0-9]+){' . ($parts - 1) . '}$/D', $index)) {
                throw new RuntimeException('Malformed MIB table index at ' . $oid);
            }
            $out[$index] = icct_backend_nd_value($values, $oid, $type);
        }
    }
    return $out;
}

/** Reused Inventory service: nd interfaces. */
function icct_backend_nd_interfaces($values)
{
    $root = '1.3.6.1.2.1.2.2.1';
    $ports = [];
    foreach (icct_backend_nd_column($values, $root, 1, 1, 2) as $index => $value) {
        if ((string) $index !== (string) $value) {
            throw new RuntimeException('IF-MIB index does not match its row.');
        }
        $name = icct_backend_nd_value($values, '1.3.6.1.2.1.31.1.1.1.1.' . $index, 4, false);
        $alias = icct_backend_nd_value($values, '1.3.6.1.2.1.31.1.1.1.18.' . $index, 4, false);
        $mac = icct_backend_nd_value($values, $root . '.6.' . $index, 4, false);
        $speed = icct_backend_nd_value($values, $root . '.5.' . $index, 66, false);
        $high = icct_backend_nd_value($values, '1.3.6.1.2.1.31.1.1.1.15.' . $index, 66, false);
        $ports[$index] = [
            'index' => (int) $index,
            'if_type' => icct_backend_nd_value($values, $root . '.3.' . $index, 2, false),
            'name' => icct_backend_nd_octets($name),
            'name_hex' => $name === null ? '' : bin2hex($name),
            'alias' => icct_backend_nd_octets($alias),
            'in_octets' => icct_backend_nd_value(
                $values,
                '1.3.6.1.2.1.31.1.1.1.6.' . $index,
                70,
                false
            ),
            'out_octets' => icct_backend_nd_value(
                $values,
                '1.3.6.1.2.1.31.1.1.1.10.' . $index,
                70,
                false
            ),
            'discontinuity' => icct_backend_nd_value(
                $values,
                '1.3.6.1.2.1.31.1.1.1.19.' . $index,
                67,
                false
            ),
            'alias_hex' => $alias === null ? '' : bin2hex($alias),
            'mac_hex' => $mac === null ? '' : bin2hex($mac),
            'description' => icct_backend_nd_octets(
                icct_backend_nd_value($values, $root . '.2.' . $index, 4, false)
            ),
            'description_hex' => bin2hex(
                icct_backend_nd_value($values, $root . '.2.' . $index, 4, false) ?? ''
            ),
            'admin' => icct_backend_nd_value($values, $root . '.7.' . $index, 2, false),
            'oper' => icct_backend_nd_value($values, $root . '.8.' . $index, 2, false),
            'speed_bps' => $speed === null ? 0 : (int) $speed,
            'high_speed_mbps' => $high === null ? 0 : (int) $high
        ];
    }
    if (!$ports) {
        throw new RuntimeException('IF-MIB contains no readable ifIndex rows.');
    }
    return $ports;
}

/** Reused Inventory service: nd lldp identity. */
function icct_backend_nd_lldp_identity($subtype, $id)
{
    if (!preg_match('/^[1-7]$/D', (string) $subtype) || $id === '' || strlen($id) > 255) {
        throw new RuntimeException('Invalid mandatory LLDP identity.');
    }
    return (int) $subtype . ':' . bin2hex($id);
}

/** Reused Inventory service: nd lldp ifindex. */
function icct_backend_nd_lldp_ifindex($subtype, $hex, $interfaces)
{
    $fields = [
        1 => ['alias_hex'],
        3 => ['mac_hex'],
        5 => ['name_hex'],
        7 => ['name_hex', 'description_hex']
    ];
    if (!isset($fields[$subtype]) || $hex === '') {
        return 0;
    }
    // Locally assigned IDs have no standard numeric relationship to ifIndex.
    // Accept only exact IF-MIB names/descriptions, unique across both columns.
    $matches = [];
    foreach ($interfaces as $i => $port) {
        foreach ($fields[$subtype] as $field) {
            if (($port[$field] ?? '') === $hex) {
                $matches[(int) $i] = true;
            }
        }
    }
    return count($matches) === 1 ? (int) array_key_first($matches) : 0;
}

/** Reused Inventory service: nd lldp label. */
function icct_backend_nd_lldp_label($subtype, $bytes, $macSubtype)
{
    return (int) $subtype === $macSubtype && strlen($bytes) === 6
        ? implode(':', str_split(bin2hex($bytes), 2))
        : icct_backend_nd_octets($bytes);
}

/** Reused Inventory service: nd observation history. */
function icct_backend_nd_observation_history($previous, $current, $now)
{
    $merged = [];
    foreach ($previous as $key => $old) {
        if (($old['last_seen'] ?? 0) >= $now - 604800) {
            $old['present'] = false;
            $merged[$key] = $old;
        }
    }
    foreach ($current as $key => $row) {
        $row['first_seen'] = $previous[$key]['first_seen'] ?? $now;
        $row['last_seen'] = $now;
        $row['present'] = true;
        $merged[$key] = $row;
    }
    return $merged;
}

/** Reused Inventory service: nd octets. */
function icct_backend_nd_octets($value)
{
    if ($value === null) {
        return '';
    }
    return preg_match('//u', $value) && !preg_match('/[\x00-\x1f\x7f]/', $value)
        ? $value
        : 'hex:' . bin2hex($value);
}

/** Reused Inventory service: nd parse cdp. */
function icct_backend_nd_parse_cdp($values, $interfaces)
{
    $root = '1.3.6.1.4.1.9.9.23.1';
    $cache = $root . '.2.1.1';
    if ((int) icct_backend_nd_value($values, $root . '.3.1.0', 2) !== 1) {
        throw new RuntimeException('CDP is disabled on this device.');
    }
    $id = icct_backend_nd_value($values, $root . '.3.4.0', 4);
    if ($id === '' || strlen($id) > 255) {
        throw new RuntimeException('CDP global Device-ID is invalid.');
    }
    $names = icct_backend_nd_column($values, $root . '.1.1.1', 6, 1, 4);
    $ports = [];
    $neighbors = [];
    foreach ($interfaces as $index => $port) {
        $ids = [];
        if ($port['name_hex'] !== '') {
            $ids[$port['name_hex']] = $port['name'];
        }
        if (isset($names[$index]) && $names[$index] !== '') {
            $ids[bin2hex($names[$index])] = icct_backend_nd_octets($names[$index]);
        }
        foreach ($ids as $hex => $label) {
            $ports[] = ['key' => $hex, 'label' => $label, 'ifindex' => (int) $index];
        }
    }
    foreach (icct_backend_nd_table_indices($values, $cache, 2) as $index) {
        $local = (int) explode('.', $index)[0];
        $peer = icct_backend_nd_value($values, $cache . '.6.' . $index, 4);
        $port = icct_backend_nd_value($values, $cache . '.7.' . $index, 4);
        if ($peer === '' || $port === '' || strlen($peer) > 255 || strlen($port) > 255) {
            throw new RuntimeException('CDP neighbor is missing a valid Device-ID or Port-ID.');
        }
        $key = hash('sha256', $local . '|' . bin2hex($peer) . '|' . bin2hex($port));
        $management = icct_backend_nd_cdp_management_addresses($values, $cache, $index);
        $neighbors[$key] = [
            'key' => $key,
            'local_key' => (string) $local,
            'local_port' => 'ifIndex ' . $local,
            'local_ifindex' => isset($interfaces[$local]) ? $local : 0,
            'peer_key' => bin2hex($peer),
            'peer_label' => icct_backend_nd_octets($peer),
            'remote_key' => bin2hex($port),
            'remote_port' => icct_backend_nd_octets($port),
            'remote_name' => icct_backend_nd_octets(
                icct_backend_nd_value($values, $cache . '.17.' . $index, 4, false)
            ),
            'management_addresses' => $management['addresses'],
            'management_address_errors' => $management['errors']
        ];
    }
    return [
        'identity' => bin2hex($id),
        'name' => icct_backend_nd_octets($id),
        'ports' => $ports,
        'interfaces' => $interfaces,
        'neighbors' => $neighbors
    ];
}

/** Reused Inventory service: nd parse lldp. */
function icct_backend_nd_parse_lldp($values, $interfaces)
{
    $management = icct_backend_nd_lldp_management_addresses($values);
    $local = '1.0.8802.1.1.2.1.3';
    $remote = '1.0.8802.1.1.2.1.4.1.1';
    $identity = icct_backend_nd_lldp_identity(
        icct_backend_nd_value($values, $local . '.1.0', 2),
        icct_backend_nd_value($values, $local . '.2.0', 4)
    );
    $ports = [];
    $neighbors = [];
    foreach (icct_backend_nd_table_indices($values, $local . '.7.1', 1) as $number) {
        $id = icct_backend_nd_value($values, $local . '.7.1.3.' . $number, 4);
        $sub = (int) icct_backend_nd_value($values, $local . '.7.1.2.' . $number, 2);
        $ifindex = icct_backend_nd_lldp_ifindex($sub, bin2hex($id), $interfaces);
        $ports[$number] = [
            'key' => icct_backend_nd_lldp_identity($sub, $id),
            'label' =>
                $interfaces[$ifindex]['name'] ?? '' ?: icct_backend_nd_lldp_label($sub, $id, 3),
            'ifindex' => $ifindex
        ];
    }
    // Detect incomplete rows as an error instead of silently dropping their evidence.
    foreach (icct_backend_nd_table_indices($values, $remote, 3) as $index) {
        $parts = explode('.', $index);
        $number = $parts[1];
        if (!isset($ports[$number])) {
            throw new RuntimeException('LLDP neighbor references an unreadable local port.');
        }
        $chassis = icct_backend_nd_value($values, $remote . '.5.' . $index, 4);
        $peer = icct_backend_nd_lldp_identity(
            icct_backend_nd_value($values, $remote . '.4.' . $index, 2),
            $chassis
        );
        $remotePort = icct_backend_nd_value($values, $remote . '.7.' . $index, 4);
        $remoteKey = icct_backend_nd_lldp_identity(
            icct_backend_nd_value($values, $remote . '.6.' . $index, 2),
            $remotePort
        );
        $key = hash('sha256', $ports[$number]['key'] . '|' . $peer . '|' . $remoteKey);
        $neighbors[$key] = [
            'key' => $key,
            'local_key' => $ports[$number]['key'],
            'local_port' => $ports[$number]['label'],
            'local_ifindex' => $ports[$number]['ifindex'],
            'peer_key' => $peer,
            'peer_label' => icct_backend_nd_lldp_label(
                icct_backend_nd_value($values, $remote . '.4.' . $index, 2),
                $chassis,
                4
            ),
            'remote_key' => $remoteKey,
            'remote_port' => icct_backend_nd_lldp_label(
                icct_backend_nd_value($values, $remote . '.6.' . $index, 2),
                $remotePort,
                3
            ),
            'remote_name' => icct_backend_nd_octets(
                icct_backend_nd_value($values, $remote . '.9.' . $index, 4, false)
            ),
            'management_addresses' => $management['rows'][$index] ?? []
        ];
    }
    return [
        'identity' => $identity,
        'management_address_errors' => $management['errors'],
        'name' => icct_backend_nd_octets(icct_backend_nd_value($values, $local . '.3.0', 4, false)),
        'ports' => array_values($ports),
        'interfaces' => $interfaces,
        'neighbors' => $neighbors
    ];
}

/** Reused Inventory service: nd table indices. */
function icct_backend_nd_table_indices($values, $root, $parts)
{
    $indices = [];
    $prefix = $root . '.';
    foreach ($values as $oid => $v) {
        if (strpos($oid, $prefix) === 0) {
            $suffix = substr($oid, strlen($prefix));
            if (
                !preg_match(
                    '/^[0-9]+\.([0-9]+(?:\.[0-9]+){' . ($parts - 1) . '})$/D',
                    $suffix,
                    $match
                )
            ) {
                throw new RuntimeException('Malformed table row: ' . $oid);
            }
            $indices[$match[1]] = true;
        }
    }
    return array_keys($indices);
}

/** Reused Inventory service: nd value. */
function icct_backend_nd_value($values, $oid, $type, $required = true)
{
    if (!isset($values[$oid])) {
        if (!$required) {
            return null;
        }
        throw new RuntimeException(
            'Required MIB object is absent or excluded by the SNMP view: ' . $oid
        );
    }
    $v = $values[$oid];
    if ((int) $v['type'] !== $type) {
        throw new RuntimeException('Unexpected ASN.1 type at ' . $oid);
    }
    return $v['value'];
}
