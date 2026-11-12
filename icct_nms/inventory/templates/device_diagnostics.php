<?php /** Saved diagnostic results never trigger a new run or change monitoring. */
require_once __DIR__ . '/../diagnostics/services/diagnostic_reading.php'; ?>
<section
    id="view-diagnostics"
    data-device-view-panel="diagnostics"
    hidden>
    <?php if (!is_realm_allowed(3)): ?>
        <h2>Diagnostic readings</h2>
        <p>Device management permission is required to read diagnostic records.</p>
    <?php else: ?>
        <h2 class="device-help-heading">Diagnostic readings <?php
        $headingHelpText = 'Your saved diagnostic runs for this device. Historical results describe the configuration at the time of the run.';
        require __DIR__ . '/../../shared/templates/heading_help.php';
        ?>
 </h2>
        <?php
        $owner = (int) ($_SESSION['sess_user_id'] ?? 0);
$canReadDiagnostics = true;
try {
    icct_backend_diag_authorize_job(['host_id' => $id, 'user_id' => $owner]);
} catch (RuntimeException $e) {
    $canReadDiagnostics = false;
}
if (!$canReadDiagnostics): ?>
            <p>Diagnostic permission is required to read saved results.</p>
        <?php else:
            $labels = icct_backend_diag_labels();
            $statuses = ['queued' => 'Queued', 'running' => 'Running', 'complete' => 'Complete', 'failed' => 'Failed'];
            $filters = [];
            foreach (['diagnostics_method' => $labels, 'diagnostics_status' => $statuses] as $key => $options) {
                $value = $_GET[$key] ?? '';
                if (is_string($value) && isset($options[$value])) {
                    $filters[$key] = $value;
                }
            }
            foreach (['diagnostics_from', 'diagnostics_until'] as $key) {
                $date = $_GET[$key] ?? '';
                if (
                    is_string($date) &&
                    preg_match('/^([1-9]\d{3})-(\d{2})-(\d{2})$/D', $date, $parts) &&
                    checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
                ) {
                    $filters[$key] = $date;
                }
            }
            $sizes = [10, 25, 50, 100];
            $size = filter_var($_GET['diagnostics_size'] ?? 10, FILTER_VALIDATE_INT);
            $size = in_array($size, $sizes, true) ? $size : 10;
            $where = ' WHERE host_id=? AND user_id=?';
            $params = [$id, $owner];
            foreach (['diagnostics_method' => 'tool', 'diagnostics_status' => 'status'] as $key => $column) {
                if (isset($filters[$key])) {
                    $where .= " AND $column=?";
                    $params[] = $filters[$key];
                }
            }
            foreach (['diagnostics_from' => ['>=', '00:00:00'], 'diagnostics_until' => ['<=', '23:59:59']] as $key => [$operator, $time]) {
                if (isset($filters[$key])) {
                    $where .= " AND requested_at $operator ?";
                    $params[] = $filters[$key] . ' ' . $time;
                }
            }
            $totalRuns = (int) db_fetch_cell_prepared(
                'SELECT COUNT(*) FROM plugin_icct_nms_diagnostic_jobs' . $where,
                $params,
            );
            $pages = max(1, (int) ceil($totalRuns / $size));
            $requestedPage = filter_var($_GET['diagnostics_target'] ?? $_GET['diagnostics_page'] ?? 1, FILTER_VALIDATE_INT);
            $runsPage = max(1, min($requestedPage ?: 1, $pages));
            $offset = ($runsPage - 1) * $size;
            $runs = db_fetch_assoc_prepared(
                'SELECT id,tool,status,requested_at,started_at,finished_at,result_json FROM '
                . 'plugin_icct_nms_diagnostic_jobs' . $where . " ORDER BY id DESC LIMIT $size OFFSET $offset",
                $params,
            );
            $diagnosticAction = 'inventory/controllers/device.php';
            $baseParams = ['id' => (int) $id, 'view' => 1];
            $clearUrl = $diagnosticAction . '?' . http_build_query($baseParams + ['diagnostics_size' => $size]) . '#view-diagnostics';
            ?>
            <form class="inventory-filters diagnostic-filters" method="get" action="<?= $diagnosticAction ?>#view-diagnostics">
                <?php foreach ($baseParams + ['diagnostics_size' => $size] as $key => $value): ?>
                    <input type="hidden" name="<?= $key ?>" value="<?= $value ?>">
                <?php endforeach; ?>
                <?php foreach (['diagnostics_method' => ['Method', $labels], 'diagnostics_status' => ['Status', $statuses]] as $key => [$label, $options]): ?>
                    <label class="field">
                        <span class="field-label"><?= $label ?></span>
                        <select name="<?= $key ?>">
                            <option value="">All</option>
                            <?php foreach ($options as $value => $caption): ?>
                                <option value="<?= icct_nms_h($value) ?>" <?= ($filters[$key] ?? '') === $value ? 'selected' : '' ?>><?= icct_nms_h($caption) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endforeach; ?>
                <?php foreach (['diagnostics_from' => 'Requested from', 'diagnostics_until' => 'Requested until'] as $key => $label): ?>
                    <label class="field">
                        <span class="field-label"><?= $label ?></span>
                        <input type="date" name="<?= $key ?>" value="<?= icct_nms_h($filters[$key] ?? '') ?>">
                    </label>
                <?php endforeach; ?>
                <?php if ($filters): ?>
                    <a class="button" href="<?= icct_nms_h($clearUrl) ?>">Clear All</a>
                <?php else: ?>
                    <button class="button" type="button" disabled>Clear All</button>
                <?php endif; ?>
                <button type="submit" class="button primary">Apply</button>
            </form>
            <div class="device-record-list">
                <?php
                foreach ($runs as $run):
                    $result = json_decode($run['result_json'] ?? '', true); ?>
                    <details
                        class="device-record-item"
                        name="diagnostic-records">
                        <summary>
                            <span>
                                <small>Requested</small><?= $display($run['requested_at']) ?>

                            </span>
                            <span>
                                <small>Method</small><?= $display($labels[$run['tool']] ?? $run['tool']) ?>

                            </span>
                            <span>
                                <small>Status</small>
                                <span
                                    class="device-status"
                                    data-log-status="<?= icct_nms_h($run['status']) ?>"><?= $display(ucfirst($run['status'])) ?></span>
                            </span>
                            <span>
                                <small>Finished</small><?= $display($run['finished_at']) ?>

                            </span>
                        </summary>
                        <div class="device-record-body">
                            <h4>Result #<?= (int) $run['id'] ?></h4>
                            <?php $readingId = 'diagnostic-result-' . (int) $run['id']; ?>
                            <div class="diagnostic-reading">
                                <div class="device-view-tabs diagnostic-reading-tabs" role="tablist" aria-label="Diagnostic result">
                                    <button type="button" role="tab" id="<?= $readingId ?>-summary-tab"
                                        aria-selected="true" aria-controls="<?= $readingId ?>-summary">Summary</button>
                                    <button type="button" role="tab" id="<?= $readingId ?>-raw-tab"
                                        aria-selected="false" aria-controls="<?= $readingId ?>-raw" tabindex="-1">Raw data</button>
                                </div>
                                <div class="diagnostic-reading-panel" id="<?= $readingId ?>-summary" role="tabpanel"
                                    aria-labelledby="<?= $readingId ?>-summary-tab" tabindex="0">
                                    <?php require __DIR__ . '/device_diagnostic_summary.php'; ?>
                                </div>
                                <div class="diagnostic-reading-panel" id="<?= $readingId ?>-raw" role="tabpanel"
                                    aria-labelledby="<?= $readingId ?>-raw-tab" hidden>
                                    <?php if (isset($result['output'])): ?>
                                        <pre class="device-record-output" tabindex="0" aria-label="Raw diagnostic output"><?= icct_nms_h((string) $result['output']) ?></pre>
                                    <?php else: ?>
                                        <p>No saved output.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </details>
                <?php
                endforeach;
            if (
                !$runs
            ): ?>
                    <p class="device-record-empty"><?= $filters ? 'No diagnostic runs match these filters.' : 'No diagnostic runs saved for this device.' ?></p>
                <?php endif;
