<?php
/** Subtract unsigned Counter64 values without rounding their low bits. */
function nms_interface_counter_delta($current, $previous)
{
    foreach ([$current, $previous] as $value) {
        if (!is_string($value) || !preg_match('/^[0-9]{1,20}$/D', $value)) return null;
        $value = ltrim($value, '0') ?: '0';
        if (strlen($value) === 20 && strcmp($value, '18446744073709551615') > 0) return null;
    }
    $a = ltrim($current, '0') ?: '0';
    $b = ltrim($previous, '0') ?: '0';
    if (strlen($a) < strlen($b) || (strlen($a) === strlen($b) && strcmp($a, $b) < 0)) return null;
    $b = str_pad($b, strlen($a), '0', STR_PAD_LEFT);
    $result = ''; $borrow = 0;
    for ($i = strlen($a) - 1; $i >= 0; $i--) {
        $digit = (int) $a[$i] - (int) $b[$i] - $borrow;
        $borrow = $digit < 0 ? 1 : 0;
        $result = (string) ($digit + ($borrow ? 10 : 0)) . $result;
    }
    return (float) $result;
}
/** Interval averages from consecutive, unchanged identity observations (RFC 2863). */
function nms_interface_rates(array $current, array $previous, int $maxAge): array
{
    $elapsed = (int) ($current['collected'] ?? 0) - (int) ($previous['collected'] ?? 0);
    $valid = $elapsed > 0 && $elapsed <= $maxAge
        && isset($current['uptime'], $previous['uptime'])
        && is_numeric($current['uptime']) && is_numeric($previous['uptime'])
        && (float) $current['uptime'] >= (float) $previous['uptime']
        && abs(((float) $current['uptime'] - (float) $previous['uptime']) / 100 - $elapsed) <= max(10, $elapsed * 0.15);
    foreach ($current['interfaces'] as $index => &$port) {
        $port['in_bps'] = $port['out_bps'] = null;
        $port['sample_seconds'] = null;
        $old = $previous['interfaces'][$index] ?? [];
        if (!$valid || !$old || !isset($port['discontinuity'], $old['discontinuity'])
            || (string) $port['discontinuity'] !== (string) $old['discontinuity']) continue;
        foreach (['index', 'name_hex', 'mac_hex', 'description_hex'] as $key) {
            if (($port[$key] ?? null) !== ($old[$key] ?? null)) continue 2;
        }
        foreach (['in', 'out'] as $direction) {
            $delta = nms_interface_counter_delta($port[$direction . '_octets'] ?? null, $old[$direction . '_octets'] ?? null);
            if ($delta !== null) {
                $port[$direction . '_bps'] = $delta * 8 / $elapsed;
                $port['sample_seconds'] = $elapsed;
            }
        }
    }
    unset($port);
    return $current;
}
