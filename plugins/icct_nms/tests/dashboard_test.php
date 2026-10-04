<?php
require __DIR__.'/../includes/dashboard_service.php';
function verify($condition){if(!$condition)throw new RuntimeException('Dashboard assertion failed.');}
$valid=['selected'=>0,'dashboards'=>[['widgets'=>['topology','birds','alarms']]]];verify(icct_nms_dashboard_validate($valid)===$valid);
foreach([['selected'=>0,'dashboards'=>[]],['selected'=>1,'dashboards'=>[['widgets'=>[]]]],['selected'=>0,'dashboards'=>array_fill(0,6,['widgets'=>[]])],['selected'=>0,'dashboards'=>[['widgets'=>['birds','birds']]]],['selected'=>0,'dashboards'=>[['widgets'=>['unknown']]]],['selected'=>0,'dashboards'=>[['widgets'=>[[]]]]]] as $bad){try{icct_nms_dashboard_validate($bad);throw new RuntimeException('Invalid layout accepted');}catch(InvalidArgumentException $e){}}
function icct_nms_map_node_summary($site){return ['status'=>'Online'];}
$map=['unlocated'=>[['category'=>'Network','fault_counts'=>['Major'=>2,'Information'=>1]]],'sites'=>[['id'=>4,'name'=>'Node A','coordinates'=>[12,77],'devices'=>[['category'=>'Computers','fault_counts'=>['Critical'=>1]]]]]];
$result=icct_nms_dashboard_readings($map);verify($result['total']===4&&$result['severity']['Major']===2&&$result['segments']['Network']===3&&count($result['nodes'])===1);
verify(icct_nms_dashboard_readings(['unlocated'=>[],'sites'=>[]])['total']===0);
verify($result['ack']['Ack']===null&&$result['escalation']['Not_Esc']===null);
$empty=icct_nms_dashboard_readings(['unlocated'=>[],'sites'=>[]]);verify($empty['ack']['Ack']===0&&$empty['escalation']['Esc']===0);
$expanded=['selected'=>0,'dashboards'=>[['widgets'=>['topology','birds','alarms','ack','escalation']]]];verify(icct_nms_dashboard_validate($expanded)===$expanded);
echo "Dashboard layout limits, known widgets, alarm aggregation and empty states passed\n";
