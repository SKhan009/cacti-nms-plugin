<?php
/** LLDP discovery parsing. */

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
