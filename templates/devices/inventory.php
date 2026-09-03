<?php
/**
 * @file inventory.php
 * Render the core device inventory with monitoring state, identity, associations, and available management actions.
 */
?><div class="nms-summary-grid nms-device-summary">
	<div class="nms-summary nms-summary-total"><span>Total devices</span><strong><?php print (int) $device_counts['total']; ?></strong><small>All Cacti device records</small></div>
	<div class="nms-summary nms-summary-resolved"><span>Enabled</span><strong><?php print (int) $device_counts['enabled']; ?></strong><small>Available to the poller</small></div>
	<div class="nms-summary nms-summary-resolved"><span>Up</span><strong><?php print (int) $device_counts['up']; ?></strong><small>Responding successfully</small></div>
	<div class="nms-summary nms-summary-critical"><span>Down</span><strong><?php print (int) $device_counts['down']; ?></strong><small>Need attention</small></div>
</div>

<section class="nms-panel">
	<div class="nms-panel-head"><div><h2>Cacti device inventory</h2><p>Live details from Cacti core; no copied device records.</p></div><a class="nms-panel-action" href="?tab=add">Add device</a></div>
	<div class="nms-table-wrap nms-fit-table-wrap nms-device-inventory-wrap">
		<table class="nms-table nms-device-table">
			<thead><tr><th>Device</th><th>State</th><th>SNMP</th><th>Template and category</th><th>Collection</th><th>Availability</th><th>Last update</th><th>Actions</th></tr></thead>
			<tbody>
			<?php if (!count($devices)) { ?><tr><td colspan="8" class="nms-empty">No Cacti devices exist yet.</td></tr><?php } ?>
			<?php foreach ($devices as $device) {
				$status = nms_device_status_name($device);
				$status_class = strtolower($status);
			?>
			<tr>
				<td data-label="Device"><div class="nms-incident"><i class="nms-severity <?php print $status === 'Up' ? 'healthy' : 'critical'; ?>"></i><div><strong><?php print nms_h($device['description']); ?></strong><small><?php print nms_h($device['hostname']); ?></small><small><?php print nms_h($device['snmp_sysName'] ?: 'SNMP identity pending'); ?></small><?php if ($device['serial_status'] === 'failed') { ?><small>Serial number (SNMP): live SNMP read failed</small><?php } elseif ($device['serial_number'] !== null && $device['serial_number'] !== '') { ?><small>Serial number: <?php print nms_h($device['serial_number']); ?><?php print $device['serial_status'] === 'changed' ? ' (changed)' : ''; ?></small><?php } elseif (!empty($device['serial_configured'])) { ?><small>Serial number: <?php print nms_h($device['serial_status']); ?> — no current value</small><?php } ?><?php if (($device['manual_serial_number'] ?? '') !== '' && $device['manual_serial_number'] !== null) { ?><small>Serial number (manual): <?php print nms_h($device['manual_serial_number']); ?></small><?php } ?></div></div></td>
				<td data-label="State"><span class="nms-state <?php print nms_h($status_class); ?>"><?php print nms_h($status); ?></span><?php if ($device['disabled'] === 'on') { ?><small>Monitoring disabled</small><?php } elseif ($device['status_last_error']) { ?><small title="<?php print nms_h($device['status_last_error']); ?>"><?php print nms_h(substr($device['status_last_error'], 0, 70)); ?></small><?php } ?></td>
				<td data-label="SNMP"><strong>v<?php print nms_h($device['snmp_version']); ?> · port <?php print (int) $device['snmp_port']; ?></strong><small><?php print nms_h($device['snmp_sysLocation'] ?: 'No reported location'); ?></small></td>
				<td data-label="Template and category"><strong><?php print nms_h($device['template_name'] ?: 'No template'); ?></strong><small><?php print nms_h($device['category_name'] ?: 'Unmapped category'); ?></small><small><?php print nms_h($device['site_name'] ?: 'No site'); ?></small></td>
				<td data-label="Collection"><strong><?php print (int) $device['poller_item_count']; ?> poller items</strong><small><?php print (int) $device['data_source_count']; ?> data sources · <?php print (int) $device['graph_count']; ?> graphs</small><small><?php print nms_h($device['poller_name'] ?: 'Collector not found'); ?></small></td>
				<td data-label="Availability"><strong><?php print nms_h(number_format((float) $device['availability'], 1)); ?>%</strong><small><?php print nms_h(number_format((float) $device['cur_time'], 2)); ?> ms last recorded</small><small><?php print (int) $device['failed_polls']; ?> of <?php print (int) $device['total_polls']; ?> polls failed</small></td>
				<td class="nms-nowrap" data-label="Last update"><?php print nms_h(nms_time_ago($device['last_updated'])); ?></td>
				<td data-label="Actions"><div class="nms-row-actions"><a class="nms-row-link" href="?tab=edit&id=<?php print (int) $device['id']; ?>">Manage</a><a class="nms-row-link secondary" href="<?php print nms_h($config['url_path'] . 'host.php?action=edit&id=' . (int) $device['id']); ?>">Cacti</a><?php if (!empty($device['nms_deletable'])) { ?><form method="post" action="devices.php?tab=inventory" onsubmit="return confirm('Delete this NMS-created device and its Cacti graphs and data sources?');"><input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="delete_device"><input type="hidden" name="id" value="<?php print (int) $device['id']; ?>"><button class="nms-delete-x" type="submit" aria-label="Delete <?php print nms_h($device['description']); ?>" title="Delete NMS-created device">×</button></form><?php } ?></div></td>
			</tr>
			<?php } ?>
			</tbody>
		</table>
	</div>
</section>
