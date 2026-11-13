<?php
require __DIR__ . '/../../protocols/netflow/services/netflow_collector.php';
$a = ['version'=>9,'src_addr'=>'10.0.0.1','dst_addr'=>'10.0.0.2','src_port'=>80,'dst_port'=>60000,'protocol'=>6,'tcp_flags'=>'A','input_if'=>1,'output_if'=>2,'bytes'=>100,'packets'=>2,'first_seen'=>'2026-11-13 10:00:00','last_seen'=>'2026-11-13 10:00:05','sampled'=>0];
$b = array_replace($a, ['src_addr'=>'10.0.0.2','dst_addr'=>'10.0.0.1','src_port'=>60000,'dst_port'=>80]);
$key = icct_nms_netflow_payload_key(2,[$a,$b]);
if ($key !== icct_nms_netflow_payload_key(2,[$b,array_replace($a,['application'=>'HTTP','application_basis'=>'port-based service'])])) throw new Exception('Replay changed identity');
foreach ([icct_nms_netflow_payload_key(3,[$a,$b]), icct_nms_netflow_payload_key(2,[$a,$a]), icct_nms_netflow_payload_key(2,[$a]), icct_nms_netflow_payload_key(2,[$a,array_replace($b,['bytes'=>101])]), icct_nms_netflow_payload_key(2,[$a,array_replace($b,['last_seen'=>'2026-11-13 10:00:06'])])] as $other) {
    if ($key === $other) throw new Exception('Distinct traffic collapsed');
}
echo "Capture replay, enrichment, ordering, device scope, direction, multiplicity and counters passed.\n";
