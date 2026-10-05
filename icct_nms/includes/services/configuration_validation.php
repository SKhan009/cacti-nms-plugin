<?php
/** ICCT-owned configuration validation services, derived from the existing ICCT NMS implementation. */

/** Reused Inventory service: config choice. */
function icct_backend_config_choice($value, $choices, $label)
{
    if (!is_string($value) || !in_array($value, $choices, true)) {
        throw new InvalidArgumentException("Select a supported $label.");
    }
    return $value;
}

/** Reused Inventory service: config integer. */
function icct_backend_config_integer($value, $min, $max, $label)
{
    if (
        (!is_int($value) && !is_string($value)) ||
        !preg_match('/^[0-9]+$/D', (string) $value) ||
        (float) $value < $min ||
        (float) $value > $max
    ) {
        throw new InvalidArgumentException("$label must be an integer from $min to $max.");
    }
    return (int) $value;
}

/** Reused Inventory service: config text. */
function icct_backend_config_text($value, $max, $label, $required = true)
{
    if (!is_string($value) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
        throw new InvalidArgumentException("Invalid $label.");
    }
    $value = trim($value);
    if (($required && $value === '') || strlen($value) > $max) {
        throw new InvalidArgumentException("$label is required and must fit within $max bytes.");
    }
    return $value;
}

/** Reused Inventory service: serial device address. */
function icct_backend_serial_device_address($value)
{
    return icct_backend_config_integer($value, 1, 247, 'Modbus device address');
}
