<?php
/** CDP discovery parsing. */

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
