<?php
/** Cacti lifecycle integration. Configuration audit history is retained after deletion. */
if(!function_exists('nms_database_ready')) require_once dirname(__DIR__).'/database.php';

function nms_config_cancel_queued($host_id,$reason)
{
    nms_category_execute("UPDATE plugin_nms_config_jobs SET status='failed',finished_at=NOW(),result_json=? WHERE host_id=? AND status='queued'",[json_encode(['error'=>$reason],JSON_THROW_ON_ERROR),(int)$host_id]);
    // A running write may already have reached the equipment. Only its worker can resolve it.
}

function nms_config_device_removed($device_ids)
{
    if(!nms_database_ready()) return $device_ids;
    foreach((array)$device_ids as $id) {
        $id=(int)$id; if($id<1) continue;
        nms_config_cancel_queued($id,'Device was deleted before execution.');
        foreach(['plugin_nms_serial_readings','plugin_nms_config_devices','plugin_nms_serial_devices','plugin_nms_node_devices'] as $table) nms_category_execute("DELETE FROM $table WHERE host_id=?",[$id]);
    }
    return $device_ids;
}

function nms_config_device_saved($host)
{
    if(!nms_database_ready() || empty($host['id'])) return $host;
    nms_config_cancel_queued($host['id'],'Device settings were saved. Submit a new request using current settings.');
    nms_category_execute('DELETE FROM plugin_nms_serial_readings WHERE host_id=?',[(int)$host['id']]);
    return $host;
}

/** Recover lifecycle changes made while hooks were disabled, including core bulk actions. */
function nms_config_reconcile()
{
    $deleted=db_fetch_assoc("SELECT a.host_id FROM plugin_nms_config_devices a LEFT JOIN host h ON h.id=a.host_id WHERE h.id IS NULL OR h.deleted<>'' UNION SELECT d.host_id FROM plugin_nms_serial_devices d LEFT JOIN host h ON h.id=d.host_id WHERE h.id IS NULL OR h.deleted<>'' UNION SELECT m.host_id FROM plugin_nms_node_devices m LEFT JOIN host h ON h.id=m.host_id WHERE h.id IS NULL OR h.deleted<>''");
    nms_config_device_removed(array_column($deleted,'host_id'));
    $invalid=db_fetch_assoc("SELECT a.host_id FROM plugin_nms_config_devices a JOIN host h ON h.id=a.host_id LEFT JOIN poller p ON p.id=h.poller_id JOIN plugin_nms_config_profiles m ON m.id=a.profile_id LEFT JOIN plugin_nms_serial_devices d ON d.host_id=h.id LEFT JOIN plugin_nms_serial_connections c ON c.id=d.connection_id WHERE h.disabled<>'' OR p.id IS NULL OR p.disabled<>'' OR (m.protocol='modbus_rtu' AND (c.id IS NULL OR c.enabled=0 OR c.poller_id<>h.poller_id)) OR (m.protocol='snmp' AND h.snmp_version=0)");
    foreach($invalid as $row) {
        nms_config_cancel_queued($row['host_id'],'Device or connection is unavailable on its assigned collector.');
        nms_category_execute('DELETE FROM plugin_nms_serial_readings WHERE host_id=?',[$row['host_id']]);
    }
}
