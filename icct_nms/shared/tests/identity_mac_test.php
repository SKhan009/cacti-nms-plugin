<?php
require __DIR__ . '/../../inventory/services/backend/identity.php';
function expect_mac($actual,$expected){if($actual!==$expected)throw new RuntimeException('Unexpected MAC selection: '.$actual);}
$routes="Iface Destination Gateway Flags RefCnt Use Metric Mask\nlo 00000000 00000000 0001 0 0 0 00000000\neth0 00000000 0100000A 0003 0 0 100 00000000\neth1 00000000 0100000A 0003 0 0 200 00000000\n";
$addresses=['lo'=>'00:00:00:00:00:00','eth0'=>'08:00:27:91:b2:51','eth1'=>'02:00:00:00:00:02'];
$read=function($name)use($addresses){return $addresses[$name]??'';};
expect_mac(icct_backend_identity_route_mac($routes,$read),'08:00:27:91:b2:51');
expect_mac(icct_backend_identity_route_mac(str_replace('0 0 200','0 0 100',$routes),$read),'');
expect_mac(icct_backend_identity_route_mac('',$read),'');
expect_mac(icct_backend_identity_mac('Hex-STRING: 08 00 27 91 B2 51'),'08:00:27:91:b2:51');
expect_mac(icct_backend_identity_mac('ff:ff:ff:ff:ff:ff'),'');
if(!icct_backend_identity_local_endpoint('localhost')||!icct_backend_identity_local_endpoint('::1')||icct_backend_identity_local_endpoint('192.0.2.1'))throw new RuntimeException('Local endpoint mismatch');
echo "PASS: local interface selection, ambiguous routes, MAC normalization and remote endpoint isolation.\n";