?>
            </div>
            <form class="inventory-pagination diagnostic-pagination" data-diagnostic-pager method="get" action="<?= $diagnosticAction ?>#view-diagnostics">
                <?php foreach ($baseParams + $filters as $key => $value): ?>
                    <input type="hidden" name="<?= $key ?>" value="<?= icct_nms_h($value) ?>">
                <?php endforeach; ?>
                <label>Items per page:
                    <select name="diagnostics_size">
                        <?php foreach ($sizes as $option): ?>
                            <option <?= $option === $size ? 'selected' : '' ?>><?= $option ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <span aria-live="polite">
                    <?= $totalRuns ? ($offset + 1) . '–' . min($offset + $size, $totalRuns) : '0' ?>
                    of <?= $totalRuns ?> items
                </span>
                <div class="page-controls">
                    <label>
                        <span class="sr-only">Diagnostic readings page</span>
                        <select name="diagnostics_page">
                            <?php for ($pageNumber = 1; $pageNumber <= $pages; $pageNumber++): ?>
                                <option <?= $pageNumber === $runsPage ? 'selected' : '' ?>><?= $pageNumber ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>
                    <span>of <?= $pages ?> pages</span>
                    <?php foreach ([-1 => ['Previous', 'M15 5 7 12 15 19Z'], 1 => ['Next', 'M9 5 17 12 9 19Z']] as $step => [$direction, $path]): ?>
                        <button class="pagination-control" type="submit" name="diagnostics_target" value="<?= $runsPage + $step ?>"
                            aria-label="<?= $direction ?> page" <?= $runsPage + $step < 1 || $runsPage + $step > $pages ? 'disabled' : '' ?>>
                            <svg class="pagination-arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="<?= $path ?>"/></svg>
                        </button>
                    <?php endforeach; ?>
                    <noscript><button type="submit">Go</button></noscript>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
