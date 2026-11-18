<?php

require_once __DIR__ . '/../../protocols/ntp/services/ntp_service.php';

/** Whitelisted observations from the saved chrony sample; none issue another network query. */
function icct_nms_ntp_fault_parameters($method = null)
{
    $parameters = [
        'absolute_offset_ms' => ['label' => 'Absolute clock offset', 'units' => 'ms'],
        'offset_ms' => ['label' => 'Signed clock offset', 'units' => 'ms'],
        'rms_offset_ms' => ['label' => 'RMS offset', 'units' => 'ms'],
        'jitter_ms' => ['label' => 'Jitter (source offset standard deviation)', 'units' => 'ms'],
        'peer_delay_ms' => ['label' => 'Peer round-trip delay', 'units' => 'ms'],
        'root_delay_ms' => ['label' => 'Root delay', 'units' => 'ms'],
        'stratum' => ['label' => 'Stratum', 'units' => ''],
        'synchronized' => ['label' => 'Synchronized (1=yes, 0=no)', 'units' => ''],
        'collection_available' => ['label' => 'Collection available (1=yes, 0=no)', 'units' => ''],
        'age_seconds' => ['label' => 'Last collection age', 'units' => 's'],
        'source_matches' => ['label' => 'Expected source matches (1=yes, 0=no)', 'units' => ''],
    ];
    if ($method === 'udp') {
        unset($parameters['rms_offset_ms'], $parameters['jitter_ms'], $parameters['root_delay_ms'], $parameters['source_matches']);
    } elseif ($method === '') {
        return [];
    }
    return $parameters;
}

function icct_nms_ntp_fault_method($assignment, $sample = null)
{
    if (($assignment['method'] ?? '') === 'auto' &&
        in_array($sample['collection_method'] ?? '', ['local', 'udp'], true)) {
        return $sample['collection_method'];
    }
    return $assignment ? icct_nms_ntp_effective_method($assignment) : '';
}

/** Status flags describe real attempts. Missing or stale clock measurements remain null. */
function icct_nms_ntp_fault_values($current)
{
    $values = array_fill_keys(array_keys(icct_nms_ntp_fault_parameters()), null);
    if (
        in_array(
            $current['status'],
            ['Not configured', 'Not collected', 'Settings changed', 'Disabled'],
            true,
        )
    ) {
        return $values;
    }
    $attempt = $current['attempted_epoch'];
    $age = $attempt === null ? null : time() - $attempt;
    if ($age !== null && $age >= 0) {
        $values['age_seconds'] = $age;
        if (in_array($current['status'], ['Unavailable', 'Stale'], true)) {
            $values['collection_available'] = 0;
        }
    }
    $sample = $current['sample'];
    if ($sample === null) {
        return $values;
    }
    $values['collection_available'] = 1;
    $values['synchronized'] = $sample['synchronized'] ? 1 : 0;
    $values['stratum'] = $sample['stratum'];
    foreach (
        ['offset_ms', 'rms_offset_ms', 'root_delay_ms', 'jitter_ms', 'peer_delay_ms']
        as $field
    ) {
        $values[$field] = $sample[$field] ?? null;
    }
    $values['absolute_offset_ms'] =
        $sample['offset_ms'] === null ? null : abs($sample['offset_ms']);
    return $values;
}

function icct_nms_ntp_fault_observations($host, $rules)
{
    $selected = array_filter($rules, fn($rule) => ($rule['source'] ?? 'rrd') === 'ntp');
    if (!$selected) {
        return [];
    }
    $current = icct_nms_ntp_current($host['id']);
    $parameters = icct_nms_ntp_fault_parameters(
        icct_nms_ntp_fault_method(icct_nms_ntp_assignment($host['id']), $current['sample']),
    );
    $values = icct_nms_ntp_fault_values($current);
    $disabled = $host['disabled'] !== '' || !icct_backend_protocol_enabled($host['id'], 'ntp');
    $out = [];
    foreach ($selected as $number => $rule) {
        $parameter = $rule['parameter'];
        if (!isset($parameters[$parameter])) {
            continue;
        }
        $value = $values[$parameter];
        if (
            $parameter === 'source_matches' &&
            $current['sample'] !== null &&
            $current['sample']['synchronized']
        ) {
            $source = $current['sample']['source_address'];
            $value =
                $source === null
                    ? null
                    : (inet_pton($source) === inet_pton($rule['expected_source'])
                        ? 1
                        : 0);
        }
        $out[] = [
            'rule' => $number,
            'graph_id' => 0,
            'graph' => $parameters[$parameter]['label'],
            'value' => $disabled ? null : $value,
            'sample_time' => $current['attempted_epoch'] ?? 0,
            'state' => $disabled ? 'Disabled' : icct_nms_fault_state($rule, $value),
        ];
    }
    return $out;
}

