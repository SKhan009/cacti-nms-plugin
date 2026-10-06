<?php
$segmentNames = array_column($segments,'name','id');
$sort = ($_GET['sort'] ?? '')==='desc' ? 'desc' : 'asc';
uasort($deviceTypes,static function($a,$b) use($sort) { $cmp=strnatcasecmp($a['name'],$b['name']) ?: ($a['category_id'] <=> $b['category_id']); return $sort==='desc' ? -$cmp : $cmp; });
$perPage = in_array((int)($_GET['per_page'] ?? 10),[10,25,50],true) ? (int)($_GET['per_page'] ?? 10) : 10;
$total=count($deviceTypes); $pages=max(1,(int)ceil($total/$perPage));
$page=max(1,min($pages,(int)($_GET['page'] ?? 1)));
$rows=array_slice($deviceTypes,($page-1)*$perPage,$perPage,true);
$pageUrl=static fn($n)=>'presets/controllers/presets.php?tab=device-type&per_page='.$perPage.'&sort='.$sort.'&page='.$n;
?>
<?php if ($management): ?>
<form method="post" enctype="multipart/form-data" class="device-type-editor" id="device-type-editor" <?= $typeEditing?'':'hidden' ?>>
<?php icct_nms_token(); ?><input type="hidden" name="action" value="save_type"><input type="hidden" name="type_id" value="<?= icct_nms_h($typeValues['type_id'] ?? '') ?>">
<h2 id="type-editor-title"><?= empty($typeValues['type_id'])?'Add Device Type':'Edit Device Type' ?></h2>
<div class="form-grid">
<label class="field"><span class="field-label">Device Type Name *</span><input name="type_name" maxlength="150" required placeholder="Enter Device Type Name" value="<?= icct_nms_h($typeValues['type_name'] ?? '') ?>"></label>
<label class="field"><span class="field-label">Segment</span><select name="category_id"><option value="0">None</option><?php foreach ($segments as $segment): ?><option value="<?= (int)$segment['id'] ?>" <?= (int)($typeValues['category_id'] ?? 0)===(int)$segment['id']?'selected':'' ?>><?= icct_nms_h($segment['name']) ?></option><?php endforeach; ?></select></label>
<div class="field icon-picker"><span class="field-label" id="icon-picker-label">Icon</span><input type="hidden" name="icon" value="<?= icct_nms_h($typeValues['icon'] ?? '') ?>"><button type="button" id="icon-picker-toggle" aria-haspopup="listbox" aria-expanded="false" aria-controls="icon-picker-grid"><img id="device-type-icon-preview" alt="" hidden><span id="icon-picker-value">Select Icon</span><span aria-hidden="true">⌄</span></button><div id="icon-picker-grid" role="listbox" aria-labelledby="icon-picker-label" hidden>
<?php foreach (icct_nms_type_icons() as $icon=>$label): ?><button type="button" role="option" aria-label="<?= icct_nms_h($label) ?>" aria-selected="<?= ($typeValues['icon'] ?? '')===$icon?'true':'false' ?>" data-icon="<?= icct_nms_h($icon) ?>" data-icon-label="<?= icct_nms_h($label) ?>" data-icon-asset="<?= icct_nms_h(icct_nms_type_icon_asset($icon)) ?>" title="<?= icct_nms_h($label) ?>"><img src="<?= icct_nms_h(icct_nms_type_icon_asset($icon)) ?>" alt=""></button><?php endforeach; ?>
</div></div>
<label class="field"><span class="field-label">No. of Ports</span><input name="physical_ports" type="number" min="0" max="65535" placeholder="Optional (0–65535)" value="<?= icct_nms_h($typeValues['physical_ports'] ?? '') ?>"></label>
</div>
<section class="type-shape-section"><h3>Device Shape</h3><fieldset class="type-shape-options"><legend class="sr-only">Device Shape</legend>
<?php foreach(icct_nms_type_shapes() as $shape=>$label): ?><label class="type-shape-choice"><input type="radio" name="shape" value="<?= $shape ?>" <?= ($typeValues['shape']??'rectangle')===$shape?'checked':'' ?>><span class="device-shape-symbol shape-<?= $shape ?>" aria-hidden="true"></span><span><?= $label ?></span></label><?php endforeach; ?>
</fieldset></section>
<section class="type-image-upload"><h3>Device Type Image</h3><p>Maximum file size: 500 KB. Supported formats: .jpg, .png, .webp.</p><label class="button primary type-upload-button">Upload <span aria-hidden="true">+</span><input type="file" name="device_image" accept="image/png,image/jpeg,image/webp" aria-label="Upload device type image"></label><span id="type-upload-name"></span><img id="type-upload-preview" alt="Device type image preview" hidden><label class="enable-field" id="type-remove-image"><input type="checkbox" name="remove_image" value="1" <?= empty($typeValues['remove_image'])?'':'checked' ?>>Remove saved image</label></section>
<section class="type-visibility" aria-label="Topology visibility"><h3>Visibility</h3><div class="form-grid">
<?php foreach (['network'=>'Network Topology','rack'=>'Rack View','map'=>'Map View'] as $view=>$label): ?><label class="field"><span class="field-label"><?= $label ?></span><select name="display_<?= $view ?>"><option value="">Select display type</option><?php foreach (['none'=>'None','icon'=>'Icon','image'=>'Image'] as $mode=>$text): ?><option value="<?= $mode ?>" <?= ($typeValues['display_'.$view] ?? '')===$mode?'selected':'' ?>><?= $text ?></option><?php endforeach; ?></select></label><?php endforeach; ?>
</div></section>
<div class="type-editor-actions"><button type="button" class="button" id="cancel-device-type">Cancel</button><button type="submit" class="button primary" id="save-device-type"><?= empty($typeValues['type_id'])?'Add':'Save' ?></button></div>
</form>
<?php endif; ?>
<div class="device-types-list">
<table class="action-table device-types-table"><thead><tr><th><a href="<?= icct_nms_h('presets/controllers/presets.php?tab=device-type&per_page='.$perPage.'&sort='.($sort==='asc'?'desc':'asc')) ?>" aria-label="Sort device types <?= $sort==='asc'?'descending':'ascending' ?>">Device Type Name &amp; Segment <span aria-hidden="true"><?= $sort==='asc'?'↑':'↓' ?></span></a></th><th>Icon</th><th>Device Shape</th><th>No. of Ports</th><th>Device Type Image</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($rows as $id=>$type): ?>
<tr><td><?= icct_nms_h($type['name']) ?><small><?= icct_nms_h($segmentNames[$type['category_id']] ?? 'None') ?></small></td>
<td><img class="type-icon" src="<?= icct_nms_h(icct_nms_type_asset($type, 'catalogue-icon') ) ?>" alt="<?= icct_nms_h(icct_nms_type_icons()[$type['icon']] ?? 'Device') ?>"></td>
<td><span class="type-shape-summary"><span class="device-shape-symbol shape-<?= icct_nms_type_shape($type) ?>" aria-hidden="true"></span><?= icct_nms_type_shapes()[icct_nms_type_shape($type)] ?></span></td>
<td><?= $type['physical_ports']===null?'Not set':(int)$type['physical_ports'] ?></td>
<td><?php $image=icct_nms_type_asset(array_replace($type,['display_modes'=>['network'=>'image'] ])); if (!empty($type['image']) && str_contains($image,'/uploads/')): ?><img class="type-thumbnail" src="<?= icct_nms_h($image) ?>" alt="<?= icct_nms_h($type['name']) ?>"><?php else: ?><span class="type-no-image">None</span><?php endif; ?></td>
<td><?php if ($management): ?><div class="type-actions">
<button type="button" class="icon-button primary" data-edit-type="<?= icct_nms_h(json_encode(['type_id'=>$id]+$type,JSON_THROW_ON_ERROR)) ?>" aria-label="Edit <?= icct_nms_h($type['name']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 16 12-12 4 4-12 12-5 1zM14 6l4 4"/></svg></button>
<form method="post" data-delete-type data-type-name="<?= icct_nms_h($type['name']) ?>"><?php icct_nms_token(); ?><input type="hidden" name="action" value="delete_type"><input type="hidden" name="type_id" value="<?= icct_nms_h($id) ?>"><button class="icon-button primary" aria-label="Delete <?= icct_nms_h($type['name']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6h14M9 6V3h6v3M7 6l1 15h8l1-15M10 10v7M14 10v7"/></svg></button></form>
</div><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6">No device types yet. Add a device type to get started.</td></tr><?php endif; ?>
</tbody></table>
<footer class="type-pagination"><form method="get"><input type="hidden" name="tab" value="device-type"><input type="hidden" name="sort" value="<?= $sort ?>"><label>Items per page: <select name="per_page" aria-label="Items per page"><?php foreach ([10,25,50] as $size): ?><option <?= $perPage===$size?'selected':'' ?>><?= $size ?></option><?php endforeach; ?></select></label><button type="submit">Apply</button></form><span><?= $total ? (($page-1)*$perPage+1).'–'.min($total,$page*$perPage) : '0' ?> of <?= $total ?> items</span><span class="type-page-count"><?= $page ?> of <?= $pages ?> pages</span><a href="<?= icct_nms_h($pageUrl(max(1,$page-1))) ?>" aria-label="Previous page" <?= $page===1?'aria-disabled="true" tabindex="-1"':'' ?>>‹</a><a href="<?= icct_nms_h($pageUrl(min($pages,$page+1))) ?>" aria-label="Next page" <?= $page===$pages?'aria-disabled="true" tabindex="-1"':'' ?>>›</a></footer>
</div>
