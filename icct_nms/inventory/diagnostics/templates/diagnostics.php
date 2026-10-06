<?php
/** Single saved-device diagnostic selection and the actual collector result. */
?>
<div class="titlebar">
    <h1><?= icct_nms_h($host["description"]) ?> — Diagnostics</h1>
    <a class="button" href="inventory/controllers/inventory.php">Done</a>
</div>
<section class="diagnostics-page">
    <?php if($diagnosticLabels): ?><form method="post" id="diagnostic-run-form">
        <?php icct_nms_token(); ?>
        <input type="hidden" name="host_id" value="<?= (int)$id ?>">
        <?php icct_nms_select(
            "Diagnostic method",
            "tool",
            $diagnosticLabels,
            $tool,
        ); ?>
        <div class="protocol-actions"><button class="button primary" type="submit">Run Diagnostic</button></div>
    </form><?php else: ?><p>No diagnostics are selected for this device.</p><?php endif; ?>
    <p id="diagnostic-live-error" class="notice error" role="alert" hidden></p>
    <section id="diagnostic-live-result" aria-live="polite" data-host-id="<?= (int)$id ?>" data-job-id="<?= (int)($job['id']??0) ?>" data-status="<?= icct_nms_h($job['status']??'') ?>" <?= $job?'':'hidden' ?>>
        <h2>Result: <span id="diagnostic-live-status"><?= icct_nms_h($job['status']??'') ?></span></h2>
        <pre id="diagnostic-live-output" <?= $result?'':'hidden' ?>><?= icct_nms_h($result['output']??'') ?></pre>
        <p id="diagnostic-live-progress" <?= $job&&in_array($job['status'],['queued','running'],true)?'':'hidden' ?>>Results update automatically when the collector finishes.</p>
    </section>
</section>
