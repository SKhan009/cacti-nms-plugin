<?php

/** Reuse native Cacti samples without changing core polling, RRD updates or credentials. */
function icct_nms_snmp_profile(array $host): string
{
    $values = [];
    foreach (['id', 'hostname', 'poller_id', 'disabled', 'snmp_version', 'snmp_port',
        'snmp_context', 'snmp_engine_id', 'snmp_username', 'snmp_community', 'snmp_password',
        'snmp_auth_protocol', 'snmp_priv_passphrase', 'snmp_priv_protocol', 'snmp_timeout', 'max_oids'] as $field) {
        $values[$field] = (string) ($host[$field] ?? '');
    }
    return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
}

function icct_nms_snmp_reuse_reset(): void
{
    $GLOBALS['icct_nms_snmp_samples'] = [];
}

function icct_nms_snmp_oid($oid): string
{
    $oid = ltrim(trim((string) $oid), '.');
    return preg_match('/^[0-9]+(?:\.[0-9]+)+$/D', $oid) ? $oid : '';
}

function icct_nms_snmp_item_matches(array $host, array $item): bool
{
    foreach ($item as $field => $value) {
        if (($field === 'hostname' || str_starts_with($field, 'snmp_')) &&
            array_key_exists($field, $host) && (string) $host[$field] !== (string) $value) return false;
    }
    return (int) ($item['host_id'] ?? 0) === (int) $host['id'] &&
        (int) ($item['poller_id'] ?? 0) === (int) $host['poller_id'];
}

function &icct_nms_snmp_samples(array $host): array
{
    global $config;
    $key = icct_nms_snmp_profile($host);
    if (!isset($GLOBALS['icct_nms_snmp_samples'][$key])) {
        $state = ['values'=>[], 'owners'=>[], 'queries'=>[], 'walks'=>[], 'columns'=>[], 'used'=>[], 'ambiguous'=>[], 'query_fresh'=>[]];
        // This bridge is a collector service, never a way around web authorization.
        $authorized = PHP_SAPI === 'cli'
            ? (int) ($host['poller_id'] ?? 0) === (int) ($config['poller_id'] ?? 0)
            : function_exists('icct_backend_require_device_access');
        if (PHP_SAPI !== 'cli' && $authorized) icct_backend_require_device_access((int) $host['id']);
        if ($authorized && !empty($host['id']) && ($host['disabled'] ?? '') === '' && ($host['deleted'] ?? '') === '') {
            $profile = json_decode((string) db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
                ['cacti_snmp_profile_' . (int) $host['id']]), true);
            $profileTime = ($profile['signature'] ?? '') === $key && (int) ($profile['time'] ?? 0) > 0 &&
                (int) $profile['time'] <= time() ? (int) $profile['time'] : PHP_INT_MAX;
            if ($profileTime === PHP_INT_MAX && PHP_SAPI === 'cli') {
                $profileTime = time();
                db_execute_prepared('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
                    ['cacti_snmp_profile_' . (int) $host['id'], json_encode(['signature'=>$key,'time'=>$profileTime], JSON_THROW_ON_ERROR)]);
            }
            foreach (db_fetch_assoc_prepared('SELECT * FROM poller_item WHERE host_id=? AND poller_id=? AND action=0 AND present=1',
                [(int) $host['id'], (int) $host['poller_id']]) ?: [] as $item) {
                $oid = icct_nms_snmp_oid($item['arg1'] ?? '');
                if ($oid && (int) $item['rrd_step'] > 0 &&
                    (int) $item['rrd_step'] <= (int) read_config_option('poller_interval') &&
                    icct_nms_snmp_item_matches($host, $item)) $state['owners'][$oid][] = $item;
            }
            $saved = json_decode((string) db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
                ['cacti_snmp_samples_' . (int) $host['id']]), true);
            if (($saved['signature'] ?? '') === $key) {
                foreach ($saved['values'] ?? [] as $oid => $sample) {
                    if (!is_array($sample) || !isset($sample['local_data_id'], $sample['rrd_name'], $sample['time'])) continue;
                    foreach ($state['owners'][$oid] ?? [] as $owner) {
                        if ((int) $sample['local_data_id'] === (int) $owner['local_data_id'] &&
                            $sample['rrd_name'] === $owner['rrd_name']) {
                            $sample['step'] = (int) $owner['rrd_step'];
                            $state['values'][$oid] = $sample;
                        }
                    }
                }
            }
            foreach (db_fetch_assoc_prepared('SELECT *,UNIX_TIMESTAMP(last_updated) AS sample_time FROM host_snmp_cache WHERE host_id=? AND present=1',
                [(int) $host['id']]) ?: [] as $row) {
                $oid = icct_nms_snmp_oid($row['oid']);
                if (!$oid) continue;
                $sample = ['value'=>(string) $row['field_value'], 'time'=>(int) $row['sample_time'],
                    'step'=>(int) read_config_option('poller_interval'), 'source'=>'query'];
                if ($sample['time'] <= $profileTime) continue; // A cache from a prior endpoint/security configuration is not evidence for this one.
                $queryId = (int) $row['snmp_query_id'];
                $state['query_fresh'][$queryId] = ($state['query_fresh'][$queryId] ?? true) && icct_nms_snmp_sample_fresh($sample);
                try { $schema = get_data_query_array($queryId); } catch (Throwable $error) { continue; }
                $field = $schema['fields'][$row['field_name'] ?? ''] ?? [];
                if (!in_array($field['direction'] ?? '', ['input','input-output'], true) ||
                    !in_array($field['method'] ?? '', ['get','walk'], true) ||
                    strtolower($field['source'] ?? '') !== 'value' || !empty($field['rewrite_value'])) continue;
                $state['queries'][$queryId][$oid] = $sample;
                if (!isset($state['owners'][$oid])) {
                    $previous = $state['values'][$oid] ?? null;
                    if ($previous && $previous['time'] === $sample['time'] && $previous['value'] !== $sample['value']) {
                        $state['ambiguous'][$oid] = true;
                    } elseif (!$previous || $previous['time'] < $sample['time']) {
                        $state['values'][$oid] = $sample;
                        unset($state['ambiguous'][$oid]);
                    }
                }
            }
        }
        $GLOBALS['icct_nms_snmp_samples'][$key] = $state;
    }
    return $GLOBALS['icct_nms_snmp_samples'][$key];
}

