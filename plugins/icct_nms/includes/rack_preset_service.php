<?php
require_once __DIR__."/rack_reservation_service.php";
/** Rack configurations are reusable defaults; instantiated racks remain node-owned. */
function icct_nms_rack_presets() {
    $json=db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['rack_profiles']);
    return $json ? json_decode($json,true,512,JSON_THROW_ON_ERROR) : [];
}
function icct_nms_save_rack_preset($input) {
    icct_backend_require_management(3);
    $rackLock='icct_backend_racks_'.substr(hash('sha256',(string)db_fetch_cell('SELECT DATABASE()')),0,32);
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,10)',[$rackLock])!==1)throw new RuntimeException('Rack configuration is busy. Retry shortly.');
    $lock='icct_nms_rack_profiles';
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,10)',[$lock])!==1){db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$rackLock]);throw new RuntimeException('Rack configurations are busy. Retry shortly.');}
    try {
        icct_backend_category_execute('START TRANSACTION');
        $profiles=icct_nms_rack_presets();$id=$input['rack_profile_id'] ?? '';
        if(!is_string($id)||($id!==''&&!preg_match('/^[a-f0-9]{16}$/D',$id)))throw new InvalidArgumentException('Invalid rack configuration.');
        if($id!==''&&!isset($profiles[$id]))throw new InvalidArgumentException('This rack configuration no longer exists.');
        if(($input['action'] ?? '')==='delete_rack_profile') {
            if($id==='')throw new InvalidArgumentException('Choose a saved rack configuration.');
            if(db_fetch_cell_prepared("SELECT n.id FROM plugin_icct_nms_rack_nodes n JOIN plugin_icct_nms_meta m ON m.meta_key=CONCAT('node_rack_profile_',n.id) WHERE m.meta_value=? LIMIT 1",[$id]))throw new InvalidArgumentException('This rack configuration is assigned to a node. Select another configuration on that node before deleting it.');
            unset($profiles[$id]);
            icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['rack_profiles',json_encode($profiles,JSON_THROW_ON_ERROR)]);
            icct_backend_category_execute('COMMIT');
            return 'Rack configuration deleted.';
        }
        $name=icct_backend_classification_text($input['rack_name'] ?? '',150);
        if($name==='')throw new InvalidArgumentException('Enter a rack name.');
        $count=icct_backend_topology_integer($input['rack_count'] ?? '',1,100,'Number of racks');
        $units=icct_backend_topology_integer($input['unit_count'] ?? '',1,100,'Units per rack');
        foreach($profiles as $key=>$profile)if($key!==$id&&strcasecmp($name,$profile['name'])===0)throw new InvalidArgumentException('A rack configuration with this name already exists.');
        $id=$id ?: bin2hex(random_bytes(8));$profiles[$id]=['name'=>$name,'rack_count'=>$count,'unit_count'=>$units];
        $json=json_encode($profiles,JSON_THROW_ON_ERROR);
        if(strlen($json)>60000)throw new InvalidArgumentException('Rack configuration catalogue is full.');
        icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['rack_profiles',$json]);
        $nodes=db_fetch_assoc_prepared("SELECT n.* FROM plugin_icct_nms_rack_nodes n JOIN plugin_icct_nms_meta m ON m.meta_key=CONCAT('node_rack_profile_',n.id) WHERE m.meta_value=?",[$id]);
        foreach($nodes as $node)icct_nms_apply_node_rack_preset((int)$node['id'],(int)$node['site_id'],$node['name'],['rack_profile_id'=>$id],$node['node_kind']);
        icct_backend_category_execute('COMMIT');
        return 'Rack configuration saved and assigned node racks synchronized.';
    } catch(Throwable $failure) {icct_backend_category_execute('ROLLBACK');throw $failure;} finally {db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$rackLock]);}
}
function icct_nms_apply_node_rack_preset($id,$site,$name,$input,$kind='node') {
    $key=$input['rack_profile_id'] ?? '';
    if(!is_string($key))throw new InvalidArgumentException('Select a rack configuration.');
    if($key==='')return;
    $profile=icct_nms_rack_presets()[$key] ?? null;
    if(!$profile)throw new InvalidArgumentException('Select an existing rack configuration.');
    icct_backend_topology_config_apply('save_node',$site,['node_id'=>$id,'name'=>$name,'node_kind'=>$kind,'rack_count'=>$profile['rack_count'],'unit_count'=>$profile['unit_count']]);
    $racks=db_fetch_assoc_prepared('SELECT * FROM plugin_icct_nms_racks WHERE node_id=? ORDER BY rack_number',[$id]);
    foreach($racks as $rack)icct_backend_topology_config_apply('save_rack',$site,['rack_id'=>$rack['id'],'name'=>$profile['name'].($profile['rack_count']>1?' '.$rack['rack_number']:''),'unit_count'=>$profile['unit_count']]);
    icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['node_rack_profile_'.$id,$key]);
}

