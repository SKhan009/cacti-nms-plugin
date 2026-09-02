<link rel="stylesheet" href="<?php print nms_h($nms_asset_base . 'css/nms-snmp-form.css?v=1.9.13'); ?>">
<?php
$core_base = $config['url_path'];
$source_item_count = count($graph_data_template_items);
$graph_template_count = count($global_graph_templates);
$data_template_count = count(array_unique(array_column($graph_data_template_items, 'data_template_id')));
$graphs_using_count = array_sum(array_map(function ($template) { return (int) $template['graph_count']; }, $global_graph_templates));
?>
<section class="nms-panel nms-graph-device-picker">
	<div><h2>Create graph template</h2><p>Create a reusable Cacti graph template from a data-template item. No device is selected or changed.</p></div>
	<a class="nms-panel-action" href="<?php print nms_h($core_base . 'graph_templates.php'); ?>">Open Cacti graph templates</a>
</section>

<section class="nms-panel nms-graph-device-summary">
	<div><span>Data templates</span><strong><?php print $data_template_count; ?></strong><small>Reusable Cacti templates</small></div>
	<div><span>Data-template items</span><strong><?php print $source_item_count; ?></strong><small>Available readings</small></div>
	<div><span>Graph templates</span><strong><?php print $graph_template_count; ?></strong><small>Stored in Cacti</small></div>
	<div><span>Device graphs</span><strong><?php print $graphs_using_count; ?></strong><small>Using these templates</small></div>
	<a class="nms-panel-action" href="?tab=inventory">Manage devices</a>
</section>

