<?php
$syslogParams = $syslogConsole['params'];
$syslogSize = $syslogConsole['size'];
$syslogPage = $syslogConsole['page'];
$syslogPages = $syslogConsole['pages'];
$syslogTotal = $syslogConsole['total'];
$severities = icct_backend_syslog_severity_labels();
$facilities = icct_backend_syslog_facility_labels();
$oldest = ($syslogParams['sort'] ?? '') === 'oldest';
?>
<div id="alarm-syslog-results">
    <div class="alarm-table-wrap">
        <table class="alarm-table alarm-syslog-table" aria-label="Syslog events">
            <thead>
                <tr>
                    <th scope="col" aria-sort="<?= $oldest ? 'ascending' : 'descending' ?>">
                        <a class="alarm-sort-button<?= $oldest ? '' : ' is-descending' ?>"
                            href="<?= icct_nms_h(icct_nms_alarm_syslog_url($syslogConsole, ['sort' => $oldest ? '' : 'oldest'])) ?>"
                            aria-label="Sort event time, <?= $oldest ? 'newest' : 'oldest' ?> first">
                            <span>Event time</span>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20V4m-7 7 7-7 7 7"/></svg>
                        </a>
                    </th>
                    <th scope="col">Device name &amp; short name</th>
                    <th scope="col">Source IP &amp; received via</th>
                    <th scope="col">Severity</th>
                    <th scope="col">Facility / Program</th>
                    <th scope="col">Event message (count) &amp; what happened</th>
                    <th scope="col">Last received</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($syslogConsole['events'] as $event):
                    $device = $syslogConsole['devices'][$event['host_id']];
                    $priority = (int) $event['severity_code'];
                    $explanation = $event['program'] === 'systemd'
                        ? icct_nms_syslog_systemd_event($event['message']) : null;
                    ?>
                    <tr>
                        <td><?= icct_nms_time_cell($event['event_time']) ?></td>
                        <td><a href="inventory/controllers/device.php?id=<?= (int) $event['host_id'] ?>&amp;view=1#view-syslog"><?= icct_nms_h($device['description']) ?></a>
                            <?php if (!empty($device['short_name'])): ?><small><?= icct_nms_h($device['short_name']) ?></small><?php endif; ?></td>
                        <td><?= icct_nms_h($event['source_ip']) ?><small><?= icct_nms_h(strtoupper($event['transport'])) ?></small></td>
                        <td><span class="alarm-syslog-priority" data-priority="<?= $priority ?>"><?= icct_nms_h($severities[$priority] ?? 'Unknown') ?></span></td>
                        <td><?= icct_nms_h($facilities[(int) $event['facility_code']] ?? $event['facility']) ?>
                            <?php if ($event['program'] !== ''): ?><small><?= icct_nms_h($event['program']) ?></small><?php endif; ?></td>
                        <td><div class="alarm-syslog-message"><?= icct_nms_h($event['message']) ?> (<?= number_format((int) $event['repeat_count']) ?>)</div>
                            <?php if ($explanation): ?><small><?= icct_nms_h($explanation[1]) ?></small><?php endif; ?>
                            <?php if (!empty($event['alarm_key'])): ?><small><a href="alarms/controllers/alarms.php?view=<?= rawurlencode($event['alarm_key']) ?>">View linked alarm</a></small><?php endif; ?></td>
                        <td><?= icct_nms_time_cell($event['last_seen']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$syslogConsole['events']): ?>
                    <tr><td colspan="7">No Syslog messages match the current filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <form class="alarm-pagination" action="alarms/controllers/alarms.php" method="get" aria-label="Syslog pagination">
        <input type="hidden" name="tab" value="syslog">
        <?php foreach ($syslogParams as $key => $value): ?>
            <?php foreach ((array) $value as $item): ?>
                <input type="hidden" name="<?= icct_nms_h($key . (is_array($value) ? '[]' : '')) ?>" value="<?= icct_nms_h($item) ?>">
            <?php endforeach; ?>
        <?php endforeach; ?>
        <label>Items per page:
            <select name="size">
                <?php foreach ([10, 25, 50, 100] as $size): ?>
                    <option <?= $size === $syslogSize ? 'selected' : '' ?>><?= $size ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="button" type="submit">Go</button>
        <span><?= $syslogTotal ? (($syslogPage - 1) * $syslogSize + 1) . '–' . min($syslogPage * $syslogSize, $syslogTotal) : '0' ?> of <?= number_format($syslogTotal) ?> items</span>
        <div class="page-controls">
            <span><?= $syslogPage ?> of <?= number_format($syslogPages) ?> pages</span>
            <button class="pagination-control" name="page" value="<?= $syslogPage - 1 ?>" aria-label="Previous page" <?= $syslogPage <= 1 ? 'disabled' : '' ?>>‹</button>
            <button class="pagination-control" name="page" value="<?= $syslogPage + 1 ?>" aria-label="Next page" <?= $syslogPage >= $syslogPages ? 'disabled' : '' ?>>›</button>
        </div>
    </form>
</div>
