<?php
/** Pure validation shared by serial forms, storage and collector admission. */
function nms_config_integer($value, $min, $max, $label)
{
    if ((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]+$/D', (string) $value)
        || (float) $value < $min || (float) $value > $max) {
        throw new InvalidArgumentException("$label must be an integer from $min to $max.");
    }
    return (int) $value;
}

function nms_config_text($value, $max, $label, $required = true)
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

/** Fixed choices correspond to implemented adapters, never arbitrary shell commands. */
function nms_config_choice($value, $choices, $label)
{
    if (!is_string($value) || !in_array($value, $choices, true)) {
        throw new InvalidArgumentException("Select a supported $label.");
    }
    return $value;
}

/** Defaults describe a connection; they do not change a connected instrument. */
function nms_serial_profile_validate(array $input)
{
    $settings = [
        'interface' => nms_config_choice($input['interface'] ?? 'unspecified', ['unspecified','rs232','rs485'], 'serial interface'),
        'protocol' => nms_config_choice($input['protocol'] ?? '', ['modbus_rtu'], 'protocol'),
        'baud_rate' => nms_config_integer((($input['baud_rate'] ?? '') === 'custom' ? ($input['custom_baud_rate'] ?? '') : ($input['baud_rate'] ?? '')), 50, 4000000, 'Baud rate'),
        'data_bits' => nms_config_integer($input['data_bits'] ?? '', 8, 8, 'Modbus RTU data bits'),
        'parity' => nms_config_choice($input['parity'] ?? '', ['none', 'even', 'odd', 'mark', 'space'], 'parity'),
        'stop_bits' => nms_config_integer($input['stop_bits'] ?? '', 1, 2, 'Stop bits'),
        'flow_control' => nms_config_choice($input['flow_control'] ?? '', ['none', 'rtscts'], 'flow control'),
        'timeout_ms' => nms_config_integer($input['timeout_ms'] ?? '', 100, 10000, 'Timeout (ms)'),
        'retries' => nms_config_integer($input['retries'] ?? '', 0, 3, 'Read retries'),
    ];
    if ($settings['interface'] === 'rs485' && $settings['flow_control'] !== 'none') {
        throw new InvalidArgumentException('RS-485 requires flow control None and an adapter with automatic direction control, or a configured RS-485 gateway.');
    }
    return [
        'name' => nms_config_text($input['name'] ?? '', 150, 'Profile name'),
        'description' => nms_config_text($input['description'] ?? '', 512, 'Description', false),
        'manufacturer' => nms_config_text($input['manufacturer'] ?? '', 120, 'Manufacturer'),
        'model' => nms_config_text($input['model'] ?? '', 120, 'Model'),
        'settings' => $settings,
    ];
}

/** One independent copy per connection; no live references to mutable presets. */
function nms_serial_profile_snapshot(array $profile, $profile_id, $revision)
{
    return [
        'profile_id' => nms_config_integer($profile_id, 1, 2147483647, 'Profile ID'),
        'profile_revision' => nms_config_integer($revision, 1, 2147483647, 'Profile revision'),
        'settings' => $profile['settings'],
    ];
}

/** RTU broadcast address zero cannot identify a monitored/configured device. */
function nms_serial_device_address($value)
{
    return nms_config_integer($value, 1, 247, 'Modbus device address');
}

/** Endpoint syntax validation; direct paths are resolved and locked again on the collector. */
function nms_serial_endpoint(array $input)
{
    $transport = nms_config_choice($input['transport'] ?? '', ['direct','rtu_tcp'], 'serial transport');
    $collector = nms_config_integer($input['poller_id'] ?? '', 1, 2147483647, 'Collector');
    $endpoint = nms_config_text($input['endpoint'] ?? '', 512, 'Endpoint');
    if ($transport === 'direct') {
        if (!preg_match('~^/dev/(?:serial/by-id/[A-Za-z0-9_.:+-]+|tty(?:USB|ACM|S)[0-9]+)$~D', $endpoint)
            || strpos($endpoint, '..') !== false) throw new InvalidArgumentException('Use a serial /dev/serial/by-id, ttyUSB, ttyACM or ttyS port.');
        $identity = 'direct:'.$collector.':'.$endpoint;
    } else {
        // Literal addresses avoid DNS aliases evading shared gateway ownership.
        $address = trim($endpoint, '[]');
        if (!filter_var($address, FILTER_VALIDATE_IP)) throw new InvalidArgumentException('Enter the gateway IPv4 or IPv6 address.');
        $address = inet_ntop(inet_pton($address));
        $port = nms_config_integer($input['port'] ?? '', 1, 65535, 'Gateway port');
        $endpoint = '['.$address.']:'.$port;
        $identity = 'rtu_tcp:'.$endpoint;
    }
    return ['transport'=>$transport,'poller_id'=>$collector,'endpoint'=>$endpoint,'endpoint_key'=>hash('sha256',$identity)];
}

/** A new bus may override a preset; saved presets and existing buses stay unchanged. */
function nms_serial_connection_settings(array $profile, array $input)
{
    if(empty($input['custom_serial_settings'])) return $profile['settings_json'];
    $validated=nms_serial_profile_validate(array_replace($profile,$profile['settings'],$input,[
        'protocol'=>'modbus_rtu','name'=>$profile['name'],
        'manufacturer'=>$profile['manufacturer'],'model'=>$profile['model']
    ]));
    return json_encode($validated['settings'],JSON_THROW_ON_ERROR);
}
