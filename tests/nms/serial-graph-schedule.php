<?php
require __DIR__.'/../../plugins/nms/includes/configuration/graph_schedule.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$profiles=[['id'=>3,'step'=>60,'heartbeat'=>600],['id'=>1,'step'=>300,'heartbeat'=>600,'default'=>'on'],['id'=>2,'step'=>30,'heartbeat'=>1200],['id'=>4,'step'=>600,'heartbeat'=>1200]];
check(nms_config_graph_profile($profiles,60,300)['id']===1,'Fast readings must not select an unschedulable 60-second graph');
check(nms_config_graph_profile($profiles,600,300)['id']===4,'Compatible slower schedule lost');
check(nms_config_graph_profile($profiles,60,60)['id']===3,'Native fast poller must retain its fast profile');
$profiles[]=['id'=>5,'step'=>450,'heartbeat'=>900];
check(nms_config_graph_profile($profiles,450,300)['id']===1,'Nonmultiple schedule accepted');
foreach([[['id'=>1,'step'=>60,'heartbeat'=>600]],[['id'=>1,'step'=>300,'heartbeat'=>100]]] as $bad){
    try { nms_config_graph_profile($bad,60,300);throw new LogicException('Invalid schedule accepted'); }
    catch(RuntimeException $e){}
}
echo "PASS: fast reader / slow Cacti poller regression, native fast polling, interval multiples and heartbeat validation\n";
