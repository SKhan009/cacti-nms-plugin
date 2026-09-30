<?php
/** Compact read-only axes and native graph item definitions. */
?>
<div class="protocol-grid cols-4 graph-parameter-grid">
<?php
icct_nms_input("X Axis", "graph_".$detail["graph_id"]."_x_axis", "Time", "text", "readonly");
icct_nms_input("Y Axis", "graph_".$detail["graph_id"]."_y_axis", $graphSettings["vertical_label"] ?: "Not set", "text", "readonly");
icct_nms_input("Y-Axis Minimum", "graph_".$detail["graph_id"]."_lower_limit", $graphSettings["lower_limit"] ?? "Not set", "text", "readonly");
icct_nms_input("Y-Axis Maximum", "graph_".$detail["graph_id"]."_upper_limit", $graphSettings["upper_limit"] ?? "Not set", "text", "readonly");
?>
</div>
<h3>Graph Items</h3>
<div class="graph-items-wrap"><table class="graph-associations graph-items">
    <colgroup><col style="width:7%"><col style="width:3%"><col style="width:25%"><col style="width:9%"><col style="width:8%"><col style="width:6%"><col style="width:7%"><col style="width:7%"><col style="width:10%"><col style="width:5%"><col style="width:5%"><col style="width:8%"></colgroup>
    <thead><tr><th>Graph Item</th><th>#</th><th>Data Source</th><th>Graph Item Type</th><th>CF Type</th><th>Minimum</th><th>Maximum</th><th>GPrint</th><th>CDEF</th><th>VDEF</th><th>Alpha %</th><th>Item Color</th></tr></thead>
    <tbody>
    <?php foreach ($graphItems as $item): ?>
        <tr><td data-label="Graph Item">Item # <?= (int)$item["sequence"] ?></td><td data-label="#"><?= (int)$item["sequence"] ?></td>
        <td data-label="Data Source"><?= icct_nms_h(($item["data_template_name"] ?? "").($item["data_source_name"] ? " (".$item["data_source_name"]."): " : "").$item["text_format"].($item["hard_return"] === "on" ? " <HR>" : "")) ?></td>
        <td data-label="Graph Item Type"><?= icct_nms_h($graph_item_types[$item["graph_type_id"]] ?? $item["graph_type_id"]) ?></td>
        <td data-label="CF Type"><?= icct_nms_h($consolidation_functions[$item["consolidation_function_id"]] ?? $item["consolidation_function_id"]) ?></td>
        <?php foreach (["rrd_minimum", "rrd_maximum"] as $limit): ?>
        <td data-label="<?= $limit === 'rrd_minimum' ? 'Minimum' : 'Maximum' ?>"><?= icct_nms_h(!isset($item[$limit]) || $item[$limit] === "" ? "Not set" : (strtoupper((string)$item[$limit]) === "U" ? "Not set (U)" : $item[$limit])) ?></td>
        <?php endforeach; ?>
        <td data-label="GPrint"><?= icct_nms_h(($graph_item_types[$item["graph_type_id"]] ?? "") === "GPRINT" ? ($item["gprint_name"] ?? "N/A") : "N/A") ?></td>
        <td data-label="CDEF"><?= icct_nms_h($item["cdef_name"] ?? "") ?></td>
        <td data-label="VDEF"><?= icct_nms_h($item["vdef_name"] ?? "") ?></td>
        <td data-label="Alpha %"><?= $item["color_hex"] ? (int)round(hexdec($item["alpha"] ?: "FF") / 255 * 100)."%" : "" ?></td>
        <td data-label="Item Color"><?php if (preg_match('/^[0-9a-fA-F]{6}$/D', (string)($item["color_hex"] ?? ""))): ?><span class="graph-item-color" style="background-color:#<?= icct_nms_h($item["color_hex"]) ?>" aria-hidden="true"></span><?= icct_nms_h($item["color_hex"]) ?><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$graphItems): ?><tr><td colspan="12">No graph items configured.</td></tr><?php endif; ?>
    </tbody>
</table></div>
