<?php
/** Device data query associations use Cacti's native cache and query APIs. */
function icct_nms_data_query_methods($host) {
    global $reindex_types;
    $methods = $reindex_types;
    if ((int)$host['snmp_version'] === 0) unset($methods[1]);
    return $methods;
}
function icct_nms_available_data_queries($id, $host) {
    icct_backend_require_device_access($id);
    return db_fetch_assoc_prepared('SELECT id,name FROM snmp_query WHERE id NOT IN (SELECT snmp_query_id FROM host_snmp_query WHERE host_id=?)' . ((int)$host['snmp_version'] === 0 ? ' AND data_input_id != 2' : '') . ' ORDER BY name', [$id]);
}
function icct_nms_data_query_associations($id) {
    icct_backend_require_device_access($id);
    return db_fetch_assoc_prepared('SELECT sq.id,sq.name,hsq.reindex_method,COUNT(hsc.snmp_index) AS item_count,COUNT(DISTINCT hsc.snmp_index) AS row_count FROM host_snmp_query hsq JOIN snmp_query sq ON sq.id=hsq.snmp_query_id LEFT JOIN host_snmp_cache hsc ON hsc.host_id=hsq.host_id AND hsc.snmp_query_id=hsq.snmp_query_id WHERE hsq.host_id=? GROUP BY sq.id,sq.name,hsq.reindex_method ORDER BY sq.name', [$id]);
}
function icct_nms_save_data_query($id, $host, $input) {
    global $config;
    icct_backend_require_management(3);
    icct_backend_require_device_access($id);
    $queryId = icct_nms_id($input['snmp_query_id'] ?? 0);
    $action = $input['action'] ?? '';
    $actions = ['add_data_query','change_data_query','remove_data_query','reload_data_query','verbose_data_query'];
    if (!in_array($action,$actions,true)) throw new InvalidArgumentException('Unknown data query action.');
    if ($action === 'add_data_query') {
        $available = array_map('intval',array_column(icct_nms_available_data_queries($id,$host),'id'));
        if (!in_array($queryId,$available,true)) throw new InvalidArgumentException('Choose an available data query.');
    } elseif (!db_fetch_cell_prepared('SELECT COUNT(*) FROM host_snmp_query WHERE host_id=? AND snmp_query_id=?', [$id,$queryId])) {
        throw new InvalidArgumentException('Data query association no longer exists.');
    }
    if (in_array($action,['add_data_query','change_data_query'],true)) {
        $raw = (string)($input['reindex_method'] ?? '');
        if (!ctype_digit($raw) || !array_key_exists((int)$raw,icct_nms_data_query_methods($host))) throw new InvalidArgumentException('Choose a valid re-index method.');
        $method = (int)$raw;
    }
    require_once $config['base_path'].'/lib/api_device.php';
    require_once $config['base_path'].'/lib/data_query.php';
    switch ($action) {
        case 'add_data_query': api_device_dq_add($id,$queryId,$method); return 'Data query associated.';
        case 'change_data_query': api_device_dq_change($id,$queryId,$method); return 'Re-index method saved.';
        case 'remove_data_query': api_device_dq_remove($id,$queryId); return 'Data query association removed. Existing graphs and data sources remain saved.';
        default:
            debug_log_clear('data_query');
            $success = run_data_query($id,$queryId);
            if ($action === 'verbose_data_query') return trim(html_entity_decode(strip_tags(str_replace(['<br>','<br/>','<br />'],"\n",debug_log_return('data_query'))),ENT_QUOTES,'UTF-8')) ?: 'No verbose output was returned by Cacti.';
            return $success ? 'Data query reloaded.' : 'Data query did not complete. Check the device status and query configuration.';
    }
}
