<?php

/** Collector-local sharing through Cacti's database connection; no transport or remote fallback. */
function icct_nms_snmp_shared_key(array $host): string
{
    return 'snmp_shared_' . (int) $host['poller_id'] . '_' . (int) $host['id'];
}

/** JSON must retain arbitrary SNMP octets, including invalid UTF-8. */
function icct_nms_snmp_cache_encode($value)
{
    if (is_string($value)) return ['$bytes'=>base64_encode($value)];
    if (is_array($value)) return array_map('icct_nms_snmp_cache_encode', $value);
    return $value;
}

function icct_nms_snmp_cache_decode($value)
{
    if (is_array($value) && array_keys($value) === ['$bytes']) {
        $decoded = base64_decode($value['$bytes'], true);
        if ($decoded === false) throw new RuntimeException('Invalid SNMP cache encoding.');
        return $decoded;
    }
    if (is_array($value)) return array_map('icct_nms_snmp_cache_decode', $value);
    return $value;
}

function icct_nms_snmp_cycle(): int
{
    return intdiv(time(), max(1, (int) read_config_option('poller_interval')));
}

/** One host lease covers nested scalar/column reads and snapshot merges. */
function icct_nms_snmp_shared(array $host, callable $work)
{
    global $config;
    if (($host['disabled'] ?? '') !== '' || ($host['deleted'] ?? '') !== '' ||
        (int) $host['poller_id'] !== (int) $config['poller_id']) {
        throw new RuntimeException('SNMP collection is not owned by this collector.');
    }
    // Unsaved discovery endpoints have no durable host identity. Keep their existing job ownership.
    if (empty($host['id'])) return $work();
    $key = icct_nms_snmp_shared_key($host);
    if (!empty($GLOBALS['icct_nms_snmp_leases'][$key])) return $work();
    $lock = 'icct_snmp_' . substr(hash('sha256', $key), 0, 48);
    if ((int) db_fetch_cell_prepared('SELECT GET_LOCK(?, 1)', [$lock]) !== 1) {
        throw new RuntimeException('SNMP observation is already being collected.');
    }
    $GLOBALS['icct_nms_snmp_leases'][$key] = true;
    try {
        $currentHost = db_fetch_row_prepared("SELECT * FROM host WHERE id=? AND deleted=''", [(int) $host['id']]);
        if (!$currentHost || icct_nms_snmp_profile($currentHost) !== icct_nms_snmp_profile($host)) {
            throw new RuntimeException('Device configuration changed before SNMP collection.');
        }
        $epoch = (string) db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?', [$key . '_epoch']);
        $profile = icct_nms_snmp_profile($host);
        if (($GLOBALS['icct_nms_snmp_samples'][$profile]['epoch'] ?? '') !== $epoch) unset($GLOBALS['icct_nms_snmp_samples'][$profile]);
        $state = &icct_nms_snmp_samples($host);
        $state['epoch'] = $epoch;
        icct_nms_snmp_native_refresh($host, $state);
        $saved = json_decode((string) db_fetch_cell_prepared(
            'SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?', [$key]), true);
        if (($saved['signature'] ?? '') === icct_nms_snmp_profile($host) && ($saved['epoch'] ?? '') === $epoch &&
            ($saved['cycle'] ?? -1) === icct_nms_snmp_cycle()) {
            $payload = icct_nms_snmp_cache_decode($saved['payload'] ?? []);
            foreach ($payload['values'] ?? [] as $oid => $sample) {
                if ((int) $sample['time'] >= (int) ($state['observations'][$oid]['time'] ?? 0)) $state['observations'][$oid] = $sample;
                $current = $state['values'][$oid] ?? null;
                // An unavailable native owner is never replaced by an old plugin success.
                if (isset($state['owners'][$oid]) && empty($current['inexact'])) continue;
                if (!$current || (int) $sample['time'] > (int) $current['time']) {
                    $state['values'][$oid] = $sample;
                    unset($state['ambiguous'][$oid]);
                }
            }
            $state['columns'] += $payload['columns'] ?? [];
            $state['walks'] += $payload['walks'] ?? [];
            $state['errors'] += $payload['errors'] ?? [];
        }
        try {
            return $work();
        } finally {
            $values = $state['observations'];
            // Derived native columns must be reconstructed from the latest native observations.
            $walks = array_filter($state['walks'], static fn($key)=>str_starts_with($key, 'wire:'), ARRAY_FILTER_USE_KEY);
            $snapshot = ['signature'=>icct_nms_snmp_profile($host), 'cycle'=>$state['cycle'], 'epoch'=>$epoch,
                'payload'=>icct_nms_snmp_cache_encode(['values'=>$values, 'columns'=>$state['columns'], 'walks'=>$walks, 'errors'=>$state['errors']])];
            if ($snapshot !== $saved && ($values || $state['columns'] || $state['walks'] || $state['errors']) &&
                !db_execute_prepared('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
                [$key, json_encode($snapshot, JSON_THROW_ON_ERROR)])) {
                throw new RuntimeException('Could not save shared SNMP observations.');
            }
        }
    } finally {
        unset($GLOBALS['icct_nms_snmp_leases'][$key]);
        db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}

/** Refresh native values after obtaining the lease; another process may have just published a batch. */
function icct_nms_snmp_native_refresh(array $host, array &$state): void
{
    $owners = [];
    foreach (db_fetch_assoc_prepared('SELECT * FROM poller_item WHERE host_id=? AND poller_id=? AND action=0 AND present=1',
        [(int) $host['id'], (int) $host['poller_id']]) ?: [] as $item) {
        $oid = icct_nms_snmp_oid($item['arg1'] ?? '');
        if ($oid && (int) $item['rrd_step'] > 0 && icct_nms_snmp_item_matches($host, $item)) $owners[$oid][] = $item;
    }
    if ($owners !== $state['native_owners']) {
        $state['native_owners'] = $owners;
        $state['owners'] = [];
        foreach ($owners as $oid => $items) foreach ($items as $item) {
            if ((int) $item['rrd_step'] <= (int) read_config_option('poller_interval')) $state['owners'][$oid][] = $item;
        }
        $state['values'] = array_filter($state['values'], static fn($s)=>($s['source'] ?? '') !== 'native');
        $state['walks'] = [];
    }
    $saved = json_decode((string) db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
        ['cacti_snmp_samples_' . (int) $host['id']]), true);
    if (($saved['signature'] ?? '') !== icct_nms_snmp_profile($host) || ($saved['epoch'] ?? '') !== ($state['epoch'] ?? '')) return;
    foreach ($saved['values'] ?? [] as $oid => $sample) {
        foreach ($state['native_owners'][$oid] ?? [] as $owner) {
            if ((int) ($sample['local_data_id'] ?? 0) !== (int) $owner['local_data_id'] ||
                ($sample['rrd_name'] ?? '') !== $owner['rrd_name']) continue;
            $current = $state['values'][$oid] ?? [];
            if ((int) ($sample['time'] ?? 0) < (int) ($current['time'] ?? 0)) continue;
            if (!empty($sample['inexact']) && ($current['source'] ?? '') === 'collector' &&
                (int) $sample['time'] <= (int) $current['time']) continue;
            $sample['step'] = (int) $owner['rrd_step'];
            if ($current !== $sample) {
                $state['values'][$oid] = $sample;
                $state['walks'] = array_filter($state['walks'], static fn($key)=>str_starts_with($key, 'wire:'), ARRAY_FILTER_USE_KEY);
            }
        }
    }
}

/** Remove obsolete collector snapshots without modifying Cacti-owned records. */
function icct_nms_snmp_shared_cleanup(): void
{
    global $config;
    db_execute_prepared("DELETE m FROM plugin_icct_nms_meta m LEFT JOIN host h ON (m.meta_key=CONCAT('snmp_shared_',h.poller_id,'_',h.id) OR m.meta_key=CONCAT('snmp_shared_',h.poller_id,'_',h.id,'_epoch')) " .
        "WHERE m.meta_key LIKE ? AND (h.id IS NULL OR h.disabled<>'' OR h.deleted<>'' OR m.updated_at<DATE_SUB(NOW(),INTERVAL ? SECOND))",
        ['snmp\\_shared\\_' . (int) $config['poller_id'] . '\\_%', max(600, 4 * (int) read_config_option('poller_interval'))]);
}
