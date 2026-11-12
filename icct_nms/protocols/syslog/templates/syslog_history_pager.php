<?php $syslogPages = max(1, (int) ceil($syslogTotal / $syslogPageSize)); ?>
<form class="inventory-pagination" data-syslog-history-pager method="get" action="inventory/controllers/device.php#view-syslog">
    <?php foreach (['id' => (int) $id, 'view' => 1] + $syslogTabParams + $syslogFilterParams as $key => $value): ?>
        <?php foreach ((array) $value as $item): ?>
            <input type="hidden" name="<?= icct_nms_h($key . (is_array($value) ? '[]' : '')) ?>" value="<?= icct_nms_h($item) ?>">
        <?php endforeach; ?>
    <?php endforeach; ?>
    <label>Items per page:
        <select name="syslog_size">
            <?php foreach ($syslogPageSizes as $size): ?>
                <option <?= $size === $syslogPageSize ? 'selected' : '' ?>><?= $size ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <span aria-live="polite">
        <?= $syslogTotal ? (($syslogPage - 1) * $syslogPageSize + 1) . '–' . min($syslogPage * $syslogPageSize, $syslogTotal) : '0' ?>
        of <?= $syslogTotal ?> items
    </span>
    <div class="page-controls">
        <label>
            <span class="sr-only">Syslog page</span>
            <select name="syslog_page">
                <?php for ($pageNumber = 1; $pageNumber <= $syslogPages; $pageNumber++): ?>
                    <option <?= $pageNumber === $syslogPage ? 'selected' : '' ?>><?= $pageNumber ?></option>
                <?php endfor; ?>
            </select>
        </label>
        <span>of <?= $syslogPages ?> pages</span>
        <?php foreach ([-1 => ['Previous', 'M15 5 7 12 15 19Z'], 1 => ['Next', 'M9 5 17 12 9 19Z']] as $step => [$direction, $path]): ?>
            <button class="pagination-control" type="submit" name="syslog_target" value="<?= $syslogPage + $step ?>"
                aria-label="<?= $direction ?> page" <?= $syslogPage + $step < 1 || $syslogPage + $step > $syslogPages ? 'disabled' : '' ?>>
                <svg class="pagination-arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="<?= $path ?>"/></svg>
            </button>
        <?php endforeach; ?>
        <noscript><button type="submit">Go</button></noscript>
    </div>
</form>
