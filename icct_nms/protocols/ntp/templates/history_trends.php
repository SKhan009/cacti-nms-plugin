<h4 data-field-help="These plots use the observations on the current page. Change pages to see another set of readings.">Trends for this page</h4>
<p>Points use the recorded observation times. A break marks missing readings, a missed collection interval, or a clock adjustment.</p>
<div class="device-detail-grid">
    <?php
    $ntpChronological = array_reverse($ntpHistory);
    foreach (icct_nms_ntp_history_metrics() as $metric => $label):
        $trend = icct_nms_ntp_history_trend($ntpChronological, $metric, (int) $ntpAssignment['interval_seconds']);
        ?>
        <div>
            <h5><?= icct_nms_h($label) ?></h5>
            <?php if (!$trend): ?>
                <p>No measurements on this page.</p>
            <?php else: ?>
                <svg viewBox="0 0 400 60" width="100%" height="60" role="img" aria-label="<?= icct_nms_h($label . ' trend') ?>">
                    <?php foreach ($trend['segments'] as $segment): ?>
                        <polyline
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            points="<?= icct_nms_h(implode(' ', array_map(static fn($point) => implode(',', $point), $segment))) ?>" />
                        <?php foreach ($segment as [$x, $y]): ?>
                            <circle cx="<?= icct_nms_h($x) ?>" cy="<?= icct_nms_h($y) ?>" r="2.5" fill="currentColor" />
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </svg>
                <p>Min <?= $ntpNumber($trend['min']) ?> · Max <?= $ntpNumber($trend['max']) ?></p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
