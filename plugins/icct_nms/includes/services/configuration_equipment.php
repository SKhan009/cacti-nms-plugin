<?php
/** ICCT-owned configuration equipment services, derived from the existing ICCT NMS implementation. */

/** Reused Inventory service: equipment fields. */
function icct_backend_equipment_fields($json, $protocol)
{
    if (!is_string($json) || strlen($json) > 32768) {
        throw new InvalidArgumentException('Field definitions must be JSON, at most 32 KB.');
    }
    try {
        $fields = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        throw new InvalidArgumentException('Invalid field definition JSON.');
    }
    if (
        !is_array($fields) ||
        !$fields ||
        count($fields) > 16 ||
        array_keys($fields) !== range(0, count($fields) - 1)
    ) {
        throw new InvalidArgumentException('Define a list of 1–16 fields.');
    }
    $keys = [];
    $result = [];
    foreach ($fields as $field) {
        if (!is_array($field)) {
            throw new InvalidArgumentException('Each field must be an object.');
        }
        $key = icct_backend_config_text($field['key'] ?? '', 32, 'Field key');
        if (!preg_match('/^[a-z][a-z0-9_]*$/D', $key) || isset($keys[$key])) {
            throw new InvalidArgumentException('Field keys must be unique lowercase identifiers.');
        }
        $keys[$key] = true;
        if (!is_bool($field['writable'] ?? null)) {
            throw new InvalidArgumentException('Each field needs an explicit writable boolean.');
        }
        $item = [
            'key' => $key,
            'label' => icct_backend_config_text($field['label'] ?? '', 100, 'Field label'),
            'unit' => icct_backend_config_text($field['unit'] ?? '', 30, 'Unit', false),
            'writable' => $field['writable']
        ];
        if ($protocol === 'modbus_rtu') {
            $item['offset'] = icct_backend_config_integer(
                $field['offset'] ?? '',
                0,
                65535,
                'Zero-based register offset'
            );
            $item['function'] = icct_backend_config_integer(
                $field['function'] ?? '',
                3,
                4,
                'Read function'
            );
            $item['type'] = icct_backend_config_choice(
                $field['type'] ?? '',
                ['uint16', 'int16'],
                'register type'
            );
            if ($item['writable'] && $item['function'] !== 3) {
                throw new InvalidArgumentException('Input registers are read-only.');
            }
        } elseif ($protocol === 'snmp') {
            $item['oid'] = icct_backend_config_text($field['oid'] ?? '', 200, 'Numeric OID');
            if (!preg_match('/^\.?[0-2](?:\.[0-9]+){2,}$/D', $item['oid'])) {
                throw new InvalidArgumentException('Use a complete numeric scalar or indexed OID.');
            }
            $item['type'] = icct_backend_config_choice(
                $field['type'] ?? '',
                ['integer', 'unsigned', 'string'],
                'SNMP type'
            );
        } else {
            throw new InvalidArgumentException('Unsupported equipment protocol.');
        }
        if ($item['type'] === 'string') {
            $item['max_length'] = icct_backend_config_integer(
                $field['max_length'] ?? '',
                1,
                255,
                'String maximum length'
            );
        } else {
            $low = in_array($item['type'], ['int16', 'integer'], true)
                ? ($item['type'] === 'int16'
                    ? -32768
                    : -2147483648)
                : 0;
            $high = in_array($item['type'], ['int16', 'uint16'], true)
                ? ($item['type'] === 'int16'
                    ? 32767
                    : 65535)
                : ($item['type'] === 'integer'
                    ? 2147483647
                    : 4294967295);
            foreach (['min', 'max'] as $bound) {
                $v = $field[$bound] ?? null;
                if (!is_int($v) || $v < $low || $v > $high) {
                    throw new InvalidArgumentException(
                        'Provide integer min/max within the field type range.'
                    );
                }
                $item[$bound] = $v;
            }
            if ($item['min'] > $item['max']) {
                throw new InvalidArgumentException('Field minimum exceeds maximum.');
            }
        }
        $result[] = $item;
    }
    return $result;
}

/** Reused Inventory service: equipment value. */
function icct_backend_equipment_value(array $field, $value)
{
    if ($field['type'] === 'string') {
        return icct_backend_config_text($value, $field['max_length'], 'Setting value', false);
    }
    if (
        (!is_string($value) && !is_int($value)) ||
        !preg_match('/^-?[0-9]+$/D', (string) $value) ||
        (float) $value < $field['min'] ||
        (float) $value > $field['max']
    ) {
        throw new InvalidArgumentException(
            'Setting must be an integer within its documented range.'
        );
    }
    return (int) $value;
}
