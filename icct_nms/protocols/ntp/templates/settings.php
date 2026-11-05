<?php
/** One field definition serves presets and inventory; no transport or server is guessed. */
$ntpFields = [
    'method' => ['Collection method', 'select'],
    'timeout' => ['Command timeout (seconds)', 'number', 1, 30, 1],
    'interval_seconds' => ['Collection interval (seconds)', 'number', 60, 86400, 1],
    'stale_seconds' => ['Stale after (seconds)', 'number', 60, 604800, 1],
];
$ntpHelp = [
    'method' => 'Read-only chrony tracking, jitter and peer delay through the fixed Unix socket. The device account requires the offline NTP read-only access policy. Local mode requires localhost on its assigned collector. Remote Linux devices require saved SSH credentials and an approved host key. No alternate transport is attempted.',
    'timeout' => 'Combined command timeout: 1–30 seconds for tracking, jitter and peer-delay reads. SSH connection uses its saved connection settings. A failed or timed-out read shows an error without substitute measurements.',
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
                    name="<?= $field ?>"
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