/** Catalogue choices include reusable presets not yet instantiated at a device site. */
function icct_nms_device_rack_choices($excludeDevice=0) {
    $rows=db_fetch_assoc("SELECT r.*,n.site_id,n.name AS node_name,m.meta_value AS profile_id FROM plugin_icct_nms_racks r JOIN plugin_icct_nms_rack_nodes n ON n.id=r.node_id LEFT JOIN plugin_icct_nms_meta m ON m.meta_key=CONCAT('node_rack_profile_',n.id) ORDER BY r.name,n.name,r.id");
    foreach ($rows as &$row) {
        $row['occupied']=icct_nms_rack_reserved_units((int)$row['id']);
        foreach (db_fetch_assoc_prepared('SELECT start_unit,unit_height FROM plugin_icct_nms_rack_devices WHERE rack_id=? AND host_id<>?',[(int)$row['id'],(int)$excludeDevice]) as $placement) for ($u=(int)$placement['start_unit'];$u<(int)$placement['start_unit']+(int)$placement['unit_height'];$u++) $row['occupied'][]=$u;
    } unset($row);
    foreach (icct_nms_rack_presets() as $key=>$profile) for ($number=1;$number<=(int)$profile['rack_count'];$number++) {
        $rows[]=['id'=>'preset:'.$key.':'.$number,'name'=>$profile['name'].((int)$profile['rack_count']>1?' '.$number:''),'unit_count'=>(int)$profile['unit_count'],'rack_number'=>$number,'node_id'=>0,'occupied'=>[],'site_id'=>0,'node_name'=>'','profile_id'=>$key];
    }
    return $rows;
}
/** Instantiate a selected reusable rack at its device site under the shared rack lock. */
function icct_nms_resolve_preset_rack($selection,$site,$position) {
    icct_backend_require_management(3);
    if (!is_string($selection) || !preg_match('/^preset:([a-f0-9]{16}):([1-9][0-9]*)$/D',$selection,$match)) throw new InvalidArgumentException('Select a saved rack.');
    $key=$match[1]; $number=(int)$match[2];
    if (!$site || !db_fetch_cell_prepared('SELECT id FROM sites WHERE id=?',[$site])) throw new InvalidArgumentException('Select a device site before choosing a rack.');
    $lock='icct_backend_racks_'.substr(hash('sha256',(string)db_fetch_cell('SELECT DATABASE()')),0,32);
    if ((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,10)',[$lock])!==1) throw new RuntimeException('Rack configuration is busy. Retry shortly.');
    icct_backend_category_execute('START TRANSACTION');
    try {
        $profile=icct_nms_rack_presets()[$key] ?? null;
        if (!$profile || $number>(int)$profile['rack_count']) throw new InvalidArgumentException('This rack preset changed. Reload the page.');
        if ($position!=='peripheral') {
        $parts=explode(':',(string)$position);
        if (count($parts)!==2) throw new InvalidArgumentException('Select rack placement.');
        $start=icct_backend_topology_integer($parts[0],1,100,'Rack unit');
        $height=icct_backend_topology_integer($parts[1],1,100,'Rack height');
        if ($start+$height-1>(int)$profile['unit_count']) throw new InvalidArgumentException('Placement exceeds rack capacity.');
        }
        $node=db_fetch_row_prepared("SELECT n.* FROM plugin_icct_nms_rack_nodes n JOIN plugin_icct_nms_meta m ON m.meta_key=CONCAT('node_rack_profile_',n.id) WHERE n.site_id=? AND m.meta_value=? ORDER BY n.id LIMIT 1",[$site,$key]);
        if (!$node) {
            $name=mb_substr($profile['name'],0,120).' racks';
            if (db_fetch_cell_prepared('SELECT id FROM plugin_icct_nms_rack_nodes WHERE site_id=? AND name=?',[$site,$name])) $name.=' '.substr($key,0,8);
            $id=icct_backend_topology_config_apply('save_node',$site,['node_id'=>0,'name'=>$name,'node_kind'=>'node','rack_count'=>$profile['rack_count'],'unit_count'=>$profile['unit_count']]);
            icct_nms_apply_node_rack_preset($id,$site,$name,['rack_profile_id'=>$key]);
            $node=['id'=>$id];
        }
        $rack=(int)db_fetch_cell_prepared('SELECT id FROM plugin_icct_nms_racks WHERE node_id=? AND rack_number=?',[(int)$node['id'],$number]);
        if (!$rack) throw new RuntimeException('The preset rack is unavailable. Reload the page.');
        icct_backend_category_execute('COMMIT');
        return $rack;
    } catch (Throwable $failure) {icct_backend_category_execute('ROLLBACK');throw $failure;}
    finally {db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}
