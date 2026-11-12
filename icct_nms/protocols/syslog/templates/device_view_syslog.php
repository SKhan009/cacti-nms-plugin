<?php /** Read-only, device-scoped received Syslog history; device access is already checked. */
require_once __DIR__ . '/../services/syslog_sessions.php';
require_once __DIR__ . '/../services/syslog_systemd.php';
require_once __DIR__ . '/../services/syslog_history_report.php';
[$syslogFiltersView, $syslogTab] = icct_nms_syslog_history_request((int) $id, $_GET);
$syslogSearch = $syslogFiltersView['search'];
$syslogSeverityFilter = $syslogFiltersView['severity_codes'] ?? [];
$syslogFacility = (string) ($syslogFiltersView['facility_codes'][0] ?? '');
$syslogPageSizes = [10, 25, 50, 100];
$syslogSize = $_GET['syslog_size'] ?? '';
$syslogPageSize = is_scalar($syslogSize) && in_array((string) $syslogSize, array_map('strval', $syslogPageSizes), true)
    ? (int) $syslogSize
    : 10;
$syslogRequestedPage = $_GET['syslog_target'] ?? $_GET['syslog_page'] ?? 1;
$syslogPage = is_scalar($syslogRequestedPage) ? max(1, min(1000000, (int) $syslogRequestedPage)) : 1;
$syslogRequestedPage = $syslogPage;
[$syslogSummary, $syslogReportRows, $syslogTotal, $syslogPage] = icct_nms_syslog_history_report(
    (int) $id, $syslogFiltersView, $syslogTab, $syslogPageSize, $syslogPage,
);
$readEvents = static function ($page) use ($id, $syslogFiltersView, $syslogPageSize, &$syslogTotal) {
    return icct_backend_syslog_events(
        [(int) $id],
        $syslogFiltersView,
        $syslogPageSize,
        ($page - 1) * $syslogPageSize,
        $syslogTotal,
        include_alarm_details: false,
    );
};
$syslogEvents = [];
if ($syslogTab === 'all') {
    $syslogPage = $syslogRequestedPage;
    $syslogEvents = $readEvents($syslogPage);
    $syslogPages = max(1, (int) ceil($syslogTotal / $syslogPageSize));
    if ($syslogPage > $syslogPages) {
        $syslogPage = $syslogPages;
        $syslogEvents = $readEvents($syslogPage);
    }
}
$syslogOccurrences = icct_nms_syslog_history_occurrences((int) $id, $syslogFiltersView, $syslogEvents);
$syslogContext = [];
$verificationTraps = [];
$contextKeys = [];
$logindSessions = [];
foreach ($syslogEvents as $event) {
    if (isset($event['id']) && ($event['event_origin'] ?? '') === 'syslog') {
        $contextKeys[] = 'syslog_context_' . (int) $id . '_' . (int) $event['id'];
        $logindSession = icct_nms_syslog_session($event);
        if ($logindSession) {
            $logindSessions[(int) $event['id']] = $logindSession;
        }
    }
}
$logindEvidence = icct_nms_syslog_session_evidence((int) $id, $syslogEvents, $logindSessions);
if ($contextKeys) {
    $contextRows = db_fetch_assoc_prepared(
        'SELECT meta_key,meta_value FROM plugin_icct_nms_meta WHERE meta_key IN (' .
            implode(',', array_fill(0, count($contextKeys), '?')) . ')',
        $contextKeys,
    );
    $trapIds = [];
    foreach ($contextRows ?: [] as $contextRow) {
        $context = json_decode($contextRow['meta_value'], true);
        if (is_array($context)) {
            $syslogContext[$contextRow['meta_key']] = $context;
            if (is_int($context['verification_trap_id'] ?? null) && $context['verification_trap_id'] > 0) {
                $trapIds[] = $context['verification_trap_id'];
            }
        }
    }
    if ($trapIds) {
        $trapRows = db_fetch_assoc_prepared(
            "SELECT id,event_time,source_ip,transport,program,repeat_count FROM plugin_icct_nms_syslog_events
                WHERE host_id=? AND event_origin='trap' AND id IN (" .
                implode(',', array_fill(0, count($trapIds), '?')) . ')',
            [(int) $id, ...$trapIds],
        );
        $verificationTraps = array_column($trapRows ?: [], null, 'id');
    }
}
$syslogFilterParams = array_filter([
    'syslog_search' => $syslogSearch,
    'syslog_facility' => $syslogFacility,
    'syslog_severity' => $syslogSeverityFilter,
    'syslog_since' => str_replace(' ', 'T', substr($syslogFiltersView['since'], 0, 16)),
    'syslog_until' => str_replace(' ', 'T', substr($syslogFiltersView['until'], 0, 16)),
], static fn ($value) => $value !== '' && $value !== []);
$syslogUrl = static fn ($params = []) => 'inventory/controllers/device.php?' .
    http_build_query(['id' => (int) $id, 'view' => 1] + $params) . '#view-syslog';
$syslogTabParams = $syslogTab === 'all' ? [] : ['syslog_tab' => $syslogTab];
$syslogSizeParams = $syslogPageSize === 10 ? [] : ['syslog_size' => $syslogPageSize];
$syslogSeverityNames = icct_backend_syslog_severity_labels();
$syslogFacilityNames = icct_backend_syslog_facility_labels();
$syslogTransportLabels = ['both' => 'UDP and TCP', 'udp' => 'UDP', 'tcp' => 'TCP', 'internal' => 'Internal'];
?>
<section
    id="view-syslog"
    data-device-view-panel="syslog"
    hidden>
    <div class="device-history-heading">
        <h2 data-field-help="Messages received from this device and availability events recorded by Cacti. Expand a row to see its details.">Syslog Event History</h2>
        <div class="syslog-history-heading-actions">
            <a class="button" aria-label="Download Syslog CSV" title="Download CSV for the selected tab and applied filters"
                href="inventory/controllers/export.php?<?= icct_nms_h(http_build_query(['id' => (int) $id, 'syslog' => 1] + $syslogTabParams + $syslogFilterParams)) ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M12 3v12M7 10l5 5 5-5M4 15v6h16v-6"/>
                </svg>
            </a>
            <a class="button"
                href="<?= icct_nms_h($syslogUrl($syslogFilterParams + $syslogTabParams + $syslogSizeParams + ['syslog_page' => $syslogPage])) ?>">Refresh</a>
        </div>
    </div>
    <div class="netflow-summary syslog-summary" aria-label="Syslog history totals">
        <?php foreach ([
            'Total messages' => number_format((int) $syslogSummary['messages']),
            'Event types' => number_format((int) $syslogSummary['event_types']),
            'Link-down incidents' => number_format($syslogSummary['incidents']),
            'Recorded link downtime' => icct_nms_syslog_duration((int) $syslogSummary['downtime']),
            'Open incidents' => number_format($syslogSummary['open']),
        ] as $label => $value): ?>
            <div><span><?= icct_nms_h($label) ?></span><strong><?= icct_nms_h($value) ?></strong></div>
        <?php endforeach; ?>
    </div>
    <form
        method="get"
        action="inventory/controllers/device.php#view-syslog"
        class="device-history-filters">
        <input
            type="hidden"
            name="id"
            value="<?= (int) $id ?>">
        <input
            type="hidden"
            name="view"
            value="1">
        <?php if ($syslogSizeParams): ?>
            <input type="hidden" name="syslog_size" value="<?= $syslogPageSize ?>">
        <?php endif; ?>
        <?php if ($syslogTabParams): ?>
            <input type="hidden" name="syslog_tab" value="<?= icct_nms_h($syslogTab) ?>">
        <?php endif; ?>
        <div class="field">
            <span id="syslog-severity-label" data-field-help="Choose one or more severities reported by the Syslog sender.">Severity</span>
            <details
                class="syslog-select-menu"
                data-syslog-history-severity>
                <summary aria-labelledby="syslog-severity-label syslog-severity-summary">
                    <span id="syslog-severity-summary"><?= $syslogSeverityFilter ? count($syslogSeverityFilter) . ' selected' : 'All severities' ?></span>
                    <span
                        class="syslog-select-chevron"
                        aria-hidden="true"></span>
                </summary>
                <div
                    class="syslog-select-options"
                    role="group"
                    aria-labelledby="syslog-severity-label">
                    <?php foreach ($syslogSeverityNames as $code => $label): ?>
                        <label class="check-row">
                            <input
                                type="checkbox"
                                name="syslog_severity[]"
                                value="<?= $code ?>"
                                <?= in_array($code, $syslogSeverityFilter, true) ? 'checked' : '' ?>>
                            <span><?= icct_nms_h($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </details>
        </div>
        <?php foreach (['since' => 'From', 'until' => 'Until'] as $key => $label): ?>
            <label class="field">
                <span data-field-help="<?= $key === 'since' ? 'Show events at or after this time.' : 'Show events at or before this time.' ?>"><?= $label ?></span>
                <input
                    type="datetime-local"
                    name="syslog_<?= $key ?>"
                    value="<?= icct_nms_h($syslogFilterParams['syslog_' . $key] ?? '') ?>">
            </label>
        <?php endforeach; ?>
        <label class="field device-history-search">
            <span data-field-help="Search by message text or sender IP.">Search messages</span>
            <input
                type="search"
                name="syslog_search"
                value="<?= icct_nms_h($syslogSearch) ?>"
                maxlength="200"
                placeholder="Search message or source IP">
        </label>
        <label class="field">
            <span data-field-help="Filter by the facility reported by the sender.">Facility</span>
            <select name="syslog_facility">
                <option value="">All facilities</option>
                <?php foreach ($syslogFacilityNames as $code => $label): ?>
                    <option value="<?= $code ?>" <?= (string) $code === $syslogFacility ? 'selected' : '' ?>><?= icct_nms_h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="device-history-actions">
            <?php if ($syslogFilterParams): ?>
                <a
                    class="button"
                    href="<?= icct_nms_h($syslogUrl($syslogTabParams + $syslogSizeParams)) ?>">Clear All</a>
            <?php else: ?>
                <button
                    class="button"
                    type="button"
                    disabled>Clear All</button>
            <?php endif; ?>
            <button
                class="button primary"
                type="submit">Apply</button>
        </div>
    </form>
    <nav class="device-view-tabs" aria-label="Syslog history views">
        <?php foreach (['all' => 'All logs', 'repeated' => 'Repeated events', 'incidents' => 'Incidents'] as $syslogTabKey => $syslogTabLabel): ?>
            <a href="<?= icct_nms_h($syslogUrl($syslogFilterParams + $syslogSizeParams + ($syslogTabKey === 'all' ? [] : ['syslog_tab' => $syslogTabKey]))) ?>"
                <?= $syslogTabKey === $syslogTab ? 'class="current" aria-current="page"' : '' ?>>
                <?= icct_nms_h($syslogTabLabel) ?> (<?= number_format((int) ($syslogTabKey === 'all' ? $syslogSummary['messages'] : $syslogSummary[$syslogTabKey])) ?>)
            </a>
        <?php endforeach; ?>
    </nav>
    <?php if ($syslogTab === 'all'): ?>
    <div class="device-record-list syslog-event-history" aria-label="Syslog and availability events for this device">
        <?php foreach ($syslogEvents as $event):
            $availability = ($event['event_origin'] ?? '') === 'availability';
            $program = $event['program'];
            $message = $event['message'];
            $occurrences = (int) ($syslogOccurrences[icct_nms_syslog_history_key($event)] ?? $event['repeat_count']);
            $firstLine = strtok(trim($message), "\r\n") ?: '';
            $title = $availability ? 'Device availability changed' : mb_strimwidth($firstLine, 0, 130, '…');
            $title = $title !== '' ? $title : 'Device Syslog message';
            $explanation = $availability
                ? $message
                : ($message === '' ? 'The device reported a Syslog event.' : $message);

            if (!$availability && $program === 'systemd' && ($systemdEvent = icct_nms_syslog_systemd_event($message))) {
                [$title, $explanation] = $systemdEvent;
            }
            // Explain explicit session events. Keep the sender's original wording as evidence.
            if (!$availability && preg_match('/^pam_unix\((sshd|sudo):session\): session (opened|closed) for user ([^\s(]+)/', $message, $session)) {
                $service = $session[1] === 'sshd' ? 'SSH' : 'sudo';
                $title = $service . ' session ' . $session[2];
                $explanation = 'The ' . $service . ' service recorded a session ' .
                    ($session[2] === 'opened' ? 'opening' : 'closing') . ' for the account ' . $session[3] . '.';
            }
            $logindSession = $logindSessions[(int) ($event['id'] ?? 0)] ?? null;
            $sessionEvidence = $logindEvidence[(int) ($event['id'] ?? 0)] ?? null;
            if ($logindSession) {
                $sessionId = $logindSession['session'];
                $opening = $sessionEvidence ? icct_nms_syslog_session([
                    'event_origin' => 'syslog',
                    'program' => 'systemd-logind',
                    'message' => $sessionEvidence['opening_message'],
                ]) : null;
                $account = $logindSession['account'] ?? $opening['account'] ?? null;
                $accountText = $account === null ? '' : ' for ' . $account;
                [$title, $explanation] = match ($logindSession['state']) {
                    'opened' => [
                        'Login session opened' . $accountText,
                        'RHEL opened login session ' . $sessionId . $accountText . '.',
                    ],
                    'logged_out' => [
                        'Login session logged out' . $accountText,
                        'RHEL recorded a logout for session ' . $sessionId . $accountText . '.' .
                            (str_contains($message, 'Waiting for processes to exit.') ? ' It was waiting for the session’s processes to exit.' : ''),
                    ],
                    'removed' => [
                        'Login session cleanup completed' . $accountText,
                        'RHEL removed login session ' . $sessionId . $accountText . ' from its session list' .
                            (!empty($sessionEvidence['logout_message']) ? ' after the recorded logout.' : '.'),
                    ],
                };
                if ($account === null) {
                    $explanation .= ' The account could not be verified from the stored session records.';
                }
            }
            $timeSource = $availability ? 'Recorded by Cacti' : match ($event['timestamp_kind'] ?? null) {
                'device' => 'Reported by the device',
                'receiver' => 'Recorded by the Syslog receiver',
                default => 'Time saved in the event record',
            };
            if ($program === 'icct-serial' && $event['transport'] === 'internal') {
                $title = 'Serial register event';
                $explanation = $message;
                $timeSource = 'Recorded by the serial collector';
            }
            $contextKey = 'syslog_context_' . (int) $id . '_' . (int) ($event['id'] ?? 0);
            $context = $syslogContext[$contextKey] ?? null;
            if (($context['fingerprint'] ?? '') !== ($event['fingerprint'] ?? null)) {
                $context = null;
            }
            if (is_string($context['title'] ?? null) && is_string($context['explanation'] ?? null)) {
                $title = $context['title'];
                $explanation = $context['explanation'];
            }
            $verificationTrap = $verificationTraps[$context['verification_trap_id'] ?? 0] ?? null;
            $followUp = $context['follow_up'] ?? '';
            $hasFollowUp = is_string($followUp) && trim($followUp) !== '';
            $hasRecoveryDetails = $hasFollowUp || $verificationTrap;
            ?>
            <details class="device-record-item" name="syslog-event-records">
                <summary>
                    <span><small>Event time</small><?= icct_nms_h($event['event_time']) ?></span>
                    <span><small>Event</small><strong><?= icct_nms_h($title) ?></strong></span>
                    <span>
                        <small>Severity</small>
                        <span class="device-syslog-severity severity-<?= icct_nms_h(icct_backend_syslog_nms_severity($event['severity_code'])) ?>"><?= icct_nms_h($syslogSeverityNames[(int) $event['severity_code']] ?? 'Unknown') ?></span>
                    </span>
                    <span><small data-field-help="Total times this event appears across the filtered history.">Occurrences</small><?= number_format($occurrences) ?></span>
                </summary>
                <div class="device-record-body syslog-event-details">
                    <section class="syslog-event-overview">
                        <h3>What happened</h3>
                        <p class="syslog-event-text"><?= icct_nms_h($explanation) ?></p>
                        <?php if (is_string($context['impact'] ?? null) && $context['impact'] !== ''): ?>
                            <p class="syslog-event-text"><strong><?= icct_nms_h($context['impact']) ?></strong></p>
                        <?php endif; ?>
                    </section>
                    <div class="syslog-event-panels">
                        <section class="syslog-detail-panel">
                            <h4 data-field-help="Shows the recorded time, sender, transport, and the original message.">Syslog record</h4>
                            <dl class="syslog-event-evidence">
                                <?php foreach ([
                                    'Event time' => $event['event_time'] . ' (' . $timeSource . ')',
                                    $availability ? 'Last recorded' : 'Last received' => $event['last_seen'],
                                    'Source IP' => $event['source_ip'],
                                    'Received via' => $syslogTransportLabels[$event['transport'] ?? ''] ?? null,
                                    'Times recorded' => (int) $event['repeat_count'],
                                    'Facility' => $syslogFacilityNames[(int) $event['facility_code']] ?? $event['facility'],
                                ] as $label => $value): ?>
                                    <?php if ($value !== '' && $value !== null): ?>
                                        <div>
                                            <dt><?= icct_nms_h($label) ?></dt>
                                            <dd><?= icct_nms_h($value) ?></dd>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </dl>
                            <?php if (is_string($context['evidence'] ?? null)): ?>
                                <div class="syslog-original-message">
                                    <h5>Journal evidence</h5>
                                    <p><?= icct_nms_h($context['evidence']) ?></p>
                                </div>
                            <?php endif; ?>
                            <?php if ($sessionEvidence): ?>
                                <div class="syslog-original-message">
                                    <h5>Related session records</h5>
                                    <p><?= icct_nms_h($sessionEvidence['opening_time'] . ' · ' . $sessionEvidence['opening_message']) ?></p>
                                    <?php if (!empty($sessionEvidence['logout_message'])): ?>
                                        <p><?= icct_nms_h($sessionEvidence['logout_time'] . ' · ' . $sessionEvidence['logout_message']) ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!$availability): ?>
                                <div class="syslog-original-message">
                                    <h5>Original message</h5>
                                    <p class="syslog-event-text"><?= icct_nms_h($message) ?></p>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($event['raw_message'])): ?>
                                <details class="syslog-original-message">
                                    <summary>Raw Syslog</summary>
                                    <pre class="syslog-event-text"><?= icct_nms_h($event['raw_message']) ?></pre>
                                </details>
                            <?php endif; ?>
                        </section>
                        <?php if (!$availability && $hasRecoveryDetails): ?>
                            <section class="syslog-detail-panel syslog-verification-panel">
                                <h4><?= $verificationTrap ? 'What changed and how it was checked' : 'Fix or recovery status' ?></h4>
                                <?php if ($hasFollowUp): ?>
                                    <p><?= icct_nms_h($followUp) ?></p>
                                <?php endif; ?>
                                <?php if ($verificationTrap): ?>
                                    <h5>Test trap received after the fix</h5>
                                    <dl class="syslog-event-evidence">
                                        <?php foreach ([
                                            'Received at' => $verificationTrap['event_time'],
                                            'Source IP' => $verificationTrap['source_ip'],
                                            'Transport' => strtoupper($verificationTrap['transport']),
                                            'Times received' => $verificationTrap['repeat_count'],
                                            'Trap OID' => $verificationTrap['program'],
                                        ] as $label => $value): ?>
                                            <div>
                                                <dt><?= icct_nms_h($label) ?></dt>
                                                <dd><?= icct_nms_h($value) ?></dd>
                                            </div>
                                        <?php endforeach; ?>
                                    </dl>
                                    <div class="syslog-event-actions">
                                        <a class="button primary" href="inventory/controllers/device.php?<?= icct_nms_h(http_build_query([
                                            'id' => (int) $id,
                                            'view' => 1,
                                            'trap_search' => $verificationTrap['program'],
                                        ])) ?>#view-traps">View test trap and payload</a>
                                    </div>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>
                    </div>
                </div>
            </details>
        <?php endforeach; ?>
        <?php if (!$syslogEvents): ?>
            <p class="device-record-empty">No Syslog or availability events<?= $syslogFilterParams ? ' match the current filters' : ' for this device' ?>.</p>
        <?php endif; ?>
    </div>
    <?php else: ?>
        <?php require __DIR__ . '/syslog_history_report.php'; ?>
    <?php endif; ?>
    <?php require __DIR__ . '/syslog_history_pager.php'; ?>
</section>
