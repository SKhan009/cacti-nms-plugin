<?php
require __DIR__.'/../includes/services/ports.php';
$snapshot=[]; $enabled=true;
function db_fetch_cell_prepared($sql,$args) { global $snapshot; return json_encode($snapshot); }
function read_config_option($key) { return 300; }
function icct_backend_protocol_enabled($id,$protocol) { global $enabled; return $enabled; }
function expectPorts($condition,$message) { if (!$condition) throw new Exception($message); }
$ports=icct_backend_ports_parse(['index'=>[1=>'1',2=>'2',3=>'99'],'name'=>[1=>'"Gi1/0/1"',2=>'Vlan10'],'admin'=>[1=>'up',2=>'down(2)'],'oper'=>[1=>'up(1)',2=>'2'],'connector'=>[1=>'true',2=>'false']]);
expectPorts(count($ports)===2 && $ports[0]['name']==='Gi1/0/1' && $ports[0]['connector']===1 && $ports[1]['connector']===2,'IF-MIB parsing or connector classification failed');
expectPorts(icct_backend_ports_status($ports[0],true)==='In use','Live link not in use');
expectPorts(icct_backend_ports_status($ports[1],true)==='Disabled','Admin-down interface marked available');
expectPorts(icct_backend_ports_status(['admin'=>1,'oper'=>2],true)==='Available (link down)','Enabled down link not available');
expectPorts(icct_backend_ports_status(['admin'=>1,'oper'=>5],true)==='Unknown','Dormant interface misreported');
$host=['id'=>2,'hostname'=>'test-host','poller_id'=>1,'disabled'=>'','snmp_version'=>2,'snmp_community'=>'test'];
$snapshot=['time'=>time(),'signature'=>icct_backend_ports_signature($host),'ports'=>$ports,'error'=>''];
expectPorts(icct_backend_ports_view($host)['fresh'],'Fresh observation rejected');
$snapshot['time']=time()-601;
expectPorts(!icct_backend_ports_view($host)['fresh'] && icct_backend_ports_view($host)['ports'][0]['status']==='Unknown','Stale status retained');
$snapshot['time']=time();
expectPorts(icct_backend_ports_view(array_replace($host,['hostname'=>'other']))['ports']===[],'Different endpoint leaked old ports');
expectPorts(icct_backend_ports_view(array_replace($host,['snmp_community'=>'other']))['ports']===[],'Changed credentials retained old ports');
$enabled=false;
expectPorts(!icct_backend_ports_view($host)['fresh'],'Disabled protocol reported live');
$enabled=true;$snapshot['error']='Interface discovery failed.';
expectPorts(!icct_backend_ports_view($host)['fresh'],'Failed collection reported live');
echo "Port parsing, link states, physical connectors, stale observations and device isolation passed.\n";

$extended=icct_backend_ports_parse(['index'=>['7'=>'7'],'name'=>['7'=>'Gi1/0/7'],'last_change'=>['7'=>'Timeticks: (12345) 0:02:03.45'],'bridge_ifindex'=>['2'=>'7'],'pvid'=>['2'=>'49'],'high_speed_mbps'=>['7'=>'10000']]);
if($extended[0]['last_change_ticks']!==12345||$extended[0]['bridge_port']!==2||$extended[0]['vlan_id']!==49||$extended[0]['high_speed_mbps']!==10000)throw new RuntimeException('Extended interface parsing failed.');
if(icct_backend_ports_ticks('1 day, 01:02:03.45')!==9012345||icct_backend_ports_ticks('unavailable')!==null)throw new RuntimeException('TimeTicks parsing failed.');
echo "Interface speeds, bridge mapping, PVID and last-change TimeTicks passed.\n";
