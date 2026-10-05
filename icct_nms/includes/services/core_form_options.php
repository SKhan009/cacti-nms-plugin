<?php
/** ICCT-owned core form options services, derived from the existing ICCT NMS implementation. */

/** Reused Inventory service: core field choices. */
function icct_backend_core_field_choices($field)
{
    $choices = [];
    if (isset($field['none_value'])) {
        $choices[0] = $field['none_value'];
    }
    if (isset($field['array'])) {
        return $choices + $field['array'];
    }
    if ($field['method'] === 'radio') {
        foreach ($field['items'] as $item) {
            $choices[$item['radio_value']] = $item['radio_caption'];
        }
    } elseif ($field['method'] === 'drop_sql') {
        // The query comes only from installed Cacti metadata, never from a submitted form.
        foreach (db_fetch_assoc($field['sql']) as $row) {
            $choices[$row['id']] = $row['name'];
        }
    }
    return $choices;
}

/** Reused Inventory service: core field value. */
function icct_backend_core_field_value($name, $field, $value)
{
    if (!is_scalar($value)) {
        throw new InvalidArgumentException('Invalid value for ' . $name . '.');
    }
    if ($field['method'] === 'checkbox') {
        return !empty($value) ? 'on' : '';
    }
    $value = (string) $value;
    if (isset($field['max_length']) && mb_strlen($value) > (int) $field['max_length']) {
        throw new InvalidArgumentException($name . ' exceeds the Cacti field length.');
    }
    if (in_array($field['method'], ['drop_array', 'drop_sql', 'radio'], true)) {
        $choices = icct_backend_core_field_choices($field);
        if ($value === '' && isset($field['none_value'])) {
            $value = '0';
        }
        if (!array_key_exists($value, $choices)) {
            throw new InvalidArgumentException('Select a Cacti value for ' . $name . '.');
        }
    }
    return $value;
}
