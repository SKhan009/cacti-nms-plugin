<?php
require __DIR__.'/../../plugins/nms/includes/workspace/admission.php';
function verify($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
$c=['target'=>'192.0.2.20','poller_id'=>1,'site_id'=>2];
$h=['id'=>7,'hostname'=>'192.0.2.20','poller_id'=>1,'site_id'=>2,'snmp_context'=>''];
verify(count(nms_workspace_admission_matches($c,[$h],[]))===1,'Configured address blocks onboarding without discovery assignment');
$hidden=nms_workspace_admission_visible(nms_workspace_admission_matches($c,[$h],[]),[]);
verify($hidden['blocked'] && $hidden['restricted_match'] && !$hidden['matches'] && !str_contains(json_encode($hidden),'192.0.2.20'),'Hidden match blocks admission without exposing address or ID');
$visible=nms_workspace_admission_visible(nms_workspace_admission_matches($c,[$h],[]),[7]);
verify($visible['matches'][0]['host_id']===7 && !$visible['restricted_match'],'Visible match retains review evidence');
$h['hostname']='192.0.2.10';
$s=['host_id'=>7,'protocol'=>'identity','valid'=>true,'status'=>'success','data'=>['own_addresses'=>[['address'=>'192.0.2.20','type'=>2,'status'=>1,'zone'=>0]]]];
verify(count(nms_workspace_admission_matches($c,[$h],[$s]))===1,'Shared or anycast reported target requires review');
$s['status']='stale';verify(!nms_workspace_admission_matches($c,[$h],[$s]),'Stale own-address evidence is not a current admission match');
$s['status']='success';$s['valid']=false;verify(!nms_workspace_admission_matches($c,[$h],[$s]),'Changed configuration invalidates identity evidence');
$s['valid']=true;$h['poller_id']=2;verify(!nms_workspace_admission_matches($c,[$h],[$s]),'Other collector scope is not conflated');
$h['poller_id']=1;$h['snmp_context']='VRF';verify(!nms_workspace_admission_matches($c,[$h],[$s]),'Separate SNMP contexts are not conflated');
$h['snmp_context']='';$chassis=['hardware'=>['chassis'=>[['serial'=>'SERIAL7','model'=>'router']]]];
$c['verified_identity']=$chassis;$s['data']=$chassis;
verify(count(nms_workspace_admission_matches($c,[$h],[$s]))===1,'Matching chassis finds same-router candidate at another IP');
$c['verified_identity']['hardware']['chassis'][0]['serial']='DIFFERENT';
verify(!nms_workspace_admission_matches($c,[$h],[$s]),'Same model with different serial does not identify one device');
$c['target']='2001:db8::20';$h['hostname']='2001:0db8:0:0::20';
verify(count(nms_workspace_admission_matches($c,[$h],[]))===1,'Equivalent IPv6 literals match');
verify(!nms_workspace_admission_visible([],[])['blocked'],'No match stays provisional rather than claiming uniqueness');
echo "12 assertions passed; pure evidence and redaction checks, not onboarding execution.\n";
