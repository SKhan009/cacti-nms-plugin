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
