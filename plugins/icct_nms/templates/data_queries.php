<?php /** Step 5: native query associations and cache status. */ ?>
<section id="device-data-queries" class="graphs-page data-queries-page" hidden>
    <h2>Associated Data Queries</h2>
    <form method="post" class="data-query-add">
        <?php icct_nms_token(); ?><input type="hidden" name="action" value="add_data_query" />
        <label class="field"><span class="field-label">Add Data Query</span><span class="select-wrap"><select name="snmp_query_id" data-searchable-template="Data Query" required <?= $availableDataQueries ? '' : 'disabled' ?>><option value="">Select Data Query</option><?php foreach ($availableDataQueries as $query): ?><option value="<?= (int)$query['id'] ?>"><?= icct_nms_h($query['name']) ?></option><?php endforeach; ?></select></span></label>
        <label class="field"><span class="field-label">Re-Index Method</span><span class="select-wrap"><select name="reindex_method"><?php foreach ($dataQueryMethods as $key=>$label): ?><option value="<?= (int)$key ?>" <?= (int)$key === $defaultDataQueryMethod ? 'selected' : '' ?>><?= icct_nms_h($label) ?></option><?php endforeach; ?></select></span></label>
        <button class="button primary" <?= $availableDataQueries ? '' : 'disabled' ?>>Add</button>
    </form>
    <div class="table-scroll"><table class="data-query-table">
        <thead><tr><th>Data Query Name</th><th>Re-Index Method</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody><?php foreach ($dataQueryAssociations as $index=>$query): ?>
            <tr><td><?= $index+1 ?>) <?= icct_nms_h($query['name']) ?></td>
                <td><form method="post" class="query-method-form"><?php icct_nms_token(); ?><input type="hidden" name="action" value="change_data_query" /><input type="hidden" name="snmp_query_id" value="<?= (int)$query['id'] ?>" />
                    <fieldset class="query-methods" aria-label="Re-index method for <?= icct_nms_h($query['name']) ?>"><?php foreach ($dataQueryMethods as $key=>$label): ?><label data-tooltip="<?= icct_nms_h($reindex_types_tips[$key] ?? $label) ?>"><input type="radio" data-query-method name="reindex_method" value="<?= (int)$key ?>" <?= (int)$key === (int)$query['reindex_method'] ? 'checked' : '' ?> /><span><?= icct_nms_h($label) ?></span></label><?php endforeach; ?></fieldset>
                </form></td>
                <td><span class="<?= (int)$query['item_count'] ? 'graph-status' : 'query-empty' ?>"><?= (int)$query['item_count'] ? 'Success' : 'No cached data' ?></span> [<?= (int)$query['item_count'] ?> Items, <?= (int)$query['row_count'] ?> Rows]</td>
                <td><div class="query-actions"><?php foreach (['reload_data_query'=>['Reload Query','↻'], 'verbose_data_query'=>['Verbose Query','↻'], 'remove_data_query'=>['Remove Query','×']] as $action=>$button): ?>
                    <form method="post" <?= $action === 'remove_data_query' ? 'data-remove-data-query' : '' ?> data-query-name="<?= icct_nms_h($query['name']) ?>"><?php icct_nms_token(); ?><input type="hidden" name="action" value="<?= $action ?>" /><input type="hidden" name="snmp_query_id" value="<?= (int)$query['id'] ?>" /><button class="icon-button query-<?= $action ?>" aria-label="<?= $button[0] ?>: <?= icct_nms_h($query['name']) ?>" data-tooltip="<?= $button[0] ?>"><?= $button[1] ?></button></form>
                <?php endforeach; ?></div></td>
            </tr>
        <?php endforeach; ?><?php if (!$dataQueryAssociations): ?><tr><td colspan="4" class="empty-state">No associated data queries.</td></tr><?php endif; ?></tbody>
    </table></div>
</section>
