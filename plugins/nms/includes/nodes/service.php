<?php
/** Node containers reference native sites and devices; membership is always explicit. */
function nms_nodes_schema()
{
    nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_nodes (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT, site_id INT UNSIGNED NOT NULL,
        code VARCHAR(64) NOT NULL, name VARCHAR(150) NOT NULL, description VARCHAR(512) NOT NULL DEFAULT '',
        updated_by INT UNSIGNED NOT NULL, updated_at DATETIME NOT NULL,
        PRIMARY KEY(id), UNIQUE KEY code(code), UNIQUE KEY site_name(site_id,name)
    ) ENGINE=InnoDB ROW_FORMAT=Dynamic");
    nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_node_devices (
        host_id MEDIUMINT UNSIGNED NOT NULL, node_id INT UNSIGNED NOT NULL,
        updated_by INT UNSIGNED NOT NULL, updated_at DATETIME NOT NULL,
        PRIMARY KEY(host_id), KEY node_id(node_id)
    ) ENGINE=InnoDB ROW_FORMAT=Dynamic");
}

function nms_node_id($value)
{
    if (!is_scalar($value) || !preg_match('/^[0-9]+$/D', (string) $value) || (float) $value > PHP_INT_MAX) {
        throw new InvalidArgumentException('Invalid node or device identifier.');
    }
    return (int) $value;
}

/** Serialize member replacement and device form changes on the same database. */
function nms_nodes_lock()
{
    $key = 'nms_nodes_' . substr(hash('sha256', (string) db_fetch_cell('SELECT DATABASE()')), 0, 32);
    if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?,10)', [$key]) !== 1) {
        throw new RuntimeException('Node configuration is busy. Please retry.');
    }
    return $key;
}

function nms_nodes_unlock($key)
{
    db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$key]);
}

/** Node names are available to managers; readers only see nodes with accessible members. */
function nms_nodes_list()
{
    $visible = nms_visible_host_sql();
    $scope = is_realm_allowed(3) ? '1=1' : "EXISTS (SELECT 1 FROM plugin_nms_node_devices m JOIN host h ON h.id=m.host_id WHERE m.node_id=n.id AND h.deleted='' AND $visible)";
    return db_fetch_assoc("SELECT n.*,s.name AS site_name FROM plugin_nms_nodes n JOIN sites s ON s.id=n.site_id WHERE $scope ORDER BY s.name,n.name");
}

function nms_node_get($id)
{
    foreach (nms_nodes_list() as $node) if ((int) $node['id'] === (int) $id) return $node;
    throw new InvalidArgumentException('Select an accessible node.');
}

