<?php
/** Saved uploads and scoped object lists share Inventory's table and pager. */
$objectList=isset($repositoryBundle);
$listUrl='protocols/snmp/mibs/controllers/mib_repository.php';
$svg=function($name){
    $paths=['edit'=>'M4 16v4h4L20 8l-4-4zM14 6l4 4','view'=>'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12zM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0',
        'delete'=>'M3 6h18M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7M14 10v7','download'=>'M12 3v12M7 10l5 5 5-5M4 15v6h16v-6'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="'.$paths[$name].'"/></svg>';
};
$filterLabels=$objectList?['module'=>'MIB Module','syntax'=>'Syntax','state'=>'Data Source Status']:['type'=>'Device Type','module'=>'MIB Module'];
if($objectList)$listBrowser=icct_mib_browser_data([$repositoryBundle]);
?>
<section class="mib-saved-list <?= $objectList?'mib-object-table-list':'' ?>" data-mib-saved-list aria-label="<?= $objectList?'MIB list':'MIB repositories' ?>">
<?php if($objectList): ?><p class="mib-list-context"><a href="<?= $listUrl ?>">MIB Repository</a> / <?= icct_nms_h($repositoryBundle['name']) ?> / MIB List <a class="button" href="<?= $listUrl ?>?browse=<?= icct_nms_h($repositoryBundle['id']) ?>">Tree View</a></p><?php endif; ?>
<div class="inventory-filters">
<?php foreach($filterLabels as $key=>$label): ?><label class="field"><span class="field-label"><?= $label ?></span><select data-list-filter="<?= $key ?>"><option value="">All</option></select></label><?php endforeach; ?>
<button type="button" class="button" data-list-clear disabled>Clear All</button>
<label class="field inventory-search"><span class="sr-only">Search <?= $objectList?'MIB name or OID':'repositories' ?></span><input type="search" data-list-search placeholder="<?= $objectList?'Search With MIB Name or OID Number':'Search With Repository or MIB Name' ?>"></label>
</div>
<div class="table-scroll" tabindex="0" role="region" aria-label="<?= $objectList?'MIB list':'MIB repository list' ?>">
<table class="action-table mib-saved-table"><thead><tr>
<th scope="col" aria-sort="ascending"><button type="button" data-list-sort><?= $objectList?'MIB Name':'Repository Name' ?> <span>↑</span></button></th>
<?php foreach($objectList?['OID','MIB Module','Syntax / Access','Data Source Status','Actions']:['Device Type','MIB Modules / Files','Objects','Uploaded','Actions'] as $heading): ?><th scope="col"><?= $heading ?></th><?php endforeach; ?>
</tr></thead><tbody>
<?php if($objectList): foreach($listBrowser['records'] as $record): ?>
<tr data-list-row data-name="<?= icct_nms_h($record['name']) ?>" data-module="<?= icct_nms_h($record['module']) ?>" data-syntax="<?= icct_nms_h($record['syntax']) ?>" data-state="<?= icct_nms_h($record['source_status']) ?>" data-search="<?= icct_nms_h(implode(' ',[$record['name'],$record['base_oid'],$record['module']])) ?>">
<td><a href="<?= $listUrl ?>?browse=<?= icct_nms_h($record['bundle']) ?>&amp;object=<?= (int)$record['object_index'] ?>"><?= icct_nms_h($record['name']) ?></a></td><td><?= icct_nms_h($record['base_oid']) ?></td><td><?= icct_nms_h($record['module']) ?></td><td><?= icct_nms_h($record['syntax']) ?><small><?= icct_nms_h($record['access']) ?></small></td><td><?= icct_nms_h($record['source_status']) ?></td>
<td><div class="row-actions"><a href="<?= $listUrl ?>?browse=<?= icct_nms_h($record['bundle']) ?>&amp;object=<?= (int)$record['object_index'] ?>" aria-label="View <?= icct_nms_h($record['name']) ?>" title="View"><?= $svg('view') ?></a><?php if($management&&$record['can_create']): ?><a href="<?= $listUrl ?>?select=<?= icct_nms_h($record['bundle']) ?>&amp;object=<?= (int)$record['object_index'] ?>" aria-label="Configure <?= icct_nms_h($record['name']) ?>" title="Configure"><?= $svg('edit') ?></a><?php endif; ?></div></td>
</tr>
<?php endforeach; else: foreach($bundles as $bundle): $id=$bundle['id'];$modules=implode(', ',array_column($bundle['files'],'module')); ?>
<tr data-list-row data-name="<?= icct_nms_h($bundle['name']) ?>" data-type="<?= icct_nms_h($bundle['type_name']) ?>" data-modules="<?= icct_nms_h(json_encode(array_column($bundle['files'],'module'),JSON_THROW_ON_ERROR)) ?>" data-module="<?= icct_nms_h($modules) ?>" data-search="<?= icct_nms_h($bundle['name'].' '.$bundle['type_name'].' '.$modules.' '.implode(' ',array_column($bundle['files'],'name'))) ?>">
<td><a href="<?= $listUrl ?>?browse=<?= icct_nms_h($id) ?>"><?= icct_nms_h($bundle['name']) ?></a><?php if(!empty($bundle['parse_error'])): ?><small class="mib-list-error"><?= icct_nms_h($bundle['parse_error']) ?></small><?php endif; ?></td>
<td><?= icct_nms_h($bundle['type_name']) ?></td><td><?= icct_nms_h($modules) ?><small><?= icct_nms_h(implode(', ',array_column($bundle['files'],'name'))) ?></small></td><td><?= (int)$bundle['object_count'] ?></td><td><?= icct_nms_h($bundle['created_at']) ?></td>
<td><div class="row-actions">
<?php if($management): ?><a href="<?= $listUrl ?>?select=<?= icct_nms_h($id) ?>" aria-label="Edit <?= icct_nms_h($bundle['name']) ?>" title="Edit"><?= $svg('edit') ?></a><?php endif; ?>
<a href="<?= $listUrl ?>?browse=<?= icct_nms_h($id) ?>" aria-label="View <?= icct_nms_h($bundle['name']) ?>" title="View"><?= $svg('view') ?></a>
<?php if($management): ?><form method="post"><?php icct_nms_token(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="bundle_id" value="<?= icct_nms_h($id) ?>"><button type="submit" class="icon-button" aria-label="Delete <?= icct_nms_h($bundle['name']) ?>" title="Delete"><?= $svg('delete') ?></button></form><?php endif; ?>
<?php foreach($bundle['files'] as $index=>$file): ?><a href="<?= $listUrl ?>?download=<?= icct_nms_h($id) ?>&amp;file=<?= (int)$index ?>" aria-label="Download <?= icct_nms_h($file['name']) ?>" title="Download <?= icct_nms_h($file['name']) ?>"><?= $svg('download') ?></a><?php endforeach; ?>
</div></td></tr>

<?php endforeach; endif; ?>
<tr data-list-empty hidden><td colspan="6"><?= $objectList?'No matching MIB objects.':'No matching repositories. Upload MIB files to add a repository.' ?></td></tr>
</tbody></table></div>
<footer class="inventory-pagination"><label>Items per page: <select data-list-size><?php foreach([10,25,50,100] as $size): ?><option><?= $size ?></option><?php endforeach; ?></select></label><span data-list-range aria-live="polite"></span><div class="page-controls"><label><span class="sr-only">Page</span><select data-list-page></select></label><span data-list-pages></span><button type="button" data-list-prev aria-label="Previous page">‹</button><button type="button" data-list-next aria-label="Next page">›</button></div></footer>
</section>
<?php if($objectList): foreach($listBrowser['warnings'] as $warning): ?><p class="notice error"><?= icct_nms_h($warning) ?></p><?php endforeach; endif; ?>
