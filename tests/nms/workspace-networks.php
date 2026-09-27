<?php
require __DIR__.'/../../plugins/nms/includes/workspace/networks.php';
function verify($ok,$message) {if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
function reject($fn,$message) {try{$fn();}catch(InvalidArgumentException $e){echo "PASS: $message\n";return;}throw new RuntimeException($message);}
$input=['name'=>'Test','subnet_range'=>'192.0.2.0/24','poller_id'=>1,'site_id'=>0,'snmp_id'=>1,'sched_type'=>2,'start_at'=>'2026-09-25 12:00:00','recur_every'=>1,'threads'=>10,'run_limit'=>1200];
$count=static function($range){return $range==='192.0.2.0/24'?254:false;};
$valid=nms_workspace_network_validate($input,$count);
verify($valid['total_ips']===254 && $valid['enabled']==='' && $valid['add_to_cacti']==='','New networks default disabled and reviewed addition');
foreach(['poller_id'=>'1 OR 1','threads'=>51,'run_limit'=>0,'start_at'=>'2026-02-30 00:00:00','subnet_range'=>'bad','enabled'=>['on'],'name'=>str_repeat('x',129),'sched_type'=>3] as $key=>$value)reject(fn()=>nms_workspace_network_validate(array_replace($input,[$key=>$value]),$count),'Reject invalid '.$key);
$weekly=nms_workspace_network_validate(array_replace($input,['sched_type'=>3,'day_of_week'=>'7,2,2']),$count);
verify($weekly['day_of_week']==='2,7','Weekly schedule normalized');
reject(fn()=>nms_workspace_network_validate(array_replace($input,['sched_type'=>5,'month'=>'1','monthly_week'=>'4','monthly_day'=>'2']),$count),'Native week enum validated');
verify(nms_workspace_network_revision($valid)===nms_workspace_network_revision(array_replace($valid,['last_status'=>'Running','up_hosts'=>2])),'Runtime scan progress does not invalidate settings revision');
verify(nms_workspace_network_revision($valid)!==nms_workspace_network_revision(array_replace($valid,['subnet_range'=>'192.0.3.0/24'])),'Range changes invalidate stale forms');
