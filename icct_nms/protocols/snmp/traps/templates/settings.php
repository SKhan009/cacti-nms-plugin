<?php
$trapPreset = ($presetMode ? $protocolPresets : icct_nms_protocol_presets())['snmp_notifications'] ?? [];
$trapEnabled = $trapSender !== '' || (($presetMode || !$id) && (string) ($trapPreset['trap_enabled'] ?? '0') === '1');
$trapSourceMode = $trapPreset['trap_source_mode'] ?? 'device';
$trapUseDeviceIp = !$presetMode && $trapSourceMode === 'device' && $trapSender === '';
$trapSourceAddress = $trapSender;
if ($id && $trapUseDeviceIp && filter_var($host['hostname'] ?? '', FILTER_VALIDATE_IP) !== false) {
    $trapSourceAddress = $host['hostname'];
}
$trapHelp = static function (string $headingHelpText): void {
    require __DIR__ . '/../../../../shared/templates/heading_help.php';
};
?>
<section class="protocol-content trap-receiver-settings" id="trap-receiver-settings" aria-labelledby="snmp-notifications-heading">
    <hr />
    <h3
        class="device-help-heading"
        id="snmp-notifications-heading">
        SNMP notifications
        <?php $trapHelp('Receive SNMP traps and informs sent by the device. Notification reception is configured separately from SNMP polling.'); ?>
    </h3>
    <form method="post">
        <?php icct_nms_token(); ?>
        <?php if ($presetMode): ?>
            <input type="hidden" name="action" value="save_protocol_defaults">
            <input
                type="hidden"
                name="preset_protocol"
                value="snmp_notifications">
            <div class="protocol-grid cols-2">
                <label class="field">
                    <span class="field-label">Collect notifications by default
                        <?php $trapHelp('Sets the starting choice for new device drafts. Reception begins only after the device settings are saved.'); ?>
                    </span>
                    <span class="select-wrap">
                        <select name="trap_enabled">
                            <option
                                value="0"
                                <?= !$trapEnabled ? 'selected' : '' ?>>Off</option>
                            <option
                                value="1"
                                <?= $trapEnabled ? 'selected' : '' ?>>On</option>
                        </select>
                    </span>
                </label>
                <label class="field">
                    <span class="field-label">Default trap sender IP
                        <?php $trapHelp('Use each device’s own numeric IPv4 or IPv6 address, or enter the address manually. Choose manual entry for devices that send from a different address.'); ?>
                    </span>
                    <span class="select-wrap">
                        <select name="trap_source_mode">
                            <option
                                value="device"
                                <?= $trapSourceMode === 'device' ? 'selected' : '' ?>>Use the device IP address</option>
                            <option
                                value="manual"
                                <?= $trapSourceMode === 'manual' ? 'selected' : '' ?>>Enter manually per device</option>
                        </select>
                    </span>
                </label>
            </div>
        <?php else: ?>
            <input type="hidden" name="action" value="trap_sender">
            <?php if ($trapUseDeviceIp): ?>
                <input type="hidden" name="trap_use_device_ip" value="1">
            <?php endif; ?>
            <div class="protocol-grid cols-2">
                <label class="check-row">
                    <input
                        type="checkbox"
                        name="trap_enabled"
                        value="1"
                        <?= $trapEnabled ? 'checked' : '' ?>>
                    Collect SNMP notifications
                </label>
                <label class="field">
                    <span class="field-label">Trap sender IP address
                        <?php $trapHelp('Enter the IPv4 or IPv6 source address the collector sees. This can differ from the device’s polling address.'); ?>
                    </span>
                    <input
                        name="trap_source_address"
                        maxlength="45"
                        value="<?= icct_nms_h($trapSourceAddress) ?>"
                        <?= $trapUseDeviceIp ? 'placeholder="Uses the device IP if blank"' : '' ?>>
                </label>
            </div>
        <?php endif; ?>
        <div class="protocol-actions">
            <button
                class="button primary"
                type="submit"><?= $presetMode ? 'Save notification defaults' : 'Save notifications' ?></button>
        </div>
    </form>
</section>
