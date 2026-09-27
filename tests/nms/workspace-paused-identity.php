<?php
/** Pure evidence binding checks against the actual hash and review matcher functions. */
$base=__DIR__.'/../../plugins/nms/includes/';
$s=file_get_contents($base.'discovery.php');$start=strpos($s,'function nms_nd_hash(');$end=strpos($s,'function nms_nd_hosts(',$start);eval(substr($s,$start,$end-$start));
$s=file_get_contents($base.'topology/discovery.php');eval(substr($s,strpos($s,'function nms_nd_review_snapshot_matches(')));
$host=['hostname'=>'192.0.2.1','disabled'=>'','snmp_community'=>'fixture','poller_id'=>1,'site_id'=>1];
$snapshot=['protocol'=>'identity','config_hash'=>nms_nd_hash($host)];
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
check(nms_nd_review_snapshot_matches($snapshot,$host,false),'unchanged enabled evidence matches');
$host['disabled']='on';
check(!nms_nd_review_snapshot_matches($snapshot,$host,false),'normal topology rejects pre-pause snapshot');
check(nms_nd_review_snapshot_matches($snapshot,$host,true),'explicit identity review accepts polling-only pause');
foreach(['hostname'=>'192.0.2.2','snmp_community'=>'changed','poller_id'=>2,'site_id'=>2] as $field=>$value){$changed=$host;$changed[$field]=$value;check(!nms_nd_review_snapshot_matches($snapshot,$changed,true),$field.' change still invalidates saved evidence');}
$snapshot['protocol']='lldp';check(!nms_nd_review_snapshot_matches($snapshot,$host,true),'paused neighbour evidence is not promoted');
echo "8 paused identity binding checks passed. Freshness and device ACL checks remain in the discovery reader.\n";
