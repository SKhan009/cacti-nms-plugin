<?php $showIncidents = $syslogTab === 'incidents'; ?>
<?php if ($showIncidents): ?>
    <p>Link incidents start with an interface-down message. Recovery requires a later link-up message from the same sender and interface. Recorded downtime totals completed incidents.</p>
<?php else: ?>
    <p>Identical events and automatic restart attempts for the same service are grouped together. Occurrences include repeats counted by the receiver.</p>
<?php endif; ?>
<?php if (!$syslogReportRows): ?>
    <p class="device-record-empty"><?= $showIncidents ? 'No link incidents match the current filters.' : 'No repeated events match the current filters.' ?></p>
<?php else: ?>
    <div class="site-table-wrap">
        <table class="site-table" aria-label="<?= $showIncidents ? 'Syslog link incidents' : 'Repeated Syslog events' ?>">
            <thead>
                <tr>
                    <?php foreach ($showIncidents
                        ? ['Started', 'Interface', 'Event', 'Sender IP', 'Severity', 'Occurrences', 'Recovery', 'Duration', 'Status']
                        : ['First occurrence', 'Last occurrence', 'Event', 'Sender IP', 'Severity', 'Occurrences'] as $label): ?>
                        <th scope="col"><?= icct_nms_h($label) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($syslogReportRows as $row):
                    $event = $showIncidents ? $row['start'] : $row;
                    $severity = (int) $event['severity_code'];
                    $restart = !$showIncidents && str_starts_with($event['event_key'], 'restart:')
                        ? icct_nms_syslog_restart($event['message'])
                        : null;
                    ?>
                    <tr>
                        <?php if ($showIncidents): ?>
                            <td><?= icct_nms_h($event['event_time']) ?></td>
                            <td><?= icct_nms_h($row['port']) ?></td>
                        <?php else: ?>
                            <td><?= icct_nms_h($row['first_time']) ?></td>
                            <td><?= icct_nms_h($row['last_time']) ?></td>
                        <?php endif; ?>
                        <td class="syslog-event-text"><?= icct_nms_h($restart ? 'Automatic restart scheduled for ' . $restart['unit'] : $event['message']) ?></td>
                        <td><?= icct_nms_h($event['source_ip']) ?></td>
                        <td><span class="device-syslog-severity severity-<?= icct_nms_h(icct_backend_syslog_nms_severity($severity)) ?>"><?= icct_nms_h($syslogSeverityNames[$severity] ?? 'Unknown') ?></span></td>
                        <td><?= number_format((int) $row['occurrences']) ?></td>
                        <?php if ($showIncidents): ?>
                            <td><?= icct_nms_h($row['recovery'] ?? '—') ?></td>
                            <td><?= $row['seconds'] === null ? '—' : icct_nms_h(icct_nms_syslog_duration($row['seconds'])) ?></td>
                            <td><?= $row['recovery'] === null ? 'Open' : 'Recovered' ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
