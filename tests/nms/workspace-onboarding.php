<?php
require __DIR__.'/../../plugins/nms/includes/workspace/onboarding.php';
function verify($ok,$m){if(!$ok)throw new RuntimeException($m);echo "PASS: $m\n";}
function rejects($fn,$m){try{$fn();}catch(Throwable $e){verify(true,$m);return;}throw new RuntimeException('Unexpected acceptance: '.$m);}
$now=time();$c=['id'=>str_repeat('a',64),'target'=>'192.0.2.20','poller_id'=>1,'site_id'=>2,'observed_at'=>date('Y-m-d H:i:s',$now-30)];
$h=['hostname'=>'192.0.2.10','poller_id'=>1,'site_id'=>2];
$r=['target'=>$c['target'],'collector_id'=>1,'identity_usable'=>true,'identity'=>['uptime'=>123]];
$check=['status'=>'complete','cancel_requested'=>0,'finished_at'=>date('Y-m-d H:i:s',$now-10),'candidate_id'=>$c['id'],'target'=>$c['target'],'poller_id'=>1,'config_hash'=>nms_workspace_verification_hash($c,$h),'result_json'=>json_encode($r)];
verify(nms_workspace_onboarding_evidence($check,$c,$h,$now)===['uptime'=>123],'Current SNMP evidence can support reviewed onboarding');
foreach(['queued','failed','cancelled'] as $status)rejects(fn()=>nms_workspace_onboarding_evidence(array_replace($check,['status'=>$status]),$c,$h,$now),'Reject '.$status.' verification');
rejects(fn()=>nms_workspace_onboarding_evidence(array_replace($check,['finished_at'=>date('Y-m-d H:i:s',$now-901)]),$c,$h,$now),'Reject stale verification');
rejects(fn()=>nms_workspace_onboarding_evidence(array_replace($check,['finished_at'=>date('Y-m-d H:i:s',$now+90)]),$c,$h,$now),'Reject future verification');
rejects(fn()=>nms_workspace_onboarding_evidence($check,array_replace($c,['target'=>'192.0.2.21']),$h,$now),'Changed target requires reverification');
rejects(fn()=>nms_workspace_onboarding_evidence($check,$c,array_replace($h,['snmp_context'=>'VRF']),$now),'Changed SNMP context requires reverification');
$r['identity_usable']=false;$bad=array_replace($check,['result_json'=>json_encode($r)]);rejects(fn()=>nms_workspace_onboarding_evidence($bad,$c,$h,$now),'Ping alone cannot qualify for SNMP onboarding');
$r['identity_usable']=true;$r['identity']=[];$bad['result_json']=json_encode($r);rejects(fn()=>nms_workspace_onboarding_evidence($bad,$c,$h,$now),'Empty identity cannot claim usable verification');
echo "10 evidence eligibility assertions passed; no device creation.\n";