function icct_nms_snmp_sample_fresh(array $sample): bool
{
    $age = time() - (int) ($sample['time'] ?? 0);
    $step = (int) ($sample['step'] ?? read_config_option('poller_interval'));
    return $step > 0 && $age >= 0 && $age <= 2 * $step;
}

function icct_nms_snmp_known(array $host, string $oid): ?array
{
    $state = &icct_nms_snmp_samples($host);
    $sample = $state['values'][$oid] ?? null;
    if (isset($state['ambiguous'][$oid]) || !empty($sample['inexact'])) return null;
    if ($sample && icct_nms_snmp_sample_fresh($sample)) {
        $state['used'][] = (int) $sample['time'];
        return $sample;
    }
    // Cacti owns these OIDs. A failed/late native sample is unknown, not permission to poll it twice.
    return isset($state['owners'][$oid]) ? ['value'=>null, 'time'=>0, 'source'=>'native', 'reason'=>$sample ? 'stale' : 'missing'] : null;
}

function icct_nms_snmp_remember(array $host, string $oid, $value, ?array $wire = null): array
{
    $state = &icct_nms_snmp_samples($host);
    unset($state['ambiguous'][$oid]);
    $state['used'][] = time();
    return $state['values'][$oid] = ['value'=>$value, 'wire'=>$wire, 'time'=>time(),
        'step'=>(int) read_config_option('poller_interval'), 'source'=>'collector'];
}

function icct_nms_snmp_read(array $host, $oid, callable $collect, $format = null)
{
    $oid = icct_nms_snmp_oid($oid);
    $sample = $oid ? icct_nms_snmp_known($host, $oid) : null;
    $hex = defined('SNMP_STRING_OUTPUT_HEX') && $format === SNMP_STRING_OUTPUT_HEX;
    if ($sample && !$hex) return $sample['value'] ?? false;
    // Formatted Cacti strings cannot be relabelled as lossless binary octets.
    if ($sample && $hex && isset($sample['wire']) && $sample['wire']['type'] === 4) {
        return strtoupper(implode(' ', str_split(bin2hex($sample['wire']['value']), 2)));
    }
    $value = $collect();
    if ($oid && !$hex) icct_nms_snmp_remember($host, $oid, $value);
    return $value;
}

/** Convert only representations whose original value is retained by the native source. */
function icct_nms_snmp_wire(array $sample, int $type): ?array
{
    if (($sample['source'] ?? '') === 'query' && in_array($type, [67,70], true)) return null;
    if (isset($sample['wire'])) return (int) $sample['wire']['type'] === $type ? $sample['wire'] + ['_sample_time'=>$sample['time']] : null;
    $value = $sample['value'] ?? null;
    if (!is_scalar($value) || is_bool($value) || is_float($value)) return null;
    $value = (string) $value;
    if ($type === 4) {
        // Native Cacti strings are formatted/trimmed; only wire observations prove raw identity bytes.
        return null;
    } else {
        if ($type === 67 && function_exists('icct_backend_ports_ticks')) {
            $value = icct_backend_ports_ticks($value);
        } elseif ($type === 2 && function_exists('icct_backend_ports_integer')) {
            $value = icct_backend_ports_integer($value);
        }
        if (!is_scalar($value) || !preg_match($type === 2 ? '/^-?[0-9]+$/D' : '/^[0-9]+$/D', (string) $value)) return null;
        $value = (string) $value;
    }
    return ['type'=>$type, 'value'=>$value, '_sample_time'=>(int) $sample['time']];
}

