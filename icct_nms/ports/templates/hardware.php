<?php $hardware = icct_nms_hardware_view($old); ?>
<h3>Hardware ports and devices</h3>
<p>USB, serial, PCI/PCIe and display connectors are collected from the device OS using SSH or local collection. Network adapters appear in the interface list above. Network link alarm settings apply to network interfaces only.</p>
<p role="status"><?= icct_nms_h($hardware['message']) ?> <?= icct_nms_h($hardware['source']) ?></p>
<div class="site-table-wrap">
    <table class="site-table">
        <thead><tr><th>Type</th><th>Port / device</th><th>Description</th><th>Observed state</th></tr></thead>
        <tbody>
        <?php foreach ($hardware['ports'] as $item): ?>
            <tr><?php foreach (['type', 'name', 'description', 'status'] as $key): ?>
                <td><?= icct_nms_h($item[$key] ?? '') ?><?= $key === 'status' && !$hardware['fresh'] ? ' (stale)' : '' ?></td>
            <?php endforeach; ?></tr>
        <?php endforeach; ?>
        <?php if (!$hardware['ports']): ?><tr><td colspan="4">No hardware inventory reported. Empty sockets and unsupported drivers may not expose inventory.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
