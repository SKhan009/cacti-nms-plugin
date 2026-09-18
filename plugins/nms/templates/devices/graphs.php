<?php
/**
 * @file graphs.php
 * Render the reusable graph-template builder and template usage list from Cacti data supplied by the device controller.
 */
?><link rel="stylesheet" href="<?php print nms_h(nms_asset_url("css/nms-snmp-form.css")); ?>">
<?php
$core_base = nms_cacti_url_path();
$source_item_count = count($graph_data_template_items);
$graph_template_count = count($global_graph_templates);
$data_template_count = count(array_unique(array_column($graph_data_template_items, "data_template_id")));
$graphs_using_count = array_sum(
	array_map(
		/** Extract each template's integer graph count for the usage total. */ function ($template) {
			return (int) $template["graph_count"];
		},
		$global_graph_templates,
	),
);
?>
<section class="nms-panel nms-panel-head nms-graph-device-picker">
	<div><h2>Create graph template</h2><p>Create a reusable Cacti graph template from a data-template item. No device is selected or changed.</p></div>
	<a class="nms-panel-action" href="templates.php?section=graph">All graph templates</a>
</section>

<section class="nms-panel nms-graph-device-summary">
	<div><span>Data templates</span><strong><?php print $data_template_count; ?></strong><small>Reusable Cacti templates</small></div>
	<div><span>Data-template items</span><strong><?php print $source_item_count; ?></strong><small>Available readings</small></div>
	<div><span>Graph templates</span><strong><?php print $graph_template_count; ?></strong><small>Stored in Cacti</small></div>
	<div><span>Device graphs</span><strong><?php print $graphs_using_count; ?></strong><small>Using these templates</small></div>
	<a class="nms-panel-action" href="devices.php?tab=inventory">Manage devices</a>
</section>

