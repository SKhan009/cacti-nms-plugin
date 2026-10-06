<?php
require_once __DIR__ . "/rack_reservation_service.php";
/** Each rack preset owns one physical rack shared by all placement surfaces. */
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
            $blocked=icct_nms_rack_delete_block_reason($id);
            if($blocked!=='')throw new InvalidArgumentException($blocked);
            icct_backend_category_execute('DELETE FROM plugin_icct_nms_racks WHERE profile_id=?',[$id]);
            unset($profiles[$id]);
            icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['rack_profiles',json_encode($profiles,JSON_THROW_ON_ERROR)]);
            icct_backend_category_execute('COMMIT');
            return 'Rack configuration deleted.';
        }
        $name=icct_backend_classification_text($input['rack_name'] ?? '',150);
        if($name==='')throw new InvalidArgumentException('Enter a rack name.');
        // A preset is one named physical rack.
        $count=1;
        $units=icct_backend_topology_integer($input['unit_count'] ?? '',1,100,'Units per rack');
        foreach($profiles as $key=>$profile)if($key!==$id&&strcasecmp($name,$profile['name'])===0)throw new InvalidArgumentException('A rack configuration with this name already exists.');
        $id=$id ?: bin2hex(random_bytes(8));$profiles[$id]=['name'=>$name,'rack_count'=>$count,'unit_count'=>$units];
        $json=json_encode($profiles,JSON_THROW_ON_ERROR);
        if(strlen($json)>60000)throw new InvalidArgumentException('Rack configuration catalogue is full.');
        icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['rack_profiles',$json]);
        icct_nms_apply_site_rack_preset(0,$id);
        icct_backend_category_execute('COMMIT');
        return 'Rack configuration saved and site racks synchronized.';
    } catch(Throwable $failure) {icct_backend_category_execute('ROLLBACK');throw $failure;} finally {db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$rackLock]);}
}
function icct_nms_apply_site_rack_preset($site,$key) {
    $profile=icct_nms_rack_presets()[$key] ?? null;
    if(!$profile)throw new InvalidArgumentException('Select an existing rack configuration.');
    $racks=db_fetch_assoc_prepared('SELECT * FROM plugin_icct_nms_racks WHERE profile_id=? ORDER BY id',[$key]);
    foreach($racks as $rack)icct_backend_topology_config_apply('save_rack',0,['rack_id'=>$rack['id'],'name'=>$profile['name'],'unit_count'=>$profile['unit_count']]);
    if(!$racks)icct_backend_category_execute('INSERT INTO plugin_icct_nms_racks(site_id,profile_id,rack_number,name,unit_count,updated_by,updated_at) VALUES(0,?,1,?,?,?,NOW())',[$key,$profile['name'],$profile['unit_count'],icct_backend_current_user_id()]);
}

/** The device editor and dashboard use the same concrete rack catalogue. */
function icct_nms_device_rack_choices($excludeDevice=0) {
    $rows=db_fetch_assoc("SELECT r.*,s.name AS site_name FROM plugin_icct_nms_racks r LEFT JOIN sites s ON s.id=r.site_id ORDER BY r.name,s.name,r.id");
    foreach ($rows as &$row) {
        $row['occupied']=icct_nms_rack_reserved_units((int)$row['id']);
        foreach (db_fetch_assoc_prepared('SELECT start_unit,unit_height FROM plugin_icct_nms_rack_devices WHERE rack_id=? AND host_id<>?',[(int)$row['id'],(int)$excludeDevice]) as $placement) for ($u=(int)$placement['start_unit'];$u<(int)$placement['start_unit']+(int)$placement['unit_height'];$u++) $row['occupied'][]=$u;
    } unset($row);
    return $rows;
}
/** Resolve legacy preset selections against the shared rack catalogue. */
function icct_nms_resolve_preset_rack($selection,$site,$position) {
    icct_backend_require_management(3);
    if (!is_string($selection) || !preg_match('/^preset:([a-f0-9]{16}):([1-9][0-9]*)$/D',$selection,$match)) throw new InvalidArgumentException('Select a saved rack.');
    $key=$match[1]; $number=(int)$match[2];

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
        icct_nms_apply_site_rack_preset(0,$key);
        $rack=(int)db_fetch_cell_prepared('SELECT id FROM plugin_icct_nms_racks WHERE profile_id=? AND rack_number=? ORDER BY id LIMIT 1',[$key,$number]);
        if (!$rack) throw new RuntimeException('The preset rack is unavailable. Reload the page.');
        icct_backend_category_execute('COMMIT');
        return $rack;
    } catch (Throwable $failure) {icct_backend_category_execute('ROLLBACK');throw $failure;}
    finally {db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}

/** Shared UI and mutation guard; checked again under the rack lock on delete. */
function icct_nms_rack_delete_block_reason($profile) {
    $reserved=false;
    foreach(db_fetch_assoc_prepared('SELECT * FROM plugin_icct_nms_racks WHERE profile_id=?',[$profile]) as $rack) {
        if(db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_icct_nms_rack_devices WHERE rack_id=?',[$rack['id']]) || db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_icct_nms_meta WHERE meta_key LIKE 'rack_peripheral_%' AND meta_value=?",[(string)$rack['id']]))return 'Cannot delete this rack because devices are assigned to it. Move or unassign them first.';
        if(icct_nms_rack_reservations((int)$rack['id']))$reserved=true;
    }
    return $reserved?'Cannot delete this rack because it contains reserved units. Clear the reservations first.':'';
}
