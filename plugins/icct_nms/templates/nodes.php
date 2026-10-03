<?php if ($management): ?>
<form method="post" id="node-editor" class="site-editor" <?= $nodeEditing?'':'hidden' ?>>
<?php icct_nms_token(); ?><input type="hidden" name="action" value="save_node"><input type="hidden" name="node_id" value="<?= icct_nms_h(is_scalar($nodeValues['node_id'] ?? '') ? ($nodeValues['node_id'] ?? '') : '') ?>">
<div class="type-editor-heading"><h2 id="node-editor-title"><?= empty($nodeValues['node_id'])?'Add':'Edit' ?> Node</h2><div><button type="button" id="cancel-node" class="button">Cancel</button><button type="submit" class="button primary">Save</button></div></div>
<div class="site-fields">
<label class="field"><span class="field-label">Node Name *</span><input name="node_name" maxlength="150" required placeholder="Enter node name" value="<?= icct_nms_h(is_string($nodeValues['node_name'] ?? '') ? ($nodeValues['node_name'] ?? '') : '') ?>"></label>
<label class="field"><span class="field-label">Site *</span><select name="site_id" required><option value="">Select site</option><?php foreach ($sites as $site): ?><option value="<?= (int)$site['id'] ?>" <?= (string)($nodeValues['site_id'] ?? '')===(string)$site['id']?'selected':'' ?>><?= icct_nms_h($site['name']) ?></option><?php endforeach; ?></select></label>
</div>
</form><?php endif; ?>
<section id="node-list" aria-label="Nodes"><label class="field site-search"><span class="field-label">Search nodes</span><input type="search" id="node-search" placeholder="Search by node name or site"></label>
<div class="site-table-wrap"><table class="site-table"><thead><tr><th>Node Name</th><th>Site</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($nodes as $node): ?><tr data-node-row data-node-search="<?= icct_nms_h(mb_strtolower($node['name'].' '.($node['site_name'] ?? ''))) ?>"><td><?= icct_nms_h($node['name']) ?></td><td><?= icct_nms_h($node['site_name'] ?? 'Site unavailable') ?></td><td><div class="node-actions">
<?php if ($management): ?><button type="button" class="icon-button" data-edit-node="<?= icct_nms_h(json_encode(['id'=>$node['id'],'name'=>$node['name'],'site_id'=>$node['site_id']],JSON_THROW_ON_ERROR)) ?>" aria-label="Edit <?= icct_nms_h($node['name']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 16 12-12 4 4-12 12-5 1zM14 6l4 4"/></svg></button>
<form method="post" data-delete-node data-node-name="<?= icct_nms_h($node['name']) ?>"><?php icct_nms_token(); ?><input type="hidden" name="action" value="delete_node"><input type="hidden" name="node_id" value="<?= (int)$node['id'] ?>"><button type="submit" class="icon-button" aria-label="Delete <?= icct_nms_h($node['name']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6h14M9 6V3h6v3M7 6l1 15h8l1-15M10 10v7M14 10v7"/></svg></button></form><?php endif; ?>
</div></td></tr><?php endforeach; ?></tbody></table></div><p id="node-empty" class="empty-state" <?= $nodes?'hidden':'' ?>>No nodes found. Add a node to get started.</p></section>
