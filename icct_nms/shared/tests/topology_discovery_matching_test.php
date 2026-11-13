<?php

require __DIR__ . '/../../dashboard/topology/services/topology_configuration_service.php';

function icct_backend_nd_hosts()
{
    return array_map(
        fn($id) => ['id' => $id, 'enabled' => 1, 'collection_enabled' => 1, 'stale_seconds' => 900],
        [1, 2, 3, 4],
    );
}

function icct_backend_nd_host_methods($host)
{
    return ['arp', 'fdb', 'lldp'];
}

function icct_backend_nd_hash($host)
{
    return 'config';
}

function db_fetch_assoc_prepared($sql, $args)
{
    return $GLOBALS['snapshots'][$args[0]] ?? [];
}

function snapshot($protocol, $items, $time)
{
    return [
        'protocol' => $protocol,
        'status' => 'success',
        'succeeded_at' => $time,
        'config_hash' => 'config',
        'data_json' => json_encode([$protocol === 'lldp' ? 'neighbors' : 'endpoints' => $items]),
    ];
}

function check($condition, $message)
{
    if (!$condition) {
        throw new LogicException($message);
    }
}

$current = date('Y-m-d H:i:s');
$stale = date('Y-m-d H:i:s', time() - 1800);
$mac = '02:11:22:33:44:55';
$learned = ['mac' => $mac, 'interface' => 'Gi1/1', 'status' => 3];
$snapshots = [
    1 => [
        snapshot(
            'arp',
            [
                ['ip' => '192.0.2.10', 'mac' => '02-11-22-33-44-55', 'interface' => 'Vlan10'],
                ['ip' => '2001:db8::10', 'mac' => $mac],
                ['ip' => '192.0.2.20', 'mac' => '02:11:22:33:44:20'],
                ['ip' => '192.0.2.30', 'mac' => '00:00:00:00:00:00'],
            ],
            $current,
        ),
        snapshot('lldp', [['remote_name' => 'Peer', 'mac' => $mac]], $current),
    ],
    2 => [
        snapshot(
            'fdb',
            [
                $learned,
                $learned + ['fdb_id' => 10],
                array_replace($learned, ['interface' => 'Gi1/2']),
                array_replace($learned, ['interface' => 'Gi1/3', 'present' => false]),
                array_replace($learned, ['mac' => '02:11:22:33:44:20', 'status' => 4]),
                array_replace($learned, ['mac' => '02:11:22:33:44:40']),
            ],
            $stale,
        ),
    ],
    3 => [
        snapshot(
            'fdb',
            [array_replace($learned, ['interface' => '', 'bridge_port' => 5])],
            $current,
        ),
    ],
    4 => [snapshot('fdb', [array_replace($learned, ['interface' => 'Hidden'])], $current)],
];
$observations = icct_nms_topology_discovery_rows([
    ['id' => 1, 'description' => 'Router'],
    ['id' => 2, 'description' => 'Switch A'],
    ['id' => 3, 'description' => 'Switch B'],
]);
$rows = icct_nms_match_discovery_observations($observations);
$byName = array_column($rows, null, 'name');

foreach (['192.0.2.10', '2001:db8::10'] as $ip) {
    $row = $byName[$ip];
    $matches = $row['fdb_matches'];
    check($row['address'] === $ip && $row['reporter'] === 'Router', 'ARP address or reporter lost');
    check($row['status'] === 'Current' && $row['last_check'] === $current, 'ARP freshness lost');
    check(
        array_column($matches, 'learned_port') === ['Gi1/1', 'Gi1/2', 'Bridge port 5'],
        'Incorrect learned ports',
    );
    check(
        array_column($matches, 'reporter') === ['Switch A', 'Switch A', 'Switch B'],
        'FDB reporters lost',
    );
    check(array_column($matches, 'status') === ['Stale', 'Stale', 'Current'], 'FDB freshness lost');
    check(
        array_column($matches, 'last_check') === [$stale, $stale, $current],
        'FDB timestamps lost',
    );
}
check($byName['192.0.2.10']['port'] === 'Vlan10', 'ARP interface replaced by a switch port');
check(
    str_contains($byName['192.0.2.20']['detail'], 'no matching learned port'),
    'ARP without a learned port lost',
);
check(
    str_contains($byName['192.0.2.30']['detail'], 'no usable IP-to-MAC mapping'),
    'Invalid ARP MAC matched',
);
check(
    str_contains($byName['02:11:22:33:44:20']['detail'], 'no learned port available'),
    'Non-learned FDB row lost',
);
check(
    str_contains($byName['02:11:22:33:44:40']['detail'], 'no matching IP observation'),
    'FDB-only observation lost',
);
check(!isset($byName[$mac]), 'Matched FDB rows repeated as standalone observations');
check(
    $byName['Peer'] === array_column($observations, null, 'name')['Peer'],
    'LLDP observation changed',
);

echo "Discovered Devices MAC matching, reporter context, freshness and unmatched observations passed.\n";
