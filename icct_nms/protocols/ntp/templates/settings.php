<?php
/** Shared NTP settings for presets and inventory. */
$ntpFields = [
    'method' => ['Collection method', 'select'],
    'timeout' => ['Collection timeout (seconds)', 'number', 1, 30, 1],
    'interval_seconds' => ['Collection interval (seconds)', 'number', 0, 86400, 1],
    'stale_seconds' => ['Stale after (seconds)', 'number', 60, 604800, 1],
];
$ntpHelp = [
    'method' => 'Choose Direct NTP to monitor a time server or Local collector chrony to check the collector’s clock.',
    'timeout' => 'Time allowed for the direct NTP query or combined chrony reads. Optional SSH mode uses its own saved connection timeout.',
    'interval_seconds' => 'Use 0 for every poll. Other intervals must be at least 60 seconds and a multiple of the Cacti poller interval.',
    'stale_seconds' => 'Must cover the collection interval plus timeout. Older observations are excluded from current measurements.',
];
$ntpSsh = [];
$ntpChoices = array_diff_key(icct_nms_ntp_methods(), ['auto' => true]);
if (empty($presetMode) && !empty($id)) {
    try {
        $ntpSsh = icct_backend_ssh_device($id, false);
    } catch (Throwable $error) {
    }
}
if (empty($presetMode)) {
    $ntpHelp['method'] .= ' Use verified SSH to read a remote Linux device’s chrony status. SSH profile: ' .
        ($ntpSsh['preset_name'] ?? 'Assign and verify a profile in SSH settings') . '.';
} else {
    $ntpHelp['method'] .= ' Device chrony over verified SSH uses each device’s assigned SSH profile and verified host key.';
}
if (($ntp['method'] ?? '') === 'auto') {
    $ntpHelp['method'] .= ' Choose a method to replace the saved Automatic setting.';
}
$ntpStaticSource = ($ntp['server_address'] ?? '') !== '' || ($ntp['expected_source'] ?? '') !== '';
$ntpDeviceAddress = empty($presetMode) ? ($ntpAssignment['hostname'] ?? $old['hostname'] ?? '') : '';
$ntpSshVerified = !empty($ntpSsh['enabled']) && !empty($ntpSsh['host_key']) &&
    ($ntpSsh['verified_endpoint'] ?? null) === ($ntpSsh['endpoint'] ?? null);
$ntpSshEnabled = empty($presetMode) && !empty($id) && $ntpSsh &&
    icct_backend_protocol_enabled($id, 'ssh');
