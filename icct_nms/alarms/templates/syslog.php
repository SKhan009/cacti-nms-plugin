<?php
$syslogParams = $syslogConsole['params'];
$syslogSize = $syslogConsole['size'];
$severities = icct_backend_syslog_severity_labels();
$facilities = icct_backend_syslog_facility_labels();
$oldest = ($syslogParams['sort'] ?? '') === 'oldest';
?>
<section class="device-panel alarm-page alarm-syslog">
    <?php require __DIR__ . '/list_header.php'; ?>
    <form action="alarms/controllers/alarms.php" method="get" class="alarm-filters alarm-syslog-filters" aria-label="Syslog filters">
        <input type="hidden" name="tab" value="syslog">
        <input type="hidden" name="size" value="<?= $syslogSize ?>">
        <?php if ($oldest): ?><input type="hidden" name="sort" value="oldest"><?php endif; ?>
        <label class="field"><span>Device</span>
            <select name="device">
                <option value="">All devices</option>
                <?php foreach ($syslogConsole['devices'] as $id => $device): ?>
                    <option value="<?= (int) $id ?>" <?= (int) ($syslogParams['device'] ?? 0) === (int) $id ? 'selected' : '' ?>>
                        <?= icct_nms_h($device['description']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field"><span>Severity</span>
            <select name="syslog_severity[]">
                <option value="">All severities</option>
                <?php foreach ($severities as $code => $label): ?>
                    <option value="<?= $code ?>" <?= (string) ($syslogParams['syslog_severity'][0] ?? '') === (string) $code ? 'selected' : '' ?>><?= icct_nms_h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field"><span>Facility</span>
            <select name="syslog_facility">
                <option value="">All facilities</option>
                <?php foreach ($facilities as $code => $label): ?>
                    <option value="<?= $code ?>" <?= (string) ($syslogParams['syslog_facility'] ?? '') === (string) $code ? 'selected' : '' ?>><?= icct_nms_h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field"><span>Event time (start)</span>
            <input type="datetime-local" name="syslog_since" value="<?= icct_nms_h($syslogParams['syslog_since'] ?? '') ?>">
        </label>
        <label class="field alarm-search"><span class="sr-only">Search Syslog</span>
            <input type="search" name="syslog_search" maxlength="200" placeholder="Search message, program, or facility"
                value="<?= icct_nms_h($syslogParams['syslog_search'] ?? '') ?>">
        </label>
        <div class="alarm-syslog-filter-actions">
            <button class="button primary" type="submit">Apply</button>
            <a class="button" href="alarms/controllers/alarms.php?tab=syslog">Clear All</a>
        </div>
    </form>
    <p id="alarm-syslog-refresh-status" role="status">Checking for new Syslog readings every <?= $syslogRefreshSeconds ?> seconds.</p>
    <?php require __DIR__ . '/syslog_results.php'; ?>
</section>
<script src="alarms/js/syslog-console.js?v=<?= substr(hash_file('sha256', __DIR__ . '/../js/syslog-console.js'), 0, 12) ?>" defer></script>
