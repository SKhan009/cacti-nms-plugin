<?php
require __DIR__.'/../../plugins/nms/includes/workspace/evidence.php';
function verify($ok,$message) {if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
$now=time();$host=['id'=>1,'hostname'=>'192.0.2.1','poller_id'=>1,'status'=>1,'disabled'=>'','last_updated'=>date('Y-m-d H:i:s',$now)];
$result=['target'=>$host['hostname'],'tool'=>'ping','exit'=>0,'output'=>"4 packets transmitted, 4 received, 0% packet loss\nrtt min/avg/max/mdev = 1.0/2.5/4.0/0.3 ms"];
$job=['id'=>2,'host_id'=>1,'poller_id'=>1,'tool'=>'ping','status'=>'complete','finished_at'=>date('Y-m-d H:i:s',$now-30),'result_json'=>json_encode($result)];
$e=nms_workspace_probe_evidence($host,$job,$now);
verify($e['packet_loss']===0.0 && $e['latency_ms']===2.5,'Measured zero loss and latency retained');
foreach(['finished_at'=>date('Y-m-d H:i:s',$now-1000),'poller_id'=>2,'host_id'=>2,'status'=>'running'] as $key=>$value) verify(nms_workspace_probe_evidence($host,array_replace($job,[$key=>$value]),$now)===null,'Reject mismatched/stale '.$key);
foreach(['target'=>'192.0.2.2','self_test'=>true,'truncated'=>true,'output'=>'Timeout without measurements','exit'=>2] as $key=>$value) verify(nms_workspace_probe_evidence($host,array_replace($job,['result_json'=>json_encode(array_replace($result,[$key=>$value]))]),$now)===null,'Reject unsuitable result '.$key);
$failed=array_replace($job,['status'=>'failed','result_json'=>json_encode(array_replace($result,['exit'=>1,'output'=>'4 packets transmitted, 0 received, 100% packet loss']))]);
verify(nms_workspace_probe_evidence($host,$failed,$now)['packet_loss']===100.0,'Measured complete loss retained as failure');
verify(count(nms_workspace_tabs())===6 && !isset(nms_workspace_tabs()['diagnosis']),'Readings excludes relocated diagnosis section');
verify(nms_workspace_url('diagnosis',2,['job_id'=>3,'redirect'=>'bad'])==='diagnostics.php?section=diagnosis&host_id=2&job_id=3','Diagnosis links retain device/result at dedicated page and discard unsupported parameters');
verify(nms_workspace_url('../../bad',0,['redirect'=>'https://bad','job_id'=>3])==='devices.php?tab=readings&section=overview&id=0&job_id=3','Routes and query extensions are whitelisted');
verify(strpos(nms_workspace_url('identity',1,['management_review'=>9]),'management_review=9')!==false,'Management review link retains request ID');
verify(strpos(nms_workspace_url('duplicates',1,['consolidation_keep'=>1,'consolidation_other'=>2]),'consolidation_keep=1&consolidation_other=2')!==false,'Consolidation links retain both selected records');
