<?php
/** Saved MIB repositories use Inventory's table and pager. */
$listUrl='protocols/snmp/mibs/controllers/mib_repository.php';
require_once __DIR__.'/../services/mib_usage_service.php';$fileUsage=icct_mib_repository_usage($bundles);
$svg=function($name){
    $paths=['edit'=>'M4 16v4h4L20 8l-4-4zM14 6l4 4','view'=>'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12zM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0',
        'delete'=>'M3 6h18M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7M14 10v7','download'=>'M12 3v12M7 10l5 5 5-5M4 15v6h16v-6'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="'.$paths[$name].'"/></svg>';
};
$filterLabels=['type'=>'Device Type','module'=>'MIB Module'];
?>
<section class="mib-saved-list " data-mib-saved-list aria-label="MIB repositories">

<div class="inventory-filters">
<?php foreach($filterLabels as $key=>$label): ?><label class="field"><span class="field-label"><?= $label ?></span><select data-list-filter="<?= $key ?>"><option value="">All</option></select></label><?php endforeach; ?>
<button type="button" class="button" data-list-clear disabled>Clear All</button>
<label class="field inventory-search"><span class="sr-only">Search MIB files</span><input type="search" data-list-search placeholder="Search With File or Device Name"></label>
</div>
<div class="table-scroll" tabindex="0" role="region" aria-label="MIB repository list">
<table class="action-table mib-saved-table"><thead><tr>
<th scope="col" aria-sort="ascending"><button type="button" data-list-sort>File Name <span>↑</span></button></th>
<?php foreach(['No. of OIDs','Data Sources Created','Used by Devices','Date','Actions'] as $heading): ?><th scope="col"><?= $heading ?></th><?php endforeach; ?>
</tr></thead><tbody>
<?php foreach($bundles as $bundle): $id=$bundle['id'];$modules=implode(', ',array_column($bundle['files'],'module'));$fileNames=implode(', ',array_column($bundle['files'],'name'));$usage=$fileUsage[$id]; ?>
<tr data-list-row data-name="<?= icct_nms_h($fileNames) ?>" data-type="<?= icct_nms_h($bundle['type_name']) ?>" data-modules="<?= icct_nms_h(json_encode(array_column($bundle['files'],'module'),JSON_THROW_ON_ERROR)) ?>" data-module="<?= icct_nms_h($modules) ?>" data-search="<?= icct_nms_h($fileNames.' '.$bundle['type_name'].' '.$modules.' '.implode(' ',array_column($usage['devices'],'description'))) ?>">
<td class="mib-file-name"><?php foreach($bundle['files'] as $file): ?><span><?= icct_nms_h($file['name']) ?></span><?php endforeach; ?><?php if(!empty($bundle['parse_error'])): ?><small class="mib-list-error"><?= icct_nms_h($bundle['parse_error']) ?></small><?php endif; ?></td>
<td><?= (int)$bundle['object_count'] ?></td><td><span class="mib-source-count <?= $usage['data_sources']?'has-sources':'' ?>"><?= (int)$usage['data_sources'] ?></span></td>
<td class="mib-used-devices"><?php foreach($usage['devices'] as $device): ?><a href="inventory/controllers/device.php?id=<?= (int)$device['id'] ?>&amp;view=1"><?= icct_nms_h($device['description']) ?></a><?php endforeach; ?><?php if(!$usage['devices']): ?><span>Not used</span><?php endif; ?></td><td><?= icct_nms_h($bundle['created_at']) ?></td>
<td><div class="row-actions">
<?php if($management): ?><a href="<?= $listUrl ?>?select=<?= icct_nms_h($id) ?>" aria-label="Edit <?= icct_nms_h($fileNames) ?>" title="Edit"><?= $svg('edit') ?></a><?php endif; ?>
<a href="<?= $listUrl ?>?browse=<?= icct_nms_h($id) ?>" aria-label="View <?= icct_nms_h($fileNames) ?>" title="View"><?= $svg('view') ?></a>
<?php if($management): ?><form method="post"><?php icct_nms_token(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="bundle_id" value="<?= icct_nms_h($id) ?>"><button type="submit" class="icon-button" aria-label="Delete <?= icct_nms_h($fileNames) ?>" title="Delete"><?= $svg('delete') ?></button></form><?php endif; ?>
<?php foreach($bundle['files'] as $index=>$file): ?><a href="<?= $listUrl ?>?download=<?= icct_nms_h($id) ?>&amp;file=<?= (int)$index ?>" aria-label="Download <?= icct_nms_h($file['name']) ?>" title="Download <?= icct_nms_h($file['name']) ?>"><?= $svg('download') ?></a><?php endforeach; ?>
</div></td></tr>

<?php endforeach; ?>
<tr data-list-empty hidden><td colspan="6">No matching MIB files.</td></tr>
</tbody></table></div>
<footer class="inventory-pagination"><label>Items per page: <select data-list-size><?php foreach([10,25,50,100] as $size): ?><option><?= $size ?></option><?php endforeach; ?></select></label><span data-list-range aria-live="polite"></span><div class="page-controls"><label><span class="sr-only">Page</span><select data-list-page></select></label><span data-list-pages></span><button type="button" data-list-prev aria-label="Previous page">‹</button><button type="button" data-list-next aria-label="Next page">›</button></div></footer>
</section>
