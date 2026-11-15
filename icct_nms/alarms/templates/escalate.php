<?php
/** Escalation page presents the alarm's history before creating a ticket. */
require_once __DIR__ . '/../../shared/services/presentation.php';
$alarm = $escalateDetail['alarm'];
$history = array_slice($escalateDetail['history'], 0, 100);
$canEscalate = empty($alarm['escalated']) && $alarm['state'] !== 'Closed';
$ticketSeverity = icct_nms_alarm_ticket_severity($alarm['severity']);
$severityColors = icct_nms_severity_colors();
$value = static fn ($text) => icct_nms_h(trim((string) $text) !== '' ? $text : '—');
?>
<section class="alarm-ack-page alarm-escalate-page" id="alarm-escalate-page">
    <form id="alarm-escalate-form" method="post" action="alarms/controllers/alarms.php">
        <?php icct_nms_token(); ?>
        <input type="hidden" name="alarm_key" value="<?= icct_nms_h($alarm['alarm_key']) ?>">
        <input type="hidden" name="revision" value="<?= (int) $alarm['revision'] ?>">
        <input type="hidden" name="alarm_action" value="escalate">
        <header class="alarm-ack-heading">
            <h1>Escalate Alarm</h1>
            <div class="alarm-ack-controls">
                <a class="button" href="alarms/controllers/alarms.php">Cancel</a>
                <button class="button primary" type="submit" id="alarm-escalate-submit" disabled>Escalate</button>
            </div>
        </header>
        <div class="alarm-ack-content">
            <dl class="alarm-escalate-summary">
                <div><dt>Site Name</dt><dd><?= $value($alarm['site_name'] ?? '') ?></dd></div>
                <div><dt>Device Name</dt><dd><?= $value($alarm['device_name']) ?><?= !empty($alarm['short_name']) ? ' (' . icct_nms_h($alarm['short_name']) . ')' : '' ?></dd></div>
                <div><dt>Device Type</dt><dd><?= $value($alarm['model_number'] ?: $alarm['network_type']) ?></dd></div>
                <div><dt>IP Address</dt><dd><?= $value($alarm['device_address'] ?? $alarm['address']) ?></dd></div>
                <div><dt>Alarm Name (Count)</dt><dd><?= $value($alarm['name']) ?> (<?= (int) $alarm['occurrences'] ?>)</dd></div>
            </dl>

            <section class="alarm-escalate-history" aria-labelledby="alarm-escalate-history-title">
                <h2 id="alarm-escalate-history-title">Alarm History</h2>
                <div class="alarm-escalate-history-wrap">
                    <table class="alarm-history-table">
                        <thead><tr>
                            <th>Alarm<br>Occurred</th>
                            <th>Severity &amp;<br>Ack</th>
                            <th>Description &amp; Fix</th>
                            <th>Ack &amp; Remarks</th>
                            <th>Count</th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($history as $index => $event):
                                $snapshot = $event['snapshot'];
                                $severity = $snapshot['severity'] ?? $alarm['severity']; ?>
                                <tr <?= $index > 0 ? 'hidden' : '' ?> title="<?= icct_nms_h($event['event']) ?>">
                                    <td><?= icct_nms_time_cell($event['created_epoch'] ?? null) ?></td>
                                    <td><?= isset($severityColors[$severity]) ? icct_nms_severity_badge($severity) : $value($severity) ?></td>
                                    <td><span><?= $value($snapshot['description'] ?? $alarm['description']) ?></span>
                                        <small><?= $value($snapshot['fix'] ?? $alarm['fix']) ?></small></td>
                                    <td><span class="<?= !empty($snapshot['acknowledged']) ? 'alarm-status-checked' : '' ?>"><?= !empty($snapshot['acknowledged']) ? 'Ack' : 'Not Ack' ?></span>
                                        <small><?= $value($event['event'] === 'Acknowledged' ? $event['note'] : ($snapshot['ack_note'] ?? '')) ?></small></td>
                                    <td><?= (int) ($snapshot['occurrences'] ?? $alarm['occurrences']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($history) > 1): ?>
                    <button type="button" class="alarm-escalate-show-more" id="alarm-escalate-show-more" aria-expanded="false">Show more <span aria-hidden="true">⌄</span></button>
                <?php endif; ?>
            </section>

            <div class="alarm-escalate-fields">
                <fieldset class="alarm-escalate-severity" <?= $canEscalate ? '' : 'disabled' ?>>
                    <legend><span class="field-label" data-field-help="Ticket severity is preselected from the alarm severity.">Ticket Severity</span></legend>
                    <div class="alarm-escalate-severity-options">
                        <?php foreach (['Low' => 'Low', 'Medium' => 'Med', 'High' => 'High'] as $choice => $label): ?>
                            <label><input type="radio" name="ticket_severity" value="<?= $choice ?>" <?= $ticketSeverity === $choice ? 'checked' : '' ?>><span><?= $label ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <label class="alarm-escalate-ticket-name"><span class="field-label" data-field-help="Name used to identify the escalation ticket.">Ticket Name</span>
                    <input name="ticket_name" maxlength="320" placeholder="Enter Ticket Name" <?= $canEscalate ? 'required' : 'disabled' ?>></label>
            </div>
            <div class="alarm-ack-remarks">
                <div class="alarm-ack-remarks-heading">
                    <label for="alarm-escalate-note"><span class="field-label" data-field-help="Record why this alarm needs escalation.">Escalation Remarks</span></label>
                    <output id="alarm-escalate-count" for="alarm-escalate-note">0/512</output>
                </div>
                <textarea id="alarm-escalate-note" name="note" maxlength="512" rows="5" placeholder="Write Remarks" <?= $canEscalate ? 'required' : 'disabled' ?>></textarea>
            </div>
            <p id="alarm-escalate-feedback" role="status" aria-live="polite"><?= $canEscalate ? '' : 'This alarm cannot be escalated.' ?></p>
        </div>
    </form>
</section>
