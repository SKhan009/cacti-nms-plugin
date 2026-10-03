<?php
/** Rack configurations are reusable defaults; instantiated racks remain node-owned. */
function icct_nms_rack_presets() {
    $json=db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['rack_profiles']);
    return $json ? json_decode($json,true,512,JSON_THROW_ON_ERROR) : [];
}
function icct_nms_save_rack_preset($input) {
    icct_backend_require_management(3);
    $lock='icct_nms_rack_profiles';
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,10)',[$lock])!==1)throw new RuntimeException('Rack configurations are busy. Retry shortly.');
    try {
        $profiles=icct_nms_rack_presets();$id=$input['rack_profile_id'] ?? '';
        if(!is_string($id)||($id!==''&&!preg_match('/^[a-f0-9]{16}$/D',$id)))throw new InvalidArgumentException('Invalid rack configuration.');
        if($id!==''&&!isset($profiles[$id]))throw new InvalidArgumentException('This rack configuration no longer exists.');
        $name=icct_backend_classification_text($input['rack_name'] ?? '',150);
        if($name==='')throw new InvalidArgumentException('Enter a rack name.');
        $count=icct_backend_topology_integer($input['rack_count'] ?? '',1,100,'Number of racks');
        $units=icct_backend_topology_integer($input['unit_count'] ?? '',1,100,'Units per rack');
        foreach($profiles as $key=>$profile)if($key!==$id&&strcasecmp($name,$profile['name'])===0)throw new InvalidArgumentException('A rack configuration with this name already exists.');
        $id=$id ?: bin2hex(random_bytes(8));$profiles[$id]=['name'=>$name,'rack_count'=>$count,'unit_count'=>$units];
        $json=json_encode($profiles,JSON_THROW_ON_ERROR);
        if(strlen($json)>60000)throw new InvalidArgumentException('Rack configuration catalogue is full.');
        icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['rack_profiles',$json]);
        return 'Rack configuration saved. Select it on a node to apply these values.';
    } finally {db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
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
