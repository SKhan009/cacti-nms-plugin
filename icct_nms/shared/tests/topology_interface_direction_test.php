<?php
require __DIR__ . '/../../ports/services/backend/interface_rates.php';
require __DIR__ . '/../../dashboard/topology/services/topology_view_service.php';
$links=[];
icct_nms_topology_store_link($links, [
    'source'=>9,'target'=>2,'source_port'=>'Gi1/0/24','target_port'=>'enp0s8',
    'source_ifindex'=>24,'target_ifindex'=>2,
    'source_identity'=>str_repeat('a',64),'target_identity'=>str_repeat('b',64),
    'label'=>'Ethernet · Gi1/0/24 / enp0s8',
]);
$link=reset($links);
if ($link['source']!==2 || $link['source_ifindex']!==2 || $link['source_identity']!==str_repeat('b',64) ||
    $link['target']!==9 || $link['target_ifindex']!==24 || $link['target_identity']!==str_repeat('a',64)) {
    throw new RuntimeException('Canonical topology direction must keep each interface identity with its device.');
}
echo "PASS: Canonical link direction retains both endpoint indexes and identities.\n";
