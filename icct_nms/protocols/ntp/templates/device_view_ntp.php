<?php
require_once __DIR__ . '/../../../shared/services/presentation.php';
/** The controller has already checked this device's native Cacti access policy. */
$ntpAssignment = icct_nms_ntp_assignment($id);
$ntpCurrent = icct_nms_ntp_current($id);
$ntpSample = $ntpCurrent['sample'];
$ntpAlarm = $ntpAssignment ? db_fetch_row_prepared(
    'SELECT severity,state FROM plugin_icct_nms_alarms WHERE host_id=? AND source=? ORDER BY last_seen DESC LIMIT 1',
    [(int) $id, 'NTP'],
) : [];
$ntpHistory = $ntpAssignment ? db_fetch_assoc_prepared(
    'SELECT status,sample_json,error,observed_at FROM plugin_icct_nms_ntp_monitor_history WHERE host_id=? ORDER BY id DESC LIMIT 25',
    [(int) $id],
) : [];
$ntpNumber = static function ($value): string {
    return $value === null ? '—' : icct_nms_h(number_format((float) $value, 3, '.', ''));
};
?>
<section
    id="view-ntp"
    data-device-view-panel="ntp"
    hidden>
    <h2>NTP clock monitoring <?php $ntpHelpText = 'Read-only status from the configured collector or verified SSH device. Clock settings are never changed.';
    require __DIR__ . '/help.php'; ?>
 </h2>
    <p role="status">
        <span
            class="device-status"
            data-log-status="<?= icct_nms_h($ntpCurrent['status']) ?>"><?= icct_nms_h($ntpCurrent['status']) ?></span>
    </p>
    <?php if ($ntpCurrent['error'] !== ''): ?>
        <p role="alert"><?= icct_nms_h($ntpCurrent['error']) ?></p>
    <?php endif; ?>
    <?php if ($ntpAssignment): ?>
        <dl class="device-detail-grid">
            <div>
                <dt>NTP alarm <?php $ntpHelpText = 'Latest actual NTP alarm journal state. A normal observation without a previous warning does not create an alarm entry.';
                require __DIR__ . '/help.php'; ?>
 </dt>
                <dd><?= $ntpAlarm ? icct_nms_severity_badge($ntpAlarm['severity']) . ' / ' . icct_nms_h($ntpAlarm['state']) : 'No NTP alarm recorded' ?></dd>
            </div>
            <div>
                <dt>Collection method</dt>
                <dd><?= icct_nms_h(icct_nms_ntp_methods()[$ntpAssignment['method']]) ?></dd>
            </div>
            <div>
                <dt>Last attempt</dt>
                <dd><?= $display($ntpCurrent['last_attempt']) ?></dd>
            </div>
            <div>
                <dt>Last successful read</dt>
                <dd><?= $display($ntpCurrent['last_success']) ?></dd>
            </div>
            <div>
                <dt>Observed source IP</dt>
                <dd><?= $display($ntpSample['source_address'] ?? null) ?></dd>
            </div>
            <div>
                <dt>Observed reference ID</dt>
                <dd><?= $display($ntpSample['source_id'] ?? null) ?></dd>
            </div>
            <div>
                <dt>Stratum</dt>
                <dd><?= $display($ntpSample['stratum'] ?? null) ?></dd>
            </div>
            <div>
                <dt>Leap status</dt>
                <dd><?= $display($ntpSample['leap_status'] ?? null) ?></dd>
            </div>
            <div>
                <dt>Last measured offset (ms)</dt>
                <dd><?= $ntpNumber($ntpSample['offset_ms'] ?? null) ?></dd>
            </div>
            <div>
                <dt>RMS offset (ms)</dt>
                <dd><?= $ntpNumber($ntpSample['rms_offset_ms'] ?? null) ?></dd>
            </div>
            <div>
                <dt>Jitter (ms) <?php $ntpHelpText = 'Selected-source residual offset standard deviation reported by chrony, with at least three samples. This is distinct from tracking RMS offset.';
                require __DIR__ . '/help.php'; ?>
 </dt>
                <dd><?= $ntpNumber($ntpSample['jitter_ms'] ?? null) ?></dd>
            </div>
            <div>
                <dt>Peer round-trip delay (ms) <?php $ntpHelpText = 'Measured NTP round-trip delay to the selected peer. Root delay is the total path delay back to the ultimate reference; it is retained separately.';
                require __DIR__ . '/help.php'; ?>
 </dt>
                <dd><?= $ntpNumber($ntpSample['peer_delay_ms'] ?? null) ?></dd>
            </div>
            <div>
                <dt>Root delay (ms)</dt>
                <dd><?= $ntpNumber($ntpSample['root_delay_ms'] ?? null) ?></dd>
            </div>
        </dl>
        <h3>Recent observations <?php $ntpHelpText = 'Latest 25 historical observations. Collection removes records older than 30 days. Jitter is selected-source offset standard deviation; peer delay is measured to that source. Older records without these measurements stay unknown. Historical values are not current readings.';
        require __DIR__ . '/help.php'; ?>
 </h3>
        <div class="site-table-wrap">
            <table class="site-table">
                <thead>
                    <tr>
                        <th>Observed at</th>
                        <th>Status</th>
                        <th>Source IP</th>
                        <th>Offset (ms)</th>
                        <th>Jitter (ms)</th>
                        <th>Peer delay (ms)</th>
                        <th>Collection detail</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ntpHistory as $observation):
                        $sample = $observation['sample_json'] === null ? null : json_decode($observation['sample_json'], true, 16, JSON_THROW_ON_ERROR); ?>
                        <tr>
                            <td><?= icct_nms_h($observation['observed_at']) ?></td>
                            <td>
                                <span
                                    class="device-status"
                                    data-log-status="<?= icct_nms_h($observation['status']) ?>"><?= icct_nms_h($observation['status']) ?></span>
                            </td>
                            <td><?= $display($sample['source_address'] ?? null) ?></td>
                            <td><?= $ntpNumber($sample['offset_ms'] ?? null) ?></td>
                            <td><?= $ntpNumber($sample['jitter_ms'] ?? null) ?></td>
                            <td><?= $ntpNumber($sample['peer_delay_ms'] ?? null) ?></td>
                            <td><?= $display($observation['error']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (!$ntpHistory): ?>
            <p>No NTP observations collected.</p>
        <?php endif; ?>
    <?php else: ?>
        <p>Configure NTP in the device's Protocol Config to collect its clock status.</p>
    <?php endif; ?>
</section>
