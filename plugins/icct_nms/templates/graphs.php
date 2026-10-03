<?php
/** Native graph template associations, without graph image previews. */
?>
<section id="device-graphs" class="graphs-page" hidden>
    <div class="graph-add-control">
    <button type="button" class="add-graph-button" aria-expanded="false" aria-controls="graph-add-panel">+ Add Graph</button>
    <div id="graph-add-panel" hidden>
    <form method="post" class="graph-template-add">
        <?php icct_nms_token(); ?><input type="hidden" name="action" value="add_graph_template"/>
        <label class="field"><span class="field-label">Add Graph Template</span><span class="select-wrap"><select name="graph_template_id" data-searchable-template="Graph Template" required <?= $availableGraphTemplates
            ? ""
            : "disabled" ?>><option value="">Select Graph Template</option><?php foreach (
    $availableGraphTemplates
    as $template
): ?><option value="<?= (int) $template["id"] ?>"><?= icct_nms_h(
    $template["name"],
) ?></option><?php endforeach; ?></select></span></label>
        <button class="button primary" <?= $availableGraphTemplates
            ? ""
            : "disabled" ?>>Add</button>
    </form>
    </div>
    </div>
    <div class="graph-accordion-list">
        <?php foreach ($graphAssociations as $index => $template): ?>
        <details class="graph-association">
            <summary>
                <span class="graph-association-name"><?= icct_nms_h($template["name"]) ?></span>
                <span class="graph-badges">
                    <?php if (!empty($template["device_template_name"])): ?><span class="graph-badge"><?= icct_nms_h($template["device_template_name"]) ?></span><?php endif; ?>
                    <span class="graph-badge">System Defined</span>
                </span>
                <form method="post" data-remove-graph-template data-template-name="<?= icct_nms_h($template["name"]) ?>">
                    <?php icct_nms_token(); ?><input type="hidden" name="action" value="remove_graph_template"/><input type="hidden" name="graph_template_id" value="<?= (int)$template["id"] ?>"/>
                    <button class="icon-button" type="submit" aria-label="Remove <?= icct_nms_h($template["name"]) ?> association" data-tooltip="Remove this template association. Existing graphs and data remain saved."><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6h14M9 6V3h6v3M7 6l1 15h8l1-15M10 10v7M14 10v7"/></svg></button>
                </form>
            </summary>
            <div class="graph-association-content">
                <?php foreach ($template["details"] ?: [$template["parameters"]] as $detail): ?>
                    <?php $graphSettings = $detail["settings"]; $graphItems = $detail["items"]; ?>
                    <h3><?= icct_nms_h(($graphSettings["title_cache"] ?? "") ?: ($graphSettings["title"] ?? $template["name"])) ?></h3>
                    <?php include __DIR__ . "/graph_parameters.php"; ?>
                <?php endforeach; ?>
            </div>
        </details>
        <?php endforeach; ?>
        <?php if (!$graphAssociations): ?><p class="empty-state">No associated graph templates.</p><?php endif; ?>
    </div>
</section>
