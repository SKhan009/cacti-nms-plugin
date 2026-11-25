<?php

/** ICCT-owned interface rates services, derived from the existing ICCT NMS implementation. */

function icct_backend_interface_counter_delta($current, $previous)
{
    foreach ([$current, $previous] as $value) {
        if (!is_string($value) || !preg_match('/^[0-9]{1,20}$/D', $value)) {
            return null;
        }
        $value = ltrim($value, '0') ?: '0';
        if (strlen($value) === 20 && strcmp($value, '18446744073709551615') > 0) {
            return null;
        }
    }
    $a = ltrim($current, '0') ?: '0';
    $b = ltrim($previous, '0') ?: '0';
    if (strlen($a) < strlen($b) || (strlen($a) === strlen($b) && strcmp($a, $b) < 0)) {
        return null;
    }
    $b = str_pad($b, strlen($a), '0', STR_PAD_LEFT);
    $result = '';
    $borrow = 0;
    for ($i = strlen($a) - 1; $i >= 0; $i--) {
        $digit = (int) $a[$i] - (int) $b[$i] - $borrow;
        $borrow = $digit < 0 ? 1 : 0;
        $result = (string) ($digit + ($borrow ? 10 : 0)) . $result;
    }
    return (float) $result;
}

/** The same identity guards both consecutive samples and saved endpoint mappings. */
function icct_backend_interface_identity(array $port): string
{
    $identity = [];
    foreach (['index', 'name_hex', 'mac_hex', 'description_hex'] as $key) {
        $identity[$key] = $port[$key] ?? null;
    }
    return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
}

function icct_backend_interface_rates(array $current, array $previous, int $maxAge): array
{
    $elapsed = (int) ($current['collected'] ?? 0) - (int) ($previous['collected'] ?? 0);
    $valid =
        $elapsed > 0 &&
        $elapsed <= $maxAge &&
        isset($current['uptime'], $previous['uptime']) &&
        is_numeric($current['uptime']) &&
        is_numeric($previous['uptime']) &&
        (float) $current['uptime'] >= (float) $previous['uptime'] &&
        abs(((float) $current['uptime'] - (float) $previous['uptime']) / 100 - $elapsed) <=
            max(10, $elapsed * 0.15);
    foreach ($current['interfaces'] as $index => &$port) {
        $port['in_bps'] = $port['out_bps'] = null;
        $port['sample_seconds'] = null;
        $port['sample_started'] = $port['sample_ended'] = null;
        $port['measurement_state'] = 'waiting_for_second_sample';
        $port['measurement_message'] = 'Waiting for the second sample.';
        if (!empty($port['_native_counter_stale'])) {
            $port['measurement_state'] = 'collection_stale';
            $port['measurement_message'] = 'Collection stale. Waiting for a fresh interface snapshot.';
            continue;
        }
        if (!empty($port['_native_counter_pending'])) continue;
        if (!isset($port['in_octets'], $port['out_octets'], $port['discontinuity'])) {
            $port['measurement_state'] = 'counters_inaccessible';
            $port['measurement_message'] = 'Counters inaccessible. Check the SNMP view and IF-MIB support.';
            continue;
        }
        $old = $previous['interfaces'][$index] ?? [];
        if (
            !$valid ||
            !$old ||
            !isset($port['discontinuity'], $old['discontinuity']) ||
            (string) $port['discontinuity'] !== (string) $old['discontinuity']
        ) {
            continue;
        }
        if (icct_backend_interface_identity($port) !== icct_backend_interface_identity($old)) {
            $port['measurement_message'] = 'Interface identity changed. Waiting for two consistent samples.';
            continue;
        }
        // Re-reading the same native sample is not a second sample or zero traffic.
        $sameSample = !empty($port['in_sample_time']) && !empty($port['out_sample_time']) &&
            $port['in_sample_time'] === ($old['in_sample_time'] ?? null) &&
            $port['out_sample_time'] === ($old['out_sample_time'] ?? null) &&
            (string) $port['in_octets'] === (string) $old['in_octets'] &&
            (string) $port['out_octets'] === (string) $old['out_octets'];
        if ($sameSample && ($old['measurement_state'] ?? '') === 'valid') {
            foreach (['in_bps','out_bps','sample_seconds','sample_started','sample_ended',
                'measurement_state','measurement_message'] as $field) $port[$field] = $old[$field];
            continue;
        }
        $starts = $ends = [];
        foreach (['in', 'out'] as $direction) {
            $delta = icct_backend_interface_counter_delta(
                $port[$direction . '_octets'] ?? null,
                $old[$direction . '_octets'] ?? null,
            );
            $end = (int) ($port[$direction . '_sample_time'] ?? $current['collected']);
            $start = (int) ($old[$direction . '_sample_time'] ?? $previous['collected']);
            $seconds = $end - $start;
            if ($delta !== null && $seconds > 0 && $seconds <= $maxAge) {
                $port[$direction . '_bps'] = ($delta * 8) / $seconds;
                $port['sample_seconds'] = $seconds;
                $starts[] = $start;
                $ends[] = $end;
            }
        }
        if ($port['in_bps'] !== null && $port['out_bps'] !== null) {
            $port['measurement_state'] = 'valid';
            $port['measurement_message'] = 'Valid measurement.';
            $port['sample_started'] = min($starts);
            $port['sample_ended'] = max($ends);
        } else {
            $port['in_bps'] = $port['out_bps'] = null;
            $port['sample_seconds'] = null;
            $port['measurement_message'] = 'Counters changed or reset. Waiting for two consistent samples.';
        }
    }
    unset($port);
    return $current;
}
