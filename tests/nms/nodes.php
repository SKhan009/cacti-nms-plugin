<?php
/** Node state and validation regressions, independent of live Cacti state. */
require __DIR__.'/../../plugins/nms/includes/nodes/service.php';
function check($ok,$message) { if (!$ok) throw new RuntimeException($message); }
$now=time();
$up=['status'=>3,'disabled'=>'','last_updated'=>date('Y-m-d H:i:s',$now)];
$down=array_replace($up,['status'=>1]);$disabled=array_replace($up,['disabled'=>'on']);$stale=array_replace($up,['last_updated'=>date('Y-m-d H:i:s',$now-900)]);
foreach ([[[],'Empty'],[[$up,$up],'Up'],[[$up,$down],'Degraded'],[[$up,$stale],'Unknown'],[[$disabled],'Disabled'],[[$disabled,$stale],'Unknown']] as [$devices,$expected]) check(nms_node_health($devices,$now-600)['state']===$expected,'Incorrect node state: '.$expected);
check(nms_node_health([$up,$down,$disabled,$stale],$now-600)['counts']===['Up'=>1,'Down'=>1,'Recovering'=>0,'Unknown'=>1,'Disabled'=>1],'Incorrect counts');
foreach (['-1','1.2','abc',[],null,'1 OR 1=1'] as $bad) { try {nms_node_id($bad);throw new RuntimeException('Invalid ID accepted');} catch(InvalidArgumentException $e){} }
check(nms_node_id('0')===0,'Explicit Unassigned rejected');
echo "PASS: empty, healthy, degraded, stale and disabled summaries; exact counts; strict IDs\n";
