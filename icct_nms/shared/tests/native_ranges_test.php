<?php
require __DIR__ . '/../../inventory/services/device_service.php';
require __DIR__ . '/../../dashboard/topology/services/backend/topology_config.php';
$old=['snmp_port'=>161,'snmp_timeout'=>500,'ping_timeout'=>400,'ping_retries'=>1,'snmp_engine_id'=>'','external_id'=>'','location'=>'','ping_port'=>23];
foreach(icct_nms_native_ranges() as $field=>[$min,$max]) {
    foreach([$min,$max] as $value) { $values=$old;$values[$field]=$value; $result=icct_nms_native_values($values,[],[]);if($result[$field]!==$value)throw new RuntimeException('Boundary rejected'); }
    foreach([$min-1,$max+1] as $value) { $values=$old;$values[$field]=$value;try{icct_nms_native_values($values,[],[]);throw new RuntimeException('Out of range accepted');}catch(InvalidArgumentException $e){} }
    $attrs=icct_nms_native_range_attributes($field);
    if(!str_contains($attrs,'min="'.$min.'"')||!str_contains($attrs,'max="'.$max.'"'))throw new RuntimeException('Form and validation differ');
}
echo "Native timeout/retry ranges: accepted boundaries, rejected overflow, form parity passed.\n";
