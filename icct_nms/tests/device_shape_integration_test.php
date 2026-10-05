<?php
if(!in_array('--integration',$argv,true)){echo "Run with --integration on Cacti; all test writes are rolled back.\n";exit;}
chdir(dirname(__DIR__,3));require 'include/global.php';$_SESSION['sess_user_id']=1;
require 'plugins/icct_nms/includes/bootstrap.php';icct_nms_backend();
foreach(['inventory','device_type_service','graph_service','map_service','rack_view_service','topology_view_service'] as $file)require_once 'plugins/icct_nms/includes/'.$file.'.php';
$types=icct_nms_device_types();$profile=null;$id=null;$host=null;
foreach(icct_nms_inventory() as $device)foreach($types as $key=>$type)if($type['physical_ports']!==null && (int)$type['category_id']===(int)$device['category_id'] && $type['name']===$device['device_type']){$profile=$type;$id=$key;$host=$device;break 2;}
if(!$profile)throw new RuntimeException('Integration test needs an assigned device type with a port count.');
$input=['action'=>'save_type','type_id'=>$id,'type_name'=>$profile['name'],'category_id'=>$profile['category_id'],'icon'=>$profile['icon'],'physical_ports'=>$profile['physical_ports']];
foreach(['network','rack','map'] as $view)$input['display_'.$view]=$profile['display_modes'][$view];
db_execute('START TRANSACTION');try{
 foreach(array_keys(icct_nms_type_shapes()) as $shape){
  icct_nms_save_device_type($input+['shape'=>$shape],null,false);
  $map=icct_nms_map_data();$network=icct_nms_topology_data($map);$rack=icct_nms_rack_view_data();$mapped=$map['unlocated'];foreach($map['sites'] as $site)$mapped=array_merge($mapped,$site['devices']);
  foreach(['map'=>$mapped,'topology'=>$network['devices'],'rack'=>$rack['devices']] as $view=>$rows){$rows=array_column($rows,null,'id');if(($rows[$host['id']]['shape']??null)!==$shape)throw new RuntimeException('Shape mismatch in '.$view);}
  echo $shape." persisted and matched in map, topology and rack data.\n";
 }
 $renamed='QA Type '.bin2hex(random_bytes(4));
 icct_nms_save_device_type(array_replace($input,['type_name'=>$renamed,'physical_ports'=>0]),null,false);
 $saved=db_fetch_row_prepared('SELECT category_id,device_type FROM plugin_icct_nms_device_classification WHERE host_id=?',[$host['id']]);
 if($saved['device_type']!==$renamed || icct_nms_device_types()[$id]['physical_ports']!==0)throw new RuntimeException('Assigned device rename or zero ports failed');
 if(icct_nms_resolve_device_type($saved['category_id'],$renamed)!==$renamed)throw new RuntimeException('Renamed classification cannot resolve');
 $inventory=array_column(icct_nms_inventory(),null,'id');if($inventory[$host['id']]['device_type']!==$renamed)throw new RuntimeException('Inventory did not synchronize rename');
 echo "Assigned rename synchronized with classification and inventory; zero ports saved.\n";
}finally{db_execute('ROLLBACK');}
echo "Shape synchronization passed; all test writes rolled back.\n";
