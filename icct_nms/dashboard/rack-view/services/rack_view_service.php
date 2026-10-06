<?php
require_once __DIR__ . "/../../../presets/services/rack_reservation_service.php";
require_once __DIR__ . "/../../../fcaps/services/fcaps_service.php";
/** Both rack editing surfaces share these placement records and validation. */
function icct_nms_rack_units($units,$capacity) {
    if (!is_array($units) || !$units || count($units)>100) throw new InvalidArgumentException('Select at least one rack unit.');
    $numbers=[];
    foreach ($units as $unit) $numbers[]=icct_backend_topology_integer($unit,1,$capacity,'Rack unit');
    sort($numbers,SORT_NUMERIC);
    if (count(array_unique($numbers))!==count($numbers) || end($numbers)-$numbers[0]+1!==count($numbers)) throw new InvalidArgumentException('Select consecutive units for one device.');
    return [$numbers[0],count($numbers)];
}
function icct_nms_rack_revision($id) {
    $row=db_fetch_row_prepared('SELECT rack_id,start_unit,unit_height,updated_at FROM plugin_icct_nms_rack_devices WHERE host_id=?',[$id]) ?: [];
    $peripheral=(string)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['rack_peripheral_'.$id]);
    return hash('sha256',json_encode([$row,$peripheral]));
}
function icct_nms_rack_place($id,$site,$rack,$units,$peripheral=false,$revision=null,$unused=false,$ownTransaction=true) {
    icct_backend_require_management(3); icct_backend_require_device_access($id);
    $lock='icct_backend_racks_'.substr(hash('sha256',(string)db_fetch_cell('SELECT DATABASE()')),0,32);
    if ($ownTransaction && (int)db_fetch_cell_prepared('SELECT GET_LOCK(?,10)',[$lock])!==1) throw new RuntimeException('Rack configuration is busy. Retry shortly.');
    if ($ownTransaction) icct_backend_category_execute('START TRANSACTION');
    try {
        $host=db_fetch_row_prepared("SELECT site_id FROM host WHERE id=? AND deleted='' FOR UPDATE",[$id]);
        if (!$host || (int)$host['site_id']!==$site) throw new InvalidArgumentException('Device site changed. Reload the page.');
        if ($revision!==null && (!is_string($revision) || !hash_equals(icct_nms_rack_revision($id),$revision))) throw new InvalidArgumentException('Placement changed in another page. Reload before moving this device.');
        $previousPlacement=db_fetch_row_prepared('SELECT rack_id FROM plugin_icct_nms_rack_devices WHERE host_id=?',[$id]);
        $previousPeripheral=(int)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['rack_peripheral_'.$id]);
        if ($rack) {
            $record=db_fetch_row_prepared('SELECT r.* FROM plugin_icct_nms_racks r WHERE r.id=? FOR UPDATE',[$rack]);
            if (!$record || ((int)$record['site_id']!==0 && (int)$record['site_id']!==$site)) throw new InvalidArgumentException('Select a rack at the device site.');
            if (!$peripheral) {
                [$start,$height]=icct_nms_rack_units($units,(int)$record['unit_count']);
                icct_backend_topology_config_apply('place_device',$site,['rack_id'=>$rack,'host_id'=>$id,'start_unit'=>$start,'unit_height'=>$height]);
            }
        }
        if (!$rack || $peripheral) icct_backend_category_execute('DELETE FROM plugin_icct_nms_rack_devices WHERE host_id=?',[$id]);
        if ($rack && $peripheral) icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['rack_peripheral_'.$id,(string)$rack]);
        else icct_backend_category_execute('DELETE FROM plugin_icct_nms_meta WHERE meta_key=?',['rack_peripheral_'.$id]);
        if ($ownTransaction) icct_backend_category_execute('COMMIT');
    } catch (Throwable $error) {if ($ownTransaction) icct_backend_category_execute('ROLLBACK');throw $error;}
    finally {if ($ownTransaction) db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function icct_nms_rack_view_data() {
    $devices=icct_nms_inventory(); $types=icct_nms_device_types(); $allowed=[]; $out=[];
    foreach ($devices as $device) {
        $id=(int)$device['id']; $allowed[$id]=true;
        $peripheral=(int)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['rack_peripheral_'.$id]);
        $rackId=(int)($device['rack_id'] ?: $peripheral);
        $asset='';
        foreach ($types as $type) if ((int)$type['category_id']===(int)$device['category_id'] && $type['name']===$device['device_type']) {$asset=icct_nms_type_asset($type,'rack');break;}
        $counts=array_fill_keys(['Critical','Major','Minor','Warning','Information'],0);
        foreach (icct_nms_fault_observations($device) as $fault) if(isset($counts[$fault['state']]))$counts[$fault['state']]++;
        $severity='';foreach($counts as $level=>$count)if($count){$severity=$level;break;}
        $out[]=['rack_asset'=>$asset,'fault_count'=>array_sum($counts),'fault_severity'=>$severity,'shape'=>icct_nms_device_shape($device['category_id'],$device['device_type'],$types),'id'=>$id,'name'=>$device['description'],'site_id'=>(int)$device['site_id'],'status'=>$device['status_label'],'rack_id'=>(int)($device['rack_id'] ?: $peripheral),'start'=>(int)$device['start_unit'],'height'=>(int)($device['unit_height'] ?: 1),'peripheral'=>(bool)$peripheral,'revision'=>icct_nms_rack_revision($id)];
    }
    $racks=db_fetch_assoc('SELECT r.*,s.name AS site_name FROM plugin_icct_nms_racks r LEFT JOIN sites s ON s.id=r.site_id ORDER BY s.name,r.rack_number,r.id');
    foreach ($racks as &$rack) {
        $rack['blocked']=[];
        $rack['reservations']=icct_nms_rack_reservations((int)$rack['id']);
        $rack['reservation_revision']=icct_nms_rack_reservation_revision((int)$rack['id']);
        foreach (db_fetch_assoc_prepared('SELECT host_id,start_unit,unit_height FROM plugin_icct_nms_rack_devices WHERE rack_id=?',[(int)$rack['id']]) as $placement) if (!isset($allowed[(int)$placement['host_id']])) for ($u=(int)$placement['start_unit'];$u<(int)$placement['start_unit']+(int)$placement['unit_height'];$u++) $rack['blocked'][]=$u;
    } unset($rack);
    return ['sites'=>db_fetch_assoc('SELECT id,name FROM sites ORDER BY name,id'),'racks'=>$racks,'devices'=>$out,'management'=>is_realm_allowed(3)];
}

