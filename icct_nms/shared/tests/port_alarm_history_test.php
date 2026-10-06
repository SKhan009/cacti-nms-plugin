<?php
require __DIR__.'/../../ports/services/port_monitoring_service.php';
$meta=[];$events=[];$host=['id'=>2,'hostname'=>'device.example','poller_id'=>1,'disabled'=>''];
$settings=['enabled'=>true,'ports'=>[2=>['name'=>'eth0','alarm'=>true,'cnms'=>false,'oper_severity'=>'Major','admin_severity'=>'Major']]];
$view=['ports'=>[['index'=>2,'name'=>'eth0','admin'=>1,'oper'=>1]],'fresh'=>true,'collected'=>time()];
function check($value,$message){if(!$value)throw new RuntimeException($message);}
function db_fetch_cell_prepared($sql,$args){if(str_contains($sql,'LOCK'))return 1;return $GLOBALS['meta'][$args[0]]??null;}
function icct_backend_category_execute($sql,$args){
 if(str_starts_with($sql,'INSERT INTO plugin_icct_nms_port_alarm_events'))$GLOBALS['events'][]=$args;
 elseif(str_starts_with($sql,'INSERT INTO plugin_icct_nms_meta'))$GLOBALS['meta'][$args[0]]=$args[1];
 elseif(!in_array($sql,['START TRANSACTION','COMMIT','ROLLBACK'],true))throw new Exception('Unexpected write');
}
function icct_backend_ports_view($host){return $GLOBALS['view'];}
$meta['port_monitoring_2']=json_encode($settings);
foreach([[1,1,true],[1,2,true],[1,2,true],[2,2,true],[2,2,false],[2,2,false],[1,1,true],[1,1,true]] as [$admin,$oper,$fresh]) {
 $view['ports'][0]['admin']=$admin;$view['ports'][0]['oper']=$oper;$view['fresh']=$fresh;
 icct_nms_record_port_alarms($host,$view,$settings);
 if(!$fresh)check(icct_nms_port_faults($host)[0]['state']==='Major','Missing reading falsely removed active severity');
}
check(array_column($events,3)===['Observed','Raised','Interface state changed','Observation unavailable','Cleared'],'Incorrect lifecycle or duplicate events');
check($events[1][7]==='Major'&&$events[3][7]==='Major'&&$events[4][7]==='','Incorrect logged severity');
check(icct_nms_port_faults($host)[0]['state']==='Normal','Recovered port remained active');
check(count($events)===5,'Clear deleted prior records');
check(array_unique(array_column($events,0))===[2],'History crossed devices');
$view['fresh']=false;$host['hostname']='another.example';
check(icct_nms_port_faults($host)[0]['state']==='Unknown','Old alarm attributed to a new device endpoint');
echo "Port alarm journaling, recovery, stale retention, raw-state changes and device isolation passed\n";