<section class="nms-panel nms-standalone-graph-builder" id="graph-builder">
	<div class="nms-panel-head"><div><h2>Create a graph template from a data template</h2><p>Choose one reusable Cacti reading. NMS creates the global graph template and its graph and GPRINT items only.</p></div><a class="nms-panel-action" href="<?php print nms_h($core_base . 'data_templates.php'); ?>">View data templates</a></div>
	<div class="nms-graph-builder-steps">
		<div><b>1</b><span><strong>Choose a reading</strong><small>Fetched from Cacti data templates</small></span></div>
		<div><b>2</b><span><strong>Choose its appearance</strong><small>Style, legend, size, and scale</small></span></div>
		<div><b>3</b><span><strong>Create template</strong><small>Assign it to devices separately</small></span></div>
	</div>
	<form class="nms-graph-builder-form" method="post" action="devices.php?tab=graphs#graph-builder">
		<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
		<input type="hidden" name="nms_action" value="create_graph_template">
		<label class="source"><span>Cacti data-template item</span><span class="nms-searchable-select"><input id="nmsDataTemplateSearch" type="search" autocomplete="off" placeholder="Search this dropdown"><select id="nmsDataTemplateSelect" required name="data_template_rrd_id" <?php print !$source_item_count ? 'disabled' : ''; ?>><option value=""><?php print $source_item_count ? 'Select a reusable reading from Cacti' : 'Cacti has no reusable data-template items'; ?></option><?php foreach ($graph_data_template_items as $source_item) { $source_label = $source_item['data_template_name'] . ' — ' . $source_item['data_source_name'] . ((int) $source_item['graph_template_count'] > 0 ? ' — used by ' . (int) $source_item['graph_template_count'] . ' template(s)' : ' — not yet graphed'); ?><option value="<?php print (int) $source_item['data_template_rrd_id']; ?>"><?php print nms_h($source_label); ?></option><?php } ?></select></span><small><?php print $source_item_count ? 'Search by template or reading name, then choose from the filtered list.' : 'Cacti has no reusable data-template items.'; ?></small></label>
		<label><span>Graph template name <em>optional</em></span><input name="graph_name" maxlength="190" placeholder="Automatic from data template"></label>
		<label><span>Vertical label <em>optional</em></span><input name="vertical_label" maxlength="20" placeholder="Value, %, bytes, °C"></label>
		<label><span>Graph item style</span><select name="graph_style"><option value="line1">Line — 1 px</option><option value="line2">Line — 2 px</option><option value="line3">Line — 3 px</option><option value="area">Filled area</option></select></label>
		<label><span>Data calculation</span><select name="consolidation"><option value="average">Average</option><option value="last">Current / last</option><option value="minimum">Minimum</option><option value="maximum">Maximum</option></select></label>
		<label><span>Item color</span><span class="nms-color-select"><i id="nmsGraphColorSwatch" aria-hidden="true"></i><select id="nmsGraphColor" name="color_id"><?php foreach ($graph_colors as $color) { ?><option value="<?php print (int) $color['id']; ?>" data-hex="<?php print nms_h($color['hex']); ?>" <?php print (int) $color['id'] === 86 ? 'selected' : ''; ?>><?php print nms_h($color['name']); ?></option><?php } ?></select></span></label>
		<label><span>Item transparency</span><select name="alpha_percent"><?php foreach (array(100, 90, 80, 70, 60, 50, 40, 30, 20) as $alpha) { ?><option value="<?php print $alpha; ?>"><?php print $alpha; ?>% visible</option><?php } ?></select></label>
		<label><span>CDEF calculation</span><select name="cdef_id"><option value="0">None — use the reading as collected</option><?php foreach ($graph_cdefs as $cdef) { ?><option value="<?php print (int) $cdef['id']; ?>"><?php print nms_h($cdef['name']); ?></option><?php } ?></select></label>
		<label><span>GPRINT number format</span><select name="gprint_id"><?php foreach ($graph_gprints as $gprint) { ?><option value="<?php print (int) $gprint['id']; ?>" <?php print (int) $gprint['id'] === 2 ? 'selected' : ''; ?>><?php print nms_h($gprint['name'] . ' — ' . $gprint['gprint_text']); ?></option><?php } ?></select><small>Controls how Current, Minimum, Average, and Maximum appear in the legend.</small></label>
		<fieldset class="nms-graph-options"><legend>Legend values</legend><div><label><input type="checkbox" name="show_current" checked><span>Current</span></label><label><input type="checkbox" name="show_minimum" checked><span>Minimum</span></label><label><input type="checkbox" name="show_average" checked><span>Average</span></label><label><input type="checkbox" name="show_maximum" checked><span>Maximum</span></label></div></fieldset>
		<label><span>Graph size</span><span class="nms-inline-selects"><select aria-label="Graph width" name="width"><option value="300">300 px wide</option><option value="500">500 px wide</option><option value="700" selected>700 px wide</option><option value="900">900 px wide</option><option value="1200">1200 px wide</option></select><select aria-label="Graph height" name="height"><option value="120">120 px high</option><option value="160">160 px high</option><option value="200" selected>200 px high</option><option value="300">300 px high</option><option value="400">400 px high</option></select></span></label>
		<label><span>Value base</span><select name="base_value"><option value="1000">1000 — network/decimal</option><option value="1024">1024 — memory/binary</option></select></label>
		<label><span>Image format</span><select name="image_format_id"><option value="3">SVG — scalable and clear</option><option value="1">PNG — raster image</option></select></label>
		<label><span>Auto-scale method</span><select id="nmsAutoScaleMethod" name="auto_scale_opts"><option value="1">Use minimum and maximum data values</option><option value="2" selected>Keep lower limit; calculate maximum</option><option value="3">Keep upper limit; calculate minimum</option><option value="4">Respect both configured limits</option></select></label>
		<label><span>Scale limits</span><span class="nms-inline-selects"><input id="nmsLowerLimit" aria-label="Lower graph limit" name="lower_limit" value="0" inputmode="decimal"><input id="nmsUpperLimit" aria-label="Upper graph limit" name="upper_limit" value="100" inputmode="decimal"></span><small id="nmsScaleHelp">Used according to the selected auto-scale method.</small></label>
		<fieldset class="nms-graph-options"><legend>Rendering and scale</legend><div><label><input type="checkbox" name="slope_mode" checked><span>Smooth lines</span></label><label><input type="checkbox" name="auto_scale" checked><span>Auto scale</span></label><label><input type="checkbox" name="auto_padding" checked><span>Auto padding</span></label><label><input type="checkbox" name="auto_scale_log"><span>Logarithmic</span></label><label><input type="checkbox" name="auto_scale_rigid"><span>Rigid limits</span></label></div></fieldset>
		<button type="submit" <?php print !$source_item_count ? 'disabled' : ''; ?>>Create graph template in Cacti</button>
	</form>
</section>

<section class="nms-panel nms-standalone-graph-list">
	<div class="nms-panel-head"><div><h2>Current graph templates</h2><p>All reusable graph templates and their device-graph usage counts read directly from Cacti.</p></div><a class="nms-panel-action" href="<?php print nms_h($core_base . 'graph_templates.php'); ?>">Open graph templates</a></div>
	<div class="nms-association-table nms-global-graph-table">
		<div class="nms-association-row heading"><span>Graph template</span><span>ID</span><span>Graphs using</span><span>Size</span><span>Format</span><span>Vertical label</span><span>Action</span></div>
		<?php if (!$global_graph_templates) { ?><div class="nms-association-empty">Cacti has no graph templates.</div><?php } ?>
		<?php foreach ($global_graph_templates as $graph_template) { ?><div class="nms-association-row"><strong><?php print nms_h($graph_template['name']); ?></strong><span><?php print (int) $graph_template['id']; ?></span><span><?php print (int) $graph_template['graph_count']; ?></span><span><?php print (int) $graph_template['width']; ?> × <?php print (int) $graph_template['height']; ?></span><span><?php print (int) $graph_template['image_format_id'] === 3 ? 'SVG' : 'PNG'; ?></span><span><?php print nms_h($graph_template['vertical_label'] ?: 'Not set'); ?></span><a href="<?php print nms_h($core_base . 'graph_templates.php?action=template_edit&id=' . (int) $graph_template['id']); ?>">Open</a></div><?php } ?>
	</div>
</section>
