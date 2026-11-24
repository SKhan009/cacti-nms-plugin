<?php

/** Match only an explicit index with its saved identity, or a unique exact legacy label. */
function icct_nms_link_port($ports, $label, $index = 0, $identity = '')
{
    if ($index > 0) {
        $port = $ports[$index] ?? null;
        if (!$port || ($identity !== '' && !hash_equals(icct_backend_interface_identity($port), $identity))) {
            return null;
        }
        // Fresh LLDP/CDP observations may carry an authoritative index without an IF-MIB label.
        // New manual mappings always carry the fingerprint checked above.
        return $port;
    }
    $matches = [];
    foreach ($ports as $key => $port) {
        if ($label !== '' &&
            in_array($label, [$port['name'] ?? '', $port['description'] ?? '', 'ifIndex ' . $key], true)) {
            $matches[] = $port;
        }
    }
    return count($matches) === 1 ? $matches[0] : null;
}

/** Reuse the assigned collector's typed interface snapshot; page requests never probe devices. */
function icct_nms_link_snapshot($id)
{
    $empty = ['available' => false, 'interfaces' => [],
        'measurement_state' => 'collection_disabled',
        'measurement_message' => ''];
    $hosts = array_column(icct_backend_nd_hosts(), null, 'id');
    $host = $hosts[$id] ?? null;
    if (!$host || empty($host['enabled']) || empty($host['collection_enabled']) ||
        !icct_backend_protocol_enabled($id, 'snmp')) {
        return $empty;
    }
    $snapshot = db_fetch_row_prepared(
        "SELECT status,succeeded_at,config_hash,data_json FROM plugin_icct_nms_discovery_snapshots WHERE host_id=? AND protocol='identity'",
        [$id],
    );
    if (!$snapshot || !hash_equals(icct_backend_nd_hash($host), (string) ($snapshot['config_hash'] ?? ''))) {
        return array_replace($empty, ['measurement_state' => 'waiting_for_second_sample',
            'measurement_message' => 'Waiting for collection with the current device settings.']);
    }
    $age = time() - strtotime($snapshot['succeeded_at'] ?? '');
    if ($age < 0 || $age > (int) $host['stale_seconds']) {
        return array_replace($empty, ['measurement_state' => 'collection_stale',
            'measurement_message' => 'Collection stale. Waiting for a fresh interface snapshot.',
            'collected' => $snapshot['succeeded_at'] ?? null]);
    }
    if ($snapshot['status'] !== 'success') {
        return array_replace($empty, ['measurement_state' => 'collection_failed',
            'measurement_message' => 'Interface collection has not completed successfully.']);
    }
    $payload = json_decode($snapshot['data_json'], true) ?? [];
    return ['available' => true, 'interfaces' => $payload['interfaces'] ?? [],
        'collected' => $snapshot['succeeded_at'], 'collected_epoch' => $payload['collected'] ?? null,
        'interval_seconds' => (int) $host['interval_seconds']];
}

function icct_nms_link_interface_choices($id)
{
    $snapshot = icct_nms_link_snapshot($id);
    $choices = [];
    foreach ($snapshot['interfaces'] as $index => $port) {
        $name = ($port['name'] ?? '') ?: ($port['description'] ?? '');
        if ($name === '') {
            continue;
        }
        $choices[] = ['index' => (int) $index, 'name' => $name,
            'identity' => icct_backend_interface_identity($port),
            'description' => $port['description'] ?? '', 'alias' => $port['alias'] ?? ''];
    }
    return ['interfaces' => $choices, 'message' => $snapshot['measurement_message'] ??
        ($choices ? '' : 'No named interfaces in the latest collection.'),
        'collected' => $snapshot['collected'] ?? null];
}

function icct_nms_link_reading($id, $label, $index = 0, $identity = '')
{
    $empty = ['port' => $label ?: 'Not specified', 'available' => false,
        'in_bps' => null, 'out_bps' => null, 'capacity_bps' => null,
        'measurement_state' => 'interface_not_mapped', 'measurement_message' => 'Interface not mapped.'];
    if ($label === '' && $index <= 0) {
        return $empty;
    }
    $snapshot = icct_nms_link_snapshot($id);
    if (!$snapshot['available']) {
        unset($snapshot['interfaces']);
        return array_replace($empty, $snapshot);
    }
    $port = icct_nms_link_port($snapshot['interfaces'], $label, $index, $identity);
    if (!$port) {
        return array_replace($empty, ['measurement_message' =>
            'Interface not mapped. The saved interface is missing or its identity changed. Select it again.']);
    }
    $speed = (float) ($port['high_speed_mbps'] ?? 0) * 1000000;
    if ($speed <= 0) {
        $speed = (float) ($port['speed_bps'] ?? 0);
    }
    $state = $port['measurement_state'] ??
        (isset($port['in_octets'], $port['out_octets'], $port['discontinuity'])
            ? 'waiting_for_second_sample' : 'counters_inaccessible');
    $result = array_replace($empty, [
        'port' => ($port['name'] ?? '') ?: ($port['description'] ?? $label),
        'ifindex' => (int) $port['index'], 'interface_identity' => icct_backend_interface_identity($port),
        'available' => true, 'capacity_bps' => $speed > 0 ? $speed : null,
        'status' => (int) ($port['admin'] ?? 0) === 2 ? 'Disabled' :
            ((int) ($port['oper'] ?? 0) === 1 ? 'Up' : ((int) ($port['oper'] ?? 0) === 2 ? 'Down' : 'Unknown')),
        'collected' => $snapshot['collected'], 'collected_epoch' => $snapshot['collected_epoch'],
        'sample_seconds' => $port['sample_seconds'] ?? null,
        'sample_started' => $port['sample_started'] ?? null, 'sample_ended' => $port['sample_ended'] ?? null,
        'interval_seconds' => $snapshot['interval_seconds'], 'measurement_state' => $state,
        'measurement_message' => $port['measurement_message'] ?? ($state === 'counters_inaccessible'
            ? 'Counters inaccessible. Check the SNMP view and IF-MIB support.' : 'Waiting for the second sample.'),
    ]);
    foreach (['in', 'out'] as $direction) {
        $value = $port[$direction . '_bps'] ?? null;
        $result[$direction . '_bps'] = $state === 'valid' && is_numeric($value) &&
            is_finite((float) $value) && $value >= 0 ? (float) $value : null;
    }
    return $result;
}
