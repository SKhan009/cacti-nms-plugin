<?php
require __DIR__.'/../includes/protocol_service.php';
require __DIR__.'/../includes/services/discovery.php';
$assignment=[];$preset=[];
function db_fetch_row_prepared($sql,$args){return $GLOBALS['assignment'];}
function read_config_option($key){return 300;}
function icct_backend_current_user_id(){return 1;}
function icct_backend_require_management(){}
function icct_backend_require_device_access($id){}
function db_fetch_cell_prepared($sql,$args){return 2;}
function icct_backend_category_execute($sql,$args){if(str_contains($sql,'INSERT INTO plugin_icct_nms_discovery_devices')){$GLOBALS['assignment']=$GLOBALS['preset']+['protocol'=>'','preset_id'=>$args[1],'methods'=>$args[2],'collection_enabled'=>$args[3]];return;}$GLOBALS['preset']=['protocol'=>$args[1],'enabled'=>$args[2],'interval_seconds'=>$args[3],'stale_seconds'=>$args[4],'refresh_seconds'=>$args[5]];}
function db_fetch_cell($sql){return 1;}
$timings=['interval_seconds'=>300,'stale_seconds'=>900,'refresh_seconds'=>30];
function checkMethods($expected){if(icct_backend_nd_host_methods($GLOBALS['assignment'],false)!==$expected)throw new RuntimeException('Unexpected saved methods');}
icct_nms_save_discovery(2,$timings+['discovery_protocol'=>'lldp']);checkMethods(['lldp']);
icct_nms_save_discovery(2,$timings+['discovery_protocol'=>'cdp']);checkMethods(['lldp','cdp']);
icct_nms_save_discovery(2,$timings+['discovery_policy'=>1,'discovery_methods_present'=>1,'methods'=>['arp','fdb'],'collection_enabled'=>1]);checkMethods(['lldp','cdp','arp','fdb']);
icct_nms_save_discovery(2,$timings+['discovery_policy'=>1,'discovery_methods_present'=>1,'methods'=>['fdb']]);checkMethods(['lldp','cdp','fdb']);
if($assignment['collection_enabled'])throw new RuntimeException('Collection was not disabled');
icct_nms_save_discovery(2,$timings+['discovery_protocol'=>'lldp']);checkMethods(['lldp','cdp','fdb']);
if($assignment['collection_enabled'])throw new RuntimeException('Protocol save re-enabled collection');
$before=$assignment;
try{icct_nms_save_discovery(2,$timings+['discovery_policy'=>1,'discovery_methods_present'=>1,'methods'=>['lldp']]);throw new RuntimeException('Invalid observation accepted');}catch(InvalidArgumentException $e){}
if($assignment!==$before)throw new RuntimeException('Invalid input changed assignment');
$assignment=[];$preset=[];
icct_nms_save_discovery(2,$timings+['discovery_policy'=>1,'discovery_methods_present'=>1]);
icct_nms_save_discovery(2,$timings+['discovery_protocol'=>'lldp']);checkMethods(['lldp']);
if(!$assignment['collection_enabled'])throw new RuntimeException('First neighbour protocol did not enable collection');
echo "Device protocol selections, shared observations and disabled collection persistence passed.\n";
