<?php
require __DIR__ . '/../../fcaps/services/fcaps_service.php';
function checkSummary($value) { if (!$value) throw new Exception('Fault summary mismatch'); }
$r=['condition'=>'above','minimum'=>null,'maximum'=>85,'units'=>'%'];
$s=icct_nms_fault_summary_fields($r,[['value'=>0]]);
checkSummary($s['condition']==='> Above' && $s['threshold']===85 && $s['reading']==='0.00' && $s['units']==='%');
foreach (['at_or_above'=>'≥ At or above','equals'=>'= Equals','not_equals'=>'≠ Not equal'] as $c=>$label) checkSummary(icct_nms_fault_summary_fields(array_replace($r,['condition'=>$c]),[])['condition']===$label);
$s=icct_nms_fault_summary_fields(['condition'=>'below','minimum'=>50,'maximum'=>null],[]);
checkSummary($s['threshold']===50 && $s['units']==='Not specified');
$s=icct_nms_fault_summary_fields(['condition'=>'outside','minimum'=>20,'maximum'=>80],[['value'=>null]]);
checkSummary($s['threshold']==='20 to 80' && str_contains($s['reading'],'Unavailable'));
$s=icct_nms_fault_summary_fields(['minimum'=>null,'maximum'=>0],[]);
checkSummary($s['condition']==='> Above' && $s['threshold']===0);
echo "Fault summary comparisons, ranges, zero, units and unknown readings passed.\n";

$s=icct_nms_fault_summary_fields(['maximum'=>85],[['value'=>12.3456,'units'=>'Percent']]);
checkSummary($s['units']==='Percent' && $s['reading']==='12.35');
$s=icct_nms_fault_summary_fields(['maximum'=>90],[['value'=>63.9,'units'=>'Percent']]);
checkSummary($s['units']==='Percent' && $s['reading']==='63.90');
$s=icct_nms_fault_summary_fields(['maximum'=>1,'units'=>'ms'],[['value'=>0.001,'units'=>'Seconds']]);
checkSummary($s['units']==='ms' && $s['reading']==='0.00');
