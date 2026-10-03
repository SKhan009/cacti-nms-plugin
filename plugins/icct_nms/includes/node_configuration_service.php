<?php
require_once __DIR__ . "/node_membership_service.php";
/** Node membership is explicit for unracked devices; rack placements determine membership. */
function icct_nms_node_configuration_groups($nodes, $devices, $memberships, $rackNodes) {
    $groups=[];
    foreach ($nodes as $node) $groups[(int)$node['id']]=['node'=>$node,'devices'=>[]];
    $unassigned=[];
    foreach ($devices as $device) {
        $id=(int)$device['id'];
        $nodeId=!empty($device['rack_id']) ? (int)($rackNodes[(int)$device['rack_id']] ?? 0) : (int)($memberships[$id] ?? 0);
        if ($nodeId && isset($groups[$nodeId]) && (int)$groups[$nodeId]['node']['site_id']===(int)$device['site_id']) $groups[$nodeId]['devices'][]=$device;
        else $unassigned[]=$device;
    }
    return ['groups'=>$groups,'unassigned'=>$unassigned];
}
function icct_nms_assign_node_device($input) {
    icct_backend_require_management(3);
    $deviceId=icct_nms_id($input['device_id'] ?? 0);
    $nodeId=icct_backend_topology_integer($input['node_id'] ?? 0,0,16777215,'Node ID');
    if (!$deviceId) throw new InvalidArgumentException('Select a device.');
    icct_backend_require_device_access($deviceId);
    $lock='icct_backend_racks_'.substr(hash('sha256',(string)db_fetch_cell('SELECT DATABASE()')),0,32);
    if ((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,10)',[$lock])!==1) throw new RuntimeException('Node configuration is busy. Retry shortly.');
    icct_backend_category_execute('START TRANSACTION');
    try {
        $device=db_fetch_row_prepared("SELECT id,site_id FROM host WHERE id=? AND deleted='' FOR UPDATE",[$deviceId]);
        if (!$device) throw new InvalidArgumentException('This device no longer exists.');
        $rack=db_fetch_row_prepared('SELECT r.node_id FROM plugin_icct_nms_rack_devices d JOIN plugin_icct_nms_racks r ON r.id=d.rack_id WHERE d.host_id=?',[$deviceId]);
        $peripheral=(int)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['rack_peripheral_'.$deviceId]);
        if ($rack || $peripheral) throw new InvalidArgumentException('This device belongs to its rack’s node. Change its rack placement in Add/Edit Device.');
        icct_nms_validate_device_node($deviceId,$nodeId);
        if ($nodeId) {
            $node=db_fetch_row_prepared('SELECT id,site_id FROM plugin_icct_nms_rack_nodes WHERE id=? FOR UPDATE',[$nodeId]);
            if (!$node || (int)$node['site_id']!==(int)$device['site_id']) throw new InvalidArgumentException('Select a node at the device’s site.');
            icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['device_node_id_'.$deviceId,(string)$nodeId]);
        } else icct_backend_category_execute('DELETE FROM plugin_icct_nms_meta WHERE meta_key=?',['device_node_id_'.$deviceId]);
        icct_backend_category_execute('COMMIT');
    } catch (Throwable $error) { icct_backend_category_execute('ROLLBACK'); throw $error; }
    finally { db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]); }
}
