<?php
require __DIR__.'/../../plugins/nms/includes/topology/diagnostics.php';
function verify($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
$now=time();$host=['id'=>10,'poller_id'=>2,'hostname'=>'192.0.2.10'];
$output=['target'=>'192.0.2.10','tool'=>'ping','exit'=>0,'output'=>"4 packets transmitted, 4 received, 0% packet loss\nrtt min/avg/max/mdev = 0.0/0.0/0.0/0.0 ms"];
$job=['id'=>1,'host_id'=>10,'poller_id'=>2,'tool'=>'ping','status'=>'complete','finished_at'=>date('Y-m-d H:i:s',$now-30),'result_json'=>json_encode($output)];
$m=nms_topology_measurement($host,$job,$now);
verify($m['state']==='Current' && $m['packet_loss']===0.0 && $m['latency_ms']===0.0,'Measured zero values remain valid');
verify($m['method']==='ping' && $m['target']===$host['hostname'] && $m['collector_id']===2 && $m['collected_at']===$job['finished_at'],'Measurement carries method, target, collector, and timestamp');
verify(!isset($m['result_json']) && !isset($m['output']),'Raw diagnostic output is excluded');
$stale=nms_topology_measurement($host,array_replace($job,['finished_at'=>date('Y-m-d H:i:s',$now-901)]),$now);
verify($stale['state']==='Stale' && $stale['packet_loss']===null && $stale['latency_ms']===null && $stale['collected_at']!==null,'Stale measurements retain provenance but cannot appear as current metrics');
$none=nms_topology_measurement($host,null,$now);
verify($none['state']==='Not measured' && $none['packet_loss']===null,'Missing result never becomes zero loss');
foreach(['hostname'=>'192.0.2.11','poller_id'=>3,'id'=>11] as $field=>$value){
    $m=nms_topology_measurement(array_replace($host,[$field=>$value]),$job,$now);
    verify($m['state']==='No usable measurement' && $m['target']===null && $m['packet_loss']===null,'Changed '.$field.' invalidates the saved measurement');
}
$bad=array_replace($job,['result_json'=>json_encode(array_replace($output,['truncated'=>true]))]);
verify(nms_topology_measurement($host,$bad,$now)['packet_loss']===null,'Truncated output is not a topology measurement');
$loss=array_replace($job,['status'=>'failed','result_json'=>json_encode(array_replace($output,['exit'=>1,'output'=>'4 packets transmitted, 0 received, 100% packet loss']))]);
verify(nms_topology_measurement($host,$loss,$now)['packet_loss']===100.0,'Measured complete packet loss is preserved');
