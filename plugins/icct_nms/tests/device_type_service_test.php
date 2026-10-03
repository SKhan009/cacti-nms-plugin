<?php
require __DIR__.'/../includes/services/categories.php';
require __DIR__.'/../includes/services/topology_config.php';
require __DIR__.'/../includes/device_type_service.php';
$catalogue=['0123456789abcdef'=>['name'=>'Switch','category_id'=>4,'icon'=>'switch','physical_ports'=>16,'image'=>'','display_modes'=>['network'=>'icon','rack'=>'none','map'=>'icon']]]; $writes=[]; $assigned=false;
function db_fetch_cell($sql) { return 'test'; }
function db_fetch_assoc($sql) { return []; }
function db_fetch_cell_prepared($sql,$args) { global $catalogue,$assigned; if (str_contains($sql,'meta_value')) return json_encode($catalogue); if(str_contains($sql,'classification')) return $assigned?1:0; return 1; }
function db_execute_prepared($sql,$args) { global $writes,$catalogue; $writes[]=$args; $catalogue=json_decode($args[1],true); return true; }
$input=['action'=>'save_type','type_id'=>'0123456789abcdef','type_name'=>'Switch','category_id'=>4,'icon'=>'router','physical_ports'=>48,'display_network'=>'icon','display_rack'=>'none','display_map'=>'icon'];
function reject_type($changes,$expected) { global $input,$writes; $n=count($writes); try { icct_nms_save_device_type(array_replace($input,$changes)); throw new RuntimeException('Expected rejection'); } catch(InvalidArgumentException $e) { if(!str_contains($e->getMessage(),$expected) || count($writes)!==$n) throw new RuntimeException('Validation failed: '.$e->getMessage()); } }
reject_type(['physical_ports'=>-1],'whole number');
reject_type(['icon'=>'../private'],'topology icon');
reject_type(['display_network'=>'image'],'Upload');
reject_type(['display_map'=>'invalid'],'None, Icon or Image');
$assigned=true; reject_type(['type_name'=>'Renamed'],'Reassign'); reject_type(['action'=>'delete_type'],'Reassign'); $assigned=false;
icct_nms_save_device_type($input);
if($catalogue[$input['type_id']]['physical_ports']!==48 || $catalogue[$input['type_id']]['display_modes']['rack']!=='none') throw new RuntimeException('Profile not persisted');
reject_type(['type_id'=>'','icon'=>'device'],'already has');
if(icct_nms_type_asset(['icon'=>'../../etc','display_modes'=>['network'=>'icon']])!=='assets/images/device-types/device.svg') throw new RuntimeException('Unsafe asset');
if(icct_nms_type_asset(['display_modes'=>['rack'=>'none']],'rack')!=='') throw new RuntimeException('None visibility failed');
icct_nms_save_device_type(['action'=>'delete_type','type_id'=>$input['type_id']]);
if($catalogue) throw new RuntimeException('Delete failed');
echo "Device type validation, visibility and persistence tests passed.\n";
