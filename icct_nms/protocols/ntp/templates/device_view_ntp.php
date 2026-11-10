<?php
require_once __DIR__ . '/../../../shared/services/presentation.php';
require_once __DIR__ . '/../services/ntp_history.php';
require __DIR__ . '/../../../inventory/templates/device_record_pager.php';
/** The controller has already checked this device's native Cacti access policy. */
$ntpAssignment = icct_nms_ntp_assignment($id);
$ntpCurrent = icct_nms_ntp_current($id);
$ntpSample = $ntpCurrent['sample'];
$ntpAlarms = $ntpAssignment ? db_fetch_assoc_prepared(
    'SELECT alarm_key,name,description,severity FROM plugin_icct_nms_alarms WHERE host_id=? AND source=? AND state=? ' .
        'ORDER BY last_seen DESC,alarm_key',
    [(int) $id, 'NTP', 'Active'],
) : [];
$ntpTabs = ['overview' => 'Overview', 'history' => 'Observation History'];
$ntpTab = $_GET['ntp_tab'] ?? (
    isset($_GET['ntp_page']) ||
    isset($_GET['ntp_from']) || isset($_GET['ntp_to']) || isset($_GET['ntp_status'])
        ? 'history'
        : 'overview'
);
$ntpTab = is_string($ntpTab) && isset($ntpTabs[$ntpTab]) ? $ntpTab : 'overview';
$ntpUrl = static fn ($tab, $params = []) => 'inventory/controllers/device.php?' .
    http_build_query(['id' => (int) $id, 'view' => 1, 'ntp_tab' => $tab] + $params) . '#view-ntp';
