<!-- Alarm rows expose acknowledgement actions only when the current user can manage alarms. -->
<?php $alarmSuccess = $_SESSION['icct_nms_alarm_action_success'] ??
    (!empty($_SESSION['icct_nms_alarm_ack_success']) ? 'Alarm acknowledged successfully.' : '');
unset($_SESSION['icct_nms_alarm_action_success'], $_SESSION['icct_nms_alarm_ack_success']); ?>
<section
    class="device-panel alarm-page"
    id="alarm-page"
    data-management="<?= $management ? '1' : '0' ?>"
    data-report-site="<?= icct_nms_h($serverStatus['hostname'] ?? 'ICCT NMS') ?>"
    data-report-user="<?= icct_nms_h($user['username'] ?? 'Cacti user') ?>"
    data-success="<?= icct_nms_h($alarmSuccess) ?>">
    <?php require __DIR__ . '/list_header.php'; ?>
    <form
        id="alarm-filters"
        class="alarm-filters">
        <div class="field alarm-device-field">
            <span>Device</span>
            <details class="alarm-device-picker" id="alarm-device-picker">
                <summary id="alarm-device-summary">All devices</summary>
                <div class="alarm-device-menu">
                    <input type="search" id="alarm-device-search" aria-label="Search devices" placeholder="Search devices">
                    <div id="alarm-device-options" class="alarm-device-options" role="group" aria-label="Select devices"></div>
                </div>
            </details>
        </div>
        <label class="field">
            <span>Severity</span>
            <select
                name="severity"
                aria-label="Severity levels">
                <option value="">All</option>
                <?php foreach (
                    ['Critical', 'Major', 'Minor', 'Warning', 'Information'] as $v
                ): ?>
                    <option><?= $v ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field alarm-source-field">
            <span>Event Source</span>
            <select name="source">
                <option value="">All sources</option>
            </select>
        </label>
        <div class="field alarm-date-range">
            <span>Date Range</span>
            <div class="alarm-date-range-inputs">
                <input type="datetime-local" name="since" aria-label="From">
                <span aria-hidden="true">–</span>
                <input type="datetime-local" name="until" aria-label="Until">
            </div>
        </div>
        <button
            type="reset"
            class="button"
            id="alarm-clear">Clear All</button>
        <label class="field alarm-search">
            <span class="sr-only">Search alarms</span>
            <input
                name="search"
                placeholder="Search With Device Name or IP Address or Alarm Name"
                type="search">
        </label>
    </form>
    <p
        id="alarm-status"
        role="status"
        aria-live="polite"></p>
    <div class="alarm-table-wrap">
        <table class="alarm-table">
            <thead>
                <tr>
                    <?php foreach ([
                        'occurred' => 'Alarm Occurred',
                        'device' => 'Device Name & Short Name',
                        'source' => 'Event Source',
                        'severity' => 'Severity & Ack Status',
                        'escalation' => 'Escalation & Status',
                    ] as $key => $caption): ?>
                        <th scope="col">
                            <?php if ($key === 'source'): ?>
                                <?= icct_nms_h($caption) ?>
                            <?php else: ?>
                                <button type="button" class="alarm-sort-button" data-alarm-sort="<?= $key ?>">
                                    <span><?= icct_nms_h($caption) ?></span>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20V4m-7 7 7-7 7 7"/></svg>
                                </button>
                            <?php endif; ?>
                        </th>
                    <?php endforeach; ?>
                    <th>Alarm Name
                        <br> (Count)
                    </th>
                    <th>Alarm Description &amp; Fix</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="alarm-rows"></tbody>
        </table>
    </div>
    <footer class="alarm-pagination">
        <label>Items per page: <select id="alarm-page-size">
                <option>10</option>
                <option>25</option>
                <option>50</option>
                <option>100</option>
            </select>
        </label>
        <span id="alarm-page-count"></span>
        <div class="page-controls">
            <span id="alarm-page-number"></span>
            <button
                type="button"
                id="alarm-prev"
                aria-label="Previous page"
                class="pagination-control">
                <svg
                    class="pagination-arrow"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                    focusable="false">
                    <path d="M15 5 7 12 15 19Z" />
                </svg>
            </button>
            <button
                type="button"
                id="alarm-next"
                aria-label="Next page"
                class="pagination-control">
                <svg
                    class="pagination-arrow"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                    focusable="false">
                    <path d="M9 5 17 12 9 19Z" />
                </svg>
            </button>
        </div>
    </footer>
    <script
        type="application/json"
        id="alarm-data"><?= json_encode(
            $alarmRows,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE,
        ) ?></script>
</section>
