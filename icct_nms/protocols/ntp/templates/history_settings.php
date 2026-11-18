<?php
$historySettings = [
    'NTP collection' => icct_backend_protocol_enabled($id, 'ntp') ? 'Enabled' : 'Disabled',
    'Collection method' => icct_nms_ntp_methods()[$ntpAssignment['method']],
    'Collection timeout' => (int) $ntpAssignment['timeout'] . ' seconds',
    'Collection interval' => (int) $ntpAssignment['interval_seconds']
        ? (int) $ntpAssignment['interval_seconds'] . ' seconds' : 'Every Cacti poll',
    'Stale after' => (int) $ntpAssignment['stale_seconds'] . ' seconds',
];
if (icct_nms_ntp_effective_method($ntpAssignment) === 'udp') {
    $historySettings['NTP server'] = $ntpAssignment['server_address']
        ?: $ntpAssignment['hostname'] . ' (inventory address)';
} elseif ($ntpAssignment['method'] === 'ssh') {
    try {
        $historySsh = icct_backend_ssh_device($id, false);
        $historySettings['SSH profile'] = $historySsh['preset_name'];
    } catch (RuntimeException $error) {
        $historySettings['SSH profile'] = 'Unavailable';
    }
}
$historyRules = array_filter(icct_nms_fault_rules($id), static fn($rule) => ($rule['source'] ?? '') === 'ntp');
?>
<details class="syslog-detail-panel ntp-overview-card ntp-history-settings">
    <summary>NTP configuration</summary>
    <dl class="device-detail-grid">
        <?php foreach ($historySettings as $label => $value): ?>
            <div><dt><?= icct_nms_h($label) ?></dt><dd><?= icct_nms_h($value) ?></dd></div>
        <?php endforeach; ?>
    </dl>
    <section class="ntp-history-rules">
        <h4>NTP alarm rules</h4>
        <?php if ($historyRules): ?>
            <dl class="device-detail-grid">
                <?php foreach ($historyRules as $rule): ?>
                    <?php
                    $ruleSettings = [empty($rule['enabled']) ? 'Disabled' : 'Enabled'];
                    if (!empty($rule['expected_source'])) {
                        $ruleSettings[] = 'Expected source ' . $rule['expected_source'];
                    } elseif (($rule['parameter'] ?? '') !== 'synchronized') {
                        foreach (['minimum' => 'Min', 'maximum' => 'Max'] as $bound => $label) {
                            if (isset($rule[$bound])) {
                                $ruleSettings[] = trim($label . ' ' . $rule[$bound] . ' ' . ($rule['units'] ?? ''));
                            }
                        }
                    }
                    ?>
                    <div><dt><?= icct_nms_h($rule['name']) ?></dt><dd><?= icct_nms_h(implode(' · ', $ruleSettings)) ?></dd></div>
                <?php endforeach; ?>
            </dl>
        <?php else: ?>
            <p>No NTP alarm rules are configured.</p>
        <?php endif; ?>
    </section>
</details>
