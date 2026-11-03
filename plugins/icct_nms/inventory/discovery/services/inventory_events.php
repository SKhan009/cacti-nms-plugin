<?php

/** Compare native observations without treating a poll as a device change. */
function icct_nms_inventory_observe($poller)
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Inventory observations are collector-owned.');
    }
    foreach (
        db_fetch_assoc_prepared(
            'SELECT ' .
                'id,hostname,description,status,disabled,site_id,poller_id,snmp_sysName,snmp_sysDescr,snmp_sysObjectID ' .
                'FROM host WHERE poller_id=? AND deleted=\'\'',
            [(int) $poller],
        ) as $host
    ) {
        $key = 'inventory_observation_' . (int) $host['id'];
        $raw = db_fetch_cell_prepared(
            'SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
            [$key],
        );
        $previous = $raw ? json_decode($raw, true) : null;
        if (is_array($previous)) {
            $changed = [];
            foreach ($host as $field => $value) {
                if ((string) ($previous[$field] ?? '') !== (string) $value) {
                    $changed[$field] = ['before' => $previous[$field] ?? null, 'after' => $value];
                }
            }
            if ($changed) {
                icct_backend_category_execute(
                    'INSERT INTO plugin_icct_nms_inventory_events(host_id,event,details_json,created_at) VALUES(?,?,?,NOW())',
                    [$host['id'], 'Device changed', json_encode($changed, JSON_THROW_ON_ERROR)],
                );
            }
        }
        if ($previous !== $host) {
            icct_backend_category_execute(
                'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON ' .
                    'DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
                [$key, json_encode($host, JSON_THROW_ON_ERROR)],
            );
        }
    }
}
function icct_nms_inventory_recent_events($ids)
{
    if (!$ids) {
        return [];
    }
    $ids = array_map('intval', $ids);
    return db_fetch_assoc(
        'SELECT e.id,e.host_id,e.event,e.details_json,e.created_at,h.description FROM ' .
            'plugin_icct_nms_inventory_events e JOIN host h ON h.id=e.host_id WHERE e.host_id IN(' .
            implode(',', $ids) .
            ') ORDER BY e.id DESC LIMIT 25',
    );
}
