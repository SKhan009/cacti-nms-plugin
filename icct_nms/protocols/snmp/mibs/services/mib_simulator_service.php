<?php

const ICCT_MIB_SIM_STATE = '/var/lib/icct-nms-mib-simulator';
const ICCT_MIB_SIM_LIMIT = 128;

function icct_mib_simulator_devices()
{
    $devices = db_fetch_assoc_prepared(
        "SELECT h.id,h.description FROM host h WHERE h.deleted='' AND h.disabled='' AND h.poller_id=?
         AND h.hostname='127.0.0.1' AND h.snmp_port=1161 AND h.snmp_version=2 AND h.snmp_community='icct-mib-sim'
         ORDER BY h.description,h.id",
        [icct_backend_inventory_collector_id()],
    ) ?: [];
    return array_filter($devices, static fn($device) => is_device_allowed((int) $device['id']));
}

function icct_mib_simulator_state(): array
{
    $path = ICCT_MIB_SIM_STATE . '/cycle.json';
    $content = is_readable($path) ? file_get_contents($path) : false;
    $state = is_string($content) ? json_decode($content, true) : null;
    return ($state['version'] ?? null) === 1 && !empty($state['objects']) ? $state : [];
}

function icct_mib_simulator_objects(array $records): array
{
    $objects = [];
    foreach ($records as $record) {
        if ($record['kind'] !== 'OBJECT-TYPE' ||
            !in_array($record['access'], ['read-only', 'read-write', 'read-create'], true)) {
            continue;
        }
        $syntax = $record['syntax'];
        $type = match (true) {
            preg_match('/^(?:INTEGER|Integer32)\b/', $syntax) === 1 => 'integer',
            preg_match('/^(?:Unsigned32|Gauge32)\b/', $syntax) === 1 => 'gauge',
            str_starts_with($syntax, 'Counter32') => 'counter32',
            str_starts_with($syntax, 'Counter64') => 'counter64',
            str_starts_with($syntax, 'TimeTicks') => 'ticks',
            str_starts_with($syntax, 'OCTET STRING'), str_starts_with($syntax, 'BITS') => 'string',
            str_starts_with($syntax, 'OBJECT IDENTIFIER') => 'oid',
            str_starts_with($syntax, 'IpAddress') => 'ip',
            default => null,
        };
        if ($type === null) {
            continue;
        }
        $object = array_intersect_key($record, array_flip(['symbol', 'base_oid', 'oid', 'syntax', 'table']));
        $object['type'] = $type;
        if ($type === 'integer' || $type === 'gauge') {
            $min = $type === 'integer' ? -2147483648 : 0;
            $max = $type === 'integer' ? 2147483647 : 4294967295;
            $classification = strtolower($record['symbol'] . ' ' . $record['units']);
            $percent = str_contains($classification, '%') || str_contains($classification, 'percent');
            $identifier = preg_match('/(?:ID|Id|Index|Identifier|SerialNumber|Version|Revision|Address|Status|State|Mode|Type|Code|Number)$/', $record['label'])
                || preg_match('/(?:identifier|serial number|index value|index of this)/i', $record['description']);
            preg_match('/\{[^}]*?\(\s*(-?\d+)\s*\)/', $syntax, $enumeration);
            preg_match('/\(\s*(-?\d+)\s*(?:\.\.\s*(-?\d+))?\s*(?:\)|\|)/', $syntax, $range);
            $lower = isset($range[1]) ? max($min, (int) $range[1]) : 0;
            $upper = isset($range[2]) ? min($max, (int) $range[2]) : (isset($range[1]) ? $lower : 100);
            if ($lower > $upper) {
                continue;
            }
            if ($percent && $lower <= 100 && $upper >= 0) {
                $lower = max($lower, 0);
                $upper = min($upper, 100);
            }
            if (isset($enumeration[1])) {
                $object['value'] = (int) $enumeration[1];
            } elseif ($identifier || $lower === $upper) {
                $object['value'] = max($lower, min(1, $upper));
            } else {
                // Avoid generating billion-unit readings for sensors declared with a generic integer range.
                if ($upper - $lower > 200) {
                    $lower = max($lower, min(0, $upper - 100));
                    $upper = min($upper, $lower + 200);
                }
                $object['bounds'] = [$lower, $upper];
            }
        } elseif ($type === 'string') {
            $value = str_starts_with($syntax, 'BITS') ? '' : 'Simulated ' . $record['label'];
            if (preg_match('/SIZE\s*\(\s*(\d+)\s*(?:\.\.\s*(\d+))?/', $syntax, $size)) {
                $min = (int) $size[1];
                $max = (int) ($size[2] ?? $min);
                if ($min > 512 || $max < $min) {
                    continue;
                }
                $value = str_pad(substr($value, 0, $max), $min);
            }
            $object['value'] = $value;
        } elseif ($type === 'oid') {
            $object['value'] = '1.3.6.1.4.1.32473';
        } elseif ($type === 'ip') {
            $object['value'] = '192.0.2.1';
        }
        $objects[$record['symbol']] = $object;
    }
    return $objects;
}

