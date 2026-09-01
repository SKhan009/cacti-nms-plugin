<?php
$device_id = (int) $edit_device['id'];
$core_base = $config['url_path'];
$device_actions = array(
	array('Create New Device', $core_base . 'host.php?action=edit', 'Open the Cacti device creation form.'),
	array('Create Graphs for this Device', $core_base . 'graphs_new.php?reset=true&host_id=' . $device_id, 'Select graph templates and data queries for this device.'),
	array('Re-Index Device', $core_base . 'host.php?action=reindex&host_id=' . $device_id, 'Refresh indexed SNMP data such as interfaces and sensors.'),
	array('Enable Device Debug', $core_base . 'host.php?action=enable_debug&host_id=' . $device_id, 'Enable detailed Cacti troubleshooting for this device.'),
	array('Repopulate Poller Cache', $core_base . 'host.php?action=repopulate&host_id=' . $device_id, 'Rebuild the poller entries for this device.'),
	array('View Poller Cache', $core_base . 'utilities.php?poller_action=-1&action=view_poller_cache&host_id=' . $device_id . '&template_id=-1&filter=&rows=-1', 'Inspect the poller items currently generated for this device.'),
	array('Data Source List', $core_base . 'data_sources.php?reset=true&host_id=' . $device_id . '&ds_rows=30&filter=&template_id=-1&method_id=-1&page=1', 'View all Cacti data sources linked to this device.'),
	array('Graph List', $core_base . 'graphs.php?reset=true&host_id=' . $device_id . '&graph_rows=30&filter=&template_id=-1&page=1', 'View all Cacti graphs linked to this device.')
);
?>
<section class="nms-panel nms-device-overview">
	<div class="nms-panel-head">
		<div><h2>Device overview</h2><p>Current state and collection details read directly from Cacti.</p></div>
		<div class="nms-overview-actions">
			<a class="nms-panel-action" href="<?php print nms_h($core_base . 'host.php?action=edit&id=' . $device_id); ?>">Open in Cacti</a>
			<details class="nms-device-tools-menu">
				<summary><span>Device actions</span><small>ID <?php print $device_id; ?></small></summary>
				<nav aria-label="Cacti device actions"><?php foreach ($device_actions as $device_action) { ?><a href="<?php print nms_h($device_action[1]); ?>"><?php print nms_h($device_action[0]); ?><span>↗</span></a><?php } ?></nav>
			</details>
		</div>
	</div>
	<div class="nms-device-facts">
		<div><span>State</span><strong><?php print nms_h(nms_host_status_name((int) $edit_device['status'])); ?></strong></div>
		<div><span>SNMP identity</span><strong><?php print nms_h($edit_device['snmp_sysName'] ?: 'Pending'); ?></strong></div>
		<div><span>Poller items</span><strong><?php print (int) $edit_device['poller_item_count']; ?></strong></div>
		<div><span>Data sources</span><strong><?php print (int) $edit_device['data_source_count']; ?></strong></div>
		<div><span>Graphs</span><strong><?php print (int) $edit_device['graph_count']; ?></strong></div>
		<div><span>Availability</span><strong><?php print nms_h(number_format((float) $edit_device['availability'], 1)); ?>%</strong></div>
	</div>
</section>

<?php require($config['base_path'] . '/plugins/nms/templates/devices/add.php'); ?>

