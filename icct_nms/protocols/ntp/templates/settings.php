<?php
/** Shared NTP settings for presets and inventory. */
$ntpFields = [
    'method' => ['Collection method', 'select'],
    'timeout' => ['Collection timeout (seconds)', 'number', 1, 30, 1],
    'interval_seconds' => ['Collection interval (seconds)', 'number', 60, 86400, 1],
    'stale_seconds' => ['Stale after (seconds)', 'number', 60, 604800, 1],
];
$ntpHelp = [
    'method' => 'Automatic reads local chrony for a collector address and otherwise queries the inventory address on UDP port 123. Direct NTP requires an NTP server that answers on that address. Only the optional remote chrony method uses SSH.',
    'timeout' => 'Time allowed for the direct NTP query or combined chrony reads. Optional SSH mode uses its own saved connection timeout.',
    'interval_seconds' => 'Collection interval: 60–86400 seconds, in multiples of the Cacti poller interval.',
    'stale_seconds' => 'Must cover the collection interval plus timeout. Older observations are excluded from current measurements.',
];
?>
<div class="site-fields">
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
                    <?php foreach (icct_nms_ntp_methods() as $method => $label): ?>
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
</div>
<div class="protocol-actions">
    <button
        class="button primary"
        type="submit">Save</button>
</div>
