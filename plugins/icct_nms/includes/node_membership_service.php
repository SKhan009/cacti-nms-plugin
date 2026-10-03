<?php
/** One device may have only one node across direct, rack and peripheral assignments. */
function icct_nms_device_node_ids($id) {
    $physical=(int)db_fetch_cell_prepared('SELECT r.node_id FROM plugin_icct_nms_rack_devices d JOIN plugin_icct_nms_racks r ON r.id=d.rack_id WHERE d.host_id=?',[$id]);
    $peripheral=(int)db_fetch_cell_prepared("SELECT r.node_id FROM plugin_icct_nms_meta m JOIN plugin_icct_nms_racks r ON m.meta_value=CAST(r.id AS CHAR) WHERE m.meta_key=?",['rack_peripheral_'.$id]);
    $direct=(int)db_fetch_cell_prepared("SELECT n.id FROM plugin_icct_nms_meta m JOIN plugin_icct_nms_rack_nodes n ON m.meta_value=CAST(n.id AS CHAR) WHERE m.meta_key=?",['device_node_id_'.$id]);
    return array_values(array_unique(array_filter([$physical,$peripheral,$direct])));
}
function icct_nms_validate_device_node($id,$nodeId) {
    if (!$nodeId) return;
    foreach (icct_nms_device_node_ids($id) as $assigned) if ($assigned!==$nodeId) throw new InvalidArgumentException('This device is already assigned to another node. Unassign it from its current node or rack before assigning it here.');
}
