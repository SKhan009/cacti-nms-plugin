<?php
require __DIR__ . '/../../presets/services/backend/categories.php';
require __DIR__ . '/../../configuration/services/backend/configuration_validation.php';
require __DIR__ . '/../../protocols/ssh/services/backend/ssh_linux.php';
require __DIR__ . '/../../protocols/serial/services/serial_service.php';
require __DIR__ . '/../../protocols/shared/services/protocol_preset_service.php';
require __DIR__ . '/../../protocols/syslog/services/backend/syslog.php';
$stored=null; $writes=0;
function db_fetch_cell_prepared($sql,$args) { global $stored; return str_contains($sql,'meta_value') ? $stored : 1; }
function db_execute_prepared($sql,$args) { global $stored,$writes; if(!str_contains($sql,'plugin_icct_nms_meta')) throw new RuntimeException('Defaults must not write a device or assignment.'); $stored=$args[1];$writes++; return true; }
$ssh=['preset_protocol'=>'ssh','port'=>'22','connect_timeout'=>'10','command_timeout'=>'30','retries'=>'1','keepalive'=>'30','auth_method'=>'password','username'=>'operator','monitoring'=>'1','secret'=>'device-only-secret'];
icct_nms_save_protocol_preset($ssh);
$draft=icct_nms_protocol_presets()['ssh'];
if(isset($draft['secret']) || $draft['port']!==22) throw new RuntimeException('Wrong default fields.');
$draft['port']=2222; // A device draft is a detached value snapshot.
if(icct_nms_protocol_presets()['ssh']['port']!==22) throw new RuntimeException('Device edit changed defaults.');
icct_nms_save_protocol_preset(array_replace($ssh,['port'=>'222']));
if($draft['port']!==2222) throw new RuntimeException('Preset edit changed existing device snapshot.');
$before=$writes;
try { icct_nms_save_protocol_preset(array_replace($ssh,['port'=>'65536'])); throw new RuntimeException('Accepted bad port.'); } catch(InvalidArgumentException $e) { if($writes!==$before) throw new RuntimeException('Invalid input wrote data.'); }
$serial=['preset_protocol'=>'serial','serial_interface'=>'rs485','baud_rate'=>'9600','data_bits'=>'8','stop_bits'=>'1','parity'=>'even','flow_control'=>'none','response_timeout'=>'3','serial_retries'=>'2','serial_interval'=>'60','serial_protocol'=>'modbus_rtu','connection_id'=>'99','device_address'=>'10'];
icct_nms_save_protocol_preset($serial);
$saved=icct_nms_protocol_presets();
if(!isset($saved['ssh']) || isset($saved['serial']['connection_id']) || isset($saved['serial']['device_address'])) throw new RuntimeException('Endpoint or another protocol was modified.');
echo "Protocol defaults validate, exclude credentials/endpoints, and remain independent of device snapshots.\n";

icct_nms_save_protocol_preset(['preset_protocol'=>'syslog','severity_codes'=>'[3,4]','facility_codes'=>'[1,16]','match_strings'=>'["auth failed","access denied"]','source_address'=>'192.0.2.10']);
$syslog=icct_nms_protocol_presets()['syslog'];
if(isset($syslog['source_address']) || $syslog['severity_codes']!=='[3,4]')throw new RuntimeException('Syslog presets must contain filter parameters only.');
try{icct_nms_protocol_preset_validate('syslog',['severity_codes'=>'[8]','facility_codes'=>'[1]','match_strings'=>'[]']);throw new RuntimeException('Invalid severity accepted.');}catch(InvalidArgumentException $e){}
echo "Syslog presets validate severity/facility selections and match strings.\n";
