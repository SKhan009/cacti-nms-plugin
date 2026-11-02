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

$ticketAlarm=['name'=>'Supply lost','device_name'=>'Core SW','severity'=>'Major'];
$ticketEvents=[['event'=>'Ticket reopened','snapshot'=>['ticket'=>['name'=>'Saved ticket','severity'=>'Medium','remarks'=>'Initial investigation']],'created_epoch'=>4,'note'=>'Rechecking'],['event'=>'Ticket resolved','snapshot'=>[],'created_epoch'=>3,'note'=>'Replaced supply'],['event'=>'Ticket progress','snapshot'=>[],'created_epoch'=>2,'note'=>'Inspecting'],['event'=>'Escalated','snapshot'=>[],'created_epoch'=>1,'note'=>'Initial investigation'],['event'=>'Raised']];
$ticket=icct_nms_alarm_ticket($ticketAlarm,$ticketEvents);
ok(array_column($ticket['timeline'],'kind')===['reopen','resolved','progress','opened'],'Timeline order and state classes must match the saved workflow');
ok($ticket['details']['name']==='Saved ticket'&&$ticket['details']['severity']==='Medium','Saved ticket metadata must survive alarm severity/name changes');
$ticket=icct_nms_alarm_ticket($ticketAlarm,array_slice($ticketEvents,3));
ok($ticket['details']['name']==='Supply lost - Core SW'&&$ticket['details']['severity']==='High','Legacy escalation details must derive from the linked alarm');
ok(icct_nms_alarm_ticket($ticketAlarm,[['event'=>'Raised'],...$ticketEvents])['timeline']===[],'A new activation must not show the previous escalation');
echo "PASS: immutable ticket details, legacy escalation compatibility and activation-scoped status timeline.\n";
