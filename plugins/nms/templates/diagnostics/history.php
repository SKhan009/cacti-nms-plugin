<?php $filters = $history['filters']; $statuses = ['queued' => 'Starting', 'running' => 'Running', 'complete' => 'Passed', 'failed' => 'Failed']; ?>
<section class="nms-panel" id="diagnostic-history">
    <div class="nms-panel-head"><div><h2>Test history</h2><p>Your saved tests by device. Open a result to see its full output.</p></div></div>
    <form method="get" id="nms-history-filters" class="nms-config-form nms-history-filters" action="diagnostics.php#diagnostic-history">
        <input type="hidden" name="section" value="run">
        <label>Device<select name="history_device"><option value="0">All devices</option>
        <?php foreach ($history['devices'] as $device) { ?>
            <option value="<?php print (int) $device['id']; ?>" <?php print (int) $device['id'] === $filters['history_device'] ? 'selected' : ''; ?>><?php print nms_h($device['description'] . ' · ' . $device['hostname']); ?></option>
        <?php } ?></select></label>
        <label>Tool<select name="history_tool"><option value="">All tools</option>
        <?php foreach (nms_diag_labels() as $key => $label) { ?>
            <option value="<?php print nms_h($key); ?>" <?php print $key === $filters['history_tool'] ? 'selected' : ''; ?>><?php print nms_h($label); ?></option>
        <?php } ?></select></label>
        <label>Status<select name="history_status"><option value="">All statuses</option>
        <?php foreach ($statuses as $key => $label) { ?>
            <option value="<?php print nms_h($key); ?>" <?php print $key === $filters['history_status'] ? 'selected' : ''; ?>><?php print nms_h($label); ?></option>
        <?php } ?></select></label>
        <label>From date<input type="date" name="history_from" value="<?php print nms_h($filters['history_from']); ?>"></label>
        <label>To date<input type="date" name="history_to" value="<?php print nms_h($filters['history_to']); ?>"></label>
        <div class="nms-history-actions"><button type="submit">Apply filters</button><a class="nms-list-action" href="diagnostics.php?section=run#diagnostic-history">Reset</a></div>
    </form>
    <div class="nms-table-wrap"><table class="nms-table" data-server-pagination="true">
        <thead><tr><th>Test</th><th>Device</th><th>Tool</th><th>Status</th><th>Requested</th><th>Finished</th><th>Result</th></tr></thead>
        <tbody><?php foreach ($history['rows'] as $job) { ?>
            <tr><td>#<?php print (int) $job['id']; ?></td><td><?php print nms_h($job['description']); ?><br><small><?php print nms_h($job['hostname']); ?></small></td><td><?php print nms_h(nms_diag_labels()[$job['tool']] ?? $job['tool']); ?></td><td><span class="nms-test-status <?php print $job['status'] === 'complete' ? 'passed' : ($job['status'] === 'failed' ? 'failed' : 'pending'); ?>"><?php print nms_h($statuses[$job['status']] ?? $job['status']); ?></span></td><td><?php print nms_h($job['requested_at']); ?></td><td><?php print nms_h($job['finished_at'] ?: '—'); ?></td><td><a class="nms-list-action" href="<?php print nms_h(nms_diag_history_url($filters, ['history_page' => $history['page'], 'job_id' => (int) $job['id']])); ?>#diagnostic-result">View result</a></td></tr>
        <?php } ?>
        <?php if (!$history['rows']) { ?><tr><td colspan="7" class="nms-empty">No tests match these filters.</td></tr><?php } ?>
        </tbody>
    </table></div>
    <nav class="nms-pagination" aria-label="Test history pagination" data-nms-no-tooltip="1">
        <span class="nms-pagination-summary">Showing <?php print $history['total'] ? $history['offset'] + 1 : 0; ?>–<?php print min($history['total'], $history['offset'] + $filters['history_size']); ?> of <?php print $history['total']; ?> tests</span>
        <div class="nms-pagination-actions">
            <label class="nms-pagination-size">Rows<select name="history_size" form="nms-history-filters" aria-label="History rows per page" onchange="this.form.requestSubmit()">
                <?php foreach ([5,10,25,50,100] as $size) { ?><option <?php print $size === $filters['history_size'] ? 'selected' : ''; ?>><?php print $size; ?></option><?php } ?>
            </select></label>
            <?php if ($history['page'] === 1) { ?><button type="button" class="nms-page-button" disabled aria-label="Previous page">‹</button><?php } else { ?><a class="nms-page-button" aria-label="Previous page" href="<?php print nms_h(nms_diag_history_url($filters, ['history_page' => $history['page'] - 1])); ?>#diagnostic-history">‹</a><?php } ?>
            <?php
            $pages = [1, $history['pages']];
            for ($page = max(2, $history['page'] - 1); $page <= min($history['pages'] - 1, $history['page'] + 1); $page++) $pages[] = $page;
            $pages = array_unique($pages); sort($pages); $previous = 0;
            foreach ($pages as $page) {
                if ($previous && $page > $previous + 1) print '<span class="nms-page-gap">…</span>';
                $previous = $page;
            ?>
                <?php if ($page === $history['page']) { ?><span class="nms-page-button current" aria-current="page" aria-label="Page <?php print $page; ?>"><?php print $page; ?></span><?php } else { ?><a class="nms-page-button" aria-label="Page <?php print $page; ?>" href="<?php print nms_h(nms_diag_history_url($filters, ['history_page' => $page])); ?>#diagnostic-history"><?php print $page; ?></a><?php } ?>
            <?php } ?>
            <?php if ($history['page'] === $history['pages']) { ?><button type="button" class="nms-page-button" disabled aria-label="Next page">›</button><?php } else { ?><a class="nms-page-button" aria-label="Next page" href="<?php print nms_h(nms_diag_history_url($filters, ['history_page' => $history['page'] + 1])); ?>#diagnostic-history">›</a><?php } ?>
        </div>
    </nav>
</section>
