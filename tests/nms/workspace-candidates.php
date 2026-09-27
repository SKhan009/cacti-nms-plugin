<?php
require __DIR__.'/../../plugins/nms/includes/workspace/candidates.php';
function verify($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
foreach(['127.0.0.1','::1','::ffff:127.0.0.1','0.0.0.0','::','ff02::1','fe80::2','224.0.0.1','169.254.1.1','example.com','192.0.2.1;id'] as $ip)verify(nms_workspace_candidate_target($ip)===null,'Reject unsuitable advertised target '.$ip);
verify(nms_workspace_candidate_target('2001:0db8::1')==='2001:db8::1','Canonical IPv6 target');
$hosts=[1=>['id'=>1,'hostname'=>'192.0.2.1','poller_id'=>1,'site_id'=>1],2=>['id'=>2,'hostname'=>'192.0.2.2','poller_id'=>1,'site_id'=>1],3=>['id'=>3,'hostname'=>'192.0.2.2','poller_id'=>2,'site_id'=>1]];
$neighbor=['peer_key'=>'peer','remote_name'=>'Peer router','local_port'=>'eth0','remote_port'=>'eth1','management_addresses'=>[['address'=>'192.0.2.2']]];
$snapshot=['host_id'=>1,'protocol'=>'lldp','valid'=>true,'status'=>'success','succeeded_at'=>'2026-09-26 12:00:00','data'=>['neighbors'=>['n1'=>$neighbor]]];
$r=nms_workspace_neighbour_candidates($hosts,[$snapshot],[]);
verify(count($r)===1 && $r[0]['state']==='Possible existing device' && array_column($r[0]['matches'],'host_id')===[2],'Candidate finds same-scope management address without cross-collector collision');
$hidden=[1=>$hosts[1]];
$r=nms_workspace_neighbour_candidates($hidden,[$snapshot],[]);
verify($r[0]['state']==='Unverified onboarding candidate' && !$r[0]['matches'],'Candidate does not reveal records outside supplied visible hosts');
$stale=array_replace($snapshot,['status'=>'stale']);
$r=nms_workspace_neighbour_candidates($hosts,[$stale],[]);
verify(!$r[0]['eligible'] && $r[0]['state']==='Stale or unavailable observation','Stale observations cannot be verified as current candidates');
$absent=$snapshot;$absent['data']['neighbors']['n1']['present']=false;
verify(!nms_workspace_neighbour_candidates($hosts,[$absent],[])[0]['eligible'],'Historical missing neighbours are not current candidates');
verify(nms_workspace_neighbour_candidates($hosts,[$snapshot],[],2)===[],'Selected reporter filters candidates');
verify(nms_workspace_neighbour_candidates([2=>$hosts[2]],[$snapshot],[])===[],'Hidden reporting device cannot produce candidates');
$r=nms_workspace_neighbour_candidates($hosts,[$snapshot],[]);
verify($r[0]['id']===nms_workspace_neighbour_candidates($hosts,[$snapshot],[])[0]['id'] && $r[0]['confidence']==='Advertised only; reachability and physical identity are unverified','Stable candidate key retains explicit uncertainty');

$r=nms_workspace_neighbour_candidates($hidden,[$snapshot],[],1,[$hosts[2],$hosts[3]]);
verify(array_column($r[0]['matches'],'host_id')===[2] && $r[0]['state']==='Possible existing device','Newly onboarded visible native record matches without a discovery assignment');
verify(nms_workspace_neighbour_candidates([],[$snapshot],[],0,[$hosts[1],$hosts[2]])===[],'Native inventory does not create an unauthorized discovery reporter');