function icct_mib_simulator_control(string $action, array $input, array $draft): void
{
    if (!is_dir(ICCT_MIB_SIM_STATE)) {
        throw new RuntimeException('The MIB simulator has not been provisioned on this collector.');
    }
    if (!is_writable(ICCT_MIB_SIM_STATE)) {
        throw new RuntimeException('The Cacti PHP account cannot write to the MIB Simulator state directory.');
    }
    $path = ICCT_MIB_SIM_STATE . '/cycle.json';
    if ($action === 'simulator_stop') {
        if (is_file($path) && !@unlink($path) && is_file($path)) {
            throw new RuntimeException('Could not stop the simulation.');
        }
        return;
    }
    if ($action !== 'simulator_start') {
        throw new InvalidArgumentException('Choose Start or Stop.');
    }
    $selected = $input['objects'] ?? [];
    if (!is_array($selected) || !$selected || count($selected) > ICCT_MIB_SIM_LIMIT) {
        throw new InvalidArgumentException('Select 1–' . ICCT_MIB_SIM_LIMIT . ' OIDs to simulate.');
    }
    if (empty($draft['token']) || !hash_equals($draft['token'], (string) ($input['mib_token'] ?? ''))) {
        throw new InvalidArgumentException('The MIB selection expired. Upload the MIB again.');
    }
    $objects = [];
    foreach ($selected as $symbol) {
        $object = is_string($symbol) ? ($draft['objects'][$symbol] ?? null) : null;
        if (!$object) {
            throw new InvalidArgumentException('Select OIDs from the uploaded MIB.');
        }
        $oid = $object['oid'];
        if ($object['table']) {
            $suffix = $input['instance'][$symbol] ?? '';
            $suffix = is_string($suffix) ? trim($suffix) : '';
            if (!preg_match('/^\.?\d{1,10}(?:\.\d{1,10}){0,31}$/D', $suffix)) {
                throw new InvalidArgumentException("Enter a numeric instance suffix for $symbol.");
            }
            $oid = $object['base_oid'] . '.' . ltrim($suffix, '.');
        }
        $arcs = array_map('intval', explode('.', $oid));
        if (count($arcs) < 2 || count($arcs) > 128 || $arcs[0] > 2 ||
            ($arcs[0] < 2 && $arcs[1] > 39) || min($arcs) < 0 || max($arcs) > 4294967295) {
            throw new InvalidArgumentException("Invalid numeric OID for $symbol.");
        }
        $oid = implode('.', $arcs);
        if (isset($objects[$oid])) {
            throw new InvalidArgumentException("The selected OIDs contain a duplicate instance: $oid.");
        }
        $objects[$oid] = ['oid' => $oid] + array_intersect_key($object, array_flip(['type', 'value', 'bounds']));
    }
    $cadence = (int) read_config_option('poller_interval');
    if ($cadence < 1) {
        throw new RuntimeException('The Cacti poller interval is unavailable.');
    }
    $state = json_encode([
        'version' => 1,
        'interval' => $cadence,
        'started_at' => time(),
        'seed' => bin2hex(random_bytes(16)),
        'files' => $draft['files'],
        'objects' => array_values($objects),
    ], JSON_THROW_ON_ERROR);
    $temporary = tempnam(ICCT_MIB_SIM_STATE, 'cycle-');
    try {
        if ($temporary === false || file_put_contents($temporary, $state) !== strlen($state) ||
            !chmod($temporary, 0640) || !rename($temporary, $path)) {
            throw new RuntimeException('Could not start the simulation.');
        }
    } finally {
        if ($temporary !== false && is_file($temporary)) {
            unlink($temporary);
        }
    }
}