?>
<div data-ntp-settings>
    <fieldset class="radio-group" data-ntp-methods="udp<?= empty($ntpSaved) ? ' local ssh' : '' ?>" data-ntp-addressing>
        <legend class="field-label">
            NTP source addressing
            <?php $ntpHelpText = 'Static specifies the server to query for Direct NTP or the expected source for a chrony alarm. DHCP uses the saved inventory address for Direct NTP or reads the source already selected by chrony.';
            require __DIR__ . '/help.php'; ?>
        </legend>
        <label><input type="radio" name="ntp_source_addressing" value="static" <?= $ntpStaticSource ? 'checked' : '' ?>> Static</label>
        <label><input type="radio" name="ntp_source_addressing" value="dhcp" <?= $ntpStaticSource ? '' : 'checked' ?>> DHCP</label>
    </fieldset>
    <div class="protocol-grid cols-4">
        <?php foreach ($ntpFields as $field => $definition): ?>
            <label class="field">
                <span class="field-label">
                    <?= icct_nms_h($definition[0]) ?>
                    <?php $ntpHelpText = $ntpHelp[$field];
                    require __DIR__ . '/help.php'; ?>
                </span>
                <?php if ($definition[1] === 'select'): ?>
                    <select
                        name="ntp_method"
                        required>
                        <option value="">Choose collection method</option>
                        <?php foreach ($ntpChoices as $method => $label): ?>
                            <option
                                value="<?= $method ?>"
                                <?= ($ntp[$field] ?? '') === $method ? 'selected' : '' ?>><?= icct_nms_h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input
                        type="<?= $definition[1] ?>"
                        name="<?= $field ?>"
                        value="<?= icct_nms_h($ntp[$field] ?? '') ?>"
                        required
                        <?php if ($definition[1] === 'number'): ?>
                        min="<?= $definition[2] ?>"
                        max="<?= $definition[3] ?>"
                        step="<?= $definition[4] ?>"
                        <?php endif; ?>>
                <?php endif; ?>
            </label>
        <?php endforeach; ?>
        <?php if ($ntpDeviceAddress !== ''): ?>
            <label class="field" data-ntp-methods="udp" data-ntp-device-source
                <?= ($ntp['method'] ?? '') === 'udp' && !$ntpStaticSource ? '' : 'hidden' ?>>
                <span class="field-label">NTP server from device
                    <?php $ntpHelpText = 'Direct NTP uses the saved hostname or IP from Basic Information. Changes to that address automatically apply here after saving the device.';
                    require __DIR__ . '/help.php'; ?>
                </span>
                <input type="text" value="<?= icct_nms_h($ntpDeviceAddress) ?>" readonly>
            </label>
        <?php endif; ?>
        <div class="field" data-ntp-methods="udp" data-ntp-static-source>
            <label><span class="field-label">NTP server IP or hostname
                    <?php $ntpHelpText = 'Direct NTP sends queries to this address from the collector over UDP port 123.';
                    require __DIR__ . '/help.php'; ?>
                </span>
                <input name="server_address" maxlength="253" value="<?= icct_nms_h($ntp['server_address'] ?? '') ?>"
                    required>
            </label>
        </div>
        <?php if (empty($ntpSaved)): ?>
            <label class="field"><span class="field-label">Initial maximum offset (ms)</span>
                <input type="number" name="maximum_offset_ms" min="0" step="any" value="<?= icct_nms_h($ntp['maximum_offset_ms'] ?? '') ?>">
            </label>
            <label class="field" data-ntp-methods="udp"><span class="field-label">Initial maximum round-trip delay (ms)</span>
                <input type="number" name="maximum_delay_ms" min="0" step="any" value="<?= icct_nms_h($ntp['maximum_delay_ms'] ?? '') ?>">
            </label>
            <label class="field"><span class="field-label">Alarm when unsynchronized</span>
                <select name="alarm_unsynchronized">
                    <?php foreach (['' => 'Use FCAPS default', '1' => 'Yes', '0' => 'No'] as $value => $label): ?>
                        <option value="<?= $value ?>" <?= (string) ($ntp['alarm_unsynchronized'] ?? '') === (string) $value ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field" data-ntp-methods="local ssh" data-ntp-static-source><span class="field-label">Expected chrony source IP
                    <?php $ntpHelpText = 'FCAPS checks the selected chrony source against this IP.';
                    require __DIR__ . '/help.php'; ?>
                </span>
                <input name="expected_source" maxlength="45" value="<?= icct_nms_h($ntp['expected_source'] ?? '') ?>" required>
            </label>
        <?php endif; ?>
    </div>
</div>
<?php if (empty($presetMode)): ?>
<div
    class="inline-feedback error"
    data-ntp-ssh-error
    data-ssh-enabled="<?= $ntpSshEnabled ? '1' : '0' ?>"
    data-ssh-verified="<?= $ntpSshVerified ? '1' : '0' ?>"
    role="alert"
    hidden></div>
<?php endif; ?>
<script src="protocols/ntp/js/settings.js?v=<?= substr(hash_file('sha256', __DIR__ . '/../js/settings.js'), 0, 12) ?>" defer></script>
<div class="protocol-actions">
    <?php if (empty($presetMode) && isset($ntpChoices[$ntpPreset['method'] ?? ''])): ?>
        <button type="button" class="button" data-ntp-load-preset="<?= icct_nms_h(json_encode(
            array_intersect_key($ntpPreset, array_flip(array_merge(icct_nms_ntp_fields(), icct_nms_ntp_optional_fields()))),
            JSON_THROW_ON_ERROR,
        )) ?>">Load preset</button>
        <span data-ntp-preset-status role="status"></span>
    <?php endif; ?>
    <button
        class="button primary"
        type="submit">Save</button>
</div>
