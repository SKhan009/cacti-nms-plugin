<?php
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
function icct_nms_rack_place($id,$site,$rack,$units,$peripheral=false,$revision=null) {
    icct_backend_require_management(3); icct_backend_require_device_access($id);
    $lock='icct_backend_racks_'.substr(hash('sha256',(string)db_fetch_cell('SELECT DATABASE()')),0,32);
    if ((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,10)',[$lock])!==1) throw new RuntimeException('Rack configuration is busy. Retry shortly.');
    icct_backend_category_execute('START TRANSACTION');
    try {
        $host=db_fetch_row_prepared("SELECT site_id FROM host WHERE id=? AND deleted='' FOR UPDATE",[$id]);
        if (!$host || (int)$host['site_id']!==$site) throw new InvalidArgumentException('Device site changed. Reload the page.');
        if ($revision!==null && (!is_string($revision) || !hash_equals(icct_nms_rack_revision($id),$revision))) throw new InvalidArgumentException('Placement changed in another page. Reload before moving this device.');
        if ($rack) {
            $record=db_fetch_row_prepared('SELECT r.*,n.site_id FROM plugin_icct_nms_racks r JOIN plugin_icct_nms_rack_nodes n ON n.id=r.node_id WHERE r.id=? FOR UPDATE',[$rack]);
            if (!$record || (int)$record['site_id']!==$site) throw new InvalidArgumentException('Select a rack at the device site.');
            if (!$peripheral) {
                [$start,$height]=icct_nms_rack_units($units,(int)$record['unit_count']);
                icct_backend_topology_config_apply('place_device',$site,['rack_id'=>$rack,'host_id'=>$id,'start_unit'=>$start,'unit_height'=>$height]);
            }
        }
        if (!$rack || $peripheral) icct_backend_category_execute('DELETE FROM plugin_icct_nms_rack_devices WHERE host_id=?',[$id]);
        if ($rack && $peripheral) icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['rack_peripheral_'.$id,(string)$rack]);
        else icct_backend_category_execute('DELETE FROM plugin_icct_nms_meta WHERE meta_key=?',['rack_peripheral_'.$id]);
        icct_backend_category_execute('COMMIT');
    } catch (Throwable $error) {icct_backend_category_execute('ROLLBACK');throw $error;}
    finally {db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function icct_nms_rack_view_data() {
    $devices=icct_nms_inventory(); $allowed=[]; $out=[];
    foreach ($devices as $device) {
        $id=(int)$device['id']; $allowed[$id]=true;
        $peripheral=(int)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['rack_peripheral_'.$id]);
        $rackId=(int)($device['rack_id'] ?: $peripheral);
        $nodeId=$rackId ? (int)db_fetch_cell_prepared('SELECT node_id FROM plugin_icct_nms_racks WHERE id=?',[$rackId]) : (int)icct_nms_meta('device_node_id_'.$id);
        $out[]=['id'=>$id,'node_id'=>$nodeId,'name'=>$device['description'],'site_id'=>(int)$device['site_id'],'status'=>$device['status_label'],'rack_id'=>(int)($device['rack_id'] ?: $peripheral),'start'=>(int)$device['start_unit'],'height'=>(int)($device['unit_height'] ?: 1),'peripheral'=>(bool)$peripheral,'revision'=>icct_nms_rack_revision($id)];
    }
    $racks=db_fetch_assoc('SELECT r.*,n.site_id,n.name AS node_name FROM plugin_icct_nms_racks r JOIN plugin_icct_nms_rack_nodes n ON n.id=r.node_id ORDER BY n.name,r.rack_number');
    foreach ($racks as &$rack) {
        $rack['blocked']=[];
        foreach (db_fetch_assoc_prepared('SELECT host_id,start_unit,unit_height FROM plugin_icct_nms_rack_devices WHERE rack_id=?',[(int)$rack['id']]) as $placement) if (!isset($allowed[(int)$placement['host_id']])) for ($u=(int)$placement['start_unit'];$u<(int)$placement['start_unit']+(int)$placement['unit_height'];$u++) $rack['blocked'][]=$u;
    } unset($rack);
    return ['nodes'=>db_fetch_assoc('SELECT n.*,s.name AS site_name FROM plugin_icct_nms_rack_nodes n JOIN sites s ON s.id=n.site_id ORDER BY n.name'),'racks'=>$racks,'devices'=>$out,'management'=>is_realm_allowed(3)];
}