/** Save a whole drag draft atomically, including swaps between occupied units. */
function icct_nms_rack_save_draft($moves,$reservations=[]) {
    icct_backend_require_management(3);
    if (!is_array($moves) || !is_array($reservations) || (!$moves && !$reservations) || count($moves)>200 || count($reservations)>200) throw new InvalidArgumentException('Select between 1 and 200 device moves.');
    $lock='icct_backend_racks_'.substr(hash('sha256',(string)db_fetch_cell('SELECT DATABASE()')),0,32);
    if ((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,10)',[$lock])!==1) throw new RuntimeException('Rack configuration is busy. Retry shortly.');
    icct_backend_category_execute('START TRANSACTION');
    try {
        $checked=[];
        foreach ($moves as $move) {
            if (!is_array($move)) throw new InvalidArgumentException('Invalid device move.');
            $id=icct_backend_topology_integer($move['host_id'] ?? 0,1,16777215,'Device ID');
            if (isset($checked[$id])) throw new InvalidArgumentException('Duplicate device move.');
            icct_backend_require_device_access($id);
            $host=db_fetch_row_prepared("SELECT site_id FROM host WHERE id=? AND deleted='' FOR UPDATE",[$id]);
            if (!$host) throw new InvalidArgumentException('Device no longer exists.');
            $revision=$move['revision'] ?? '';
            if (!is_string($revision) || !hash_equals(icct_nms_rack_revision($id),$revision)) throw new InvalidArgumentException('A device placement changed in another page. Discard this draft and reload before editing.');
            $rack=icct_backend_topology_integer($move['rack_id'] ?? 0,0,2147483647,'Rack');
            if ($rack) {
                $record=db_fetch_row_prepared('SELECT r.* FROM plugin_icct_nms_racks r WHERE r.id=? FOR UPDATE',[$rack]);
                if (!$record || ((int)$record['site_id']!==0 && (int)$record['site_id']!==(int)$host['site_id'])) throw new InvalidArgumentException('Select a rack at the device site.');
            }
            $checked[$id]=['site'=>(int)$host['site_id'],'rack'=>$rack,'units'=>$move['units'] ?? [],'peripheral'=>($move['peripheral'] ?? false)===true];
        }
        // All original memberships and revisions are checked before freeing draft slots.
        foreach ($checked as $id=>$move) {
            icct_backend_category_execute('DELETE FROM plugin_icct_nms_rack_devices WHERE host_id=?',[$id]);
            icct_backend_category_execute('DELETE FROM plugin_icct_nms_meta WHERE meta_key=?',['rack_peripheral_'.$id]);
        }
        $seen=[];
        foreach($reservations as $change) {
            $rack=icct_backend_topology_integer($change['rack_id'] ?? 0,1,2147483647,'Rack');
            if(isset($seen[$rack]))throw new InvalidArgumentException('Duplicate rack reservation.');$seen[$rack]=true;
            $record=db_fetch_row_prepared('SELECT * FROM plugin_icct_nms_racks WHERE id=? FOR UPDATE',[$rack]);
            if(!$record || !hash_equals(icct_nms_rack_reservation_revision($rack),(string)($change['revision'] ?? '')))throw new InvalidArgumentException('Reserved units changed. Discard and reload before editing.');
            $items=$change['items'] ?? null;if(!is_array($items)||count($items)>100)throw new InvalidArgumentException('Invalid reserved units.');
            $occupied=[];$clean=[];$ids=[];
            foreach($items as $item){
                $id=$item['id'] ?? '';if(!is_string($id)||!preg_match('/^[a-zA-Z0-9-]{1,80}$/',$id)||isset($ids[$id]))throw new InvalidArgumentException('Invalid reservation ID.');$ids[$id]=true;
                $start=icct_backend_topology_integer($item['start'] ?? 0,1,(int)$record['unit_count'],'Start unit');
                $height=icct_backend_topology_integer($item['height'] ?? 0,1,(int)$record['unit_count'],'Units occupied');
                if($start+$height-1>(int)$record['unit_count'])throw new InvalidArgumentException('Reserved units exceed rack capacity.');
                for($u=$start;$u<$start+$height;$u++){if(isset($occupied[$u]))throw new InvalidArgumentException('Reserved units overlap.');$occupied[$u]=true;}
                if((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_icct_nms_rack_devices WHERE rack_id=? AND start_unit<=? AND start_unit+unit_height-1>=?',[$rack,$start+$height-1,$start]))throw new InvalidArgumentException('These rack units are already occupied.');
                $clean[]=['id'=>$id,'start'=>$start,'height'=>$height];
            }
            icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['rack_reserved_'.$rack,json_encode($clean,JSON_THROW_ON_ERROR)]);
        }
        foreach ($checked as $id=>$move) icct_nms_rack_place($id,$move['site'],$move['rack'],$move['units'],$move['peripheral'],null,$move['rack']===0,false);
        icct_backend_category_execute('COMMIT');
        return array_keys($checked);
    } catch (Throwable $error) {icct_backend_category_execute('ROLLBACK');throw $error;}
    finally {db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}
