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
if(icct_backend_identity_local_endpoint('127.0.0.1',1161))throw new RuntimeException('Alternate SNMP endpoint must not inherit server MAC');
function read_config_option($key){return 300;}
$host=['id'=>14,'hostname'=>'127.0.0.1','snmp_version'=>2,'snmp_port'=>1161,'snmp_context'=>'','host_template_id'=>1,'poller_id'=>1,'snmp_username'=>'','snmp_community'=>'test','snmp_password'=>'','snmp_priv_passphrase'=>''];
$snapshot=json_encode(['signature'=>icct_backend_identity_signature($host),'time'=>time(),'values'=>['mac_address'=>'02:00:00:00:ff:01','serial_number'=>'SIM-CORE-001']]);
function db_fetch_assoc_prepared($sql,$args){global $host,$snapshot;return str_contains($sql,'FROM host')?[$host]:[['meta_key'=>'identity_snapshot_14','meta_value'=>$snapshot]];}
$rows=icct_backend_identity_inventory_rows([['id'=>14,'manual_serial_number'=>'','mac_address'=>'','chassis_id'=>'']]);
expect_mac($rows[0]['mac_address'],'02:00:00:00:ff:01');
if($rows[0]['serial_number']!=='SIM-CORE-001'||isset($rows[0]['snmp_community']))throw new RuntimeException('List identity missing or credentials exposed');
$rows=icct_backend_identity_inventory_rows([['id'=>14,'manual_serial_number'=>'MANUAL','mac_address'=>'02:00:00:00:00:01','chassis_id'=>'']]);
if($rows[0]['serial_number']!=='MANUAL')throw new RuntimeException('Manual serial overwritten');
expect_mac($rows[0]['mac_address'],'02:00:00:00:00:01');
$changed=$host;$changed['snmp_port']=161;
if(icct_backend_identity_snapshot_values($changed,$snapshot))throw new RuntimeException('Wrong endpoint snapshot accepted');
$old=json_decode($snapshot,true);$old['time']=time()-601;
if(icct_backend_identity_snapshot_values($host,json_encode($old)))throw new RuntimeException('Stale identity accepted');
echo "PASS: Inventory list consumes fresh protocol IDs, preserves manual values, rejects stale/wrong endpoint observations and excludes credentials.\n";
