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
$d=nms_diag_description(['tool'=>'netperf','exit'=>0,'output'=>"10^6bits/sec\n\n131072 16384 16384 3.00 70084.13"]);
check($d['metrics']['Duration']==='3.00 seconds');
check($d['metrics']['Data received']==='Not reported');
check($d['metrics']['Receive socket buffer']==='131072 bytes');
check($d['tone']==='success');
$d=nms_diag_description(['tool'=>'ping','exit'=>0,'output'=>'4 packets transmitted, 3 received, 25% packet loss']); check($d['tone']==='warning');
$d=nms_diag_description(['tool'=>'netperf','exit'=>1,'output'=>'Connection refused']); check($d['tone']==='error');
$d=nms_diag_description(['tool'=>'iperf3','exit'=>0,'output'=>json_encode(['start'=>['test_start'=>['protocol'=>'TCP']], 'end'=>['sum_sent'=>['bytes'=>100,'seconds'=>3,'bits_per_second'=>800], 'sum_received'=>['bytes'=>90,'seconds'=>3,'bits_per_second'=>720]]])]);
check($d['metrics']['Data sent']==='100 bytes'); check($d['metrics']['Data received']==='90 bytes'); check($d['tone']==='success');
echo "Structured description checks passed\n";
$d=nms_diag_description(['tool'=>'pathchar','exit'=>0,'output'=>"Path length: 1 hops\nPath char: rtt = 0.190457 ms\nStart time: Mon Sep 21 03:36:21 2026\nEnd time: Mon Sep 21 03:36:29 2026"]);
check($d['metrics']['Duration']==='8 seconds'); check($d['metrics']['Path length']==='1 hops');
echo "Pathchar duration checks passed\n";
