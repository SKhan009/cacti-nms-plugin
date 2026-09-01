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
	<div class="nms-panel-head"><div><h2>Device overview</h2><p>Current state and collection details read directly from Cacti.</p></div><a class="nms-panel-action" href="<?php print nms_h($core_base . 'host.php?action=edit&id=' . $device_id); ?>">Open in Cacti</a></div>
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

<section class="nms-panel nms-device-tools">
	<div class="nms-panel-head"><div><h2>Cacti device tools</h2><p>Each action below is linked to Cacti device <?php print $device_id; ?>.</p></div></div>
	<div class="nms-device-action-grid">
		<?php foreach ($device_actions as $device_action) { ?>
		<a href="<?php print nms_h($device_action[1]); ?>">
			<strong><?php print nms_h($device_action[0]); ?></strong>
			<span><?php print nms_h($device_action[2]); ?></span>
		</a>
		<?php } ?>
	</div>
</section>
