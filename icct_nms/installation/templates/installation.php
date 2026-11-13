<?php $installationUrl = 'installation/controllers/installation.php'; ?>
<section class="topology-configuration installation-checks">
    <div class="titlebar">
        <div>
            <p>Settings / Installation</p>
            <h1>Installation</h1>
        </div>
        <?php if (!$packagesTab): ?>
            <a class="button" href="<?= $installationUrl ?>">Refresh checks</a>
        <?php endif; ?>
    </div>
    <nav
        class="installation-tabs"
        aria-label="Installation">
        <a
            href="<?= $installationUrl ?>"
            <?= !$packagesTab ? 'aria-current="page"' : '' ?>>Checks</a>
        <a
            href="<?= $installationUrl ?>?tab=packages"
            <?= $packagesTab ? 'aria-current="page"' : '' ?>>Offline Packages</a>
    </nav>

    <?php if ($packagesTab): ?>
        <?php require __DIR__ . '/packages.php'; ?>
    <?php else: ?>
        <div class="installation-table-wrap">
            <table class="action-table site-table installation-table">
                <thead>
                    <tr><th>Feature</th><th>Component</th><th>Status</th><th>Details</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($checks as $check): ?>
                        <?php $ready = in_array($check['status'], ['Ready', 'Installed'], true); ?>
                        <tr>
                            <td><?= icct_nms_h($check['feature']) ?></td>
                            <td><?= icct_nms_h($check['item']) ?></td>
                            <td><span class="device-status <?= $ready ? 'online' : 'other' ?>"><?= icct_nms_h($check['status']) ?></span></td>
                            <td><?= icct_nms_h($check['detail']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
