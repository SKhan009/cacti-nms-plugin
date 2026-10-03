<?php
/**
 * Read saved inventory and enforce native device access before exposing records.
 */

/**
 * Return nondeleted devices allowed by Cacti, including authorized disabled devices.
 */
function icct_nms_inventory()
{
    $rows = db_fetch_assoc("SELECT h.id,h.description,h.hostname,h.status,h.disabled,h.availability,
        h.snmp_sysUpTimeInstance,h.last_updated,h.site_id,h.poller_id,
        c.category_id,c.device_type,c.device_role,cat.name AS segment,
        m.serial_number AS manual_serial_number,m.mac_address,m.chassis_id,
        s.meta_value AS short_name,r.name AS rack_name,r.id AS rack_id,rd.start_unit,rd.unit_height,
        sites.name AS site_name
        FROM host h LEFT JOIN plugin_icct_nms_device_classification c ON c.host_id=h.id
        LEFT JOIN plugin_icct_nms_categories cat ON cat.id=c.category_id
        LEFT JOIN plugin_icct_nms_device_metadata m ON m.host_id=h.id
        LEFT JOIN plugin_icct_nms_meta s ON s.meta_key=CONCAT('device_short_name_',h.id)
        LEFT JOIN plugin_icct_nms_rack_devices rd ON rd.host_id=h.id
        LEFT JOIN plugin_icct_nms_meta peripheral ON peripheral.meta_key=CONCAT('rack_peripheral_',h.id)
        LEFT JOIN plugin_icct_nms_racks r ON r.id=COALESCE(rd.rack_id,CAST(peripheral.meta_value AS UNSIGNED))
        LEFT JOIN sites ON sites.id=h.site_id WHERE h.deleted='' ORDER BY h.description,h.id");
    if (!is_array($rows)) {
        throw new RuntimeException('Could not read saved inventory.');
    }
    $allowed = [];
    foreach ($rows as $row) {
        try {
            icct_backend_require_device_access((int) $row['id']);
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'Your Cacti account cannot access this device.') {
                throw $error;
            }
            continue;
        }
        $row['status_label'] = icct_backend_device_status_name($row);
        $row['polling_interval'] = (int) read_config_option('poller_interval');
        $allowed[] = $row;
    }
    return $allowed;
}

/**
 * Check access before loading the saved core device record.
 */
function icct_nms_device($id)
{
    icct_backend_require_device_access($id);
    $host = db_fetch_row_prepared("SELECT * FROM host WHERE id=? AND deleted=''", [$id]);
    if (!$host) {
        throw new RuntimeException('Device no longer exists.');
    }
    return $host;
}

/**
 * Load manual identity separately from collector observations.
 */
function icct_nms_metadata($id)
{
    return db_fetch_row_prepared('SELECT * FROM plugin_icct_nms_device_metadata WHERE host_id=?', [$id]);
}

/**
 * Read an explicit key from the shared NMS metadata store.
 */
function icct_nms_meta($key)
{
    return (string) db_fetch_cell_prepared(
        'SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
        [$key]
    );
}

/**
 * Convert SNMP TimeTicks (hundredths of a second) to a readable elapsed duration.
 */
function icct_nms_uptime($ticks)
{
    if (!$ticks) {
        return 'Not reported';
    }
    $s = (int) floor((float) $ticks / 100);
    return sprintf(
        '%02dd %02dh %02dm %02ds',
        intdiv($s, 86400),
        intdiv($s % 86400, 3600),
        intdiv($s % 3600, 60),
        $s % 60
    );
}
