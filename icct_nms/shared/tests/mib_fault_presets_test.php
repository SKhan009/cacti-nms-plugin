<?php
require __DIR__.'/../../protocols/snmp/mibs/services/mib_fault_presets_service.php';
$base=['name'=>'Sensor high','threshold'=>'0','condition'=>'at_or_above','severity'=>'Critical','enabled'=>true];
$rules=icct_mib_fault_presets(json_encode([$base]));
if($rules[0]['threshold']!==0.0||!$rules[0]['enabled'])throw new RuntimeException('Zero threshold lost.');
if(icct_mib_fault_presets([['name'=>'Unconfigured','enabled'=>false]])!==[])throw new RuntimeException('Unconfigured card became an alarm.');
foreach([[$base,$base],[array_replace($base,['threshold'=>'U'])],[array_replace($base,['threshold'=>''])],[array_replace($base,['severity'=>'wrong'])],[array_replace($base,['condition'=>'wrong'])],[array_replace($base,['name'=>''])]] as $bad){
 try{icct_mib_fault_presets($bad);throw new RuntimeException('Invalid preset accepted.');}catch(InvalidArgumentException $expected){}
}
echo "PASS: zero thresholds, disabled unconfigured cards, required numeric thresholds, duplicate names, comparators and severity validation.\n";
function icct_mib_existing_source_indexes($records){return [0=>42];}
function db_fetch_assoc_prepared($sql,$params){
 if($params!==[42])throw new RuntimeException('Fault lookup did not use the native source association.');
 return [['metric_id'=>7,'meta_value'=>json_encode([array_merge($GLOBALS['base'],['metric_id'=>7]),array_merge($GLOBALS['base'],['metric_id'=>8,'name'=>'Unrelated metric'])])]];
}
$fetched=icct_mib_saved_fault_presets(['oid'=>'1.2.3.0'],['rows'=>[['oid'=>'1.2.3.0','settings'=>['fault_presets'=>[$base]]]]]);
if(count($fetched)!==1||$fetched[0]['name']!==$base['name'])throw new RuntimeException('Preset deduplication or metric association failed.');
echo "PASS: saved fault presets are fetched by native source association, unrelated metrics excluded and duplicates merged.\n";
$status=['numeric'=>true,'symbol'=>'DYNAMIC-QA::healthState','label'=>'healthState','syntax'=>'INTEGER {ok(5), degraded(12), unavailable(27)}'];
$suggested=icct_mib_mib_fault_presets($status);
if(array_column($suggested,'threshold')!==[12.0,27.0]||array_filter($suggested,fn($rule)=>$rule['enabled'])||array_unique(array_column($suggested,'condition'))!==['equals'])throw new RuntimeException('MIB status suggestions did not preserve declared states.');
$rangeRules=icct_mib_mib_fault_presets(['numeric'=>true,'symbol'=>'QA::temperature','label'=>'temperature','syntax'=>'Integer32 (-40..125)','min'=>'0','max'=>'100']);
if(array_column($rangeRules,'threshold')!==[-40.0,125.0]||array_column($rangeRules,'condition')!==['below','above']||array_filter($rangeRules,fn($rule)=>$rule['enabled']))throw new RuntimeException('MIB range checks did not use explicit syntax bounds.');
if(icct_mib_mib_fault_presets(['numeric'=>true,'symbol'=>'QA::count','label'=>'count','syntax'=>'Integer32','min'=>'0','max'=>'100'])!==[])throw new RuntimeException('RRD-only bounds invented MIB range checks.');
if(icct_mib_mib_fault_presets(['numeric'=>true,'label'=>'sensorType','syntax'=>'INTEGER {volts(1), amperes(2)}'])!==[])throw new RuntimeException('Measurement types became fault states.');
echo "PASS: MIB state values drive disabled suggestions dynamically; normal states and measurement enums are excluded; explicit MIB ranges produce disabled validation checks, independently of RRD bounds.\n";
$indicatorRecord=['oid'=>'1.2.3.0','numeric'=>true,'symbol'=>'QA::status','label'=>'status','syntax'=>'INTEGER {ok(1), failed(2)}'];
if(icct_mib_review_fault_presets($indicatorRecord,[],['fault_presets'=>[],'fault_presets_reviewed'=>'1'])['rules']!==[])throw new RuntimeException('Removed faults were recreated in the sidebar.');
if(count(icct_mib_review_fault_presets($indicatorRecord,[],['fault_presets'=>[$base]])['rules'])!==1)throw new RuntimeException('Sidebar did not reflect configured cards.');
echo "PASS: sidebar and editor share fault state; configured presets appear and explicitly emptied lists stay empty.\n";
$context=icct_mib_fault_context($status+['oid'=>'1.2.3.4','description'=>'A sensor status reported by the device.'],['condition'=>'equals','threshold'=>12,'description'=>'','corrective_action'=>'']);
if(!str_contains($context['description'],'degraded (12)')||!str_contains($context['description'],'A sensor status')||!str_contains($context['corrective_action'],'1.2.3.4'))throw new RuntimeException('Fault context is not based on selected MIB metadata.');
$custom=icct_mib_fault_context($status+['oid'=>'1.2.3.4'],['description'=>'My description','corrective_action'=>'My fix']);
if($custom['description']!=='My description'||$custom['corrective_action']!=='My fix')throw new RuntimeException('Custom operator text was overwritten.');
echo "PASS: MIB descriptions and suggested investigation text populate missing fields while custom descriptions and fixes remain unchanged.\n";
$severityRules=icct_mib_mib_fault_presets(['numeric'=>true,'symbol'=>'VENDOR-QA::thresholdSeverity','label'=>'thresholdSeverity','syntax'=>'INTEGER {other(1), minor(10), major(20), critical(30)}']);
if(array_column($severityRules,'threshold')!==[10.0,20.0,30.0]||array_column($severityRules,'severity')!==['Minor','Major','Critical'])throw new RuntimeException('Severity enum values or levels were not read dynamically.');
if(array_filter($severityRules,fn($rule)=>$rule['enabled']))throw new RuntimeException('Suggested severity rules were enabled without review.');
echo "PASS: declared severity enums create Minor/Major/Critical rules with original vendor values; unknown states remain unclassified.\n";
