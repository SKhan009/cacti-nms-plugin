<div class="presets-heading">
    <div><nav class="presets-breadcrumb" aria-label="Breadcrumb"><a href="topology.php">Dashboard</a> / <a href="presets.php">Presets</a> / <span><?= $presetTabs[$activePreset] ?></span></nav><h1>Presets</h1></div>
    <?php if ($management && $activePreset !== 'protocols'): ?><button class="button primary" type="button" id="<?= 'add-'.$activePreset ?>">Add</button><?php endif; ?>
</div>
<div class="presets-content">
<nav class="presets-tabs" aria-label="Preset sections">
<?php foreach (['Site','Rack Config','Segment','Device Type','Network Connections','Protocols'] as $tab): ?>
<?php $key=array_search($tab,$presetTabs,true); if ($key!==false): ?><a href="presets.php?tab=<?= $key ?>" <?= $activePreset===$key?'aria-current="page"':'' ?>><?= icct_nms_h($tab) ?></a><?php else: ?><button type="button" disabled><?= icct_nms_h($tab) ?></button><?php endif; ?>
<?php endforeach; ?>
</nav>
<?php if ($activePreset === 'rack-config'): require __DIR__ . '/rack_presets.php'; elseif ($activePreset === 'protocols'): require __DIR__ . '/../../protocols/shared/templates/protocol_presets.php'; elseif ($activePreset === 'site'): require __DIR__ . '/sites.php'; elseif ($activePreset === 'device-type'): require __DIR__ . '/device_types.php'; elseif ($activePreset === 'network-connections'): require __DIR__ . '/../../dashboard/topology/templates/connections.php'; else: ?>
<?php if ($management): ?>
<form method="post" class="segment-editor" id="segment-editor" <?= $editing ? '' : 'hidden' ?>>
    <?php icct_nms_token(); ?><input type="hidden" name="action" value="save_segment"><input type="hidden" name="segment_id" value="<?= (int)$segmentId ?>">
    <label class="field"><span class="field-label">Segment Name *</span><input name="segment_name" value="<?= icct_nms_h($segmentName) ?>" maxlength="150" required autocomplete="off"></label>
    <div class="segment-editor-actions"><button type="button" class="button" id="cancel-segment">Cancel</button><button class="button primary" type="submit">Save</button></div>
</form>
<?php endif; ?>
<section class="segment-grid" id="segment" aria-label="Segments">
<?php foreach ($segments as $segment): ?>
<article class="segment-card">
    <span><?= icct_nms_h($segment['name']) ?></span>
    <?php if ($management): ?><div class="segment-actions">
    <button type="button" class="icon-button" data-edit-segment="<?= (int)$segment['id'] ?>" data-segment-name="<?= icct_nms_h($segment['name']) ?>" aria-label="Edit <?= icct_nms_h($segment['name']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 16 12-12 4 4-12 12-5 1zM14 6l4 4"/></svg></button>
    <form method="post" data-delete-segment data-segment-name="<?= icct_nms_h($segment['name']) ?>"><?php icct_nms_token(); ?><input type="hidden" name="action" value="delete_segment"><input type="hidden" name="segment_id" value="<?= (int)$segment['id'] ?>"><button type="submit" class="icon-button" aria-label="Delete <?= icct_nms_h($segment['name']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6h14M9 6V3h6v3M7 6l1 15h8l1-15M10 10v7M14 10v7"/></svg></button></form>
    </div><?php endif; ?>
</article>
<?php endforeach; ?>
<?php if (!$segments): ?><p class="empty-state">No segments yet. Add a segment to get started.</p><?php endif; ?>
</section>
<?php endif; ?>
</div>
