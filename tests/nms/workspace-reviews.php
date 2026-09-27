<?php
require __DIR__.'/../../plugins/nms/includes/workspace/reviews.php';
function verify($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
function rejects($fn,$message){try{$fn();}catch(InvalidArgumentException|RuntimeException $e){verify(true,$message);return;}throw new RuntimeException('Unexpected acceptance: '.$message);}
$allowed=true;$visible=[2,8];$checked=[];
function nms_require_management($realm){global $allowed;if(!$allowed || $realm!==3)throw new RuntimeException('Denied management');}
function nms_require_device_access($id){global $visible,$checked;$checked[]=$id;if(!in_array($id,$visible,true))throw new RuntimeException('Denied device');}
verify(nms_identity_review_pair('8','2')===[2,8],'Opposite review directions resolve to the same pair');
foreach([[2,2],['2x',8],[0,8],[[2],8]] as $pair)rejects(fn()=>nms_identity_review_pair(...$pair),'Reject malformed or identical device pair');
$hosts=[2=>['hostname'=>'192.0.2.2','poller_id'=>1,'site_id'=>1],8=>['hostname'=>'192.0.2.8','poller_id'=>1,'site_id'=>1]];
$identities=[2=>['addresses'=>['192.0.2.2'],'checked_at'=>'2026-09-26 12:00:00'],8=>['addresses'=>['192.0.2.8'],'checked_at'=>'2026-09-26 12:00:00']];
$hash=nms_identity_review_hash(2,8,$hosts,$identities);
verify($hash===nms_identity_review_hash(8,2,$hosts,$identities),'Fingerprint is independent of review direction');
$changed=$hosts;$changed[2]['hostname']='192.0.2.3';
verify($hash!==nms_identity_review_hash(2,8,$changed,$identities),'Changed management address invalidates the reviewed evidence');
$changed=$identities;$changed[8]['checked_at']='2026-09-26 13:00:00';
verify($hash!==nms_identity_review_hash(2,8,$hosts,$changed),'A new identity observation requires a fresh review');
rejects(fn()=>nms_identity_review_hash(2,8,[2=>$hosts[2]],$identities),'Missing peer evidence cannot receive a review fingerprint');
$input=['host_a'=>2,'host_b'=>8,'decision'=>'same','note'=>'reviewed'];
$allowed=false;rejects(fn()=>nms_identity_review_save($input),'Management permission is checked before saving');
$allowed=true;$visible=[2];$checked=[];
rejects(fn()=>nms_identity_review_save($input),'Both devices must be visible before saving');
verify($checked===[2,8],'Permission enforcement checks both sides of the pair');
$visible=[2,8];
rejects(fn()=>nms_identity_review_save(array_replace($input,['decision'=>'merge'])),'Unsupported merge action is rejected');
rejects(fn()=>nms_identity_review_save(array_replace($input,['note'=>str_repeat('x',513)])),'Oversized note is rejected');
echo "Review validation only; SQL commit and browser acceptance remain separate tests.\n";
