<section class="topology-configuration">
<div class="titlebar"><div><p>Settings / Installation Checks</p><h1>Installation Checks</h1></div><a class="button" href="installation/controllers/installation.php">Refresh checks</a></div>
<table class="action-table site-table"><thead><tr><th>Feature</th><th>Component</th><th>Status</th><th>Details</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($checks as $check): $installAction=icct_nms_installation_action($check); ?>
<tr><td><?= icct_nms_h($check['feature']) ?></td><td><?= icct_nms_h($check['item']) ?></td><td><span class="device-status <?= in_array($check['status'],['Ready','Installed'],true)?'online':'other' ?>"><?= icct_nms_h($check['status']) ?></span></td><td><?= icct_nms_h($check['detail']) ?></td><td><?php if($installAction): ?><button type="button" class="button primary" data-install-action="<?= icct_nms_h($installAction) ?>" data-install-component="<?= icct_nms_h($check['item']) ?>" aria-label="Install <?= icct_nms_h($check['item']) ?>">Install</button><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<dialog id="installation-dialog" aria-labelledby="installation-dialog-title">
<div class="message-heading"><h2 id="installation-dialog-title">Install component</h2><button type="button" data-install-close aria-label="Close installation">×</button></div>
<div data-install-help="packages" hidden><p>Run one option as administrator on this collector, then refresh checks.</p><p>Online</p><pre><?= icct_nms_h($command.' --online') ?></pre><p>Offline package bundle</p><pre><?= icct_nms_h($command.' --bundle /path/to/bundle') ?></pre><p>Packages already provisioned</p><pre><?= icct_nms_h($command.' --no-packages') ?></pre></div>
<?php foreach (icct_nms_installation_manifest()['optional_components'] as $id => $component): ?>
<div data-install-help="component-<?= icct_nms_h($id) ?>" hidden><p><?= icct_nms_h($component['note']) ?></p><p>Online</p><pre><?= icct_nms_h($command.' --component '.$id.' --online') ?></pre><p>Offline component bundle</p><pre><?= icct_nms_h($command.' --component '.$id.' --bundle /path/to/component-bundle') ?></pre></div>
<?php endforeach; ?>
<div data-install-help="ssh" hidden><p>The SSH terminal requires the strict-auth backend and an HTTPS address. Install the verified native bundle and configure its dedicated service using the bundled SSH guide.</p><a href="installation/controllers/installation.php?guide=ssh">SSH installation guide</a></div>
<div data-install-help="pathchar" hidden><p>Build on a matching Linux system as a regular user, or use a verified native bundle.</p><pre><?= icct_nms_h($nativeCommand.' build --component pathchar --bundle /tmp/pathchar-native') ?></pre><p>Install as administrator, then refresh checks.</p><pre><?= icct_nms_h('sudo '.$nativeCommand.' install --bundle /tmp/pathchar-native') ?></pre></div>
<div data-install-help="plugin" hidden><p>Restore the complete plugin package, then install or upgrade ICCT NMS through Cacti Plugin Management.</p><a href="<?= icct_nms_h($config['url_path'].'plugins.php') ?>">Open Plugin Management</a></div>
<div class="message-actions"><button type="button" class="button" data-install-close>Close</button><a class="button primary" href="installation/controllers/installation.php">Refresh checks</a></div>
</dialog>
</section>
<script src="installation/js/installation.js?v=<?= substr(hash_file('sha256',dirname(__DIR__).'/js/installation.js'),0,12) ?>" defer></script>