<div class="nms-device-associations">
	<section class="nms-panel" id="graph-templates">
		<div class="nms-panel-head"><div><h2>Associated Graph Templates</h2><p>The same graph-template associations stored by Cacti.</p></div><a class="nms-panel-action" href="<?php print nms_h($core_base . 'graphs_new.php?reset=true&host_id=' . $device_id); ?>">Create graphs</a></div>
		<form class="nms-association-add top" method="post" action="devices.php?tab=edit&id=<?php print $device_id; ?>#graph-templates">
			<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
			<input type="hidden" name="nms_action" value="add_graph_template">
			<input type="hidden" name="id" value="<?php print $device_id; ?>">
			<label><span>Add Graph Template</span><select required name="graph_template_id" <?php print !$available_graph_templates ? 'disabled' : ''; ?>><option value=""><?php print $available_graph_templates ? 'Select from all eligible Cacti graph templates' : 'All available templates are associated'; ?></option><?php foreach ($available_graph_templates as $available_template) { ?><option value="<?php print (int) $available_template['id']; ?>"><?php print nms_h($available_template['name']); ?></option><?php } ?></select></label>
			<button type="submit" <?php print !$available_graph_templates ? 'disabled' : ''; ?>>Add template</button>
		</form>
		<div class="nms-association-table">
			<div class="nms-association-row heading"><span>Graph template</span><span>Status</span></div>
			<?php if (!$device_graph_templates) { ?><div class="nms-association-empty">No associated graph templates.</div><?php } ?>
			<?php foreach ($device_graph_templates as $graph_template) { ?>
			<div class="nms-association-row"><strong><?php print nms_h($graph_template['name']); ?></strong><span><?php if ((int) $graph_template['graph_count'] > 0) { ?><i class="nms-association-state active">Being graphed</i><small><?php print (int) $graph_template['graph_count']; ?> graph(s)</small><?php } else { ?><i class="nms-association-state pending">Not graphed</i><?php } ?></span></div>
			<?php } ?>
		</div>
	</section>

	<section class="nms-panel" id="data-queries">
		<div class="nms-panel-head"><div><h2>Associated Data Queries</h2><p>Live indexed-query status from the Cacti SNMP cache.</p></div><a class="nms-panel-action" href="<?php print nms_h($core_base . 'host.php?action=edit&id=' . $device_id); ?>">Manage queries</a></div>
		<form class="nms-association-add query top" method="post" action="devices.php?tab=edit&id=<?php print $device_id; ?>#data-queries">
			<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
			<input type="hidden" name="nms_action" value="add_data_query">
			<input type="hidden" name="id" value="<?php print $device_id; ?>">
			<label><span>Add Data Query</span><select required name="snmp_query_id" <?php print !$available_data_queries ? 'disabled' : ''; ?>><option value=""><?php print $available_data_queries ? 'Select from all eligible Cacti data queries' : 'All available queries are associated'; ?></option><?php foreach ($available_data_queries as $available_query) { ?><option value="<?php print (int) $available_query['id']; ?>"><?php print nms_h($available_query['name']); ?></option><?php } ?></select></label>
			<label><span>Re-Index Method</span><select required name="reindex_method" <?php print !$available_data_queries ? 'disabled' : ''; ?>><?php foreach ($reindex_types as $reindex_id => $reindex_name) { ?><option value="<?php print (int) $reindex_id; ?>" <?php print (int) read_config_option('reindex_method') === (int) $reindex_id ? 'selected' : ''; ?>><?php print nms_h($reindex_name); ?></option><?php } ?></select></label>
			<button type="submit" <?php print !$available_data_queries ? 'disabled' : ''; ?>>Add query</button>
		</form>
		<div class="nms-association-table">
			<div class="nms-association-row query heading"><span>Data query</span><span>Re-index method</span><span>Status</span></div>
			<?php if (!$device_data_queries) { ?><div class="nms-association-empty">No associated data queries.</div><?php } ?>
			<?php foreach ($device_data_queries as $data_query) { ?>
			<div class="nms-association-row query"><strong><?php print nms_h($data_query['name']); ?></strong><span><?php print nms_h(isset($reindex_types[$data_query['reindex_method']]) ? $reindex_types[$data_query['reindex_method']] : 'Method ' . (int) $data_query['reindex_method']); ?></span><span><i class="nms-association-state active">Success</i><small><?php print (int) $data_query['item_count']; ?> items · <?php print (int) $data_query['row_count']; ?> rows</small></span></div>
			<?php } ?>
		</div>
	</section>
</div>
