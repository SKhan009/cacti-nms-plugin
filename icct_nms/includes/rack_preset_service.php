<?php
require_once __DIR__."/rack_reservation_service.php";
/** Rack configurations are reusable defaults; instantiated racks remain site-owned. */
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
            if(db_fetch_cell_prepared('SELECT id FROM plugin_icct_nms_racks WHERE profile_id=? LIMIT 1',[$id]))throw new InvalidArgumentException('This rack configuration is used by saved racks.');
            unset($profiles[$id]);
            icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['rack_profiles',json_encode($profiles,JSON_THROW_ON_ERROR)]);
            icct_backend_category_execute('COMMIT');
            return 'Rack configuration deleted.';
        }
        $name=icct_backend_classification_text($input['rack_name'] ?? '',150);
        if($name==='')throw new InvalidArgumentException('Enter a rack name.');
        // New configurations represent one rack. Keep existing groups and their placements.
        $count=$id!=='' ? (int)($profiles[$id]['rack_count'] ?? 1) : 1;
        $units=icct_backend_topology_integer($input['unit_count'] ?? '',1,100,'Units per rack');
        foreach($profiles as $key=>$profile)if($key!==$id&&strcasecmp($name,$profile['name'])===0)throw new InvalidArgumentException('A rack configuration with this name already exists.');
        $id=$id ?: bin2hex(random_bytes(8));$profiles[$id]=['name'=>$name,'rack_count'=>$count,'unit_count'=>$units];
        $json=json_encode($profiles,JSON_THROW_ON_ERROR);
        if(strlen($json)>60000)throw new InvalidArgumentException('Rack configuration catalogue is full.');
        icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['rack_profiles',$json]);
        $sites=db_fetch_assoc_prepared('SELECT DISTINCT site_id FROM plugin_icct_nms_racks WHERE profile_id=?',[$id]);
        foreach($sites as $site)icct_nms_apply_site_rack_preset((int)$site['site_id'],$id);
        icct_backend_category_execute('COMMIT');
        return 'Rack configuration saved and site racks synchronized.';
    } catch(Throwable $failure) {icct_backend_category_execute('ROLLBACK');throw $failure;} finally {db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$rackLock]);}
}
function icct_nms_apply_site_rack_preset($site,$key) {
    $profile=icct_nms_rack_presets()[$key] ?? null;
    if(!$profile)throw new InvalidArgumentException('Select an existing rack configuration.');
    $racks=db_fetch_assoc_prepared('SELECT * FROM plugin_icct_nms_racks WHERE site_id=? AND profile_id=? ORDER BY rack_number,id',[$site,$key]);
    foreach($racks as $rack) {
        if((int)$rack['rack_number']>(int)$profile['rack_count']) {
            if(db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_icct_nms_rack_devices WHERE rack_id=?",[$rack['id']]) || db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_icct_nms_meta WHERE (meta_key LIKE 'rack_peripheral_%' AND meta_value=?) OR (meta_key=? AND meta_value!='[]')",[(string)$rack['id'],'rack_reserved_'.$rack['id']]))throw new InvalidArgumentException('Move devices and clear reserved units before reducing the rack count.');
            icct_backend_category_execute('DELETE FROM plugin_icct_nms_racks WHERE id=?',[$rack['id']]);
        } else icct_backend_topology_config_apply('save_rack',$site,['rack_id'=>$rack['id'],'name'=>$profile['name'],'unit_count'=>$profile['unit_count']]);
    }
    for($number=1;$number<=(int)$profile['rack_count'];$number++)if(!db_fetch_cell_prepared('SELECT id FROM plugin_icct_nms_racks WHERE site_id=? AND profile_id=? AND rack_number=?',[$site,$key,$number]))
        icct_backend_category_execute('INSERT INTO plugin_icct_nms_racks(site_id,profile_id,rack_number,name,unit_count,updated_by,updated_at) VALUES(?,?,?,?,?,?,NOW())',[$site,$key,$number,$profile['name'],$profile['unit_count'],icct_backend_current_user_id()]);
}

/** Catalogue choices include reusable presets not yet instantiated at a device site. */
function icct_nms_device_rack_choices($excludeDevice=0) {
    $rows=db_fetch_assoc("SELECT r.*,s.name AS site_name FROM plugin_icct_nms_racks r LEFT JOIN sites s ON s.id=r.site_id ORDER BY r.name,s.name,r.id");
    foreach ($rows as &$row) {
        $row['occupied']=icct_nms_rack_reserved_units((int)$row['id']);
        foreach (db_fetch_assoc_prepared('SELECT start_unit,unit_height FROM plugin_icct_nms_rack_devices WHERE rack_id=? AND host_id<>?',[(int)$row['id'],(int)$excludeDevice]) as $placement) for ($u=(int)$placement['start_unit'];$u<(int)$placement['start_unit']+(int)$placement['unit_height'];$u++) $row['occupied'][]=$u;
    } unset($row);
    foreach (icct_nms_rack_presets() as $key=>$profile) for ($number=1;$number<=(int)$profile['rack_count'];$number++) {
        $rows[]=['id'=>'preset:'.$key.':'.$number,'name'=>$profile['name'],'unit_count'=>(int)$profile['unit_count'],'rack_number'=>$number,'occupied'=>[],'site_id'=>0,'site_name'=>'','profile_id'=>$key];
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
        icct_nms_apply_site_rack_preset($site,$key);
        $rack=(int)db_fetch_cell_prepared('SELECT id FROM plugin_icct_nms_racks WHERE site_id=? AND profile_id=? AND rack_number=? ORDER BY id LIMIT 1',[$site,$key,$number]);
        if (!$rack) throw new RuntimeException('The preset rack is unavailable. Reload the page.');
        icct_backend_category_execute('COMMIT');
        return $rack;
    } catch (Throwable $failure) {icct_backend_category_execute('ROLLBACK');throw $failure;}
    finally {db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}
