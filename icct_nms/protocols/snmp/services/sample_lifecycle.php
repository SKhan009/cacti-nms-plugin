<?php

/** Native lifecycle notifications invalidate only plugin-owned observations and return input unchanged. */
require_once __DIR__ . '/native_samples.php';

function icct_nms_snmp_invalidate_host(int $id): void
{
    if ($id < 1) return;
    $host = db_fetch_row_prepared('SELECT * FROM host WHERE id=?', [$id]);
    if (!$host) return;
    $key = icct_nms_snmp_shared_key($host);
    // A generation change also invalidates an in-flight writer's older snapshot after it releases its lease.
    if (!db_execute_prepared('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
        [$key . '_epoch', json_encode(['generation'=>bin2hex(random_bytes(16)), 'time'=>time()], JSON_THROW_ON_ERROR)])) {
        throw new RuntimeException('Could not invalidate SNMP observations.');
    }
    db_execute_prepared('DELETE FROM plugin_icct_nms_meta WHERE meta_key IN (?,?)', [$key, 'cacti_snmp_samples_' . $id]);
    icct_nms_snmp_reuse_reset();
}

function icct_nms_snmp_query_changed($data)
{
    try { icct_nms_snmp_invalidate_host((int) ($data['host_id'] ?? 0)); }
    catch (Throwable $error) { cacti_log('ICCT query observation invalidation: ' . $error->getMessage(), false, 'ICCT NMS'); }
    return $data;
}

function icct_nms_snmp_device_removed($ids)
{
    foreach ((array) $ids as $id) {
        try { icct_nms_snmp_invalidate_host((int) $id); }
        catch (Throwable $error) { cacti_log('ICCT device observation invalidation: ' . $error->getMessage(), false, 'ICCT NMS'); }
    }
    return $ids;
}

function icct_nms_snmp_device_saved($data)
{
    try {
        $id = (int) ($data['id'] ?? 0);
        $old = $id ? db_fetch_row_prepared('SELECT * FROM host WHERE id=?', [$id]) : [];
        if ($old && icct_nms_snmp_profile($old) !== icct_nms_snmp_profile(array_replace($old, $data))) {
            icct_nms_snmp_invalidate_host($id);
        }
    } catch (Throwable $error) { cacti_log('ICCT device observation invalidation: ' . $error->getMessage(), false, 'ICCT NMS'); }
    return $data;
}