$ntpHistory = $ntpFilters = [];
$ntpHistoryError = '';
$ntpTotal = 0;
$ntpPage = 1;
$ntpPageSize = 25;
if ($ntpAssignment) {
    try {
        $ntpFilter = icct_nms_ntp_history_filter((int) $id, $_GET);
        $ntpFilters = $ntpFilter['params'];
        if ($ntpTab === 'history') {
            $ntpTotal = (int) db_fetch_cell_prepared(
                'SELECT COUNT(*) FROM plugin_icct_nms_ntp_monitor_history WHERE ' . $ntpFilter['where'],
                $ntpFilter['args'],
            );
            $ntpPage = min($recordPage('ntp_page'), max(1, (int) ceil($ntpTotal / $ntpPageSize)));
            $ntpHistory = icct_nms_ntp_history_rows($ntpFilter, $ntpPageSize, ($ntpPage - 1) * $ntpPageSize);
        }
    } catch (InvalidArgumentException $error) {
        $ntpHistoryError = $error->getMessage();
    }
}
$ntpNumber = static function ($value): string {
    return $value === null ? '—' : icct_nms_h(number_format((float) $value, 3, '.', ''));
};
?>
<section
    id="view-ntp"
    data-device-view-panel="ntp"
    hidden>
    <div class="device-history-heading ntp-heading">
        <h2>NTP clock monitoring <?php $ntpHelpText = 'Read-only status from the configured collector or verified SSH device.';
        require __DIR__ . '/help.php'; ?></h2>
        <span class="device-status" role="status"
            data-log-status="<?= icct_nms_h($ntpCurrent['status']) ?>"><?= icct_nms_h($ntpCurrent['status']) ?></span>
    </div>
    <?php if ($ntpAssignment): ?>
        <nav class="device-view-tabs ntp-subtabs" aria-label="NTP views">
            <?php foreach ($ntpTabs as $tab => $label): ?>
                <a href="<?= icct_nms_h($ntpUrl($tab, $ntpFilters)) ?>"
                    <?= $tab === $ntpTab ? 'class="current" aria-current="page"' : '' ?>><?= icct_nms_h($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($ntpTab === 'overview'): ?>
            <?php if ($ntpCurrent['error'] !== ''): ?>
                <p role="alert"><?= icct_nms_h($ntpCurrent['error']) ?></p>
            <?php endif; ?>
            <section class="syslog-detail-panel ntp-overview-card">
                <h3 data-field-help="Synchronization means the device is following a time source. Separate alarms can still flag jitter or delay.">Current readings</h3>
                <dl class="device-detail-grid">
                    <div>
                        <dt data-field-help="IP address of the time source reported by the latest reading.">Observed source IP</dt>
                        <dd><?= $display($ntpSample['source_address'] ?? null) ?></dd>
                    </div>
                    <div>
                        <dt data-field-help="The device’s level in the NTP time hierarchy. A lower number means fewer levels from the reference clock.">Stratum</dt>
                        <dd><?= $display($ntpSample['stratum'] ?? null) ?></dd>
                    </div>
                    <div>
                        <dt data-field-help="When the collector last obtained a clock reading successfully.">Last successful read</dt>
                        <dd><?= $display($ntpCurrent['last_success']) ?></dd>
                    </div>
                    <div>
                        <dt data-field-help="The clock difference estimated during the latest update, in milliseconds.">Last measured offset (ms)</dt>
                        <dd><?= $ntpNumber($ntpSample['offset_ms'] ?? null) ?></dd>
                    </div>
                    <div>
                        <dt data-field-help="How much the clock measurements from the selected time source vary. Chrony needs at least three samples to report this value.">Jitter (ms)</dt>
                        <dd><?= $ntpNumber($ntpSample['jitter_ms'] ?? null) ?></dd>
                    </div>
                    <div>
                        <dt data-field-help="How long an NTP request takes to reach the selected time server and return.">Peer round-trip delay (ms)</dt>
                        <dd><?= $ntpNumber($ntpSample['peer_delay_ms'] ?? null) ?></dd>
                    </div>
                </dl>
            </section>
            <h3 data-field-help="Current NTP alarms for this device. Open an alarm to see the reading or condition that raised it.">Active NTP alarms</h3>
            <?php if ($ntpAlarms): ?>
                <div class="site-table-wrap">
                    <table class="site-table" aria-label="Active NTP alarms">
                        <thead>
                            <tr><th>Alarm</th><th>Severity</th><th>Description</th><th class="ntp-alarm-action">Action</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ntpAlarms as $alarm): ?>
                                <tr>
                                    <td><?= icct_nms_h($alarm['name']) ?></td>
                                    <td><?= icct_nms_severity_badge($alarm['severity']) ?></td>
                                    <td><?= $display($alarm['description']) ?></td>
                                    <td class="ntp-alarm-action"><a class="button"
                                        aria-label="<?= icct_nms_h('View alarm ' . $alarm['name']) ?>"
                                        href="alarms/controllers/alarms.php?view=<?= rawurlencode($alarm['alarm_key']) ?>">View alarm</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="device-record-empty">There are no active NTP alarms for this device.</p>
            <?php endif; ?>
            <details class="syslog-detail-panel ntp-overview-card ntp-technical-details">
                <summary>Technical details</summary>
                <dl class="device-detail-grid">
                    <div>
                        <dt data-field-help="How Cacti obtains the clock readings, either from the local collector or over SSH.">Collection method</dt>
                        <dd><?= icct_nms_h(icct_nms_ntp_methods()[$ntpAssignment['method']]) ?></dd>
                    </div>
                    <div>
                        <dt data-field-help="When the collector last tried to read the device clock status.">Last attempt</dt>
                        <dd><?= $display($ntpCurrent['last_attempt']) ?></dd>
                    </div>
                    <div>
                        <dt data-field-help="Identifier reported by the device for the time reference it is using.">Observed reference ID</dt>
                        <dd><?= $display($ntpSample['source_id'] ?? null) ?></dd>
                    </div>
                    <div>
                        <dt data-field-help="Shows whether the time source reports a leap-second warning or an unsynchronized clock.">Leap status</dt>
                        <dd><?= $display($ntpSample['leap_status'] ?? null) ?></dd>
                    </div>
                    <div>
                        <dt data-field-help="A summary of clock differences over time that gives more weight to large differences.">RMS offset (ms)</dt>
                        <dd><?= $ntpNumber($ntpSample['rms_offset_ms'] ?? null) ?></dd>
                    </div>
                    <div>
                        <dt data-field-help="Estimated total round-trip delay through the chain of time servers to the reference clock.">Root delay (ms)</dt>
                        <dd><?= $ntpNumber($ntpSample['root_delay_ms'] ?? null) ?></dd>
                    </div>
                </dl>
            </details>
        <?php else: ?>
            <h3>Observation history <?php $ntpHelpText = 'Collection removes records older than 30 days. Older observations without jitter or peer delay display a dash. Export includes all retained rows matching the applied filters.';
            require __DIR__ . '/help.php'; ?></h3>
            <form method="get" action="inventory/controllers/device.php#view-ntp" class="device-history-filters ntp-history-filters" aria-label="NTP history filters">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <input type="hidden" name="view" value="1">
                <input type="hidden" name="ntp_tab" value="history">
                <?php foreach (['ntp_from' => 'From', 'ntp_to' => 'Through'] as $field => $label): ?>
                    <label class="field">
                        <span data-field-help="<?= $field === 'ntp_from' ? 'Include readings from the start of this date.' : 'Include readings through the end of this date.' ?>"><?= $label ?></span>
                        <input type="date" name="<?= $field ?>"
                            value="<?= icct_nms_h($ntpFilters[$field] ?? (is_string($_GET[$field] ?? null) ? $_GET[$field] : '')) ?>">
                    </label>
                <?php endforeach; ?>
                <label class="field">
                    <span data-field-help="Filter by the clock or collection status recorded for each observation.">Status</span>
                    <select name="ntp_status">
                        <option value="">All statuses</option>
                        <?php foreach (icct_nms_ntp_history_statuses() as $status): ?>
                            <option <?= ($ntpFilters['ntp_status'] ?? '') === $status ? 'selected' : '' ?>><?= icct_nms_h($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="device-history-actions">
                    <button type="submit" class="button primary">Apply</button>
                    <a class="button" href="<?= icct_nms_h($ntpUrl('history')) ?>">Clear filters</a>
                    <?php if ($ntpTotal): ?>
                        <a class="button" data-tooltip="Download all retained observations matching the applied filters." href="inventory/controllers/export.php?<?= icct_nms_h(http_build_query(['id' => $id, 'ntp' => 1] + $ntpFilters)) ?>">Export CSV</a>
                    <?php endif; ?>
                </div>
            </form>
            <?php if ($ntpHistoryError !== ''): ?>
                <p role="alert"><?= icct_nms_h($ntpHistoryError) ?></p>
            <?php endif; ?>
            <?php if ($ntpHistory): ?>
                <?php require __DIR__ . '/history_trends.php'; ?>
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
                                $sample = $observation['sample']; ?>
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
                <?php $recordPager('ntp_page', $ntpPage, $ntpTotal, 'view-ntp', ['ntp_tab' => 'history'] + $ntpFilters, $ntpPageSize); ?>
            <?php elseif ($ntpHistoryError === ''): ?>
                <p>No NTP observations match the selected filters.</p>
            <?php endif; ?>
        <?php endif; ?>
    <?php else: ?>
        <p>Configure NTP in the device's Protocol Config to collect its clock status.</p>
    <?php endif; ?>
</section>