/** Complete columns are identified from the device's native query definition, never a query ID/name. */
function icct_nms_snmp_complete_column(array $host, string $root): ?array
{
    $state = &icct_nms_snmp_samples($host);
    foreach ($state['queries'] as $queryId => $samples) {
        if (!$samples || empty($state['query_fresh'][$queryId])) continue;
        try { $schema = get_data_query_array($queryId); } catch (Throwable $error) { continue; }
        if (icct_nms_snmp_oid($schema['oid_index'] ?? '') !== $root ||
            !empty($schema['oid_index_parse']) || !empty($schema['value_index_parse']) || !empty($schema['rewrite_index'])) continue;
        $field = null;
        foreach ($schema['fields'] ?? [] as $spec) {
            if (in_array($spec['direction'] ?? '', ['input','input-output'], true) && icct_nms_snmp_oid($spec['oid'] ?? '') === $root) $field = $spec;
        }
        if (!$field || array_intersect_key($state['ambiguous'], $samples)) continue;
        $rows = array_filter($samples, static fn($s, $oid)=>str_starts_with($oid, $root . '.'), ARRAY_FILTER_USE_BOTH);
        // Each native query index must have this input field; a partial cache is not complete evidence.
        $indexes = [];
        foreach ($samples as $oid => $sample) {
            foreach ($schema['fields'] ?? [] as $spec) {
                $prefix = icct_nms_snmp_oid($spec['oid'] ?? '') . '.';
                if (str_starts_with($oid, $prefix)) $indexes[substr($oid, strlen($prefix))] = true;
            }
        }
        if ($rows && count($rows) === count($indexes)) return $rows;
    }
    return null;
}

/** A partially covered indexed column probes only missing instances, using native SNMP APIs. */
function icct_nms_snmp_column(array $host, $root, callable $walk, callable $get, array $indexes = [], ?int $type = null): array
{
    $root = icct_nms_snmp_oid($root);
    $state = &icct_nms_snmp_samples($host);
    $key = $root . ':' . ($type ?? 'plain') . ':' . hash('sha256', json_encode(array_values($indexes)));
    if (isset($state['walks'][$key])) return $state['walks'][$key];
    $samples = [];
    if (!$indexes && array_key_exists($root, $state['columns'])) {
        $indexes = $state['columns'][$root];
        if (!$indexes) return [];
    }
    $complete = !$indexes ? icct_nms_snmp_complete_column($host, $root) : null;
    if ($complete) $indexes = array_map(static fn($oid)=>substr($oid, strlen($root) + 1), array_keys($complete));
    foreach ($indexes as $index) {
        $oid = $root . '.' . $index;
        $sample = icct_nms_snmp_known($host, $oid);
        if (!$sample) continue;
        $value = $type === null ? ($sample['value'] ?? false) : icct_nms_snmp_wire($sample, $type);
        if ($value !== null && $value !== false) $samples[$oid] = $value;
        elseif (in_array($sample['value'] ?? null, [null,false,'U'], true)) $samples[$oid] = null;
    }
    if ($samples) {
        foreach ($indexes as $index) {
            $oid = $root . '.' . $index;
            if (array_key_exists($oid, $samples)) continue;
            try { $samples[$oid] = $get($oid); } catch (RuntimeException $error) { $samples[$oid] = null; }
        }
        return $state['walks'][$key] = array_filter($samples, static fn($v)=>$v !== null && $v !== false);
    }
    $rows = $walk();
    $state['columns'][$root] = [];
    foreach (is_array($rows) ? $rows : [] as $oid => $value) {
        if (str_starts_with($oid, $root . '.')) $state['columns'][$root][] = substr($oid, strlen($root) + 1);
    }
    return $state['walks'][$key] = is_array($rows) ? $rows : [];
}

