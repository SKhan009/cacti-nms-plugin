<?php
require_once __DIR__.'/../../plugins/nms/includes/topology/connections.php';
require_once __DIR__.'/../../plugins/nms/includes/discovery_neighbors.php';
function iftype_check($ok,$message) { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
$raw=['1.3.6.1.2.1.2.2.1.1.1'=>['type'=>2,'value'=>1], '1.3.6.1.2.1.2.2.1.3.1'=>['type'=>2,'value'=>6]];
iftype_check(nms_nd_interfaces($raw)[1]['if_type']===6,'parse real ifType');
unset($raw['1.3.6.1.2.1.2.2.1.3.1']);
iftype_check(nms_nd_interfaces($raw)[1]['if_type']===null,'missing type stays unknown');
$link=['a'=>1,'b'=>2,'a_ifindex'=>1,'b_ifindex'=>2,'current'=>false,'state'=>'Historical / stale','protocols'=>['LLDP'=>true],'connection_type'=>'VSAT / Leased-line'];
$nodes=[['id'=>1,'interfaces'=>[['index'=>1,'if_type'=>6]]],['id'=>2,'interfaces'=>[['index'=>2,'if_type'=>6]]]];
$result=nms_connection_detect_interfaces([$link],$nodes)[0];
iftype_check($result['detected_type']==='Ethernet — detected','Ethernet from both exact interfaces');
iftype_check($result['state']===$link['state'] && $result['current']===false && $result['connection_type']===$link['connection_type'],'preserve stale status and assigned transport');
$nodes[1]['interfaces'][0]['if_type']=71;
iftype_check(str_contains(nms_connection_detect_interfaces([$link],$nodes)[0]['detected_type'],'B: Wi-Fi'),'mixed endpoints are explicit');
$nodes[1]['interfaces']=[];
iftype_check(str_contains(nms_connection_detect_interfaces([$link],$nodes)[0]['detected_type'],'other endpoint unknown'),'one-sided evidence is explicit');
$link['a_ifindex']=0; $link['a_port']='GigabitEthernet0/1';
iftype_check(nms_connection_detect_interfaces([$link],$nodes)[0]['detected_type']==='Unknown','no guessing from interface name or protocol');
foreach ([23=>'PPP',135=>'VLAN (802.1Q)',131=>'Tunnel',161=>'Link aggregation (LAG)'] as $type=>$label) iftype_check(nms_connection_iftype_label($type)===$label,$label);
iftype_check(nms_connection_iftype_label(99999)===null,'unsupported type stays unknown');
