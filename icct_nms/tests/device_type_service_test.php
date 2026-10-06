<?php
require __DIR__ . '/../presets/services/backend/categories.php';
require __DIR__ . '/../dashboard/topology/services/backend/topology_config.php';
require __DIR__ . '/../presets/services/device_type_service.php';
$catalogue=['0123456789abcdef'=>['name'=>'Switch','category_id'=>4,'icon'=>'switch','physical_ports'=>16,'image'=>'','display_modes'=>['network'=>'icon','rack'=>'none','map'=>'icon']]]; $writes=[]; $assigned=false; $reclassifications=[]; $denied=false;
function db_fetch_cell($sql) { return 'test'; }
function db_fetch_assoc($sql) { return []; }
function db_fetch_cell_prepared($sql,$args) { global $catalogue,$assigned; if (str_contains($sql,'meta_value')) return json_encode($catalogue); if(str_contains($sql,'classification')) return $assigned?1:0; return 1; }
function db_fetch_assoc_prepared($sql,$args){return [['host_id'=>2]];}
function icct_backend_require_device_access($id){if($GLOBALS['denied'])throw new RuntimeException('Denied device');}
function icct_backend_current_user_id(){return 1;}
function db_execute_prepared($sql,$args=[]) { global $writes,$catalogue,$reclassifications; if(str_starts_with($sql,'UPDATE plugin_icct_nms_device_classification')){$reclassifications[]=$args;return true;}if(!str_contains($sql,'INSERT INTO plugin_icct_nms_meta'))return true; $writes[]=$args; $catalogue=json_decode($args[1],true); return true; }
$input=['action'=>'save_type','type_id'=>'0123456789abcdef','type_name'=>'Switch','category_id'=>4,'icon'=>'router','physical_ports'=>48,'display_network'=>'icon','display_rack'=>'none','display_map'=>'icon'];
function reject_type($changes,$expected) { global $input,$writes; $n=count($writes); try { icct_nms_save_device_type(array_replace($input,$changes)); throw new RuntimeException('Expected rejection'); } catch(InvalidArgumentException $e) { if(!str_contains($e->getMessage(),$expected) || count($writes)!==$n) throw new RuntimeException('Validation failed: '.$e->getMessage()); } }
reject_type(['physical_ports'=>-1],'whole number');
reject_type(['icon'=>'../private'],'topology icon');
reject_type(['display_network'=>'image'],'Upload');
reject_type(['display_map'=>'invalid'],'None, Icon or Image');
$assigned=true; reject_type(['action'=>'delete_type'],'Reassign');
icct_nms_save_device_type(array_replace($input,['type_name'=>'Renamed','physical_ports'=>0]));
if($catalogue[$input['type_id']]['name']!=='Renamed' || $catalogue[$input['type_id']]['physical_ports']!==0 || $reclassifications[0]!==[4,'Renamed',1,4,'Switch'])throw new RuntimeException('Assigned rename or zero ports failed');
$denied=true;$before=$catalogue;
try{icct_nms_save_device_type($input);throw new LogicException('Unauthorized rename accepted');}catch(RuntimeException $e){if($e->getMessage()!=='Denied device')throw $e;}
if($catalogue!==$before)throw new RuntimeException('Unauthorized rename wrote catalogue');$denied=false;
icct_nms_save_device_type($input);$assigned=false;
icct_nms_save_device_type($input);
if($catalogue[$input['type_id']]['physical_ports']!==48 || $catalogue[$input['type_id']]['display_modes']['rack']!=='none') throw new RuntimeException('Profile not persisted');
reject_type(['type_id'=>'','icon'=>'device'],'already has');
if(icct_nms_type_asset(['icon'=>'../../etc','display_modes'=>['network'=>'icon']])!=='assets/images/icons/device.svg') throw new RuntimeException('Unsafe asset');
if(icct_nms_type_asset(['display_modes'=>['rack'=>'none']],'rack')!=='') throw new RuntimeException('None visibility failed');
if(icct_nms_resolve_device_type(4,'Switch')!=='Switch') throw new RuntimeException('Saved type not resolved');
try { icct_nms_resolve_device_type(7,'Switch'); throw new RuntimeException('Cross-segment type accepted'); } catch(InvalidArgumentException $e) {}
$folder=__DIR__ . '/../assets/images/icons'; if(!is_dir($folder)) mkdir($folder);
$files=['test-discovery.svg','Office Switch.PNG','office-switch.jpg','office-switch.jpeg','office-switch.gif','office-switch.webp','office-switch.bmp','office-switch.ico','office-switch.avif','Upper.SVG','ignored.txt'];
try {
    foreach($files as $filename) file_put_contents($folder.'/'.$filename,'fixture');
    $icons=icct_nms_type_icons();
    foreach($files as $filename) {
        $key=strtolower(pathinfo($filename,PATHINFO_EXTENSION))==='svg' ? pathinfo($filename,PATHINFO_FILENAME) : $filename;
        if($filename==='ignored.txt') { if(isset($icons[$key])) throw new RuntimeException('Non-image discovered'); continue; }
        if(!isset($icons[$key]) || icct_nms_type_icon_asset($key)!=='assets/images/icons/'.rawurlencode($filename)) throw new RuntimeException('Icon discovery failed: '.$filename);
    }
    icct_nms_save_device_type(array_replace($input,['icon'=>'Office Switch.PNG']));
    if(icct_nms_type_asset($catalogue[$input['type_id']])!=='assets/images/icons/Office%20Switch.PNG') throw new RuntimeException('Custom image selection failed');
} finally { foreach($files as $filename) @unlink($folder.'/'.$filename); }
reject_type(['shape'=>'<script>'],'device shape');
reject_type(['shape'=>['square']],'device shape');
foreach(array_keys(icct_nms_type_shapes()) as $shape) {
    icct_nms_save_device_type(array_replace($input,['shape'=>$shape]));
    $types=icct_nms_device_types();
    if($types[$input['type_id']]['shape']!==$shape || icct_nms_device_shape(4,'Switch',$types)!==$shape) throw new RuntimeException('Shape did not persist or resolve for assigned devices');
    if(icct_nms_device_shape(7,'Switch',$types)!=='rectangle') throw new RuntimeException('Shape leaked across segments');
}
if(icct_nms_type_shape(['icon'=>'switch'])!=='wide' || icct_nms_type_shape(['shape'=>'bad'])!=='rectangle') throw new RuntimeException('Legacy shape fallback failed');
icct_nms_save_device_type(['action'=>'delete_type','type_id'=>$input['type_id']]);
icct_nms_save_device_type(['type_name'=>'Name only']);$minimal=array_values($catalogue)[0];
if($minimal['category_id']!==0 || $minimal['icon']!=='device' || $minimal['physical_ports']!==null || $minimal['shape']!=='rectangle')throw new RuntimeException('Optional defaults failed');
icct_nms_save_device_type(['action'=>'delete_type','type_id'=>array_key_first($catalogue)]);
if($catalogue) throw new RuntimeException('Delete failed');
echo "Device type validation, visibility and persistence tests passed.\n";
