<div class="nms-summary-grid nms-device-summary">
	<div class="nms-summary nms-summary-total"><span>Total devices</span><strong><?php print (int) $device_counts['total']; ?></strong><small>All Cacti device records</small></div>
	<div class="nms-summary nms-summary-resolved"><span>Enabled</span><strong><?php print (int) $device_counts['enabled']; ?></strong><small>Available to the poller</small></div>
	<div class="nms-summary nms-summary-resolved"><span>Up</span><strong><?php print (int) $device_counts['up']; ?></strong><small>Responding successfully</small></div>
	<div class="nms-summary nms-summary-critical"><span>Down</span><strong><?php print (int) $device_counts['down']; ?></strong><small>Need attention</small></div>
</div>

<section class="nms-panel">
	<div class="nms-panel-head"><div><h2>Cacti device inventory</h2><p>Live details from Cacti core; no copied device records.</p></div><a class="nms-panel-action" href="?tab=add">Add device</a></div>
	<div class="nms-table-wrap">
		<table class="nms-table nms-device-table">
			<thead><tr><th>Device</th><th>State</th><th>SNMP</th><th>Template and category</th><th>Collection</th><th>Availability</th><th>Last update</th><th>Actions</th></tr></thead>
			<tbody>
			<?php if (!count($devices)) { ?><tr><td colspan="8" class="nms-empty">No Cacti devices exist yet.</td></tr><?php } ?>
			<?php foreach ($devices as $device) {
				$status = nms_host_status_name((int) $device['status']);
				$status_class = strtolower($status);
			?>
			<tr>
				<td><div class="nms-incident"><i class="nms-severity <?php print $status === 'Up' ? 'healthy' : 'critical'; ?>"></i><div><strong><?php print nms_h($device['description']); ?></strong><small><?php print nms_h($device['hostname']); ?></small><small><?php print nms_h($device['snmp_sysName'] ?: 'SNMP identity pending'); ?></small></div></div></td>
				<td><span class="nms-state <?php print nms_h($status_class); ?>"><?php print nms_h($status); ?></span><?php if ($device['disabled'] === 'on') { ?><small>Monitoring disabled</small><?php } elseif ($device['status_last_error']) { ?><small title="<?php print nms_h($device['status_last_error']); ?>"><?php print nms_h(substr($device['status_last_error'], 0, 70)); ?></small><?php } ?></td>
				<td><strong>v<?php print nms_h($device['snmp_version']); ?> · port <?php print (int) $device['snmp_port']; ?></strong><small><?php print nms_h($device['snmp_sysLocation'] ?: 'No reported location'); ?></small></td>
				<td><strong><?php print nms_h($device['template_name'] ?: 'No template'); ?></strong><small><?php print nms_h($device['category_name'] ?: 'Unmapped category'); ?></small><small><?php print nms_h($device['site_name'] ?: 'No site'); ?></small></td>
				<td><strong><?php print (int) $device['poller_item_count']; ?> poller items</strong><small><?php print (int) $device['data_source_count']; ?> data sources · <?php print (int) $device['graph_count']; ?> graphs</small><small><?php print nms_h($device['poller_name'] ?: 'Main poller'); ?></small></td>
				<td><strong><?php print nms_h(number_format((float) $device['availability'], 1)); ?>%</strong><small><?php print nms_h(number_format((float) $device['cur_time'], 2)); ?> ms current</small><small><?php print (int) $device['failed_polls']; ?> of <?php print (int) $device['total_polls']; ?> polls failed</small></td>
				<td class="nms-nowrap"><?php print nms_h(nms_time_ago($device['last_updated'])); ?></td>
				<td><div class="nms-row-actions"><a class="nms-row-link" href="?tab=edit&id=<?php print (int) $device['id']; ?>">Manage</a><a class="nms-row-link secondary" href="<?php print nms_h($config['url_path'] . 'host.php?action=edit&id=' . (int) $device['id']); ?>">Cacti</a></div></td>
			</tr>
			<?php } ?>
			</tbody>
		</table>
	</div>
</section>
