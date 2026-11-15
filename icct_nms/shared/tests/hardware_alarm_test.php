<?php
require __DIR__ . '/../../ports/services/backend/hardware.php';
require __DIR__ . '/../../ports/services/port_monitoring_service.php';
function checkHardware($ok) { if (!$ok) throw new RuntimeException('Hardware alarm regression'); }
$inventory = ['ports'=>[['type'=>'USB','name'=>'1-2','status'=>'Enumerated']], 'fresh'=>true,'source'=>'SSH Linux hardware','collected'=>100];
$p = icct_nms_hardware_ports($inventory, ['ports'=>[]])[0];
$rule=['name'=>$p['name'],'alarm'=>true,'oper_severity'=>'Major','admin_severity'=>''];
$settings=['enabled'=>true,'ports'=>[$p['index']=>$rule]];
checkHardware(icct_nms_port_monitor_validate($settings,[$p['index']=>$p['name']])['ports'][$p['index']]['alarm']);
checkHardware(icct_nms_port_fault_state($p,$rule,true)==='Normal');
$inventory['ports']=[];
$missing=icct_nms_hardware_ports($inventory,$settings)[0];
checkHardware(icct_nms_port_fault_state($missing,$rule,true)==='Major');
$raised=icct_nms_port_alarm_transition([], 'Major', $p['name']);
checkHardware($raised['event']==='Raised');
checkHardware(icct_nms_port_alarm_transition($raised,'Unknown',$p['name'])['active']==='Major');
checkHardware(icct_nms_port_fault_state($missing,$rule,false)==='Unknown');
checkHardware(icct_nms_port_alarm_transition($raised,icct_nms_port_fault_state($p,$rule,true),$p['name'])['event']==='Cleared');
checkHardware(icct_nms_port_alarm_transition($raised,'Disabled',$p['name'])['active']==='');
$inventory['ports']=[['type'=>'Display connector','name'=>'HDMI-1','status'=>'disconnected']];
checkHardware(icct_nms_port_fault_state(icct_nms_hardware_ports($inventory,['ports'=>[]])[0],$rule,true)==='Major');
echo "Hardware alarm enable, removal, stale, recovery, disable and connector tests passed\n";
