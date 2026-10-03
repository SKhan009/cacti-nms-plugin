<?php
/** Topology appearance uses local assets and the NMS network/rack/map visibility model. */
function icct_nms_type_icons() {
    return ['switch'=>'Switch','router'=>'Router','server'=>'Server','workstation'=>'PC / desktop','laptop'=>'Laptop','phone'=>'Phone','ipphone'=>'Desk phone','ups'=>'UPS / battery','sensor'=>'Sensor','printer'=>'Printer','camera'=>'Camera','wireless'=>'Wireless AP','firewall'=>'Firewall','satellite'=>'Satellite / VSAT','device'=>'Generic device'];
}
function icct_nms_device_types() {
    $json = db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?', ['device_type_profiles']);
    $types = $json ? json_decode($json,true,512,JSON_THROW_ON_ERROR) : [];
    if (!is_array($types)) throw new RuntimeException('Invalid device type catalogue.');
    foreach (db_fetch_assoc("SELECT DISTINCT category_id,device_type FROM plugin_icct_nms_device_classification WHERE device_type<>''") as $row) {
        foreach ($types as $type) if ((int)$type['category_id']===(int)$row['category_id'] && $type['name']===$row['device_type']) continue 2;
        $icon='device'; foreach (icct_nms_type_icons() as $key=>$label) if ($key!=='device' && stripos($row['device_type'],$key)!==false) { $icon=$key; break; }
        $types[substr(sha1($row['category_id'].'|'.$row['device_type']),0,16)] = ['name'=>$row['device_type'],'category_id'=>(int)$row['category_id'],'icon'=>$icon,'physical_ports'=>null,'image'=>'','display_modes'=>['network'=>'icon','rack'=>'icon','map'=>'icon']];
    }
    return $types;
}
function icct_nms_type_asset($type,$view='network') {
    $mode=$type['display_modes'][$view] ?? 'icon';
    if ($mode==='none') return '';
    if ($mode==='image' && preg_match('/^uploads\/[a-f0-9]{32}\.(png|jpg|webp)$/D',$type['image'] ?? '')) return 'assets/images/device-types/'.$type['image'];
    $icon=isset(icct_nms_type_icons()[$type['icon'] ?? '']) ? $type['icon'] : 'device';
    return 'assets/images/device-types/'.$icon.'.svg';
}
function icct_nms_type_image_upload($file) {
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) return '';
    if (($file['error'] ?? -1)!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Upload a valid device image.');
    if (filesize($file['tmp_name'])>2097152) throw new InvalidArgumentException('Use an image up to 2 MB.');
    $info=@getimagesize($file['tmp_name']);
    $extensions=[IMAGETYPE_PNG=>'png',IMAGETYPE_JPEG=>'jpg',IMAGETYPE_WEBP=>'webp'];
    if (!$info || !isset($extensions[$info[2]]) || $info[0]*$info[1]>16000000) throw new InvalidArgumentException('Use PNG, JPEG or WebP, up to 16 megapixels.');
    if (!function_exists('imagecreatefromstring')) throw new RuntimeException('Device image uploads require the PHP GD extension.');
    $source=@imagecreatefromstring(file_get_contents($file['tmp_name']));
    if (!$source) throw new InvalidArgumentException('This image cannot be read. Choose another image.');
    $ratio=min(1,512/max($info[0],$info[1])); $target=imagecreatetruecolor(max(1,(int)($info[0]*$ratio)),max(1,(int)($info[1]*$ratio)));
    imagealphablending($target,false); imagesavealpha($target,true);
    imagecopyresampled($target,$source,0,0,0,0,imagesx($target),imagesy($target),$info[0],$info[1]);
    $relative='uploads/'.bin2hex(random_bytes(16)).'.png'; $path=__DIR__.'/../assets/images/device-types/'.$relative;
    try { if (!imagepng($target,$path)) throw new RuntimeException('Cannot store the device image. Check image folder permissions.'); chmod($path,0644); }
    finally { imagedestroy($target); imagedestroy($source); }
    return $relative;
}
function icct_nms_save_device_type($input,$file=null) {
    $lock='icct_device_types_'.substr(sha1((string)db_fetch_cell('SELECT DATABASE()')),0,20);
    if ((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,5)',[$lock])!==1) throw new RuntimeException('Device types are busy. Please retry.');
    $image='';
    try {
        $types=icct_nms_device_types(); $id=$input['type_id'] ?? '';
        if (!is_string($id) || ($id!=='' && !isset($types[$id]))) throw new InvalidArgumentException('Choose a saved device type.');
        $old=$types[$id] ?? null;
        $inUse=$old && (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_icct_nms_device_classification WHERE category_id=? AND device_type=?',[$old['category_id'],$old['name']]);
        if (($input['action'] ?? '')==='delete_type') {
            if (!$old) throw new InvalidArgumentException('Choose a saved device type.');
            if ($inUse) throw new InvalidArgumentException('Reassign devices using this type before deleting it.');
            unset($types[$id]);
        } else {
            $name=icct_backend_classification_text($input['type_name'] ?? '',150);
            $category=icct_backend_topology_integer($input['category_id'] ?? '',0,16777215,'Segment');
            if ($name==='') throw new InvalidArgumentException('Enter a device type name.');
            if ($category && !icct_backend_category_exists($category)) throw new InvalidArgumentException('Choose an existing segment.');
            if ($inUse && ($name!==$old['name'] || $category!==(int)$old['category_id'])) throw new InvalidArgumentException('Reassign devices before renaming this type or changing its segment.');
            foreach ($types as $key=>$type) if ($key!==$id && (int)$type['category_id']===$category && strcasecmp($type['name'],$name)===0) throw new InvalidArgumentException('This segment already has that device type.');
            $icon=$input['icon'] ?? ''; if (!is_string($icon) || !isset(icct_nms_type_icons()[$icon])) throw new InvalidArgumentException('Choose a topology icon.');
            $ports=icct_backend_topology_integer($input['physical_ports'] ?? '',0,65535,'Number of ports');
            $image=icct_nms_type_image_upload($file);
            $savedImage=$image ?: (empty($input['remove_image']) ? ($old['image'] ?? '') : '');
            $modes=[];
            foreach (['network','rack','map'] as $view) {
                $mode=$input['display_'.$view] ?? 'icon';
                if (!in_array($mode,['none','icon','image'],true)) throw new InvalidArgumentException('Choose None, Icon or Image for each topology view.');
                if ($mode==='image' && !$savedImage) throw new InvalidArgumentException('Upload a device image before choosing Image visibility.');
                $modes[$view]=$mode;
            }
            $id=$id ?: bin2hex(random_bytes(8));
            $types[$id]=['name'=>$name,'category_id'=>$category,'icon'=>$icon,'physical_ports'=>$ports,'image'=>$savedImage,'display_modes'=>$modes];
        }
        $json=json_encode($types,JSON_THROW_ON_ERROR);
        if (strlen($json)>60000) throw new InvalidArgumentException('The device type catalogue is full.');
        icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['device_type_profiles',$json]);
        if ($old && !empty($old['image']) && ($types[$id]['image'] ?? '') !== $old['image'] && preg_match('/^uploads\/[a-f0-9]{32}\.png$/D', $old['image'])) {
            $used = false; foreach ($types as $remaining) if (($remaining['image'] ?? '') === $old['image']) $used = true;
            if (!$used) @unlink(__DIR__.'/../assets/images/device-types/'.$old['image']);
        }
        return ($input['action'] ?? '')==='delete_type' ? 'Device type deleted.' : ($old ? 'Device type updated.' : 'Device type added.');
    } catch (Throwable $e) { if ($image) @unlink(__DIR__.'/../assets/images/device-types/'.$image); throw $e; }
    finally { db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]); }
}
