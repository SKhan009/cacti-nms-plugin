<?php
/** Full-page ticket workflow shared by ticket status actions. */
$alarm = $ticketDetail['alarm'];
$ticket = $ticketDetail['ticket'];
$state = $alarm['ticket_state'] ?: 'Open';
$labels = [
    'ticket_progress' => ['In Progress Ticket', 'In Progress'],
    'ticket_resolve' => ['Resolve Ticket', 'Resolve'],
    'ticket_reopen' => ['Reopen Ticket', 'Reopen'],
    'ticket_close' => ['Close Ticket', 'Close'],
];
[$heading, $buttonLabel] = $labels[$ticketAction];
$canAct = !empty($alarm['escalated']) && match ($ticketAction) {
    'ticket_progress' => $state === 'Open',
    'ticket_resolve' => in_array($state, ['Open', 'In Progress'], true),
    'ticket_reopen' => in_array($state, ['Resolved', 'Closed'], true),
    'ticket_close' => $state !== 'Closed',
};
$returnDetail = ($_GET['return'] ?? '') === 'detail';
$returnUrl = 'alarms/controllers/alarms.php' . ($returnDetail ? '?view=' . rawurlencode($alarm['alarm_key']) : '');
$value = static fn ($text) => icct_nms_h(trim((string) $text) !== '' ? $text : '—');
?>
<section class="alarm-ack-page alarm-ticket-action-page" id="alarm-ticket-action-page" data-return-url="<?= icct_nms_h($returnUrl) ?>">
    <form id="alarm-ticket-action-form" method="post" action="alarms/controllers/alarms.php">
        <?php icct_nms_token(); ?>
        <input type="hidden" name="alarm_key" value="<?= icct_nms_h($alarm['alarm_key']) ?>">
        <input type="hidden" name="alarm_action" value="<?= icct_nms_h($ticketAction) ?>">
        <input type="hidden" name="expected_ticket_state" value="<?= icct_nms_h($state) ?>">
        <header class="alarm-ack-heading">
            <h1><?= icct_nms_h($heading) ?></h1>
            <div class="alarm-ack-controls">
                <a class="button" href="<?= icct_nms_h($returnUrl) ?>">Cancel</a>
                <button class="button primary" type="submit" id="alarm-ticket-action-submit" disabled><?= icct_nms_h($buttonLabel) ?></button>
            </div>
        </header>
        <div class="alarm-ack-content">
            <section class="alarm-ticket-action-section" aria-labelledby="ticket-alarm-heading">
                <h2 id="ticket-alarm-heading">Alarm Details</h2>
                <dl class="alarm-ticket-action-grid">
                    <div><dt>Alarm Occurred</dt><dd><?= icct_nms_time_cell($alarm['occurred_epoch']) ?></dd></div>
                    <div><dt>Device Name</dt><dd><?= $value($alarm['device_name']) ?><?= !empty($alarm['short_name']) ? ' (' . icct_nms_h($alarm['short_name']) . ')' : '' ?></dd></div>
                    <div><dt>Alarm Name (Count)</dt><dd><?= $value($alarm['name']) ?> (<?= (int) $alarm['occurrences'] ?>)</dd></div>
                    <div class="alarm-ticket-action-description"><dt>Description</dt><dd><?= $value($alarm['description']) ?></dd></div>
                </dl>
            </section>
            <section class="alarm-ticket-action-section" aria-labelledby="ticket-details-heading">
                <h2 id="ticket-details-heading">Ticket Details</h2>
                <dl class="alarm-ticket-action-grid">
                    <div><dt>Ticket Severity</dt><dd><?= $value($ticket['details']['severity'] ?? '') ?></dd></div>
                    <div><dt>Ticket Name</dt><dd><?= $value($ticket['details']['name'] ?? '') ?></dd></div>
                    <div><dt>Remarks</dt><dd><?= $value($ticket['details']['remarks'] ?? '') ?></dd></div>
                </dl>
                <?php if ($ticket['timeline']): ?>
                    <ol class="alarm-ticket-action-timeline" aria-label="Ticket status history">
                        <?php foreach ($ticket['timeline'] as $index => $event): ?>
                            <li class="alarm-ticket-event <?= icct_nms_h($event['kind']) ?>" <?= $index > 0 ? 'hidden' : '' ?>>
                                <h3><?= icct_nms_h($event['label']) ?></h3>
                                <time><?= icct_nms_time_cell($event['created_epoch']) ?></time>
                                <p><?= $value($event['note']) ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    <?php if (count($ticket['timeline']) > 1): ?>
                        <button type="button" class="alarm-escalate-show-more" id="alarm-ticket-action-show-more" aria-expanded="false">Show more <span aria-hidden="true">⌄</span></button>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
            <div class="alarm-ack-remarks">
                <div class="alarm-ack-remarks-heading">
                    <label for="alarm-ticket-action-note"><span class="field-label" data-field-help="Explain this ticket status change. Maximum 512 characters.">Remarks</span></label>
                    <output id="alarm-ticket-action-count" for="alarm-ticket-action-note">0/512</output>
                </div>
                <textarea id="alarm-ticket-action-note" name="note" maxlength="512" rows="5" placeholder="Write Remarks" <?= $canAct ? 'required' : 'disabled' ?>></textarea>
            </div>
            <p id="alarm-ticket-action-feedback" role="status" aria-live="polite"><?= $canAct ? '' : 'This ticket action is no longer available. Return to the alarm and review its current status.' ?></p>
        </div>
    </form>
</section>
