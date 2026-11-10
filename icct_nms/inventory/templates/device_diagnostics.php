<?php /** Saved diagnostic results never trigger a new run or change monitoring. */
require __DIR__ . '/device_record_pager.php'; ?>
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
            $totalRuns = (int) db_fetch_cell_prepared(
                'SELECT COUNT(*) FROM plugin_icct_nms_diagnostic_jobs WHERE host_id=? AND user_id=?',
                [$id, $owner],
            );
            $runsPage = min($recordPage('diagnostics_page'), max(1, (int) ceil($totalRuns / 25)));
            $runs = db_fetch_assoc_prepared(
                (
                    'SELECT id,tool,status,requested_at,started_at,finished_at,result_json FROM '
        . 'plugin_icct_nms_diagnostic_jobs WHERE host_id=? AND user_id=? ORDER BY id DESC LIMIT 25 '
        . 'OFFSET '
                ) .
                    ($runsPage - 1) * 25,
                [$id, $owner],
            );
            $labels = icct_backend_diag_labels();
            ?>
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
                            <?php if (
                                is_array($result) &&
                                isset($result['output'])
                            ): ?>
                                <pre class="device-record-output"><?= icct_nms_h((string) $result['output']) ?></pre>
                            <?php else: ?>
                                <p>No saved output.</p>
                            <?php endif; ?>
                        </div>
                    </details>
                <?php
                endforeach;
            if (
                !$runs
            ): ?>
                    <p class="device-record-empty">No diagnostic runs saved for this device.</p>
                <?php endif;
?>
            </div>
        <?php $recordPager('diagnostics_page', $runsPage, $totalRuns, 'view-diagnostics');endif; ?>
    <?php endif; ?>
</section>
