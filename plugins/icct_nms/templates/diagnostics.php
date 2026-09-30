<?php
/** Single saved-device diagnostic selection and the actual collector result. */
?>
<div class="titlebar">
    <h1><?= icct_nms_h($host["description"]) ?> — Diagnostics</h1>
    <a class="button" href="inventory.php">Done</a>
</div>
<section class="diagnostics-page">
    <form method="post">
        <?php icct_nms_token(); ?>
        <?php icct_nms_select(
            "Diagnostic method",
            "tool",
            icct_backend_diag_available_labels(),
            $tool,
        ); ?>
        <button class="button primary" type="submit">Run Diagnostic</button>
    </form>
    <?php if ($job): ?>
        <h2>Result: <?= icct_nms_h($job["status"]) ?></h2>
        <?php if ($result): ?><pre><?= icct_nms_h(
    $result["output"] ?? "",
) ?></pre><?php endif; ?>
        <a class="button" href="diagnostics.php?host_id=<?= $id ?>&amp;tool=<?= icct_nms_h(
    $tool,
) ?>&amp;job_id=<?= (int) $job["id"] ?>">Refresh Result</a>
    <?php endif; ?>
</section>