/** Never replace a membership set containing devices the operator cannot access. */
function nms_node_require_write($id)
{
    nms_require_management();
    nms_node_get($id);
    $visible = nms_visible_host_sql();
    if ((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_nms_node_devices m JOIN host h ON h.id=m.host_id WHERE m.node_id=? AND h.deleted='' AND NOT ($visible)", [$id])) {
        throw new RuntimeException('This node contains devices outside your access. Its configuration cannot be changed by this account.');
    }
}

function nms_node_assignment_validate($node_id, $site_id)
{
    $node_id = nms_node_id($node_id);
    if (!$node_id) return 0; // Explicit Unassigned option, never an inferred node.
    $node = nms_node_get($node_id);
    if ((int) $node['site_id'] !== (int) $site_id) throw new InvalidArgumentException('The node and device must belong to the same site.');
    return $node_id;
}

function nms_node_assign_device($host_id, $node_id)
{
    nms_require_management();
    nms_require_device_access($host_id);
    $host = db_fetch_row_prepared("SELECT id,site_id FROM host WHERE id=? AND deleted=''", [$host_id]);
    if (!$host) throw new InvalidArgumentException('Select an existing device.');
    nms_node_assignment_validate($node_id, $host['site_id']);
    if (!$node_id) {
        nms_category_execute('DELETE FROM plugin_nms_node_devices WHERE host_id=?', [$host_id]);
    } else {
        nms_category_execute('INSERT INTO plugin_nms_node_devices (host_id,node_id,updated_by,updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE node_id=VALUES(node_id),updated_by=VALUES(updated_by),updated_at=NOW()', [$host_id,$node_id,nms_current_user_id()]);
    }
}

/** Transactional node editor. Moving a device from another node is never implicit. */
function nms_node_save($input)
{
    nms_require_management();
    $id = nms_node_id($input['node_id'] ?? 0);
    $site = nms_node_id($input['site_id'] ?? 0);
    $name = nms_classification_text($input['name'] ?? '',150);
    $code = nms_classification_text($input['code'] ?? '',64);
    $description = nms_classification_text($input['description'] ?? '',512);
    if ($name === '' || $code === '') throw new InvalidArgumentException('Enter a node name and code.');
    if (!$site || !db_fetch_cell_prepared('SELECT id FROM sites WHERE id=?',[$site])) throw new InvalidArgumentException('Select an existing Cacti site.');
    $members = $input['members'] ?? [];
    if (!is_array($members)) throw new InvalidArgumentException('Select a list of devices.');
    $members = array_values(array_unique(array_map('nms_node_id',$members)));
    $lock = nms_nodes_lock();
    try {
        nms_category_execute('START TRANSACTION');
        if ($id) nms_node_require_write($id);
        if (db_fetch_cell_prepared('SELECT id FROM plugin_nms_nodes WHERE id<>? AND (code=? OR (site_id=? AND name=?))',[$id,$code,$site,$name])) throw new InvalidArgumentException('The node code or site/name is already in use.');
        foreach ($members as $host_id) {
            nms_require_device_access($host_id);
            $host = db_fetch_row_prepared("SELECT h.site_id,m.node_id FROM host h LEFT JOIN plugin_nms_node_devices m ON m.host_id=h.id WHERE h.id=? AND h.deleted=''",[$host_id]);
            if (!$host || (int) $host['site_id'] !== $site) throw new InvalidArgumentException('Every selected device must belong to the node site.');
            if (!empty($host['node_id']) && (int) $host['node_id'] !== $id) throw new InvalidArgumentException('A selected device already belongs to another node. Move it explicitly through Device Edit.');
        }
        if ($id) {
            nms_category_execute('UPDATE plugin_nms_nodes SET site_id=?,code=?,name=?,description=?,updated_by=?,updated_at=NOW() WHERE id=?',[$site,$code,$name,$description,nms_current_user_id(),$id]);
        } else {
            nms_category_execute('INSERT INTO plugin_nms_nodes (site_id,code,name,description,updated_by,updated_at) VALUES (?,?,?,?,?,NOW())',[$site,$code,$name,$description,nms_current_user_id()]);
            $id = (int) db_fetch_cell('SELECT LAST_INSERT_ID()');
        }
        nms_category_execute('DELETE FROM plugin_nms_node_devices WHERE node_id=?',[$id]);
        foreach ($members as $host_id) nms_node_assign_device($host_id,$id);
        nms_category_execute('COMMIT');
        return $id;
    } catch (Throwable $e) {
        db_execute('ROLLBACK'); throw $e;
    } finally { nms_nodes_unlock($lock); }
}

/** Summary uses fresh native state; unknown/disabled devices never masquerade as Up. */
function nms_node_health($devices, $fresh_after)
{
    $counts = ['Up'=>0,'Down'=>0,'Recovering'=>0,'Unknown'=>0,'Disabled'=>0];
    foreach ($devices as $device) {
        $state = $device['disabled'] === 'on' ? 'Disabled' :
            ((!$device['last_updated'] || strtotime($device['last_updated']) < $fresh_after) ? 'Unknown' :
            ([0=>'Unknown',1=>'Down',2=>'Recovering',3=>'Up'][(int)$device['status']] ?? 'Unknown'));
        $counts[$state]++;
    }
    $total = count($devices);
    $state = !$total ? 'Empty' : ($counts['Disabled'] === $total ? 'Disabled' :
        ($counts['Down'] || $counts['Recovering'] ? 'Degraded' : ($counts['Unknown'] ? 'Unknown' : 'Up')));
    return ['state'=>$state,'counts'=>$counts,'total'=>$total];
}

function nms_node_members($id)
{
    $visible = nms_visible_host_sql();
    return db_fetch_assoc_prepared("SELECT h.id,h.description,h.hostname,h.status,h.disabled,h.last_updated,h.site_id,dp.name AS diagnostic_profile,sp.name AS discovery_profile,sd.collection_enabled,sp.enabled AS discovery_enabled,
        (SELECT COUNT(*) FROM graph_local g WHERE g.host_id=h.id) AS graph_count
        FROM plugin_nms_node_devices m JOIN host h ON h.id=m.host_id
        LEFT JOIN plugin_nms_diagnostic_devices dd ON dd.host_id=h.id LEFT JOIN plugin_nms_diagnostic_profiles dp ON dp.id=dd.profile_id
        LEFT JOIN plugin_nms_discovery_devices sd ON sd.host_id=h.id LEFT JOIN plugin_nms_discovery_presets sp ON sp.id=sd.preset_id
        WHERE m.node_id=? AND h.deleted='' AND $visible ORDER BY h.description",[$id]);
}

/** Remove only the container, after an explicit disposition of its members. */
function nms_node_remove($input)
{
    nms_require_management();
    $id=nms_node_id($input['node_id'] ?? 0);
    $target=nms_node_id($input['target_node_id'] ?? 0);
    $mode=$input['member_action'] ?? '';
    if (!in_array($mode,['unassign','move'],true)) throw new InvalidArgumentException('Choose what happens to member devices.');
    $lock=nms_nodes_lock();
    try {
        nms_category_execute('START TRANSACTION');
        nms_node_require_write($id);
        $node=nms_node_get($id);
        if (($input['confirm_code'] ?? '') !== $node['code']) throw new InvalidArgumentException('Enter the node code to confirm removal.');
        if ($mode==='move') {
            if (!$target || $target===$id) throw new InvalidArgumentException('Select another node.');
            nms_node_require_write($target);
            nms_node_assignment_validate($target,$node['site_id']);
            foreach (nms_node_members($id) as $host) nms_node_assignment_validate($target,$host['site_id']);
            nms_category_execute('UPDATE plugin_nms_node_devices SET node_id=?,updated_by=?,updated_at=NOW() WHERE node_id=?',[$target,nms_current_user_id(),$id]);
        } else {
            nms_category_execute('DELETE FROM plugin_nms_node_devices WHERE node_id=?',[$id]);
        }
        nms_category_execute('DELETE FROM plugin_nms_nodes WHERE id=?',[$id]);
        nms_category_execute('COMMIT');
    } catch (Throwable $e) { db_execute('ROLLBACK'); throw $e; }
    finally { nms_nodes_unlock($lock); }
}
