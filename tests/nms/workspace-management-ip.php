<?php
require __DIR__.'/../../plugins/nms/includes/workspace/management_ip.php';
function verify($ok,$m){if(!$ok)throw new RuntimeException($m);echo "PASS: $m\n";}
$h=['id'=>2,'hostname'=>'192.0.2.1','poller_id'=>1,'site_id'=>1,'snmp_context'=>''];
$a=['address'=>'192.0.2.2','type'=>1,'status'=>1,'zone'=>0,'ifindex'=>2,'source'=>'ipAddressTable'];
$d=['own_addresses'=>[$a,array_replace($a,['address'=>'192.0.2.1'])],'hardware'=>['chassis'=>[['serial'=>'SERIAL1','model'=>'router']]]];
$s=['host_id'=>2,'valid'=>true,'status'=>'success','protocol'=>'identity','succeeded_at'=>'2026-09-27 00:00:00','data'=>$d];
$p=nms_workspace_management_proposals($h,$s);
verify(count($p)===1 && $p[0]['old_address']==='192.0.2.1' && $p[0]['target']==='192.0.2.2','Proposes alternative own address while retaining original management address');
verify($p[0]['eligible'] && $p[0]['ifindex']===2,'Proposal retains interface and eligibility evidence');
verify(!nms_workspace_management_proposals($h,array_replace($s,['status'=>'stale'])),'Stale collection cannot suggest a current change');
verify(!nms_workspace_management_proposals($h,array_replace($s,['valid'=>false])),'Changed settings invalidate suggestions');
verify(!nms_workspace_management_proposals($h,array_replace($s,['protocol'=>'arp'])),'ARP neighbours are never management-address proposals');
foreach([['type'=>2],['status'=>2],['zone'=>4]] as $change){$x=$s;$x['data']['own_addresses']=[array_replace($a,$change)];verify(!nms_workspace_management_proposals($h,$x)[0]['eligible'],'Shared/deprecated/scoped evidence is not eligible');}
$x=$s;$x['data']['own_addresses']=[$a,$a];verify(count(nms_workspace_management_proposals($h,$x))===1,'Duplicate interface observations yield one target');
verify(nms_workspace_management_identity_matches($p[0],$d),'Matching chassis plus reported target supports identity verification');
$x=$d;$x['hardware']['chassis'][0]['serial']='OTHER';verify(!nms_workspace_management_identity_matches($p[0],$x),'Conflicting serial blocks an IP switch');
$x=$d;$x['own_addresses']=[];verify(!nms_workspace_management_identity_matches($p[0],$x),'Matching chassis alone without reported target is insufficient');
$x=$s;$x['data']['own_addresses']=[array_replace($a,['address'=>'2001:db8::2'])];verify(nms_workspace_management_proposals($h,$x)[0]['target']==='2001:db8::2','IPv6 target can be proposed');
$x=$d;$x['own_addresses'][0]['status']=2;verify(!nms_workspace_management_identity_matches($p[0],$x),'Deprecated target returned during verification cannot authorize apply');
echo "14 proposal/identity assertions passed; no polling address changed.\n";
