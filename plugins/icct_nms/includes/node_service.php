<?php
/** Node presets use the same records as rack topology; sites remain Cacti-owned. */
function icct_nms_nodes() {
    return db_fetch_assoc("SELECT n.*,(SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=CONCAT('node_rack_profile_',n.id)) AS rack_profile_id,s.name AS site_name,(SELECT COUNT(*) FROM plugin_icct_nms_racks r WHERE r.node_id=n.id) AS racks FROM plugin_icct_nms_rack_nodes n LEFT JOIN sites s ON s.id=n.site_id ORDER BY n.name,n.id");
}
function icct_nms_save_node($input) {
    icct_backend_require_management(3);
    $rawId=$input['node_id'] ?? 0;
    $id=icct_backend_topology_integer($rawId===''?0:$rawId,0,16777215,'Node ID');
    $lock='icct_backend_racks_'.substr(hash('sha256',(string)db_fetch_cell('SELECT DATABASE()')),0,32);
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,10)',[$lock])!==1)throw new RuntimeException('Rack configuration is busy. Retry shortly.');
    icct_backend_category_execute('START TRANSACTION');
    try {
        $old=$id ? db_fetch_row_prepared('SELECT * FROM plugin_icct_nms_rack_nodes WHERE id=? FOR UPDATE',[$id]) : [];
        if ($id && !$old) throw new InvalidArgumentException('This node no longer exists. Reload the page.');
        $racks=$id ? (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_icct_nms_racks WHERE node_id=?',[$id]) : 0;
        if (($input['action'] ?? '')==='delete_node') {
            if (!$id) throw new InvalidArgumentException('Choose a saved node.');
            if ($racks) throw new InvalidArgumentException('This node contains racks. Remove or reassign its racks before deleting it.');
            icct_backend_category_execute('DELETE FROM plugin_icct_nms_rack_nodes WHERE id=?',[$id]);
            $message='Node deleted.';
        } else {
            $name=icct_backend_classification_text($input['node_name'] ?? '',150);
            if ($name==='') throw new InvalidArgumentException('Enter a node name.');
            $site=icct_nms_id($input['site_id'] ?? 0);
            if (!$site || !db_fetch_cell_prepared('SELECT id FROM sites WHERE id=?',[$site])) throw new InvalidArgumentException('Select an existing Cacti site.');
            if ($racks && (int)$old['site_id']!==$site) throw new InvalidArgumentException('This node contains racks. Reassign its racks before changing the site.');
            if (db_fetch_cell_prepared('SELECT id FROM plugin_icct_nms_rack_nodes WHERE site_id=? AND LOWER(name)=LOWER(?) AND id<>? LIMIT 1',[$site,$name,$id])) throw new InvalidArgumentException('A node with this name already exists at the selected site.');
            if ($id) icct_backend_category_execute('UPDATE plugin_icct_nms_rack_nodes SET name=?,site_id=?,updated_by=?,updated_at=NOW() WHERE id=?',[$name,$site,icct_backend_current_user_id(),$id]);
            else icct_backend_category_execute("INSERT INTO plugin_icct_nms_rack_nodes(site_id,name,node_kind,updated_by,updated_at) VALUES(?,?,'node',?,NOW())",[$site,$name,icct_backend_current_user_id()]);
            $savedId=$id ?: (int)db_fetch_cell('SELECT LAST_INSERT_ID()');
            icct_nms_apply_node_rack_preset($savedId,$site,$name,$input,$old['node_kind'] ?? 'node');
            $message=$id?'Node updated.':'Node added.';
        }
        icct_backend_category_execute('COMMIT');
        return $message;
    } catch (Throwable $failure) { icct_backend_category_execute('ROLLBACK'); throw $failure; } finally { db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]); }
}