<section class="nms-panel nms-standalone-graph-builder" id="graph-builder">
	<div class="nms-panel-head"><div><h2>Create a graph template from a data template</h2><p>Choose one reusable Cacti reading. NMS creates the global graph template and its graph and GPRINT items only.</p></div><a class="nms-panel-action" href="templates.php?section=source">View data source templates</a></div>
	<div class="nms-graph-builder-steps">
		<div><b>1</b><span><strong>Choose a reading</strong><small>Fetched from Cacti data templates</small></span></div>
		<div><b>2</b><span><strong>Choose its appearance</strong><small>Style, legend, size, and scale</small></span></div>
		<div><b>3</b><span><strong>Create template</strong><small>Assign it to devices separately</small></span></div>
	</div>
	<form class="nms-graph-builder-form" method="post" action="templates.php?section=graph&amp;view=builder#graph-builder">
		<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
		<input type="hidden" name="nms_action" value="create_graph_template">
		<section class="nms-graph-form-section">
			<h3>Data source and graph item</h3>
			<div class="nms-graph-section-grid">
				<label class="source"><span>Cacti data-template item</span><select class="nms-search-select" data-search-placeholder="Search by template or reading" required name="data_template_rrd_id" <?php print !$source_item_count
    	? "disabled"
    	: ""; ?>><option value=""><?php print $source_item_count
	? "Select a reusable reading from Cacti"
	: "Cacti has no reusable data-template items"; ?></option><?php foreach (
	$graph_data_template_items
	as $source_item
) {
	$source_label =
		$source_item["data_template_name"] .
		" — " .
		$source_item["data_source_name"] .
		((int) $source_item["graph_template_count"] > 0
			? " — used by " . (int) $source_item["graph_template_count"] . " template(s)"
			: " — not yet graphed"); ?><option value="<?php print (int) $source_item[
	"data_template_rrd_id"
]; ?>"><?php print nms_h($source_label); ?></option><?php
} ?></select><small><?php print $source_item_count
	? "Open the selector and search by template or reading name."
	: "Cacti has no reusable data-template items."; ?></small></label>
				<label><span>Graph item style</span><select id="nmsGraphItemStyle" name="graph_style"><?php foreach (
    	$graph_item_types
    	as $item_type_name
    ) { ?><option value="<?php print nms_h($item_type_name); ?>" <?php print $item_type_name ===
($graph_item_types[$struct_graph_item["graph_type_id"]["default"]] ?? "")
	? "selected"
	: ""; ?>><?php print nms_h(
	$item_type_name,
); ?></option><?php } ?></select><small id="nmsGraphItemHelp">Choose any graph item type supported by Cacti.</small></label>
				<?php nms_core_render_field(
    	"consolidation_function_id",
    	$struct_graph_item["consolidation_function_id"],
    	$struct_graph_item["consolidation_function_id"]["default"],
    ); ?>
				<label><span>Item color</span><select id="nmsGraphColor" name="color_id" class="nms-search-select" data-search-placeholder="Search colors by name or hex"><option value="0">None</option><?php foreach (
    	$graph_colors
    	as $color
    ) { ?><option value="<?php print (int) $color["id"]; ?>" data-hex="<?php print nms_h(
	$color["hex"],
); ?>" <?php print (int) $color["id"] === (int) $struct_graph_item["color_id"]["default"]
	? "selected"
	: ""; ?>><?php print nms_h($color["name"]); ?></option><?php } ?></select></label>
				<?php nms_core_render_field("alpha", $struct_graph_item["alpha"], $struct_graph_item["alpha"]["default"]); ?>
				<?php nms_core_render_field("cdef_id", $struct_graph_item["cdef_id"], $struct_graph_item["cdef_id"]["default"]); ?>
				<?php nms_core_render_field(
    	"gprint_id",
    	$struct_graph_item["gprint_id"],
    	$struct_graph_item["gprint_id"]["default"],
    ); ?>
				<fieldset class="nms-graph-options"><legend>Legend values</legend><div><label><input type="checkbox" name="show_current" checked><span>Current</span></label><label><input type="checkbox" name="show_minimum" checked><span>Minimum</span></label><label><input type="checkbox" name="show_average" checked><span>Average</span></label><label><input type="checkbox" name="show_maximum" checked><span>Maximum</span></label></div></fieldset>
				<label data-item-field="stack_source_id" hidden><span>Stack base data-template item</span><select name="stack_source_id" class="nms-search-select" data-search-placeholder="Search stack base readings"><option value="">Select the reading to draw below the stack</option><?php foreach (
    	$graph_data_template_items
    	as $source_item
    ) { ?><option value="<?php print (int) $source_item["data_template_rrd_id"]; ?>"><?php print nms_h(
	$source_item["data_template_name"] . " — " . $source_item["data_source_name"],
); ?></option><?php } ?></select><small>A base item is created first, followed by the selected stacked item.</small></label>
				<?php nms_core_render_field("vdef_id", $struct_graph_item["vdef_id"], $struct_graph_item["vdef_id"]["default"]); ?>
				<?php nms_core_render_field(
    	"text_format",
    	$struct_graph_item["text_format"],
    	$struct_graph_item["text_format"]["default"],
    ); ?>
				<label data-item-field="item_value" hidden><span id="nmsGraphItemValueLabel">Value</span><input name="item_value" inputmode="decimal"><small id="nmsGraphItemValueHelp"></small></label>
				<?php nms_core_render_field(
    	"line_width",
    	$struct_graph_item["line_width"],
    	$struct_graph_item["line_width"]["default"],
    ); ?>
				<?php nms_core_render_field("dashes", $struct_graph_item["dashes"], $struct_graph_item["dashes"]["default"]); ?>
				<?php nms_core_render_field(
    	"dash_offset",
    	$struct_graph_item["dash_offset"],
    	$struct_graph_item["dash_offset"]["default"],
    ); ?>
				<?php nms_core_render_field(
    	"textalign",
    	$struct_graph_item["textalign"],
    	$struct_graph_item["textalign"]["default"],
    ); ?>
				<label data-item-field="hard_return"><span>Insert hard return</span><select name="hard_return"><option value="">No</option><option value="on">Yes</option></select></label>
				<label data-item-field="shift"><span>Shift data</span><select name="shift"><option value="">No</option><option value="on">Yes</option></select></label>
				<label data-item-field="shift_seconds" hidden><span>Shift seconds</span><input name="shift_seconds" type="number" step="1" value="0"></label>
			</div>
		</section>
		<section class="nms-graph-form-section">
			<h3>Graph template</h3>
			<div class="nms-graph-section-grid">
				<label><span>Graph template name <em>optional</em></span><input name="graph_name" maxlength="<?php print (int) $fields_graph_template_template_edit[
    	"name"
    ]["max_length"]; ?>" placeholder="Automatic from data template"></label>
				<?php foreach (["multiple", "test_source"] as $field_name) {
    	$field = $fields_graph_template_template_edit[$field_name];
    	nms_core_render_field(
    		$field_name,
    		$field,
    		isset_request_var($field_name) ? get_nfilter_request_var($field_name) : $field["default"] ?? "",
    	);
    } ?>
			</div>
		</section>
		<?php
  // Every option section and default comes from this installation's native graph editor.
  $section_open = false;
  $submitted_graph =
  	$_SERVER["REQUEST_METHOD"] === "POST" && get_nfilter_request_var("nms_action") === "create_graph_template";
  foreach ($struct_graph as $field_name => $field) {
  	if ($field["method"] === "spacer") {
  		if ($section_open) {
  			print "</div></section>";
  		}
  		print '<section class="nms-graph-form-section"><h3>' .
  			nms_h($field["friendly_name"]) .
  			'</h3><div class="nms-graph-section-grid">';
  		$section_open = true;
  		continue;
  	}
  	$value = $submitted_graph
  		? (isset_request_var($field_name)
  			? get_nfilter_request_var($field_name)
  			: "")
  		: $field["default"] ?? "";
  	// Reject array-shaped input on save, but never echo it back as an HTML value.
  	nms_core_render_field($field_name, $field, is_scalar($value) ? $value : "");
  }
  if ($section_open) {
  	print "</div></section>";
  }
  ?>
		<div class="nms-graph-submit"><button type="submit" <?php print !$source_item_count
  	? "disabled"
  	: ""; ?>>Create graph template in Cacti</button></div>
	</form>
