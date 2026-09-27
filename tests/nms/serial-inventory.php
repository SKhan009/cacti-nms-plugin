<?php
/** Summary regression: never use native no-ping Up as serial reply evidence. */
require __DIR__.'/../../plugins/nms/includes/functions.php';
foreach(['Unavailable','Stale','Read failed','Responding'] as $state) {
    $device=['id'=>1,'disabled'=>'','status'=>3,'last_updated'=>date('Y-m-d H:i:s'),'serial_monitoring'=>['status'=>$state]];
    $counts=nms_device_inventory_counts([$device]);
    if($counts!==['total'=>1,'enabled'=>1,'up'=>$state==='Responding'?1:0,'down'=>0]) throw new RuntimeException('Incorrect serial totals for '.$state);
    if(nms_device_status_name($device)!==($state==='Responding'?'Up':$state)) throw new RuntimeException('Row and summary disagree');
    $device['disabled']='on';
    if(nms_device_inventory_counts([$device])['up']!==0 || nms_device_status_name($device)!=='Disabled') throw new RuntimeException('Disabled serial device counted Up');
}
echo "PASS: serial dashboard totals match current/missing/stale/failed/disabled row states\n";

// Render the actual node template: aggregate labels must not inherit a member's
// serial state, and Disabled must take precedence over cached response evidence.
require __DIR__.'/../../plugins/nms/includes/nodes/service.php';
function html_escape($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function is_realm_allowed($realm) { return false; }
function read_config_option($name) { return $name==='poller_interval' ? 300 : null; }
set_error_handler(function($severity,$message,$file,$line) { throw new ErrorException($message,0,$severity,$file,$line); });
$members=[];
$serial_states=[];
foreach(['Responding','Stale','Read failed','Unavailable','Disabled','Native'] as $index=>$status) {
    $id=$index+1;
    $member=['id'=>$id,'description'=>'Fixture '.$status,'hostname'=>'fixture-'.$id,
        'site_id'=>1,'disabled'=>$status==='Disabled'?'on':'','status'=>3,
        'last_updated'=>date('Y-m-d H:i:s'),'diagnostic_profile'=>'','discovery_profile'=>'','graph_count'=>0];
    $serial_states[$id]=$status==='Native'?null:['status'=>$status==='Disabled'?'Responding':$status,'connection'=>'Serial fixture'];
    $member['serial_monitoring']=$serial_states[$id];
    $members[]=$member;
}
// Simulate a variable left in the controller scope by a prior member loop.
$device=$members[2];
$error='';$removing=false;$editing=false;$can_manage=false;$node_id=1;$alarms=[];
$selected=['name'=>'Fixture node','code'=>'FIXTURE','site_name'=>'Fixture site','description'=>'','site_id'=>1];
$health=nms_node_health($members,time()-600);
ob_start();
require __DIR__.'/../../plugins/nms/templates/devices/nodes.php';
$html=ob_get_clean();
restore_error_handler();
if(!preg_match('/<div class="nms-node-counts">(.*?)<\/div>/s',$html,$matches)) throw new RuntimeException('Node counts missing');
foreach(['Up'=>2,'Down'=>0,'Recovering'=>0,'Unknown'=>3,'Disabled'=>1] as $label=>$count) {
    if(strpos($matches[1],'<span>'.$label.' <strong>'.$count.'</strong></span>')===false) throw new RuntimeException('Wrong node summary: '.$label);
}
foreach(['Responding','Stale','Read failed','Unavailable','Disabled','Up'] as $index=>$status) {
    if(!preg_match('/<tr><td><a href="devices.php\?tab=edit&amp;id='.($index+1).'">.*?<\/tr>/s',$html,$row)
        || strpos($row[0],'<td>'.$status.'</td>')===false) throw new RuntimeException('Wrong rendered member state: '.$status);
}
echo "PASS: rendered mixed-node counts, serial member states, native Up and disabled precedence\n";
