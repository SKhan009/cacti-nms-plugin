<?php
/** Syslog filters inherit presets and remain independently editable per device. */
$syslog = is_array($syslog ?? null) ? $syslog : [];
$syslogFilters = isset($syslog['host_id'])
    ? icct_backend_syslog_effective_filters($syslog)
    : icct_backend_syslog_filters($syslog);
$syslogHelp = static function ($label, $help) {
    ?>
    <span
        class="field-info"
        tabindex="0"
        role="img"
        aria-label="Information about <?= icct_nms_h($label) ?>"
        data-tooltip="<?= icct_nms_h($help) ?>">
        <svg
            viewBox="0 0 20 20"
            aria-hidden="true">
            <circle
                cx="10"
                cy="10"
                r="8" />
            <path d="M10 9v5M10 6v.5" />
        </svg>
    </span>
<?php
};
?>
<div class="protocol-grid cols-2">
    <?php if (empty($presetMode)): ?>
        <label class="field">
            <span class="field-label">Source IP address <?php $syslogHelp('Source IP address', 'Numeric sender address observed by this collector. This must uniquely identify the device.'); ?></span>
            <input
                name="source_address"
                required
                value="<?= icct_nms_h($syslog['source_address'] ?? ($host['hostname'] ?? ($values['hostname'] ?? ($old['hostname'] ?? '')))) ?>">
        </label>
    <?php endif; ?>
    <?php if (!empty($presetMode)):
        $portAvailable = !empty($syslogPortStatus['available']) && isset($syslogPortStatus['syslog_port']);
        $portJob = $_GET['port_job'] ?? '';
        $watchPort = !empty($canManageSyslogPort) && is_string($portJob) && preg_match('/^[a-f0-9]{32}$/D', $portJob);
        ?>
        <div class="field">
            <label class="field-label" for="syslog-receiver-port">Receiver port (TCP and UDP)
                <?php $syslogHelp('Receiver port', 'Save applies this port to both listeners and to local forwarding when enabled. Update your Syslog senders to use the same port.'); ?>
            </label>
            <input id="syslog-receiver-port" name="receiver_port" type="number" min="1" max="65535" step="1"
                placeholder="514" value="<?= (int) ($syslogPortStatus['syslog_port'] ?? 514) ?>"
                <?= $portAvailable ? 'required' : 'disabled' ?>>
            <?php if ($watchPort): ?>
                <small data-syslog-save-job="<?= icct_nms_h($portJob) ?>" role="status">Waiting for the offline runner to apply the port…</small>
            <?php elseif (!$portAvailable): ?>
                <small>Changing the port requires Installation permission and an available offline runner.</small>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <label class="field">
        <span class="field-label">Transport <?php $syslogHelp('Transport', 'Accept Syslog messages from this device over the selected listener transport.'); ?></span>
        <select name="transport">
            <?php foreach (['both' => 'UDP and TCP', 'udp' => 'UDP', 'tcp' => 'TCP'] as $transport => $label): ?>
                <option
                    value="<?= $transport ?>"
                    <?= ($syslog['transport'] ?? 'both') === $transport ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
    </label>
</div>
<div class="protocol-grid cols-2 syslog-settings">
    <?php foreach (
        [
            'severity_codes' => [
                'Severity',
                icct_backend_syslog_severity_labels(),
                'Choose exactly which severity levels to accept. 0 is most urgent; 7 is Debug.',
            ],
            'facility_codes' => [
                'Facility',
                icct_backend_syslog_facility_labels(),
                'Choose the message categories to accept. All 24 facilities are selected by default.',
            ],
        ] as $field => [$caption, $options, $help]
    ): ?>
        <div
            class="field syslog-selection"
            data-syslog-selection="<?= $field ?>">
            <span class="field-label"><?= $caption ?> <?php $syslogHelp($caption, $help); ?></span>
            <input
                type="hidden"
                name="<?= $field ?>"
                value="<?= icct_nms_h(json_encode($syslogFilters[$field])) ?>">
            <details class="syslog-select-menu">
                <summary aria-label="Select <?= strtolower($caption) ?>">
                    <span class="syslog-selected-count"><?= count($syslogFilters[$field]) ?></span>
                    <span>Options selected</span>
                    <span
                        class="syslog-select-chevron"
                        aria-hidden="true"></span>
                </summary>
                <div class="syslog-select-options">
                    <button
                        type="button"
                        class="syslog-select-all">Select all</button>
                    <button
                        type="button"
                        class="syslog-select-none">Clear</button>
                    <?php foreach (
                        $options as $code => $caption
                    ): ?>
                        <label class="check-row">
                            <input
                                type="checkbox"
                                value="<?= $code ?>"
                                <?= in_array($code, $syslogFilters[$field], true) ? 'checked' : '' ?>

                                aria-label="<?= icct_nms_h($code . ' — ' . $caption) ?>">
                            <?php if ($field === 'severity_codes'): ?>
                                <span class="device-syslog-severity severity-<?= icct_nms_h(icct_backend_syslog_nms_severity($code)) ?>"><?= icct_nms_h($code . ' — ' . $caption) ?></span>
                            <?php else: ?> <?= icct_nms_h($code . ' — ' . $caption) ?>
 <?php endif; ?>

                        </label>
                    <?php endforeach; ?>
                </div>
            </details>
        </div>
    <?php endforeach; ?>
</div>
<div class="field syslog-keywords">
    <span class="field-label">Trigger Keywords / Match Strings <?php $syslogHelp(
        'Trigger Keywords / Match Strings',
        'Accept messages containing any selected phrase, ignoring case. Leave empty to accept ' .
            'all messages. Type a phrase and press Enter or comma to add it.',
    ); ?><span
            class="syslog-keyword-counter"><?= mb_strlen(implode(', ', $syslogFilters['match_strings'])) ?>/148</span>
    </span>
    <input
        type="hidden"
        name="match_strings"
        value="<?= icct_nms_h(json_encode($syslogFilters['match_strings'])) ?>">
    <div class="syslog-keyword-box">
        <div class="syslog-keyword-chips"></div>
        <input
            type="text"
            class="syslog-keyword-input"
            aria-label="Add match string"
            placeholder="Add match string"
            maxlength="148">
    </div>
</div>
<div class="protocol-actions split-actions">
    <button
        class="button primary"
        type="submit">Save</button>
</div>
