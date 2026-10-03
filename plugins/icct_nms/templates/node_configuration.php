<section class="presets-content">
<div class="titlebar"><h1>Node Configuration</h1><?php if ($management): ?><a class="button primary" href="presets.php?tab=node">Manage Nodes</a><?php endif; ?></div>
<?php if ($management): ?>
<form method="post" class="node-assignment-form">
<?php icct_nms_token(); ?>
<h2>Assign Device to Node</h2>
<div class="form-grid">
<label class="field"><span class="field-label">Device *</span><select name="device_id" required><option value="">Select device</option><?php foreach ($devices as $device): if (!empty($device['rack_id'])) continue; ?><option value="<?= (int)$device['id'] ?>"><?= icct_nms_h($device['description'].' — '.($device['site_name'] ?: 'No site')) ?></option><?php endforeach; ?></select></label>
<label class="field"><span class="field-label">Node Name</span><select name="node_id"><option value="0">Unassigned</option><?php foreach ($nodes as $node): ?><option value="<?= (int)$node['id'] ?>"><?= icct_nms_h($node['name'].' — '.$node['site_name']) ?></option><?php endforeach; ?></select></label>
<div class="type-editor-actions"><button class="button primary" type="submit">Save</button></div>
</div><p class="muted">Select a node at the device’s site. Devices placed in a rack automatically belong to that rack’s node.</p>
</form>
<?php endif; ?>
<?php $sections=array_values($grouped['groups']); $sections[]=['node'=>['name'=>'Unassigned Devices','site_name'=>''],'devices'=>$grouped['unassigned']]; foreach ($sections as $group): ?>
<section class="node-device-group">
<h2><?= icct_nms_h($group['node']['name']) ?> <small><?= icct_nms_h($group['node']['site_name']) ?> · <?= count($group['devices']) ?> devices</small></h2>
<?php if (!$group['devices']): ?><p>No devices assigned to this node.</p><?php else: ?>
<table class="site-table"><thead><tr><th>Device Name</th><th>Address</th><th>Site</th><th>Rack / Unit</th><th>Status</th></tr></thead><tbody>
<?php foreach ($group['devices'] as $device): ?><tr><td><a href="device.php?id=<?= (int)$device['id'] ?>"><?= icct_nms_h($device['description']) ?></a></td><td><?= icct_nms_h($device['hostname']) ?></td><td><?= icct_nms_h($device['site_name'] ?: 'Unassigned') ?></td><td><?= icct_nms_h($device['rack_name'] ?: 'Unassigned') ?><?php if ($device['rack_id']): ?> / U<?= (int)$device['start_unit'] ?><?php endif; ?></td><td><?= icct_nms_h($device['status_label']) ?></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?></section>
<?php endforeach; ?>
</section>