/** Observe the native hook's exact raw values before RRD conversion; return its input unchanged. */
function icct_nms_snmp_capture(array $updates): void
{
    global $config;
    $byId = [];
    foreach ($updates as $update) {
        if (!empty($update['local_data_id']) && is_array($update['times'] ?? null)) {
            $byId[(int) $update['local_data_id']] = $update['times'];
        }
    }
    if (!$byId) return;
    $marks = implode(',', array_fill(0, count($byId), '?'));
    $items = db_fetch_assoc_prepared("SELECT * FROM poller_item WHERE action=0 AND present=1 AND poller_id=? AND local_data_id IN ($marks)",
        array_merge([(int) $config['poller_id']], array_keys($byId))) ?: [];
    if (!$items) return;
    $hostIds = array_values(array_unique(array_map(static fn($item)=>(int) $item['host_id'], $items)));
    $marks = implode(',', array_fill(0, count($hostIds), '?'));
    $hosts = array_column(db_fetch_assoc_prepared("SELECT * FROM host WHERE poller_id=? AND disabled='' AND deleted='' AND id IN ($marks)",
        array_merge([(int) $config['poller_id']], $hostIds)) ?: [], null, 'id');
    $saved = [];
    foreach ($items as $item) {
        $id = (int) $item['host_id'];
        $host = $hosts[$id] ?? null;
        $oid = icct_nms_snmp_oid($item['arg1'] ?? '');
        if (!$host || !$oid || !icct_nms_snmp_item_matches($host, $item)) continue;
        if (!isset($saved[$id])) {
            $prior = json_decode((string) db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',
                ['cacti_snmp_samples_' . $id]), true);
            $signature = icct_nms_snmp_profile($host);
            $saved[$id] = ($prior['signature'] ?? '') === $signature ? $prior : ['signature'=>$signature, 'values'=>[]];
        }
        foreach ($byId[(int) $item['local_data_id']] as $time => $values) {
            if (!array_key_exists($item['rrd_name'], $values) || !ctype_digit((string) $time) ||
                (int) $time > time() || (int) $time <= 0) continue;
            $raw = $values[$item['rrd_name']];
            // A float has already lost Counter64 precision. Do not manufacture an exact counter.
            $inexact = is_float($raw);
            if (!is_string($raw) && !is_int($raw)) $raw = 'U';
            $previous = $saved[$id]['values'][$oid] ?? [];
            if ((int) ($previous['time'] ?? 0) > (int) $time) continue;
            if ((int) ($previous['time'] ?? 0) === (int) $time &&
                isset($previous['value']) && (string) $previous['value'] !== (string) $raw) $inexact = true;
            $saved[$id]['values'][$oid] = ['value'=>(string) $raw, 'time'=>(int) $time, 'inexact'=>$inexact,
                'local_data_id'=>(int) $item['local_data_id'], 'rrd_name'=>$item['rrd_name'], 'source'=>'native'];
        }
    }
    foreach ($saved as $id => $snapshot) {
        $cutoff = time() - 2 * max((int) read_config_option('poller_interval'),
            ...array_map(static fn($item)=>(int) $item['rrd_step'], $items));
        $snapshot['values'] = array_filter($snapshot['values'], static fn($sample)=>(int) $sample['time'] >= $cutoff);
        db_execute_prepared('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',
            ['cacti_snmp_samples_' . $id, json_encode($snapshot, JSON_THROW_ON_ERROR)]);
    }
    icct_nms_snmp_reuse_reset();
}

function icct_nms_snmp_get(array $host, $oid)
{
    return icct_nms_snmp_read($host, $oid, static fn() => cacti_snmp_get(
        $host['hostname'], $host['snmp_community'], '.' . icct_nms_snmp_oid($oid),
        $host['snmp_version'], $host['snmp_username'], $host['snmp_password'],
        $host['snmp_auth_protocol'], $host['snmp_priv_passphrase'], $host['snmp_priv_protocol'],
        $host['snmp_context'], $host['snmp_port'], $host['snmp_timeout'],
        (int) read_config_option('snmp_retries'), 'ICCT shared SNMP', $host['snmp_engine_id'], SNMP_STRING_OUTPUT_ASCII,
    ));
}

/** Use Cacti's own formatter when a raw discovery observation is shared with a plain reader. */
function icct_nms_snmp_remember_wire(array $host, string $oid, array $wire): array
{
    $value = $wire['value'];
    if ($wire['type'] === 4 && (!preg_match('//u', $value) || preg_match('/[\x00-\x1f\x7f]/', $value))) {
        $value = 'Hex-STRING: ' . strtoupper(implode(' ', str_split(bin2hex($value), 2)));
    }
    $plain = format_snmp_string($value, false, SNMP_STRING_OUTPUT_ASCII);
    $sample = icct_nms_snmp_remember($host, $oid, $plain, $wire);
    return $wire + ['_sample_time'=>$sample['time']];
}

function icct_nms_snmp_observation_start(array $host): int
{
    $state = &icct_nms_snmp_samples($host);
    return count($state['used']);
}

function icct_nms_snmp_observation_time(array $host, int $start): int
{
    $state = &icct_nms_snmp_samples($host);
    $times = array_slice($state['used'], $start);
    return $times ? min($times) : time();
}
