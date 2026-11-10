<?php /** Read-only, device-scoped received Syslog history; device access is already checked. */
require_once __DIR__ . '/../services/syslog_sessions.php';
require_once __DIR__ . '/../services/syslog_systemd.php';
$syslogSearch = mb_substr(
    trim(is_scalar($_GET['syslog_search'] ?? '') ? (string) ($_GET['syslog_search'] ?? '') : ''),
    0,
    200,
);
$syslogFiltersView = ['host_id' => (int) $id, 'search' => $syslogSearch];
$syslogAlarmKey = $_GET['event_alarm'] ?? '';
if (is_string($syslogAlarmKey) && preg_match('/^[a-f0-9]{64}$/D', $syslogAlarmKey)) {
    $syslogFiltersView['alarm_key'] = $syslogAlarmKey;
}
$syslogSeverityFilter = $_GET['syslog_severity'] ?? [];
if (!is_array($syslogSeverityFilter)) {
    throw new InvalidArgumentException('Choose Syslog severity levels.');
}
$syslogSeverityFilter = icct_backend_syslog_filters(['severity_codes' => $syslogSeverityFilter])['severity_codes'];
if ($syslogSeverityFilter) {
    $syslogFiltersView['severity_codes'] = $syslogSeverityFilter;
}
$syslogAck = $_GET['syslog_ack'] ?? '';
if (!in_array($syslogAck, ['', 'yes', 'no'], true)) {
    throw new InvalidArgumentException('Choose a Syslog acknowledgement status.');
}
$syslogFiltersView['ack'] = $syslogAck;
foreach (['since', 'until'] as $bound) {
    $value = $_GET['syslog_' . $bound] ?? '';
    if (!is_string($value) || ($value !== '' && !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d$/D', $value))) {
        throw new InvalidArgumentException('Choose a valid Syslog time window.');
    }
    if ($value !== '') {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value);
        if (!$parsed || $parsed->format('Y-m-d\\TH:i') !== $value) {
            throw new InvalidArgumentException('Choose a valid Syslog time window.');
        }
    }
    $syslogFiltersView[$bound] = $value === '' ? '' : str_replace('T', ' ', $value) . ':00';
}
if ($syslogFiltersView['since'] !== '' && $syslogFiltersView['until'] !== '' && $syslogFiltersView['since'] > $syslogFiltersView['until']) {
    throw new InvalidArgumentException('Syslog From must not be later than Until.');
}
require __DIR__ . '/../../../inventory/templates/device_record_pager.php';
$syslogPageSize = 50;
$syslogPage = $recordPage('syslog_page');
$syslogTotal = 0;
$readEvents = static function ($page) use ($id, $syslogFiltersView, $syslogPageSize, &$syslogTotal) {
    return icct_backend_syslog_events(
        [(int) $id], $syslogFiltersView, $syslogPageSize, ($page - 1) * $syslogPageSize, $syslogTotal,
    );
};
$syslogEvents = $readEvents($syslogPage);
$syslogPages = max(1, (int) ceil($syslogTotal / $syslogPageSize));
if ($syslogPage > $syslogPages) {
    $syslogPage = $syslogPages;
    $syslogEvents = $readEvents($syslogPage);
}
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
    'event_alarm' => $syslogFiltersView['alarm_key'] ?? '',
    'syslog_search' => $syslogSearch,
    'syslog_severity' => $syslogSeverityFilter,
    'syslog_ack' => $syslogAck,
    'syslog_since' => str_replace(' ', 'T', substr($syslogFiltersView['since'], 0, 16)),
    'syslog_until' => str_replace(' ', 'T', substr($syslogFiltersView['until'], 0, 16)),
], static fn ($value) => $value !== '' && $value !== []);
$syslogUrl = static fn ($params = []) => 'inventory/controllers/device.php?' .
    http_build_query(['id' => (int) $id, 'view' => 1] + $params) . '#view-syslog';
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
        <a
            class="button"
            href="<?= icct_nms_h($syslogUrl($syslogFilterParams + ['syslog_page' => $syslogPage])) ?>">Refresh</a>
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
        <?php if (isset($syslogFiltersView['alarm_key'])): ?>
            <input type="hidden" name="event_alarm" value="<?= icct_nms_h($syslogFiltersView['alarm_key']) ?>">
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
        <label class="field">
            <span data-field-help="Filter by whether the linked alarm or event has been acknowledged.">Acknowledgement</span>
            <select name="syslog_ack">
                <option value="">All</option>
                <option
                    value="yes"
                    <?= $syslogAck === 'yes' ? 'selected' : '' ?>>Acknowledged</option>
                <option
                    value="no"
                    <?= $syslogAck === 'no' ? 'selected' : '' ?>>Not acknowledged</option>
            </select>
        </label>
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
            <span data-field-help="Search by message text, program, or sender IP.">Search messages</span>
            <input
                type="search"
                name="syslog_search"
                value="<?= icct_nms_h($syslogSearch) ?>"
                maxlength="200"
                placeholder="Search message, program or source IP">
        </label>
        <div class="device-history-actions">
            <?php if ($syslogFilterParams): ?>
                <a
                    class="button"
                    href="<?= icct_nms_h($syslogUrl()) ?>">Clear All</a>
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
    <div class="device-record-list syslog-event-history" aria-label="Syslog and availability events for this device">
        <?php foreach ($syslogEvents as $event):
            $availability = ($event['event_origin'] ?? '') === 'availability';
            $program = $event['program'];
            $message = $event['message'];
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
            $hasAlarm = !empty($event['alarm_key']);
            $showAlarmPanel = !$availability && $hasAlarm && !$hasRecoveryDetails;
            $acknowledgement = $hasAlarm
                ? (empty($event['acknowledged']) ? 'Not acknowledged' : 'Acknowledged')
                : 'No linked alarm';
            ?>
            <details class="device-record-item" name="syslog-event-records">
                <summary>
                    <span><small>Event time</small><?= icct_nms_h($event['event_time']) ?></span>
                    <span><small>Event</small><strong><?= icct_nms_h($title) ?></strong></span>
                    <span>
                        <small>Severity</small>
                        <span class="device-syslog-severity severity-<?= icct_nms_h(icct_backend_syslog_nms_severity($event['severity_code'])) ?>"><?= icct_nms_h($syslogSeverityNames[(int) $event['severity_code']] ?? 'Unknown') ?></span>
                    </span>
                    <span><small>Alarm status</small><?= icct_nms_h($acknowledgement) ?></span>
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
                                    'Program' => $program,
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
                        </section>
                        <?php if (!$availability && ($hasRecoveryDetails || $showAlarmPanel)): ?>
                            <section class="syslog-detail-panel syslog-verification-panel">
                                <h4><?= $showAlarmPanel ? 'Linked alarm' : ($verificationTrap ? 'What changed and how it was checked' : 'Fix or recovery status') ?></h4>
                                <?php if ($hasFollowUp): ?>
                                    <p><?= icct_nms_h($followUp) ?></p>
                                <?php endif; ?>
                                <?php if ($showAlarmPanel): ?>
                                    <div class="syslog-event-actions">
                                        <a class="button" href="alarms/controllers/alarms.php?view=<?= icct_nms_h($event['alarm_key']) ?>">View linked alarm</a>
                                    </div>
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
                    <?php if ($hasAlarm && !$showAlarmPanel): ?>
                        <div class="syslog-event-actions">
                            <a class="button" href="alarms/controllers/alarms.php?view=<?= icct_nms_h($event['alarm_key']) ?>">View linked alarm</a>
                        </div>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
        <?php if (!$syslogEvents): ?>
            <p class="device-record-empty">No Syslog or availability events<?= $syslogFilterParams ? ' match the current filters' : ' for this device' ?>.</p>
        <?php endif; ?>
    </div>
    <?php $recordPager('syslog_page', $syslogPage, $syslogTotal, 'view-syslog', $syslogFilterParams, $syslogPageSize); ?>
</section>
