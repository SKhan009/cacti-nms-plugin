<?php
require __DIR__ . '/../../plugins/nms/includes/diagnostic_summary.php';
function check($condition) { if (!$condition) throw new RuntimeException('Summary assertion failed'); }
$s = implode(' ', nms_diag_plain_summary(['tool'=>'ping','exit'=>0,'output'=>"4 packets transmitted, 3 received, 25% packet loss\nrtt min/avg/max/mdev = 1.0/2.0/3.0/0.1 ms"]));
check(str_contains($s, '3 of 4')); check(str_contains($s,'2.0 milliseconds'));
$s = implode(' ', nms_diag_plain_summary(['tool'=>'iperf3','exit'=>0,'self_test'=>true,'output'=>'Receiver throughput: 122,273.62 Mbit/s']));
check(str_contains($s,'122,273.62')); check(str_contains($s,'stayed inside'));
$s = implode(' ', nms_diag_plain_summary(['tool'=>'netperf','exit'=>0,'output'=>"10^6bits/sec\n\n87380 16384 16384 3.00 81060.11"]));
check(str_contains($s,'81060.11 megabits'));
$s = implode(' ', nms_diag_plain_summary(['tool'=>'iperf3','exit'=>1,'output'=>'Connection refused']));
check(str_contains($s,'could not connect')); check(!str_contains($s,'Measured receive speed'));
foreach (['ping','traceroute','arp','iperf3','netperf','pathchar'] as $tool) check(count(nms_diag_plain_summary(['tool'=>$tool,'exit'=>124,'output'=>''])) >= 2);
echo "Diagnostic summary checks passed\n";
