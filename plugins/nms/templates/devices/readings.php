<?php
/** Render complete device reading, discovery, diagnosis, and raw evidence workspace. */
$unknown = 0;
$stale = 0;
$interfaces = 0;
$traffic = 0;

foreach ($device_readings as $reading) {
	$category = nms_reading_category($reading);
	$interfaces += $category === 'interfaces';
	$traffic += $category === 'traffic';
	$unknown += nms_reading_is_unknown($reading['raw_value']);
	$stale += !nms_reading_is_unknown($reading['raw_value']) && !nms_parameter_is_fresh($reading['last_seen']);
}

$failed = array_filter($device_discovery_readings, function ($snapshot) {
	return $snapshot['status'] !== 'success';
});
$lastRead = $device_readings ? $device_readings[0]['last_seen'] : ($edit_device['last_updated'] ?: 'Not recorded');
?>
<section class="nms-reading-controls">
	<label>Device
		<select onchange="window.location.href='devices.php?tab=readings&id='+this.value">
			<?php foreach ($devices as $device) { ?>
				<option value="<?php print (int) $device['id']; ?>" <?php print (int) $device['id'] === (int) $edit_device['id'] ? 'selected' : ''; ?>>
					<?php print nms_h($device['description'] . ' · ' . $device['hostname']); ?>
				</option>
			<?php } ?>
		</select>
	</label>
	<label>View
		<select data-reading-view><option value="all">All readings</option><option value="interfaces">Interfaces</option><option value="discovery">Discovery</option><option value="traffic">Traffic</option><option value="problems">Problems</option></select>
	</label>
	<label>Protocol
		<select data-reading-protocol><option value="all">All</option><option value="snmp">SNMP / RRD</option><option value="lldp">LLDP</option><option value="cdp">CDP</option><option value="arp">ARP</option><option value="fdb">FDB</option></select>
	</label>
	<button type="button" onclick="window.location.reload()">Refresh</button>
</section>

<section class="nms-panel nms-reading-summary">
	<div class="nms-panel-head"><div><p class="nms-eyebrow">NMS / Device monitoring</p><h2>Device reading</h2><p>What Cacti read, what NMS discovered, and what each result means.</p></div><a class="nms-panel-action" href="devices.php?tab=edit&amp;id=<?php print (int) $edit_device['id']; ?>">Back to device</a></div>
	<div class="nms-reading-facts">
		<div><span>Device</span><strong><?php print nms_h($edit_device['description']); ?></strong><small><?php print nms_h($edit_device['hostname'] . ' · SNMPv' . $edit_device['snmp_version']); ?></small></div>
		<div><span>SNMP port</span><strong><?php print (int) $edit_device['snmp_port']; ?></strong><small><?php print nms_h($edit_device['poller_name'] ?: 'Assigned collector'); ?></small></div>
		<div><span>Last read</span><strong><?php print nms_h($lastRead); ?></strong><small><?php print nms_parameter_is_fresh($lastRead) ? 'Current poller evidence' : 'Waiting for a fresh poll'; ?></small></div>
		<div><span>Interfaces</span><strong><?php print $interfaces; ?></strong><small>RRD-backed readings</small></div>
		<div class="<?php print $unknown || $failed ? 'warning' : 'success'; ?>"><span>Problems</span><strong><?php print $unknown + count($failed); ?></strong><small>Unknown values or discovery failures</small></div>
	</div>
</section>

<section class="nms-reading-live"><strong>● Live reading</strong><span>Values come from each graph data source’s latest RRD sample.</span><code>Last response: <?php print nms_h($lastRead); ?></code></section>

<section class="nms-panel nms-reading-graphs">
	<div class="nms-panel-head"><div><h2>Actual Cacti graph readings</h2><p>Every graph assigned to this device, rendered live by Cacti from its RRD files.</p></div><a class="nms-panel-action" href="<?php print nms_h(nms_cacti_url('graph_view.php?action=tree&host_id=' . (int) $edit_device['id'])); ?>">Open in Cacti</a></div>
	<?php if (!$device_graphs) { ?><p class="nms-empty">No graphs are assigned to this device.</p><?php } else { ?><div class="nms-reading-graph-grid"><?php foreach ($device_graphs as $graph) { $graph_id = (int) ($graph['local_graph_id'] ?? 0); if ($graph_id < 1) continue; $title = trim((string) ($graph['title_cache'] ?? '')) ?: 'Graph ' . $graph_id; ?><article><a href="<?php print nms_h(nms_cacti_url('graph.php?action=view&local_graph_id=' . $graph_id)); ?>"><img loading="lazy" src="<?php print nms_h(nms_cacti_url('graph_image.php?local_graph_id=' . $graph_id . '&rra_id=0&graph_start=' . (time() - 86400) . '&graph_end=' . time() . '&disable_cache=1')); ?>" alt="<?php print nms_h($title); ?>"></a><strong><?php print nms_h($title); ?></strong></article><?php } ?></div><?php } ?>
</section>

