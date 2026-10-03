<?php if ($management): ?>
<form method="post" class="connection-editor" id="connection-editor" <?= $connectionEditing?'':'hidden' ?>>
<?php icct_nms_token(); ?><input type="hidden" name="action" value="save_connection"><input type="hidden" name="connection_id" value="<?= icct_nms_h($connectionValues['connection_id'] ?? '') ?>">
<h2 id="connection-editor-title"><?= empty($connectionValues['connection_id'])?'Add':'Edit' ?> Network Connection</h2>
<div class="connection-fields">
<label class="field"><span class="field-label">Connection Name *</span><input name="connection_name" maxlength="150" required value="<?= icct_nms_h($connectionValues['connection_name'] ?? '') ?>"></label>
<div class="field connection-color-field"><span class="field-label">Color</span><input type="hidden" name="color" value="<?= icct_nms_h($connectionValues['color'] ?? '') ?>"><label class="connection-color-control"><input type="color" id="connection-color-picker" aria-label="Select color" value="<?= icct_nms_h($connectionValues['color'] ?? '#00bfae') ?>"><span id="connection-color-label">Select color</span></label></div>
<?php foreach (['line_style'=>'Line Style','symbol'=>'Endpoint Symbol'] as $field=>$label): ?>
<div class="field connection-choice"><span class="field-label"><?= $label ?></span><input type="hidden" name="<?= $field ?>" value="<?= icct_nms_h($connectionValues[$field] ?? '') ?>"><details data-connection-choice="<?= $field ?>"><summary><span data-choice-label>Select <?= strtolower($label) ?></span></summary><div class="connection-options" role="group" aria-label="<?= $label ?>">
<?php foreach (($field==='line_style' ? array_keys(icct_nms_connection_styles()) : ['none','circle','square','arrow']) as $choice): ?>
<button type="button" data-connection-value="<?= $choice ?>"><?= icct_nms_connection_preview(['name'=>ucwords(str_replace('-',' ',$choice)),'color'=>'#333333','line_style'=>$field==='line_style'?$choice:'solid','symbol'=>$field==='symbol'?$choice:'none']) ?><span><?= icct_nms_h(ucwords(str_replace('-',' ',$choice))) ?></span></button>
<?php endforeach; ?></div></details></div>
<?php endforeach; ?>
</div><div id="connection-editor-preview"><?= icct_nms_connection_preview(['name'=>'Connection','color'=>$connectionValues['color'] ?? '#00bfae','line_style'=>$connectionValues['line_style'] ?? 'dotted','symbol'=>$connectionValues['symbol'] ?? 'circle']) ?></div>
<div class="type-editor-actions"><button type="button" class="button" id="cancel-connection">Cancel</button><button type="submit" class="button primary">Save</button></div>
</form>
<?php endif; ?>
<section class="segment-grid connection-grid" aria-label="Network connections">
<?php foreach ($connections as $id=>$connection): ?>
<article class="segment-card connection-card"><div class="connection-caption"><span><?= icct_nms_h($connection['name']) ?></span><?= icct_nms_connection_preview($connection) ?></div>
<?php if ($management): ?><div class="segment-actions">
<button type="button" class="icon-button" data-edit-connection="<?= icct_nms_h(json_encode(['connection_id'=>$id]+$connection,JSON_THROW_ON_ERROR)) ?>" aria-label="Edit <?= icct_nms_h($connection['name']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 16 12-12 4 4-12 12-5 1zM14 6l4 4"/></svg></button>
<form method="post" data-delete-connection data-connection-name="<?= icct_nms_h($connection['name']) ?>"><?php icct_nms_token(); ?><input type="hidden" name="action" value="delete_connection"><input type="hidden" name="connection_id" value="<?= icct_nms_h($id) ?>"><button class="icon-button" aria-label="Delete <?= icct_nms_h($connection['name']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6h14M9 6V3h6v3M7 6l1 15h8l1-15M10 10v7M14 10v7"/></svg></button></form>
</div><?php endif; ?></article>
<?php endforeach; ?>
<?php if (!$connections): ?><p class="empty-state">No network connections yet. Add one to get started.</p><?php endif; ?>
</section>
