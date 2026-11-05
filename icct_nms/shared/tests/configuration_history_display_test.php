<?php

require __DIR__ . '/../../configuration/services/configuration_history_display.php';
$record = json_encode([
    [
        'name' => 'NTP offset',
        'source' => 'ntp',
        'maximum' => 0,
        'enabled' => true,
        'email' => false,
        'minimum' => null,
        'description' => '<script>example</script>',
    ],
]);
$text = icct_nms_configuration_value_text($record);
foreach (
    ['NTP offset:', 'Maximum: 0', 'Enabled: Yes', 'Email: No', 'Minimum: Not set']
    as $expected
) {
    if (!str_contains($text, $expected)) {
        throw new RuntimeException('Recorded value missing: ' . $expected);
    }
}
if (
    str_contains($text, '{') ||
    str_contains($text, '"enabled"') ||
    icct_nms_configuration_label('fault.rules') !== 'Fault / Rules' ||
    icct_nms_configuration_label('device.snmp_port') !== 'Device / SNMP Port' ||
    icct_nms_configuration_value_text('[]') !== 'None' ||
    icct_nms_configuration_value_text('original text') !== 'original text' ||
    icct_nms_configuration_value_text('[invalid') !== '[invalid' ||
    str_contains(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), '<script>')
) {
    throw new RuntimeException(
        'History text must preserve saved facts and remain safely escapable.',
    );
}
echo "Readable history preserves nested records, booleans, zero, missing values and safe escaping.\n";
