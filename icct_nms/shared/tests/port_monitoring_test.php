<?php
require __DIR__ . '/../../ports/services/port_monitoring_service.php';
require __DIR__ . '/../../ports/services/backend/ports.php';
$rule=['name'=>'eth0','alarm'=>true,'cnms'=>true,'oper_severity'=>'Major','admin_severity'=>'Minor'];
$legacy=icct_nms_port_monitor_validate(['enabled'=>true,'ports'=>[null,null,$rule]],[2=>'eth0']);
if(array_keys($legacy['ports'])!==[2]||$legacy['ports'][2]!==$rule)throw new Exception('Sparse browser draft not recovered');
$settings=icct_nms_port_monitor_validate(['enabled'=>true,'ports'=>[2=>$rule]],[2=>'eth0']);
if($settings['ports'][2]!==$rule)throw new Exception('Port settings lost');
foreach([['admin'=>1,'oper'=>2,'fresh'=>true,'state'=>'Major'],['admin'=>1,'oper'=>7,'fresh'=>true,'state'=>'Major'],['admin'=>2,'oper'=>2,'fresh'=>true,'state'=>'Minor'],['admin'=>1,'oper'=>1,'fresh'=>true,'state'=>'Normal'],['admin'=>1,'oper'=>1,'fresh'=>false,'state'=>'Unknown'],['admin'=>1,'oper'=>4,'fresh'=>true,'state'=>'Unknown']] as $case)if(icct_nms_port_fault_state($case,$rule,$case['fresh'])!==$case['state'])throw new Exception('Incorrect port alarm state');
foreach([['name'=>'renamed'],['oper_severity'=>''],['admin_severity'=>'Bogus']] as $change){try{icct_nms_port_monitor_validate(['enabled'=>true,'ports'=>[2=>array_merge($rule,$change)]],[2=>'eth0']);throw new Exception('Invalid settings accepted');}catch(InvalidArgumentException $expected){}}
$ports=icct_backend_ports_linux_parse('[{"ifindex":1,"ifname":"lo","flags":["UP","LOOPBACK"],"operstate":"UNKNOWN","link_type":"loopback"},{"ifindex":2,"ifname":"eth0","flags":["UP"],"operstate":"DOWN","link_type":"ether"}]');
if(count($ports)!==2||$ports[0]['connector']!==2||$ports[0]['oper']!==4||$ports[1]['admin']!==1||$ports[1]['oper']!==2)throw new Exception('Linux interface status mismatch');
echo "PASS: port alarm priorities, clear/unknown states, identity binding, required severity and Linux interface parsing.\n";

$normal=icct_nms_port_alarm_transition([],'Normal','eth0');
$raised=icct_nms_port_alarm_transition($normal,'Major','eth0');
if($raised['event']!=='Raised'||$raised['active']!=='Major')throw new Exception('Raise not recorded');
$stale=icct_nms_port_alarm_transition($raised,'Unknown','eth0');
if($stale['active']!=='Major'||$stale['event']!=='Observation unavailable')throw new Exception('Stale reading falsely cleared fault');
$stillStale=icct_nms_port_alarm_transition($stale,'Unknown','eth0');
if($stillStale['changed'])throw new Exception('Repeated polls duplicated history');
$admin=icct_nms_port_alarm_transition($stale,'Minor','eth0');
if($admin['active']!=='Minor'||$admin['event']!=='Severity changed')throw new Exception('Configured admin severity not recorded');
$recovered=icct_nms_port_alarm_transition($admin,'Normal','eth0');
if($recovered['active']!==''||$recovered['event']!=='Cleared')throw new Exception('Recovery failed to clear');
if(icct_nms_port_alarm_transition($recovered,'Normal','eth0')['changed'])throw new Exception('Healthy polls duplicated clear');
$disabled=icct_nms_port_alarm_transition($raised,'Disabled','eth0');
if($disabled['event']!=='Monitoring disabled'||$disabled['active']!=='')throw new Exception('Disable incorrectly labelled recovery');
$renamed=icct_nms_port_alarm_transition($raised,'Unknown','renamed',true);
if($renamed['event']!=='Configuration changed'||$renamed['active']!=='')throw new Exception('Fault attributed to changed endpoint');
echo "PASS: raise, severity change, recovery, unknown retention, deduplication and configuration-change history.\n";