<section class="nms-panel nms-reading-table-panel">
	<div class="nms-panel-head"><div><h2>Readings, discovery &amp; diagnosis</h2><p>One combined view of values, topology evidence, and collection failures.</p></div></div>
	<nav class="nms-reading-tabs">
		<button class="active" data-reading-tab="all">All <span><?php print count($device_readings) + count($device_discovery_readings); ?></span></button>
		<button data-reading-tab="interfaces">Interfaces <span><?php print $interfaces; ?></span></button>
		<button data-reading-tab="discovery">Discovery <span><?php print count($device_discovery_readings); ?></span></button>
		<button data-reading-tab="traffic">Traffic <span><?php print $traffic; ?></span></button>
		<button data-reading-tab="problems">Problems <span><?php print $unknown + $stale + count($failed); ?></span></button>
	</nav>
	<div class="nms-reading-table-wrap"><table class="nms-table nms-reading-table"><thead><tr><th>Status</th><th>Area</th><th>OID / Source</th><th>Actual reading</th><th>Human explanation</th><th>What NMS does</th><th>Last checked</th></tr></thead><tbody>
		<?php foreach ($device_readings as $reading) {
			$p = nms_reading_presentation($reading);
			$category = nms_reading_category($reading);
			$label = nms_reading_label($reading);
			$source = nms_reading_source($reading);
			$action = nms_reading_action($reading);
		?>
			<tr data-reading-row data-category="<?php print nms_h($category); ?>" data-protocol="snmp" data-problem="<?php print $p['tone'] === 'success' ? '0' : '1'; ?>">
				<td><span class="nms-reading-state <?php print nms_h($p['tone']); ?>"><?php print nms_h($p['state']); ?></span></td><td><?php print nms_h($label); ?></td><td><code><?php print nms_h($source); ?></code></td><td><strong><?php print nms_h($p['value']); ?></strong></td><td><strong><?php print nms_h($p['meaning']); ?></strong><small><?php print nms_h($p['next']); ?></small></td><td><?php print nms_h($action); ?></td><td><?php print nms_h($reading['last_seen']); ?></td>
			</tr>
		<?php } foreach ($device_discovery_readings as $snapshot) { $isFailed = $snapshot['status'] !== 'success'; ?>
			<tr data-reading-row data-category="discovery" data-protocol="<?php print nms_h($snapshot['protocol']); ?>" data-problem="<?php print $isFailed ? '1' : '0'; ?>">
				<td><span class="nms-reading-state <?php print $isFailed ? 'warning' : 'success'; ?>"><?php print nms_h(strtoupper($snapshot['status'])); ?></span></td><td><?php print nms_h(strtoupper($snapshot['protocol'])); ?> discovery</td><td><code><?php print nms_h($snapshot['protocol'] === 'lldp' ? 'LLDP-MIB' : ($snapshot['protocol'] === 'cdp' ? 'Cisco CDP-MIB' : 'NMS discovery')); ?></code></td><td><?php print nms_h(nms_reading_snapshot_summary($snapshot)); ?></td><td><strong><?php print $isFailed ? 'Collection needs attention.' : 'Discovery evidence was collected.'; ?></strong><small><?php print nms_h($snapshot['error'] ?: 'NMS retains this evidence for topology matching.'); ?></small></td><td><?php print $isFailed ? 'Keeps the error visible and continues with other methods.' : 'Uses evidence to build topology and endpoint relationships.'; ?></td><td><?php print nms_h($snapshot['succeeded_at'] ?: $snapshot['attempted_at']); ?></td>
			</tr>
		<?php } ?>
	</tbody></table></div>
</section>

<section class="nms-panel nms-reading-diagnosis">
	<div class="nms-panel-head"><div><h2>Device diagnosis</h2><p>Simple summary of what NMS currently understands.</p></div></div>
	<div class="nms-reading-diagnosis-grid">
		<div class="<?php print $unknown ? 'warning' : 'success'; ?>"><strong>Device communication</strong><span><?php print $unknown ? 'Some poller values are unknown. Check device status, SNMP credentials, and the last Cacti error.' : 'Current captured values are numeric and usable.'; ?></span></div>
		<div class="<?php print $device_discovery_readings ? 'success' : 'warning'; ?>"><strong>Discovery evidence</strong><span><?php print $device_discovery_readings ? count($device_discovery_readings) . ' discovery methods have stored evidence.' : 'No discovery result has been collected yet.'; ?></span></div>
		<div class="<?php print $device_diagnostic_profile ? 'success' : 'warning'; ?>"><strong>On-demand diagnostics</strong><span><?php print $device_diagnostic_profile ? nms_h($device_diagnostic_profile['name'] . ' — ' . $device_diagnostic_profile['tools']) : 'No diagnostic profile is assigned. Assign one in Edit device to run Ping or Traceroute.'; ?></span></div>
		<div class="<?php print $failed || $stale ? 'warning' : 'success'; ?>"><strong>Collection health</strong><span><?php print ($failed || $stale) ? 'Review stale readings and discovery warnings in the Problems tab.' : 'No stale readings or discovery failures are currently recorded.'; ?></span></div>
	</div>
</section>

<section class="nms-panel nms-reading-raw"><div class="nms-panel-head"><div><h2>Raw device response</h2><p>Technical evidence for troubleshooting. Credentials are never shown.</p></div></div><pre><?php foreach ($device_readings as $reading) print nms_h($reading['parameter_key'] . ' = ' . $reading['raw_value'] . "\n"); foreach ($device_discovery_readings as $snapshot) print nms_h("\n[" . strtoupper($snapshot['protocol']) . '] ' . $snapshot['status'] . "\n" . $snapshot['data_json'] . "\n" . $snapshot['error'] . "\n"); ?></pre></section>
