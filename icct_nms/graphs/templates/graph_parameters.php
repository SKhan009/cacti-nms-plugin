<?php
/** Compact read-only axes and native graph item definitions. */
?>
<dl class="graph-axis-details">
<?php
foreach (["X-Axis"=>"Time", "Y-Axis"=>($graphSettings["vertical_label"] ?: "Not Set"), "Y-Axis Min"=>($graphSettings["lower_limit"] ?? "Not Set"), "Y-Axis Max"=>($graphSettings["upper_limit"] ?? "Not Set")] as $label=>$value):
?>
<div><dt><?= icct_nms_h($label) ?></dt><dd><?= icct_nms_h($value) ?></dd></div>
<?php endforeach; ?>
</dl>
<h3 class="graph-items-title"><?= icct_nms_h(($graphSettings["title_cache"] ?? "") ?: ($graphSettings["title"] ?? $template["name"])) ?></h3>
<div class="graph-items-wrap"><table class="graph-associations graph-items">
    <colgroup><col style="width:6%"><col style="width:19%"><col style="width:6%"><col style="width:6%"><col style="width:4%"><col style="width:4%"><col style="width:6%"><col style="width:18%"><col style="width:18%"><col style="width:5%"><col style="width:8%"></colgroup>
    <thead><tr><th>Graph Items</th><th>Data Source</th><th>Graph Item Type</th><th>CF Type</th><th>Min</th><th>Max</th><th>GPrint</th><th>CDEF</th><th>VDEF</th><th>Alpha %</th><th>Item Color</th></tr></thead>
    <tbody>
    <?php foreach ($graphItems as $item): ?>
        <tr><td data-label="Graph Items">Item #<?= (int)$item["sequence"] ?></td>
        <td data-label="Data Source"><?= icct_nms_h(($item["data_template_name"] ?? "").($item["data_source_name"] ? " (".$item["data_source_name"]."): " : "").$item["text_format"].($item["hard_return"] === "on" ? " <HR>" : "")) ?></td>
        <td data-label="Graph Item Type"><?= icct_nms_h($graph_item_types[$item["graph_type_id"]] ?? $item["graph_type_id"]) ?></td>
        <td data-label="CF Type"><?= icct_nms_h($consolidation_functions[$item["consolidation_function_id"]] ?? $item["consolidation_function_id"]) ?></td>
        <?php foreach (["rrd_minimum", "rrd_maximum"] as $limit): ?>
        <td data-label="<?= $limit === 'rrd_minimum' ? 'Minimum' : 'Maximum' ?>"><?= icct_nms_h(!isset($item[$limit]) || $item[$limit] === "" ? "Not set" : (strtoupper((string)$item[$limit]) === "U" ? "Not set (U)" : $item[$limit])) ?></td>
        <?php endforeach; ?>
        <td data-label="GPrint"><?= icct_nms_h(($graph_item_types[$item["graph_type_id"]] ?? "") === "GPRINT" ? ($item["gprint_name"] ?? "N/A") : "N/A") ?></td>
        <td data-label="CDEF"><?= icct_nms_h(($item["cdef_name"] ?? "") ?: "N/A") ?></td>
        <td data-label="VDEF"><?= icct_nms_h(($item["vdef_name"] ?? "") ?: "N/A") ?></td>
        <td data-label="Alpha %"><?= $item["color_hex"] ? (int)round(hexdec($item["alpha"] ?: "FF") / 255 * 100)."%" : "-" ?></td>
        <td data-label="Item Color"><?php if (preg_match('/^[0-9a-fA-F]{6}$/D', (string)($item["color_hex"] ?? ""))): ?><span class="graph-item-color" style="background-color:#<?= icct_nms_h($item["color_hex"]) ?>" aria-hidden="true"></span><?= icct_nms_h($item["color_hex"]) ?><?php else: ?>-<?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$graphItems): ?><tr><td colspan="11">No graph items configured.</td></tr><?php endif; ?>
    </tbody>
</table></div>
