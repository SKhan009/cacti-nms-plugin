<?php
require __DIR__ . '/../../fcaps/services/fcaps_service.php';
$record=['numeric'=>true,'table'=>false,'base_oid'=>'1.3.6.1.4.1.99.1','oid'=>'1.3.6.1.4.1.99.1.0','label'=>'batteryCharge','symbol'=>'UPS-MIB::batteryCharge','units'=>'%'];
$table=array_replace($record,['table'=>true,'base_oid'=>'1.3.6.1.4.1.99.2','oid'=>'1.3.6.1.4.1.99.2','label'=>'temperature']);
function db_fetch_assoc($sql){return [['meta_value'=>json_encode(['id'=>'test','type_id'=>'ups','object_parts'=>1,'object_count'=>2])]];}
function db_fetch_cell_prepared($sql,$args){global $record,$table;return base64_encode(json_encode([$record,$table]));}
function db_fetch_assoc_prepared($sql,$args){if($args!==[2])throw new Exception('Wrong host scope');return [['oid'=>'1.3.6.1.4.1.99.2.8','data_source_name'=>'temp','name'=>'UPS Temperature','metric_id'=>3,'template_id'=>4],['oid'=>'script argument','data_source_name'=>'load','name'=>'Load','metric_id'=>0,'template_id'=>0]];}
$rows=icct_nms_fault_parameters(2);
if(count($rows)!==2||$rows[0]['units']!=='%'||$rows[1]['oid']!=='1.3.6.1.4.1.99.2.8'||$rows[1]['metric_id']!==3)throw new Exception('Parameter metadata or resolved instance missing');
foreach($rows as $row)if($row['oid']===$table['base_oid'])throw new Exception('Invented table instance');
if(count(icct_nms_fault_parameters(0))!==1)throw new Exception('New device must use scalar MIB definitions only');
echo "Device-scoped OIDs, MIB units, deduplication and table instance handling passed.\n";

foreach(['tenths of degrees Celsius'=>0.1,'hundredths of volts'=>0.01,'0.1 degrees Celsius'=>0.1,'%'=>1,'degrees Celsius'=>1] as $units=>$factor){$parsed=icct_nms_fault_unit_scale($units);if($parsed['scale']!=$factor)throw new Exception('Declared unit scaling failed');}
echo "Explicit MIB unit conversions passed; plain units retain raw values.\n";
