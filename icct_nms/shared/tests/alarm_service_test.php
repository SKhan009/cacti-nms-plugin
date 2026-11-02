<?php
require __DIR__.'/../../alarms/services/alarm_service.php';
function ok($condition,$message){if(!$condition)throw new RuntimeException($message);}
$a=icct_nms_alarm_transition([],['state'=>'Major']);ok($a['state']==='Active'&&$a['occurrences']===1&&$a['event']==='Raised','First occurrence must raise once');
$prev=['state'=>'Active','severity'=>'Major','occurrences'=>1];
$a=icct_nms_alarm_transition($prev,['state'=>'Major']);ok($a['occurrences']===1&&$a['event']==='','Repeated polls must not inflate occurrences');
ok(icct_nms_alarm_transition($prev,['state'=>'Unknown'])===null,'Missing data must not recover alarms');
$a=icct_nms_alarm_transition($prev,['state'=>'Normal']);ok($a['state']==='Resolved'&&$a['event']==='Resolved','Fresh normal observation resolves');
$a=icct_nms_alarm_transition(array_replace($prev,['state'=>'Resolved']),['state'=>'Critical']);ok($a['occurrences']===2&&$a['raised'],'Recurrence counts a new activation');
$a=icct_nms_alarm_transition(array_replace($prev,['state'=>'Closed']),['state'=>'Major']);ok($a['state']==='Closed'&&!$a['raised'],'Manual closure survives an unchanged condition');
ok(icct_nms_alarm_transition([],['state'=>'Normal'])===null,'Normal ports must not create alarm incidents');
$host=['id'=>2,'hostname'=>'127.0.0.1'];$rule=['source'=>'snmp','name'=>'Battery','oid'=>'1.3.6.1.4.1.9.0'];$observation=['rule'=>0,'graph_id'=>0,'graph'=>'Battery'];
$key=icct_nms_alarm_key($host,$rule,$observation);ok($key===icct_nms_alarm_key($host,$rule,array_replace($observation,['rule'=>9])),'Reordering device rules must not lose workflow identity');
ok($key!==icct_nms_alarm_key(array_replace($host,['id'=>3]),$rule,$observation),'Alarm identity must be device-specific');
echo "PASS: durable alarm activation, recovery, missing observations, recurrence and stable device ownership.\n";
$events=[
 ['event'=>'Current observation','note'=>'','actor_id'=>0,'snapshot'=>['acknowledged'=>0]],
 ['event'=>'Raised','note'=>'Major','actor_id'=>0,'snapshot'=>[]],
 ['event'=>'Details updated','note'=>'Major','actor_id'=>0,'snapshot'=>['acknowledged'=>1]],
 ['event'=>'Acknowledged','note'=>'Investigating supply failure','actor_id'=>1,'snapshot'=>['acknowledged'=>1]],
 ['event'=>'Raised','note'=>'Major','actor_id'=>0,'snapshot'=>[]]
];
$history=icct_nms_alarm_history_remarks($events);
ok($history[2]['snapshot']['ack_note']==='Investigating supply failure','Known acknowledgement remarks must accompany later snapshots');
ok(!isset($history[0]['snapshot']['ack_note']),'An unacknowledged recurrence must not inherit old remarks');
ok($events[2]['snapshot']===['acknowledged'=>1],'Read-time enrichment must not rewrite stored snapshots');
echo "PASS: acknowledgement remarks stay within their activation and preserve historical content.\n";
