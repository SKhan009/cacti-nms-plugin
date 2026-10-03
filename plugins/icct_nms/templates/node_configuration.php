<?php
$deviceNodes=[]; $nodeNames=[]; $siteNames=[];
foreach($grouped['groups'] as $id=>$group) { $nodeNames[$id]=$group['node']['name']; foreach($group['devices'] as $device) $deviceNodes[(int)$device['id']]=$id; }
foreach($devices as $device) $siteNames[(int)$device['site_id']]=$device['site_name'] ?: 'Unassigned';
?>
<section class="presets-content node-configuration">
<div class="titlebar"><h1>Node Configuration</h1><?php if ($management): ?><div class="type-editor-actions"><a class="button" href="presets.php?tab=node">Manage Nodes</a><button class="button primary" type="button" id="nodeAdd" aria-expanded="<?= $error?'true':'false' ?>" aria-controls="nodeAssignment">Add</button></div><?php endif; ?></div>
<?php if ($management): ?>
<form method="post" class="node-assignment-form" id="nodeAssignment" <?= $error?'':'hidden' ?>>
<?php icct_nms_token(); ?>
<div class="titlebar"><h2>Add Devices to Node</h2><div class="type-editor-actions"><button class="button" type="button" id="nodeCancel">Cancel</button><button class="button primary" type="submit" id="nodeSave" disabled>Save</button></div></div>
<div class="node-add-grid">
<label class="field"><span class="field-label">Node Name *</span><select name="node_id" id="nodeAssignNode" required><option value="">Select node</option><?php foreach ($nodes as $node): ?><option value="<?= (int)$node['id'] ?>" data-site="<?= (int)$node['site_id'] ?>" data-site-name="<?= icct_nms_h($node['site_name']) ?>" <?= (string)($error?($_POST['node_id'] ?? ''):'')===(string)$node['id']?'selected':'' ?>><?= icct_nms_h($node['name']) ?></option><?php endforeach; ?></select></label>
<div class="field node-site-readonly"><span class="field-label">Site</span><output id="nodeAssignSite">Select node</output></div>
<div class="field node-picker"><span class="field-label" id="nodeDevicesLabel">Devices *</span><button type="button" class="node-picker-toggle" id="nodeDeviceToggle" aria-expanded="false" aria-controls="nodeDeviceOptions" aria-labelledby="nodeDevicesLabel nodeDeviceSummary" disabled><span id="nodeDeviceSummary">Select devices</span><span aria-hidden="true">⌄</span></button>
<div id="nodeDeviceOptions" class="node-picker-options" hidden><label class="field"><span class="field-label">Search devices</span><input id="nodeDeviceSearch" type="search" placeholder="Search by device name or address"></label>
<div class="node-picker-tools"><button type="button" id="nodeSelectVisible">Select visible</button><button type="button" id="nodeClearSelected">Clear</button></div>
<div id="nodeDeviceChoices"><?php foreach($devices as $device): $id=(int)$device['id']; $assigned=$deviceNodes[$id] ?? 0; ?><label class="node-device-option" data-site="<?= (int)$device['site_id'] ?>" data-node="<?= $assigned ?>" data-search="<?= icct_nms_h($device['description'].' '.$device['hostname']) ?>"><input type="checkbox" name="device_ids[]" value="<?= $id ?>" <?= $error&&in_array((string)$id,(array)($_POST['device_ids'] ?? []),true)?'checked':'' ?>><span><?= icct_nms_h($device['description']) ?><small><?= icct_nms_h($device['hostname']) ?></small><small class="node-assignment-note" data-node-name="<?= icct_nms_h($nodeNames[$assigned] ?? '') ?>"></small></span></label><?php endforeach; ?></div>
<p id="nodePickerEmpty" hidden>No matching devices.</p></div></div>
</div>
</form>
<?php endif; ?>
<div class="node-list-filters">
<label class="field"><span class="field-label">Search devices</span><input type="search" id="nodeListSearch" placeholder="Search by device, address, node or rack"></label>
<label class="field"><span class="field-label">Site</span><select id="nodeListSite"><option value="">All sites</option><?php foreach($siteNames as $id=>$name): ?><option value="<?= $id ?>"><?= icct_nms_h($name) ?></option><?php endforeach; ?></select></label>
<label class="field"><span class="field-label">Node</span><select id="nodeListNode"><option value="">All nodes</option><option value="0">Unassigned</option><?php foreach($nodes as $node): ?><option value="<?= (int)$node['id'] ?>" data-site="<?= (int)$node['site_id'] ?>"><?= icct_nms_h($node['name']) ?></option><?php endforeach; ?></select></label>
<label class="field"><span class="field-label">Status</span><select id="nodeListStatus"><option value="">All statuses</option><?php foreach(array_unique(array_column($devices,'status_label')) as $status): ?><option><?= icct_nms_h($status) ?></option><?php endforeach; ?></select></label>
</div>
<p id="nodeListCount" class="muted" aria-live="polite"><?= count($devices) ?> devices</p>
<table class="site-table" id="nodeDeviceTable"><thead><tr><th>Device Name</th><th>Address</th><th>Node</th><th>Site</th><th>Rack / Unit</th><th>Status</th><?php if($management): ?><th>Actions</th><?php endif; ?></tr></thead><tbody>
<?php foreach($devices as $device): $id=(int)$device['id'];$assigned=$deviceNodes[$id] ?? 0; ?><tr data-site="<?= (int)$device['site_id'] ?>" data-node="<?= $assigned ?>" data-status="<?= icct_nms_h($device['status_label']) ?>"><td><?= icct_nms_h($device['description']) ?></td><td><?= icct_nms_h($device['hostname']) ?></td><td><?= icct_nms_h($nodeNames[$assigned] ?? 'Unassigned') ?></td><td><?= icct_nms_h($device['site_name'] ?: 'Unassigned') ?></td><td><?= icct_nms_h($device['rack_name'] ?: 'Unassigned') ?><?php if($device['rack_id']): ?> / U<?= (int)$device['start_unit'] ?><?php endif; ?></td><td><span class="device-status <?= icct_nms_status_class($device['status_label']) ?>"><?= icct_nms_h($device['status_label']) ?></span></td><?php if($management): ?><td><?php if($assigned && !$device['rack_id'] && !isset($peripheralIds[$id])): ?><form method="post"><?php icct_nms_token(); ?><input type="hidden" name="device_id" value="<?= $id ?>"><input type="hidden" name="node_id" value="0"><button class="button" type="submit">Unassign</button></form><?php elseif($assigned): ?><span class="muted">Rack placement</span><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?>
<tr id="nodeListEmpty" hidden><td colspan="<?= $management?7:6 ?>">No matching devices.</td></tr>
</tbody></table>
<nav class="node-pagination" aria-label="Device list pages"><label>Rows per page <select id="nodePageSize"><option>5</option><option selected>10</option><option>25</option><option>50</option></select></label><button type="button" class="button" id="nodePagePrevious">Previous</button><span id="nodePageNumber" aria-live="polite"></span><button type="button" class="button" id="nodePageNext">Next</button></nav>
</section>
