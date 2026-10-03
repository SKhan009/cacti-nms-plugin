<?php
require __DIR__.'/../includes/fcaps_service.php';
function icct_nms_id($value){if(!is_scalar($value)||!ctype_digit((string)$value))throw new InvalidArgumentException('Invalid ID');return (int)$value;}
function checkFault($value,$message){if(!$value)throw new Exception($message);}
function rejectFault($rule,$catalogue,$associated){try{icct_nms_fault_validate([$rule],$catalogue,$associated);}catch(InvalidArgumentException $e){return;}throw new Exception('Invalid threshold accepted');}
$catalogue=[['template_id'=>1,'metric_id'=>2]];$rule=['template_id'=>'1','metric_id'=>'2','minimum'=>'10','maximum'=>'80','severity'=>'Warning','enabled'=>true];
$validated=icct_nms_fault_validate([$rule],$catalogue,[1])[0];
checkFault(icct_nms_fault_state($validated,81)==='Warning','Upper threshold not raised');
checkFault(icct_nms_fault_state($validated,9)==='Warning','Lower threshold not raised');
checkFault(icct_nms_fault_state($validated,80)==='Normal','Exact upper threshold misclassified');
checkFault(icct_nms_fault_state($validated,10)==='Normal','Exact lower threshold misclassified');
checkFault(icct_nms_fault_state($validated,null)==='Unknown','Missing value marked normal');
checkFault(icct_nms_fault_state(array_replace($validated,['enabled'=>false]),81)==='Disabled','Disabled rule raised');
rejectFault($rule,$catalogue,[99]);rejectFault(array_replace($rule,['metric_id'=>'999']),$catalogue,[1]);
rejectFault(array_replace($rule,['minimum'=>'80']),$catalogue,[1]);
rejectFault(array_replace($rule,['minimum'=>'','maximum'=>'']),$catalogue,[1]);
rejectFault(array_replace($rule,['maximum'=>'1e999']),$catalogue,[1]);
rejectFault(array_replace($rule,['severity'=>'Other']),$catalogue,[1]);
checkFault(icct_nms_fault_validate([array_replace($rule,['minimum'=>''])],$catalogue,[1])[0]['minimum']===null,'No-bound minimum failed');
echo "Fault thresholds, exact boundaries, unknown values, disabled rules, severity and graph ownership validation passed.\n";
$writes=[];
function icct_backend_require_management($realm){}
function icct_backend_require_device_access($id){if($id!==5)throw new RuntimeException('Access denied');}
function db_fetch_assoc($sql){return [['template_id'=>1,'metric_id'=>2]];}
function db_fetch_assoc_prepared($sql,$args){return [['graph_template_id'=>1]];}
function icct_backend_category_execute($sql,$args){global $writes;$writes[]=$args;}
icct_nms_save_faults(5,['fault_rules'=>json_encode([$rule])]);
checkFault($writes[0][0]==='fault_rules_5','Rule saved outside its own device');
checkFault(count($writes)===1,'Unrelated device settings updated');
echo "Fault saves are isolated to the authorized device.\n";
