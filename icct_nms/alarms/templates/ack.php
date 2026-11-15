<?php
/** Full-page acknowledgement keeps the alarm reading visible while remarks are entered. */
$alarm = $ackDetail['alarm'];
$canAcknowledge = empty($alarm['acknowledged']) && $alarm['state'] !== 'Closed';
?>
<section class="alarm-ack-page" id="alarm-ack-page">
    <form id="alarm-ack-form" method="post" action="alarms/controllers/alarms.php">
        <?php icct_nms_token(); ?>
        <input type="hidden" name="alarm_key" value="<?= icct_nms_h($alarm['alarm_key']) ?>">
        <input type="hidden" name="revision" value="<?= (int) $alarm['revision'] ?>">
        <input type="hidden" name="alarm_action" value="ack">
        <header class="alarm-ack-heading">
            <h1>Acknowledging Alarm</h1>
            <div class="alarm-ack-controls">
                <a class="button" href="alarms/controllers/alarms.php">Cancel</a>
                <button class="button primary" type="submit" id="alarm-ack-submit" disabled>Acknowledge</button>
            </div>
        </header>
        <div class="alarm-ack-content">
            <dl class="alarm-ack-summary">
                <div>
                    <dt>Alarm Occurred</dt>
                    <dd><?= icct_nms_h(icct_nms_alarm_format_time($alarm['occurred_epoch'])) ?></dd>
                </div>
                <div>
                    <dt>Device Name</dt>
                    <dd><?= icct_nms_h($alarm['device_name']) ?></dd>
                </div>
                <div>
                    <dt>Alarm Name (Count)</dt>
                    <dd><?= icct_nms_h($alarm['name']) ?> (<?= (int) $alarm['occurrences'] ?>)</dd>
                </div>
            </dl>
            <div class="alarm-ack-description">
                <h2>Description</h2>
                <p><?= icct_nms_h($alarm['description'] ?: '—') ?></p>
            </div>
            <div class="alarm-ack-remarks">
                <div class="alarm-ack-remarks-heading">
                    <label for="alarm-ack-note"><span class="field-label" data-field-help="Record why you are acknowledging this alarm.">Acknowledgment Remarks</span></label>
                    <output id="alarm-ack-count" for="alarm-ack-note">0/512</output>
                </div>
                <textarea id="alarm-ack-note" name="note" maxlength="512" rows="5"
                    placeholder="Write Remarks" <?= $canAcknowledge ? 'required' : 'disabled' ?>></textarea>
            </div>
            <p id="alarm-ack-feedback" role="status" aria-live="polite"><?= $canAcknowledge
                ? ''
                : 'This alarm cannot be acknowledged.' ?></p>
        </div>
    </form>
</section>
