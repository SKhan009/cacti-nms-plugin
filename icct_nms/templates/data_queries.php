<?php /** Step 5: native query associations and cache status. */ ?>
<section id="device-data-queries" class="graphs-page data-queries-page" hidden>
    <div class="data-query-add-control">
    <button type="button" class="add-data-query-button" aria-expanded="false" aria-controls="data-query-add-panel">+ Add Data Query</button>
    <div id="data-query-add-panel" hidden>
    <form method="post" class="data-query-add">
        <?php icct_nms_token(); ?><input type="hidden" name="action" value="add_data_query" />
        <label class="field"><span class="field-label">Add Data Query</span><span class="select-wrap"><select name="snmp_query_id" data-searchable-template="Data Query" required <?= $availableDataQueries ? '' : 'disabled' ?>><option value="">Select Data Query</option><?php foreach ($availableDataQueries as $query): ?><option value="<?= (int)$query['id'] ?>"><?= icct_nms_h($query['name']) ?></option><?php endforeach; ?></select></span></label>
        <label class="field"><span class="field-label">Re-Index Method</span><span class="select-wrap"><select name="reindex_method"><?php foreach ($dataQueryMethods as $key=>$label): ?><option value="<?= (int)$key ?>" <?= (int)$key === $defaultDataQueryMethod ? 'selected' : '' ?>><?= icct_nms_h($label) ?></option><?php endforeach; ?></select></span></label>
        <button class="button primary" <?= $availableDataQueries ? '' : 'disabled' ?>>Add</button>
    </form>
    </div>
    </div>
    <div class="table-scroll"><table class="action-table data-query-table" aria-label="Associated Data Queries">
        <colgroup><col style="width:34%"><col style="width:30%"><col style="width:25%"><col style="width:11%"></colgroup>
        <tbody><?php foreach ($dataQueryAssociations as $index=>$query): ?>
            <tr><td><span class="query-number"><?= $index+1 ?>)</span> <?= icct_nms_h($query['name']) ?></td>
                <td><form method="post" class="query-method-form"><?php icct_nms_token(); ?><input type="hidden" name="action" value="change_data_query" /><input type="hidden" name="snmp_query_id" value="<?= (int)$query['id'] ?>" />
                    <fieldset class="query-methods" aria-label="Re-index method for <?= icct_nms_h($query['name']) ?>"><?php foreach ($dataQueryMethods as $key=>$label): ?><label data-tooltip="<?= icct_nms_h($reindex_types_tips[$key] ?? $label) ?>"><input type="radio" data-query-method name="reindex_method" value="<?= (int)$key ?>" <?= (int)$key === (int)$query['reindex_method'] ? 'checked' : '' ?> /><span><?= icct_nms_h([0=>'None',1=>'Uptime',2=>'Index Count',3=>'Verify All'][$key] ?? $label) ?></span></label><?php endforeach; ?></fieldset>
                </form></td>
                <td><span class="<?= (int)$query['item_count'] ? 'graph-status' : 'query-empty' ?>"><?= (int)$query['item_count'] ? 'Success' : 'No cached data' ?></span> [<?= (int)$query['item_count'] ?> Items, <?= (int)$query['row_count'] ?> Rows]</td>
                <td><div class="query-actions"><?php foreach (['reload_data_query'=>['Reload Query','↻'], 'verbose_data_query'=>['Verbose Query','↻'], 'remove_data_query'=>['Remove Query','×']] as $action=>$button): ?>
                    <form method="post" <?= $action === 'remove_data_query' ? 'data-remove-data-query' : '' ?> data-query-name="<?= icct_nms_h($query['name']) ?>"><?php icct_nms_token(); ?><input type="hidden" name="action" value="<?= $action ?>" /><input type="hidden" name="snmp_query_id" value="<?= (int)$query['id'] ?>" /><button class="icon-button query-<?= $action ?>" aria-label="<?= $button[0] ?>: <?= icct_nms_h($query['name']) ?>" data-tooltip="<?= $button[0] ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><?php if ($action === 'remove_data_query'): ?><path d="M5 6h14M9 6V3h6v3M7 6l1 15h8l1-15M10 10v7M14 10v7"/><?php else: ?><path d="M20 7a8 8 0 0 0-14-2L3 8m0-5v5h5M4 17a8 8 0 0 0 14 2l3-3m0 5v-5h-5"/><?php endif; ?></svg></button></form>
                <?php endforeach; ?></div></td>
            </tr>
        <?php endforeach; ?><?php if (!$dataQueryAssociations): ?><tr><td colspan="4" class="empty-state">No associated data queries.</td></tr><?php endif; ?></tbody>
    </table></div>
</section>
