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
