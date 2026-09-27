<?php
/** Pure address planning tests. These tests send no packets. */
require __DIR__.'/../../plugins/nms/includes/workspace/scan_targets.php';
$checks=0;
function verify($ok,$message) {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;echo "PASS: $message\n";}
function reject($fn,$message) {try{$fn();}catch(InvalidArgumentException $e){verify(true,$message);return;}throw new RuntimeException('Unexpected acceptance: '.$message);}
$noV4=function(){throw new RuntimeException('Unexpected IPv4 expansion');};
verify(nms_scan_targets("2001:0db8::1,2001:db8:0:0::1\n192.0.2.1",$noV4)===['2001:db8::1','192.0.2.1'],'Canonical IPv6 duplicates collapse without losing IPv4');
verify(nms_scan_targets('2001:db8::7/126',$noV4)===['2001:db8::4','2001:db8::5','2001:db8::6','2001:db8::7'],'CIDR masks host bits before expansion');
verify(nms_scan_targets('2001:db8::ffff-2001:db8::1:1',$noV4)===['2001:db8::ffff','2001:db8::1:0','2001:db8::1:1'],'IPv6 range carries across byte boundaries');
verify(nms_scan_targets('::1/128',$noV4)===['::1'],'Explicit loopback remains available for collector acceptance tests');
verify(count(nms_scan_targets('2001:db8::/116',$noV4))===4096,'Largest supported IPv6 subnet is bounded at 4096');
foreach(['2001:db8::/64','2001:db8::/115','2001:db8::/129','2001:db8::/x','2001:db8::2-2001:db8::1','2001:db8::1-2001:db8::1001','fe80::1%eth0','fe80::1','ff02::1','255.255.255.255',"192.0.2.1\0"] as $text)reject(fn()=>nms_scan_targets($text,$noV4),'Reject invalid or unsupported target '.json_encode($text));
reject(fn()=>nms_scan_targets('2001:db8::/116,2001:db8::1:1',$noV4),'Combined unique targets cannot exceed the limit');
verify(count(nms_scan_targets('2001:db8::/116,2001:db8::1',$noV4))===4096,'Overlapping targets do not count twice');
verify(nms_scan_increment(str_repeat(chr(255),16))===null,'Packed-address overflow stops without wrapping');
verify(nms_scan_targets('192.0.2.254 - 192.0.3.1',$noV4)===['192.0.2.254','192.0.2.255','192.0.3.0','192.0.3.1'],'Full IPv4 range is inclusive across octet boundaries');
verify(count(nms_scan_targets('127.0.0.1-127.0.1.44',$noV4))===300,'Full IPv4 range supports more than 256 targets');
verify(count(nms_scan_targets('192.0.0.0-192.0.15.255',$noV4))===4096,'Full IPv4 range respects exact maximum');
foreach(['192.0.0.0-192.0.16.0','192.0.3.1-192.0.2.1','192.0.2.1-192.0.2.999','223.255.255.255-224.0.0.0'] as $text)reject(fn()=>nms_scan_targets($text,$noV4),'Reject invalid/oversized IPv4 range '.$text);
verify(nms_scan_targets('192.0.2.1-3',fn($range,$limit)=>['192.0.2.1','192.0.2.2','192.0.2.3'])===['192.0.2.1','192.0.2.2','192.0.2.3'],'Native abbreviated IPv4 syntax remains delegated');
// Deliberately independent reference ordering verifies every resume cursor, not just the first task.
$expected=[];
foreach(['192.0.2.1','2001:db8::1'] as $ip)foreach([['icmp',null],['tcp',80],['tcp',443],['snmp',null],['udp',80],['udp',443]] as $slot)$expected[]=['ip'=>$ip,'method'=>$slot[0],'port'=>$slot[1]];
foreach($expected as $cursor=>$task)verify(nms_scan_task(['192.0.2.1','2001:db8::1'],['icmp','tcp','snmp','udp'],[80,443],$cursor)===$task,'Resume cursor '.$cursor.' selects the expected probe');
verify(nms_scan_task(['192.0.2.1'],['icmp'],[],1)===null && nms_scan_task(['192.0.2.1'],['icmp'],[],-1)===null,'Completed or negative cursor never produces another probe');
echo "$checks assertions passed (planning only; no network probes).\n";
