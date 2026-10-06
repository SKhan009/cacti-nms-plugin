<?php
require __DIR__ . '/../../presets/services/backend/categories.php';
require __DIR__ . '/../../configuration/services/backend/configuration_validation.php';
require __DIR__ . '/../../configuration/services/backend/configuration_service.php';
require __DIR__ . '/../../protocols/serial/services/serial_service.php';
$meta=[]; $connection=['id'=>5,'poller_id'=>1,'enabled'=>1,'transport'=>'direct','revision'=>1,'settings'=>['protocol'=>'modbus_rtu','interface'=>'rs485','baud_rate'=>9600,'data_bits'=>8,'parity'=>'even','stop_bits'=>1,'flow_control'=>'none','timeout_ms'=>3000,'retries'=>2]];
$connection['settings_json']=json_encode($connection['settings']);
function db_fetch_cell($sql) {return 'test';}
function db_execute($sql) {return true;}
function icct_backend_require_management() {}
function icct_backend_require_device_access($id) {}
function icct_backend_current_user_id() { return 1; }
function db_fetch_assoc_prepared($sql,$args) { return []; }
function db_fetch_row_prepared($sql,$args) { global $connection; if(str_contains($sql,'FROM plugin_icct_nms_serial_connections')) return $connection; if(str_contains($sql,'FROM host')) return ['id'=>$args[0],'poller_id'=>1,'disabled'=>'']; return []; }
function db_fetch_cell_prepared($sql,$args) { global $meta; if(str_contains($sql,'GET_LOCK') || str_contains($sql,'RELEASE_LOCK')) return 1; return str_contains($sql,'meta_value') ? ($meta[$args[0]] ?? null) : false; }
function db_execute_prepared($sql,$args) { global $meta; if(str_contains($sql,'UPDATE plugin_icct_nms_serial_connections')) throw new RuntimeException('Modified shared serial connection.'); if(str_contains($sql,'plugin_icct_nms_meta')) $meta[$args[0]]=$args[1]; return true; }
$input=['connection_id'=>'5','connection_revision'=>'1','assignment_revision'=>'0','device_address'=>'1','serial_protocol'=>'modbus_rtu','serial_interface'=>'rs485','baud_rate'=>'9600','data_bits'=>'8','parity'=>'even','stop_bits'=>'1','flow_control'=>'none','response_timeout'=>'1','serial_retries'=>'1','serial_interval'=>'60'];
icct_nms_save_serial(1,$input);
icct_nms_save_serial(2,array_replace($input,['device_address'=>'2','response_timeout'=>'2']));
$a=icct_backend_serial_device_settings(1,5,$connection['settings']);
$b=icct_backend_serial_device_settings(2,5,$connection['settings']);
if($a['timeout_ms']!==1000 || $b['timeout_ms']!==2000 || $connection['settings']['timeout_ms']!==3000) throw new RuntimeException('Serial device isolation failed.');
if(icct_backend_serial_device_settings(1,6,$connection['settings'])!==$connection['settings']) throw new RuntimeException('Snapshot crossed connections.');
if(icct_backend_serial_device_settings(3,5,$connection['settings'])!==$connection['settings']) throw new RuntimeException('Legacy fallback failed.');
echo "Serial saves, shared connection isolation, collector settings resolution and legacy fallback passed.\n";
$fields=icct_nms_serial_register_fields([['label'=>'Voltage','offset'=>'0','function'=>'4','type'=>'uint16','unit'=>'V'],['label'=>'Temperature','offset'=>'1','function'=>'3','type'=>'int16','unit'=>'C']]);
if(count($fields)!==2||$fields[0]['offset']!==0||$fields[1]['min']!==-32768||$fields[0]['writable'])throw new RuntimeException('Serial register definition failed.');
foreach([[['label'=>'X','offset'=>'65536','function'=>'3','type'=>'uint16']], [['label'=>'X','offset'=>'1','function'=>'3','type'=>'uint16'],['label'=>'Y','offset'=>'1','function'=>'3','type'=>'int16']]] as $bad){try{icct_nms_serial_register_fields($bad);throw new RuntimeException('Invalid serial registers accepted.');}catch(InvalidArgumentException $e){}}
echo "Serial register validation, zero-based offsets, signed types and duplicate rejection passed.\n";
