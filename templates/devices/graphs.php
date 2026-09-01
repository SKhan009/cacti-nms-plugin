<link rel="stylesheet" href="<?php print nms_h($nms_asset_base . 'css/nms-snmp-form.css?v=1.8.4'); ?>">
<section class="nms-panel nms-graph-device-picker">
	<div>
		<h2>Create graph template</h2>
		<p>Select a Cacti device first. All data sources, data templates, graph templates, and graph counts below are read live from Cacti core.</p>
	</div>
	<form method="get" action="devices.php">
		<input type="hidden" name="tab" value="graphs">
		<label><span>Cacti device</span><select name="id" required onchange="this.form.submit()"><option value="">Select device</option><?php foreach ($devices as $device) { ?><option value="<?php print (int) $device['id']; ?>" <?php print $edit_device && (int) $edit_device['id'] === (int) $device['id'] ? 'selected' : ''; ?>><?php print nms_h($device['description'] . ' — ' . $device['hostname']); ?></option><?php } ?></select></label>
	</form>
</section>

<?php if (!$edit_device) { ?>
<section class="nms-panel nms-graph-device-empty"><h2>Choose a device to continue</h2><p>The graph builder will show every data-source item available for the selected Cacti device.</p></section>
<?php } else {
	$device_id = (int) $edit_device['id'];
	$core_base = $config['url_path'];
	$source_item_count = count($device_data_source_items);
?>
<section class="nms-panel nms-graph-device-summary">
	<div><span>Selected device</span><strong><?php print nms_h($edit_device['description']); ?></strong><small>ID <?php print $device_id; ?> · <?php print nms_h($edit_device['hostname']); ?></small></div>
	<div><span>Data sources</span><strong><?php print (int) $edit_device['data_source_count']; ?></strong><small>Stored in Cacti</small></div>
	<div><span>Graphs</span><strong><?php print (int) $edit_device['graph_count']; ?></strong><small>Current device graphs</small></div>
	<div><span>Data-source readings</span><strong><?php print (int) $source_item_count; ?></strong><small>Available for templates</small></div>
	<a class="nms-panel-action" href="?tab=edit&id=<?php print $device_id; ?>">Manage device</a>
</section>

<section class="nms-panel nms-standalone-graph-builder" id="graph-builder">
	<div class="nms-panel-head">
		<div><h2>Create a graph from a data source</h2><p>Choose an existing reading. NMS creates its native Cacti graph template, graph items, and device graph together.</p></div>
		<a class="nms-panel-action" href="<?php print nms_h($core_base . 'data_sources.php?reset=true&host_id=' . $device_id . '&ds_rows=30&filter=&template_id=-1&method_id=-1&page=1'); ?>">View data sources</a>
	</div>
	<div class="nms-graph-builder-steps">
		<div><b>1</b><span><strong>Choose a reading</strong><small>Fetched from this Cacti device</small></span></div>
		<div><b>2</b><span><strong>Choose its appearance</strong><small>Style, color, size, and scale</small></span></div>
		<div><b>3</b><span><strong>Create</strong><small>Template, items, and graph together</small></span></div>
	</div>
	<form class="nms-graph-builder-form" method="post" action="devices.php?tab=graphs&id=<?php print $device_id; ?>#graph-builder">
		<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
		<input type="hidden" name="nms_action" value="create_graph_from_data_source">
		<input type="hidden" name="id" value="<?php print $device_id; ?>">
		<label class="source"><span>Cacti data-source item</span><select required name="local_rrd_id" <?php print !$source_item_count ? 'disabled' : ''; ?>><option value=""><?php print $source_item_count ? 'Select any reading from this device' : 'This device has no data-source readings'; ?></option><?php foreach ($device_data_source_items as $source_item) { ?><option value="<?php print (int) $source_item['local_rrd_id']; ?>"><?php print nms_h($source_item['data_template_name'] . ' — ' . $source_item['data_source_name'] . ' (Data Source ' . (int) $source_item['local_data_id'] . ')' . ((int) $source_item['graph_count'] > 0 ? ' — currently used by ' . (int) $source_item['graph_count'] . ' graph(s)' : '')); ?></option><?php } ?></select><small>Existing readings can be reused in another graph template.</small></label>
		<label><span>Graph name <em>optional</em></span><input name="graph_name" maxlength="190" placeholder="Automatic from data template"></label>
		<label><span>Vertical label <em>optional</em></span><input name="vertical_label" maxlength="20" placeholder="Value, %, bytes, °C"></label>
		<label><span>Graph item style</span><select name="graph_style"><option value="line1">Line — 1 px</option><option value="line2">Line — 2 px</option><option value="line3">Line — 3 px</option><option value="area">Filled area</option></select></label>
		<label><span>Data calculation</span><select name="consolidation"><option value="average">Average</option><option value="last">Current / last</option><option value="minimum">Minimum</option><option value="maximum">Maximum</option></select></label>
		<label><span>Item color</span><select name="color_id"><?php foreach ($graph_colors as $color) { ?><option value="<?php print (int) $color['id']; ?>" <?php print (int) $color['id'] === 86 ? 'selected' : ''; ?>><?php print nms_h($color['name'] . ' — #' . $color['hex']); ?></option><?php } ?></select></label>
		<label><span>Item transparency</span><select name="alpha_percent"><?php foreach (array(100, 90, 80, 70, 60, 50, 40, 30, 20) as $alpha) { ?><option value="<?php print $alpha; ?>"><?php print $alpha; ?>% visible</option><?php } ?></select></label>
		<label><span>CDEF calculation</span><select name="cdef_id"><option value="0">None — use the reading as collected</option><?php foreach ($graph_cdefs as $cdef) { ?><option value="<?php print (int) $cdef['id']; ?>"><?php print nms_h($cdef['name']); ?></option><?php } ?></select></label>
		<label><span>GPRINT number format</span><select name="gprint_id"><?php foreach ($graph_gprints as $gprint) { ?><option value="<?php print (int) $gprint['id']; ?>" <?php print (int) $gprint['id'] === 2 ? 'selected' : ''; ?>><?php print nms_h($gprint['name'] . ' — ' . $gprint['gprint_text']); ?></option><?php } ?></select><small>Controls how Current, Minimum, Average, and Maximum appear in the legend.</small></label>
		<fieldset class="nms-graph-options"><legend>Legend values</legend><div><label><input type="checkbox" name="show_current" checked><span>Current</span></label><label><input type="checkbox" name="show_minimum" checked><span>Minimum</span></label><label><input type="checkbox" name="show_average" checked><span>Average</span></label><label><input type="checkbox" name="show_maximum" checked><span>Maximum</span></label></div></fieldset>
		<label><span>Graph size</span><span class="nms-inline-selects"><select aria-label="Graph width" name="width"><option value="300">300 px wide</option><option value="500">500 px wide</option><option value="700" selected>700 px wide</option><option value="900">900 px wide</option><option value="1200">1200 px wide</option></select><select aria-label="Graph height" name="height"><option value="120">120 px high</option><option value="160">160 px high</option><option value="200" selected>200 px high</option><option value="300">300 px high</option><option value="400">400 px high</option></select></span></label>
		<label><span>Value base</span><select name="base_value"><option value="1000">1000 — network/decimal</option><option value="1024">1024 — memory/binary</option></select></label>
		<label><span>Image format</span><select name="image_format_id"><option value="3">SVG — scalable and clear</option><option value="1">PNG — raster image</option></select></label>
		<label><span>Auto-scale method</span><select name="auto_scale_opts"><option value="1">Use minimum and maximum data values</option><option value="2" selected>Keep lower limit; calculate maximum</option><option value="3">Keep upper limit; calculate minimum</option><option value="4">Respect both configured limits</option></select></label>
		<label><span>Scale limits</span><span class="nms-inline-selects"><input aria-label="Lower graph limit" name="lower_limit" value="0" inputmode="decimal"><input aria-label="Upper graph limit" name="upper_limit" value="100" inputmode="decimal"></span><small>Used according to the selected auto-scale method.</small></label>
		<fieldset class="nms-graph-options"><legend>Rendering and scale</legend><div><label><input type="checkbox" name="slope_mode" checked><span>Smooth lines</span></label><label><input type="checkbox" name="auto_scale" checked><span>Auto scale</span></label><label><input type="checkbox" name="auto_padding" checked><span>Auto padding</span></label><label><input type="checkbox" name="auto_scale_log"><span>Logarithmic</span></label><label><input type="checkbox" name="auto_scale_rigid"><span>Rigid limits</span></label></div></fieldset>
		<button type="submit" <?php print !$source_item_count ? 'disabled' : ''; ?>>Create graph in Cacti</button>
	</form>
</section>

<section class="nms-panel nms-standalone-graph-list">
	<div class="nms-panel-head"><div><h2>Current graph templates</h2><p>Live graph-template associations and graph counts for this device.</p></div><a class="nms-panel-action" href="<?php print nms_h($core_base . 'graphs.php?reset=true&host_id=' . $device_id . '&graph_rows=30&filter=&template_id=-1&page=1'); ?>">Open graph list</a></div>
	<div class="nms-association-table">
		<div class="nms-association-row heading"><span>Graph template</span><span>Status</span></div>
		<?php if (!$device_graph_templates) { ?><div class="nms-association-empty">No graph templates are associated with this device.</div><?php } ?>
		<?php foreach ($device_graph_templates as $graph_template) { ?><div class="nms-association-row"><strong><?php print nms_h($graph_template['name']); ?></strong><span><?php if ((int) $graph_template['graph_count'] > 0) { ?><i class="nms-association-state active">Being graphed</i><small><?php print (int) $graph_template['graph_count']; ?> graph(s)</small><?php } else { ?><i class="nms-association-state pending">Not graphed</i><?php } ?></span></div><?php } ?>
	</div>
</section>
<?php } ?>
