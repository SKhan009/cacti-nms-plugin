<?php
try {
    $serialEventTarget = icct_backend_config_target($id);
} catch (RuntimeException $error) {
    // A connection can be saved before an equipment profile is assigned.
    return;
}
if (!$serialEventTarget['serial']) {
    return;
}
$serialEventConfig = icct_nms_serial_event_rules($id);
$serialEventRules = ($serialEventConfig['profile_id'] ?? null) === (int) $serialEventTarget['profile']['id']
    ? $serialEventConfig['rules'] : [];
?>
<details class="serial-event-settings">
    <summary>Serial Syslog events</summary>
    <?php foreach ($serialEventTarget['fields'] as $register => $field):
        $prefix = 'serial_event_rules[' . $register . ']';
        $rule = $serialEventRules[$register] ?? [];
        ?>
        <fieldset class="protocol-grid cols-3">
            <legend><?= icct_nms_h($field['label']) ?></legend>
            <?php
            $bounds = 'step="1" min="' . (int) $field['min'] . '" max="' . (int) $field['max'] . '"';
            icct_nms_input('Log when below', $prefix . '[below]', $rule['below'] ?? '', 'number', $bounds);
            icct_nms_input('Log when above', $prefix . '[above]', $rule['above'] ?? '', 'number', $bounds);
            icct_nms_select('Syslog severity', $prefix . '[severity]',
                ['' => 'Select severity'] + icct_backend_syslog_severity_labels(), $rule['severity'] ?? '');
            ?>
        </fieldset>
    <?php endforeach; ?>
</details>
