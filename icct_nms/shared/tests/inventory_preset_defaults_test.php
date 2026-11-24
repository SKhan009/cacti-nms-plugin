<?php
require __DIR__ . '/../../presets/services/preset_seed.php';
function expectStarter($condition,$message) { if(!$condition)throw new RuntimeException($message); }
$categories=[];$meta=[];$snapshot=null;$failMarker=false;$released=0;
function db_fetch_cell($sql) { return 'preset_test'; }
function db_fetch_assoc($sql) {
    global $categories;
    return str_contains($sql,'plugin_icct_nms_categories') ? array_values($categories) : [];
}
function db_fetch_cell_prepared($sql,$args) {
    global $meta,$categories,$released;
    if(str_contains($sql,'RELEASE_LOCK')) { $released++; return 1; }
    if(str_contains($sql,'GET_LOCK')) return 1;
    if(str_contains($sql,'meta_value')) return $meta[$args[0]]??'';
    if(str_contains($sql,'WHERE seed_key')) { foreach($categories as $row)if(($row['seed_key']??'')===$args[0])return $row['id']; }
    return 0;
}
function db_execute_prepared($sql,$args) {
    global $meta,$categories,$snapshot,$failMarker;
    if($sql==='START TRANSACTION') { $snapshot=[$categories,$meta]; return true; }
    if($sql==='ROLLBACK') { [$categories,$meta]=$snapshot; return true; }
    if($sql==='COMMIT') { $snapshot=null; return true; }
    if(str_starts_with($sql,'UPDATE plugin_icct_nms_categories')) {
        $categories[$args[1]]['seed_key']=$args[0];return true;
    }
    if(str_starts_with($sql,'INSERT INTO plugin_icct_nms_categories')) {
        $id=$categories ? max(array_keys($categories))+1 : 1;
        $categories[$id]=['id'=>$id,'name'=>$args[0],'seed_key'=>$args[1]];return true;
    }
    if(str_starts_with($sql,'INSERT INTO plugin_icct_nms_meta')) {
        if($failMarker && $args[0]==='inventory_starter_defaults_v1')return false;
        $meta[$args[0]]=$args[1];return true;
    }
    throw new LogicException('Unexpected database write');
}
icct_nms_seed_inventory_presets();
$types=json_decode($meta['device_type_profiles'],true);
expectStarter(count($categories)===10 && count($types)===19,'Fresh install must seed 10 segments and 19 types.');
foreach($types as $type) {
    expectStarter(isset(icct_nms_type_icons()[$type['icon']]),'Default icon must exist.');
    expectStarter(icct_nms_type_asset($type,'image')!=='','Every starter must have a bundled image.');
    expectStarter($type['physical_ports']===null,'Starter must not invent physical port counts.');
}
$before=[$categories,$meta];icct_nms_seed_inventory_presets();
expectStarter($before===[$categories,$meta],'Repeated upgrade must be idempotent.');
$id=array_key_first($types);$types[$id]['name']='Renamed by operator';$types[$id]['image']='';unset($types[array_key_last($types)]);
$meta['device_type_profiles']=json_encode($types);$before=[$categories,$meta];icct_nms_seed_inventory_presets();
expectStarter($before===[$categories,$meta],'Later upgrades must preserve removed images, renames and deleted presets.');
$categories=[77=>['id'=>77,'name'=>'NETWORK','seed_key'=>null,'description'=>'Operator text'],121=>['id'=>121,'name'=>'Computers','seed_key'=>null]];
$custom=['name'=>'Switch','category_id'=>77,'icon'=>'router','shape'=>'tall','physical_ports'=>48,
    'image'=>'uploads/'.str_repeat('a',32).'.png','display_modes'=>['network'=>'none','rack'=>'image','map'=>'none']];
$meta=['device_type_profiles'=>json_encode(['custom-switch'=>$custom,'existing-server'=>[
    'name'=>'Server','category_id'=>121,'icon'=>'server','shape'=>'rectangle','physical_ports'=>null,'image'=>'',
    'display_modes'=>['network'=>'icon','rack'=>'none','map'=>'none']]])];
icct_nms_seed_inventory_presets();$types=json_decode($meta['device_type_profiles'],true);
foreach($custom as $key=>$value)expectStarter($types['custom-switch'][$key]===$value,'Existing operator field changed: '.$key);
expectStarter($categories[77]['name']==='NETWORK' && $categories[77]['description']==='Operator text','Existing segment data must remain unchanged.');
expectStarter(count($categories)===10,'Segments must match by name rather than fixed IDs.');
expectStarter(count(array_filter($types,fn($t)=>$t['name']==='Switch' && $t['category_id']===77))===1,'Existing types must not be duplicated.');
expectStarter($types['existing-server']['image']==='defaults/server.svg','Initial upgrade must fill an absent default image.');
expectStarter($types['existing-server']['display_modes']['rack']==='none','Visibility must be preserved.');
$categories=[];$meta=[];$failMarker=true;
try { icct_nms_seed_inventory_presets();throw new LogicException('Expected rollback'); } catch(RuntimeException $e) {}
expectStarter($categories===[] && $meta===[],'A failed seed must roll back both catalogues and the marker.');
expectStarter($released===5,'Every seed path must release its lock.');
foreach(['defaults/../server.svg','defaults/not-bundled.svg','https://example.com/image.svg','uploads/unsafe.svg'] as $bad)expectStarter(icct_nms_type_image_asset($bad)==='','Unsafe or absent assets must not be accepted.');
expectStarter(icct_nms_type_asset(['image'=>'','icon'=>'server'],'image')==='','Image View must respect an explicitly missing image.');
echo "PASS: Fresh defaults, nonfixed IDs, custom field preservation, images, idempotency, deletion/rename preservation and transactional rollback.\n";
