<?php
require __DIR__ . '/../../plugins/nms/includes/diagnostics.php';
require __DIR__ . '/../../plugins/nms/includes/diagnostic_iperf.php';
require __DIR__ . '/../../plugins/nms/includes/diagnostic_netperf.php';
foreach (['127.0.0.1'=>true,'127.2.3.4'=>true,'::1'=>true,'192.0.2.1'=>false,'localhost'=>false,'127.attacker.example'=>false] as $target=>$expected) {
    if ((bool)nms_diag_iperf_loopback($target)!==$expected) throw new RuntimeException('Loopback validation failed');
}
try {
    nms_diag_netperf_self_test(['/usr/bin/netperf','-H','192.0.2.1','-p','12865'],10);
    throw new LogicException('Remote target accepted');
} catch (RuntimeException $e) {
    if ($e instanceof LogicException || strpos($e->getMessage(),'loopback')===false) throw $e;
}
echo "PASS: loopback detection and remote-server rejection\n";
$interfaces = [['addr_info'=>[['local'=>'192.0.2.20'],['local'=>'2001:db8::2']]]];
foreach (['192.0.2.20'=>true,'192.0.2.21'=>false,'2001:db8:0:0:0:0:0:2'=>true,'localhost'=>false] as $address=>$expected) {
    if (nms_diag_address_in_interfaces($address,$interfaces)!==$expected) throw new RuntimeException('Interface matching failed');
}
echo "PASS: assigned IPv4/IPv6 matching; remote address rejected\n";
