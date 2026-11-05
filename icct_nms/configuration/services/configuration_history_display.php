<?php

/** Display saved snapshots without changing the original audit data or resolving today's settings. */
function icct_nms_configuration_label($field)
{
    $label = ucwords(str_replace(['_', '.'], [' ', ' / '], (string) $field));
    return preg_replace_callback(
        '/\b(Snmp|Ntp|Ssh|Oid|Id|Ids|Ip|Mac|Cdp|Lldp)\b/',
        static fn($match) => strtoupper($match[0]),
        $label,
    );
}

/** JSON belongs in storage; the history view presents its recorded fields as ordinary text. */
function icct_nms_configuration_value_text($value)
{
    if (is_string($value) && preg_match('/^\s*[\[{]/', $value)) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $value = $decoded;
        }
    }
    if (!is_array($value)) {
        if ($value === null || $value === '') {
            return 'Not set';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        return (string) $value;
    }
    if (!$value) {
        return 'None';
    }
    $lines = [];
    $list = array_keys($value) === range(0, count($value) - 1);
    foreach ($value as $key => $item) {
        $heading =
            $list && is_array($item) && is_string($item['name'] ?? null) ? trim($item['name']) : '';
        if ($heading !== '') {
            unset($item['name']);
        }
        $text = icct_nms_configuration_value_text($item);
        $label = $list
            ? ($heading !== ''
                ? $heading
                : (is_array($item)
                    ? 'Item ' . ($key + 1)
                    : ''))
            : icct_nms_configuration_label($key);
        if ($label === '') {
            $lines[] = $text;
        } elseif (is_array($item)) {
            $lines[] = $label . ":\n  " . str_replace("\n", "\n  ", $text);
        } else {
            $lines[] = $label . ': ' . $text;
        }
    }
    return implode("\n", $lines);
}