</section>

	<section class="nms-panel nms-standalone-graph-list" id="graph-template-list">
	<div class="nms-panel-head"><div><h2>Current graph templates</h2><p>All reusable graph templates and their device-graph usage counts read directly from Cacti.</p></div><a class="nms-panel-action" href="templates.php?section=graph">Open graph templates</a></div>
	<div class="nms-association-table nms-global-graph-table">
		<div class="nms-association-row heading"><span>Graph template</span><span>ID</span><span>Graphs using</span><span>Size</span><span>Format</span><span>Vertical label</span><span>Action</span></div>
		<?php if (!$global_graph_templates) { ?><div class="nms-association-empty">Cacti has no graph templates.</div><?php } ?>
			<?php foreach (
   	$global_graph_templates
   	as $graph_template
   ) { ?><div class="nms-association-row"><strong><?php print nms_h(
	$graph_template["name"],
); ?></strong><span><?php print (int) $graph_template["id"]; ?></span><span><?php print (int) $graph_template[
	"graph_count"
]; ?></span><span><?php print (int) $graph_template["width"]; ?> × <?php print (int) $graph_template[
 	"height"
 ]; ?></span><span><?php print nms_h(
	$image_types[$graph_template["image_format_id"]] ?? "Unknown core format",
); ?></span><span><?php print nms_h(
	$graph_template["vertical_label"] ?: "Not set",
); ?></span><span class="nms-template-actions"><a href="<?php print nms_h(
	"templates.php?section=graph&core=" .
		rawurlencode("graph_templates.php?action=template_edit&id=" . (int) $graph_template["id"]),
); ?>">Open</a><?php if (
	!empty($graph_template["nms_deletable"]) &&
	(int) $graph_template["graph_count"] === 0
) { ?><form method="post" action="templates.php?section=graph&amp;view=builder#graph-template-list" onsubmit="return confirm('Permanently delete this unused NMS-created graph template?');"><input type="hidden" name="__csrf_magic" value="<?php print nms_h(
	$nms_csrf_token,
); ?>"><input type="hidden" name="nms_action" value="delete_graph_template"><input type="hidden" name="graph_template_id" value="<?php print (int) $graph_template[
	"id"
]; ?>"><button class="nms-delete-x" type="submit" aria-label="Delete <?php print nms_h(
	$graph_template["name"],
); ?>" title="Delete unused NMS-created template">×</button></form><?php } ?></span></div><?php } ?>
	</div>
</section>
