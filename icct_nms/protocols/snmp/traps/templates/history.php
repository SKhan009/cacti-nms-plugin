<?php
/** The device controller already checked device access. */
require_once __DIR__ . '/../services/trap_service.php';
require __DIR__ . '/../../../../inventory/templates/device_record_pager.php';
$trapSearch = $_GET['trap_search'] ?? '';
$trapSearch = is_string($trapSearch) ? mb_substr(trim($trapSearch), 0, 200) : '';
$trapFilters = ['host_id' => (int) $id, 'origin' => 'trap', 'search' => $trapSearch];
$trapAlarmKey = $_GET['event_alarm'] ?? '';
if (is_string($trapAlarmKey) && preg_match('/^[a-f0-9]{64}$/D', $trapAlarmKey)) {
    $trapFilters['alarm_key'] = $trapAlarmKey;
}
$trapParams = array_filter(['trap_search' => $trapSearch, 'event_alarm' => $trapFilters['alarm_key'] ?? '']);
$trapPageSize = 25;
$trapPage = $recordPage('trap_page');
$trapTotal = 0;
$readTraps = static function ($page) use ($id, $trapFilters, $trapPageSize, &$trapTotal) {
    return icct_backend_syslog_events(
        [(int) $id], $trapFilters, $trapPageSize, ($page - 1) * $trapPageSize, $trapTotal,
    );
};
$trapEvents = $readTraps($trapPage);
$trapPages = max(1, (int) ceil($trapTotal / $trapPageSize));
if ($trapPage > $trapPages) {
    $trapPage = $trapPages;
    $trapEvents = $readTraps($trapPage);
}
$trapUrl = 'inventory/controllers/device.php?' . http_build_query(['id' => (int) $id, 'view' => 1]);
?>
<section id="view-traps" data-device-view-panel="traps" hidden>
    <div class="device-history-heading">
        <h2 data-field-help="Notifications sent to Cacti by this device. A notification may be a test, a status change, or a fault.">SNMP Trap History</h2>
        <a
            class="button"
            href="<?= icct_nms_h($trapUrl . '&' . http_build_query($trapParams + ['trap_page' => $trapPage]) . '#view-traps') ?>">Refresh</a>
    </div>
    <form
        method="get"
        action="inventory/controllers/device.php#view-traps"
        class="device-history-filters">
        <input
            type="hidden"
            name="id"
            value="<?= (int) $id ?>">
        <input
            type="hidden"
            name="view"
            value="1">
        <?php if (isset($trapFilters['alarm_key'])): ?>
            <input type="hidden" name="event_alarm" value="<?= icct_nms_h($trapFilters['alarm_key']) ?>">
        <?php endif; ?>
        <label class="field device-history-search">
            <span data-field-help="Search by notification OID or sender IP. Expand a result to see its readings.">Search traps</span>
            <input
                type="search"
                name="trap_search"
                maxlength="200"
                value="<?= icct_nms_h($trapSearch) ?>"
                placeholder="Search trap OID or source IP">
        </label>
        <div class="device-history-actions">
            <?php if ($trapParams): ?>
                <a class="button" href="<?= icct_nms_h($trapUrl . '#view-traps') ?>">Clear All</a>
            <?php endif; ?>
            <button class="button primary" type="submit">Apply</button>
        </div>
    </form>
    <div
        class="device-record-list syslog-event-history"
        aria-label="SNMP notifications for this device">
        <?php foreach ($trapEvents as $trapEvent):
            $trapOid = $trapEvent['program'];
            $hasTrapAlarm = !empty($trapEvent['alarm_key']);
            $trapAlarmState = $trapEvent['alarm_state'] ?? '';
            $trapPayload = json_decode($trapEvent['raw_message'], true, 32, JSON_THROW_ON_ERROR) ?? [];
            $explanation = icct_nms_trap_explanation($trapOid, $trapEvent['source_ip'], $trapPayload);
            $trapCount = (int) $trapEvent['repeat_count'];
            ?>
            <details class="device-record-item" name="trap-event-records">
                <summary>
                    <span><small>Received</small><?= icct_nms_h($trapEvent['event_time']) ?></span>
                    <span>
                        <small>Event</small>
                        <strong><?= icct_nms_h($explanation['title']) ?></strong>
                        <small><?= icct_nms_h($trapOid) ?></small>
                    </span>
                    <span><small>Source IP</small><?= icct_nms_h($trapEvent['source_ip']) ?></span>
                    <span>
                        <small>Alarm</small>
                        <?= $hasTrapAlarm ? icct_nms_h(
                            ($trapAlarmState !== '' ? $trapAlarmState . ' · ' : '') .
                            (empty($trapEvent['acknowledged']) ? 'Not acknowledged' : 'Acknowledged'),
                        ) : 'No linked alarm' ?>
                    </span>
                </summary>
                <div class="device-record-body syslog-event-details">
                    <section class="syslog-detail-panel trap-event-summary">
                        <div>
                            <h3>Event summary</h3>
                            <p class="syslog-event-text"><?= icct_nms_h($explanation['what']) ?></p>
                            <p class="syslog-event-text"><?= icct_nms_h($explanation['why']) ?></p>
                            <?php if (isset($explanation['result'])): ?>
                                <p class="syslog-event-text"><strong><?= icct_nms_h($explanation['result']) ?></strong></p>
                            <?php endif; ?>
                        </div>
                        <?php if ($hasTrapAlarm): ?>
                            <a class="button" data-tooltip="Open the alarm linked to this notification." href="alarms/controllers/alarms.php?view=<?= icct_nms_h($trapEvent['alarm_key']) ?>">View linked alarm</a>
                        <?php endif; ?>
                    </section>
                    <section class="syslog-detail-panel">
                        <h4 data-field-help="Received at is when the receiver saw the trap. Last stored at is when Cacti last saved the record.">SNMP notification record</h4>
                        <dl class="syslog-event-evidence">
                            <?php foreach ([
                                'Trap OID' => $trapOid,
                                'Source IP' => $trapEvent['source_ip'],
                                'Received at' => $trapEvent['event_time'],
                                'Last stored at' => $trapEvent['last_seen'],
                                'Times received' => $trapCount,
                            ] as $label => $value): ?>
                                <div><dt><?= icct_nms_h($label) ?></dt><dd><?= icct_nms_h($value) ?></dd></div>
                            <?php endforeach; ?>
                        </dl>
                    </section>
                    <section class="syslog-detail-panel">
                        <h4 data-field-help="The sender supplies these values. The Meaning column explains known fields and keeps their original identifiers visible.">Reported readings</h4>
                        <div class="site-table-wrap">
                            <table class="site-table">
                                <thead><tr><th scope="col">Field</th><th scope="col">Reported value</th><th scope="col">Meaning</th></tr></thead>
                                <tbody>
                                    <?php foreach ($trapPayload as $fieldOid => $values): ?>
                                        <?php foreach ($values as $value):
                                            [$fieldName, $fieldMeaning] = icct_nms_trap_payload_field($fieldOid, $value); ?>
                                            <tr>
                                                <td>
                                                    <strong><?= icct_nms_h($fieldName) ?></strong>
                                                    <small class="trap-field-oid"><?= icct_nms_h($fieldOid) ?></small>
                                                </td>
                                                <td><?= icct_nms_h($value) ?></td>
                                                <td><?= icct_nms_h($fieldMeaning) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </details>
        <?php endforeach; ?>
        <?php if (!$trapEvents): ?>
            <p class="device-record-empty">No SNMP traps<?= $trapParams ? ' match the current filters' : ' have been received for this device' ?>.</p>
        <?php endif; ?>
    </div>
    <?php $recordPager('trap_page', $trapPage, $trapTotal, 'view-traps', $trapParams, $trapPageSize); ?>
</section>
