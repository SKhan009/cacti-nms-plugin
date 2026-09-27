<?php
require __DIR__.'/../../plugins/nms/includes/configuration/equipment.php';
function check($ok,$message) { if(!$ok) throw new RuntimeException($message); }
$field=['key'=>'temperature_limit','label'=>'Temperature limit','unit'=>'C','offset'=>5,'function'=>3,'type'=>'int16','writable'=>true,'min'=>-20,'max'=>100];
$fields=nms_equipment_fields(json_encode([$field]),'modbus_rtu');
check(nms_equipment_value($fields[0],'-10')===-10,'Signed setting rejected');
foreach([['function'=>4],['offset'=>65536],['min'=>-32769],['max'=>32768],['min'=>101],['key'=>'bad key'],['writable'=>'yes'],['type'=>'shell']] as $change) {
    try { nms_equipment_fields(json_encode([array_replace($field,$change)]),'modbus_rtu'); }
    catch(InvalidArgumentException $e) { continue; }
    throw new RuntimeException('Invalid field accepted');
}
foreach(['101','-21','1.5','1e2',true,[],null] as $bad) {
    try { nms_equipment_value($fields[0],$bad); }
    catch(InvalidArgumentException $e) { continue; }
    throw new RuntimeException('Out-of-range or invalid value accepted');
}
try { nms_equipment_fields(json_encode([$field,$field]),'modbus_rtu'); throw new RuntimeException('Duplicate fields accepted'); } catch(InvalidArgumentException $e) {}
$snmp=['key'=>'label','label'=>'Label','oid'=>'.1.3.6.1.2.1.1.5.0','type'=>'string','max_length'=>64,'writable'=>true];
$snmpFields=nms_equipment_fields(json_encode([$snmp]),'snmp');
check(nms_equipment_value($snmpFields[0],'site-device')==='site-device','String rejected');
foreach(['sysName.0','1.3.6; echo secret','1'] as $oid) {
    try { nms_equipment_fields(json_encode([array_replace($snmp,['oid'=>$oid])]),'snmp'); }
    catch(InvalidArgumentException $e) { continue; }
    throw new RuntimeException('Invalid OID accepted');
}
echo "PASS: model field ranges/types, signed registers, read-only input registers, duplicate keys, numeric OIDs and string limits\n";
