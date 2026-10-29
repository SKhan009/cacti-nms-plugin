<?php
require dirname(__DIR__,2).'/protocols/snmp/mibs/services/mib_generation_service.php';
$_SESSION=[];$checks=0;
function check($value,$message){global $checks;if(!$value)throw new RuntimeException($message);$checks++;}
function rejects($run,$message){try{$run();}catch(InvalidArgumentException $e){check(true,$message);return;}throw new RuntimeException($message);}
$original=[2=>1,4=>1];$review=['selected'=>[2=>1],'records'=>[2=>['data_name'=>'Kept settings','fault_presets'=>'[]'],4=>['data_name'=>'Excluded']]];
rejects(fn()=>icct_mib_generation_prepare($original,$review,false),'Unchecked OIDs require confirmation');
$result=icct_mib_generation_prepare($original,$review,true);
check(array_keys($result['selected'])===[2],'Only checked OID remains');
check(array_keys($result['records'])===[2]&&$result['records'][2]['data_name']==='Kept settings','Edits retained and unchecked records removed');
rejects(fn()=>icct_mib_generation_prepare($original,['selected'=>[],'records'=>[]],true),'Empty selection rejected');
rejects(fn()=>icct_mib_generation_prepare($original,['selected'=>[2=>1,999=>1],'records'=>[]],true),'Unknown or stale OIDs cannot silently disappear');
rejects(fn()=>icct_mib_generation_prepare($original,['selected'=>[2=>'invalid'],'records'=>[]],true),'Invalid selection flag rejected');
$token=icct_mib_generation_draft_put('bundle','ready',['values'=>$result,'object'=>null]);
check(icct_mib_generation_draft_get($token,'bundle','ready')['review']['values']===$result,'Draft round trip');
rejects(fn()=>icct_mib_generation_draft_get($token,'other','ready'),'Bundle isolation');
rejects(fn()=>icct_mib_generation_draft_get($token,'bundle','review'),'Phase isolation');
rejects(fn()=>icct_mib_generation_draft_get('../','bundle','ready'),'Invalid token rejected');
$_SESSION['icct_mib_generation_'.$token]['time']=time()-7200;
$_SESSION['icct_mib_generation_'.$token]['last_access']=time()-60;
check(icct_mib_generation_draft_get($token,'bundle','ready')['review']['values']===$result,'Active review survives the original one-hour creation limit');
check($_SESSION['icct_mib_generation_'.$token]['last_access']>=time()-1,'Review access renews the inactivity lease');
$_SESSION['icct_mib_generation_'.$token]['last_access']=time()-14401;
rejects(fn()=>icct_mib_generation_draft_get($token,'bundle','ready'),'Expired draft rejected');
check(icct_mib_generation_prepare($original,['selected'=>$original,'records'=>[]],false)['selected']===$original,'Unchanged selection needs no confirmation');
echo "Passed $checks generation draft checks.\n";
