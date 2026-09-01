<link rel="stylesheet" href="<?php print nms_h($nms_asset_base . 'css/nms-snmp-form.css?v=1.8.1'); ?>">
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
	$available_source_item_count = 0;
	foreach ($device_data_source_items as $source_item) if ((int) $source_item['graph_count'] === 0) $available_source_item_count++;
?>
<section class="nms-panel nms-graph-device-summary">
	<div><span>Selected device</span><strong><?php print nms_h($edit_device['description']); ?></strong><small>ID <?php print $device_id; ?> · <?php print nms_h($edit_device['hostname']); ?></small></div>
	<div><span>Data sources</span><strong><?php print (int) $edit_device['data_source_count']; ?></strong><small>Stored in Cacti</small></div>
	<div><span>Graphs</span><strong><?php print (int) $edit_device['graph_count']; ?></strong><small>Current device graphs</small></div>
	<div><span>Available readings</span><strong><?php print (int) $available_source_item_count; ?></strong><small>Not currently graphed</small></div>
	<a class="nms-panel-action" href="?tab=edit&id=<?php print $device_id; ?>">Manage device</a>
</section>

<section class="nms-panel nms-standalone-graph-builder" id="graph-builder">
	<div class="nms-panel-head">
		<div><h2>Create a graph from a data source</h2><p>Choose an existing reading. NMS creates its native Cacti graph template, graph items, and device graph together.</p></div>
		<a class="nms-panel-action" href="<?php print nms_h($core_base . 'data_sources.php?reset=true&host_id=' . $device_id . '&ds_rows=30&filter=&template_id=-1&method_id=-1&page=1'); ?>">View data sources</a>
	</div>
	<div class="nms-graph-builder-steps">
		<div><b>1</b><span><strong>Choose a reading</strong><small>Fetched from this Cacti device</small></span></div>
		<div><b>2</b><span><strong>Name the graph</strong><small>Or use the automatic name</small></span></div>
		<div><b>3</b><span><strong>Create</strong><small>Template, items, and graph together</small></span></div>
	</div>
	<form class="nms-graph-builder-form" method="post" action="devices.php?tab=graphs&id=<?php print $device_id; ?>#graph-builder">
		<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
		<input type="hidden" name="nms_action" value="create_graph_from_data_source">
		<input type="hidden" name="id" value="<?php print $device_id; ?>">
		<label class="source"><span>Cacti data-source item</span><select required name="local_rrd_id" <?php print !$available_source_item_count ? 'disabled' : ''; ?>><option value=""><?php print $available_source_item_count ? 'Select a reading from this device' : 'Every data-source item already has a graph'; ?></option><?php foreach ($device_data_source_items as $source_item) { ?><option value="<?php print (int) $source_item['local_rrd_id']; ?>" <?php print (int) $source_item['graph_count'] > 0 ? 'disabled' : ''; ?>><?php print nms_h($source_item['data_template_name'] . ' — ' . $source_item['data_source_name'] . ' (Data Source ' . (int) $source_item['local_data_id'] . ')' . ((int) $source_item['graph_count'] > 0 ? ' — already graphed' : '')); ?></option><?php } ?></select><small>Already-graphed readings stay visible but cannot be selected again.</small></label>
		<label><span>Graph name <em>optional</em></span><input name="graph_name" maxlength="190" placeholder="Automatic from data template"></label>
		<label><span>Vertical label <em>optional</em></span><input name="vertical_label" maxlength="20" placeholder="Value, %, bytes, °C"></label>
		<button type="submit" <?php print !$available_source_item_count ? 'disabled' : ''; ?>>Create graph in Cacti</button>
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