function icct_nms_ntp_fault_default_preset()
{
    $base = icct_nms_ntp_fault_starters(50)[0];
    $definitions = [
        'absolute_offset_ms' => ['NTP clock offset', 'above', null, 50],
        'offset_ms' => ['NTP signed offset', 'outside', -50, 50],
        'rms_offset_ms' => ['NTP RMS offset', 'above', null, 25],
        'jitter_ms' => ['NTP jitter', 'above', null, 20],
        'peer_delay_ms' => ['NTP peer round-trip delay', 'above', null, 50],
        'root_delay_ms' => ['NTP root delay', 'above', null, 100],
        'stratum' => ['NTP stratum', 'above', null, 8],
        'synchronized' => ['NTP not synchronized', 'equals', null, 0],
        'collection_available' => ['NTP collection unavailable', 'equals', null, 0],
        'age_seconds' => ['NTP last collection age', 'above', null, 900],
        'source_matches' => ['NTP expected source mismatch', 'equals', null, 0],
    ];
    $rules = [];
    foreach ($definitions as $parameter => [$name, $condition, $minimum, $maximum]) {
        $rules[$parameter] = array_replace($base, [
            'parameter' => $parameter, 'name' => $name, 'condition' => $condition,
            'minimum' => $minimum, 'maximum' => $maximum,
            'units' => icct_nms_ntp_fault_parameters()[$parameter]['units'],
            // Both offset rules detect the same threshold. Enable the signed rule if needed.
            'enabled' => $parameter !== 'offset_ms',
        ]);
    }
    return $rules;
}

function icct_nms_ntp_fault_seed_default_preset()
{
    icct_backend_category_execute(
        'INSERT IGNORE INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',
        ['ntp_fault_defaults_v2', json_encode(icct_nms_ntp_fault_default_preset(), JSON_THROW_ON_ERROR)],
    );
}

/** Factory defaults are configuration, never substitute readings. Operators edit them in FCAPS. */
function icct_nms_ntp_fault_starters($limit = 100, $expected = '')
{
    $definitions = [
        ['absolute_offset_ms', 'NTP clock offset', 'above', (float) $limit],
        ['synchronized', 'NTP synchronization', 'equals', 0],
        ['collection_available', 'NTP collection unavailable', 'equals', 0],
    ];
    if ($expected !== '') {
        $definitions[] = ['source_matches', 'NTP expected source', 'equals', 0];
    }
    $out = [];
    foreach ($definitions as [$parameter, $name, $condition, $threshold]) {
        $out[] = [
            'source' => 'ntp',
            'parameter' => $parameter,
            'template_id' => 0,
            'metric_id' => 0,
            'oid' => '',
            'scale' => 1,
            'units' => icct_nms_ntp_fault_parameters()[$parameter]['units'],
            'preset_key' => '',
            'condition' => $condition,
            'minimum' => null,
            'maximum' => $threshold,
            'expected_source' => $parameter === 'source_matches' ? $expected : '',
            'name' => $name,
            'severity' => 'Warning',
            'enabled' => true,
            'email' => false,
            'audio' => false,
            'description' => '',
            'corrective_action' => '',
            'email_recipients' => '',
        ];
    }
    return $out;
}

/** Called inside the new assignment's transaction. Editing collection never recreates deleted rules. */
function icct_nms_ntp_fault_assign_defaults($id, $settings = [])
{
    $key = 'fault_rules_' . $id;
    icct_backend_category_execute(
        'INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ' .
            'ON DUPLICATE KEY UPDATE meta_key=meta_key',
        [$key, '[]'],
    );
    $stored = db_fetch_cell_prepared(
        'SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=? FOR UPDATE',
        [$key],
    );
    $rules = $stored ? json_decode($stored, true, 64, JSON_THROW_ON_ERROR) : [];
    if (array_filter($rules, fn($rule) => ($rule['source'] ?? '') === 'ntp')) {
        return;
    }
    $defaults = db_fetch_cell_prepared(
        'SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
        ['ntp_fault_defaults_v2'],
    );
    $initial = $defaults ? json_decode($defaults, true, 64, JSON_THROW_ON_ERROR) : icct_nms_ntp_fault_default_preset();
    $offset = $settings['maximum_offset_ms'] ?? '';
    $delay = $settings['maximum_delay_ms'] ?? '';
    $expected = $settings['expected_source'] ?? '';
    if ($offset !== '') {
        $initial['absolute_offset_ms']['maximum'] = (float) $offset;
    }
    if (($settings['alarm_unsynchronized'] ?? '') !== '') {
        $initial['synchronized']['enabled'] = $settings['alarm_unsynchronized'] === '1';
    }
    if ($expected !== '') {
        $initial['source_matches']['expected_source'] = $expected;
    } else {
        unset($initial['source_matches']);
    }
    if ($delay !== '') {
        $initial['peer_delay_ms']['maximum'] = (float) $delay;
    }
    $supported = icct_nms_ntp_fault_parameters(icct_nms_ntp_fault_method(icct_nms_ntp_assignment($id)));
    $rules = array_merge($rules, array_values(array_intersect_key($initial, $supported)));
    if (count($rules) > 100) {
        throw new InvalidArgumentException('NTP defaults would exceed the 100-rule FCAPS limit.');
    }
    icct_backend_category_execute(
        'REPLACE INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',
        ['fault_rules_' . $id, json_encode($rules, JSON_THROW_ON_ERROR)],
    );
}
