<h4 class="ntp-history-trends-heading" data-field-help="Hover over a reading or focus it with Tab to see its value and observation time.">Trends for this page</h4>
<ul class="ntp-history-trend-legend" aria-label="Trend chart legend">
    <li><span class="ntp-legend-line" aria-hidden="true"></span>Consecutive readings</li>
    <li><span class="ntp-legend-dot" aria-hidden="true"></span>Single reading</li>
    <li><span class="ntp-legend-gap" aria-hidden="true"></span>Gap after a missed reading or clock change</li>
</ul>
<div class="ntp-history-trends">
    <?php
    $ntpChronological = array_reverse($ntpHistory);
    $ntpTrendInterval = (int) $ntpAssignment['interval_seconds'] ?: (int) read_config_option('poller_interval');
    foreach (icct_nms_ntp_history_metrics() as $metric => $label):
        $trend = icct_nms_ntp_history_trend($ntpChronological, $metric, $ntpTrendInterval);
        ?>
        <div class="ntp-history-trend">
            <h5><?= icct_nms_h($label) ?></h5>
            <?php if (!$trend): ?>
                <p>No measurements on this page.</p>
            <?php else: ?>
                <p>Min <?= $ntpNumber($trend['min']) ?> · Max <?= $ntpNumber($trend['max']) ?></p>
                <svg viewBox="0 0 400 60" width="400" height="60" preserveAspectRatio="none" role="group" aria-label="<?= icct_nms_h($label . ' trend') ?>">
                    <?php foreach ($trend['segments'] as $segment): ?>
                        <?php if (count($segment) === 1): ?>
                            <?php [$x, $y] = $segment[0]; ?>
                            <path class="ntp-trend-isolated" d="M<?= icct_nms_h($x) ?> <?= icct_nms_h($y) ?>h0" stroke="currentColor"
                                stroke-width="5" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                        <?php else: ?>
                            <polyline
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linejoin="round"
                                stroke-linecap="round"
                                vector-effect="non-scaling-stroke"
                                points="<?= icct_nms_h(implode(' ', array_map(static fn($point) => implode(',', $point), $segment))) ?>" />
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php foreach ($trend['readings'] as $reading): ?>
                        <?php
                        [$x, $y] = $reading['point'];
                        $readingLabel = $label . ' ' . $ntpNumber($reading['value']) . ' · ' .
                            icct_nms_display_time($reading['observed_at']);
                        ?>
                        <path class="ntp-trend-reading" d="M<?= icct_nms_h($x) ?> <?= icct_nms_h($y) ?>h0"
                            stroke-linecap="round" vector-effect="non-scaling-stroke" tabindex="0" role="img"
                            data-tooltip="<?= icct_nms_h($readingLabel) ?>" aria-label="<?= icct_nms_h($readingLabel) ?>" />
                    <?php endforeach; ?>
                </svg>
                <p class="ntp-history-trend-time"><span>Earlier</span><span>Later</span></p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
