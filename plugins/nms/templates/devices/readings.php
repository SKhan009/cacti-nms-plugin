<?php
/** Render complete device reading, discovery, diagnosis, and raw evidence workspace. */
require_once __DIR__ . '/../../includes/configuration/monitoring.php';
$serial_status = nms_config_connection_status((int) $edit_device['id']);
$serial_samples = [];
if ($serial_status) {
	if (($edit_device['disabled'] ?? '') === 'on') $serial_status['status'] = 'Disabled';
	try {
		$serial_target = nms_config_target((int) $edit_device['id'], false);
		foreach ($serial_target['fields'] as $key => $field) {
			$serial_samples[] = ['field' => $field, 'sample' => nms_config_reading((int) $edit_device['id'], $key)];
		}
	} catch (Throwable $e) { /* Missing or ineligible equipment remains unavailable. */ }
}
if ($serial_status && !$serial_samples) {
	$serial_samples[] = ['field' => ['label' => 'Equipment reading', 'unit' => ''],
		'sample' => ['status' => $serial_status['status'] === 'Disabled' ? 'disabled' : 'unavailable', 'value' => null, 'observed_at' => null]];
}
$serial_problems = count(array_filter($serial_samples, function ($entry) {
	return $entry['sample']['status'] !== 'current';
}));
$unknown = 0;
$stale = 0;
$interfaces = 0;
$traffic = 0;
$battery_evidence = nms_device_battery_readings((int) $edit_device['id']);
$device_readings = array_merge($device_readings, $battery_evidence['readings']);

foreach ($device_readings as $reading) {
	$category = nms_reading_category($reading);
	$interfaces += $category === 'interfaces';
	$traffic += $category === 'traffic';
	$unknown += nms_reading_is_actionable_problem($reading);
	$stale += !nms_reading_is_unknown($reading['raw_value']) && !nms_parameter_is_fresh($reading['last_seen']);
}

$failed = array_filter($device_discovery_readings, function ($snapshot) {
	return $snapshot['status'] !== 'success';
});
$lastRead = $device_readings ? $device_readings[0]['last_seen'] : ($edit_device['last_updated'] ?: 'Not recorded');
if ($serial_status) {
	$serial_times = array_filter(array_column(array_column($serial_samples, 'sample'), 'observed_at'));
	$lastRead = $serial_times ? max($serial_times) : 'Not recorded';
}
?>
<section class="nms-panel nms-reading-summary">
	<div class="nms-panel-head"><div><p class="nms-eyebrow">NMS / Device monitoring</p><h2>Device reading</h2><p>Explanation of the current readings Cacti collected for this device.</p></div><div class="nms-reading-actions"><a class="nms-panel-action" href="diagnostics.php?section=diagnosis&amp;host_id=<?php print (int) $edit_device['id']; ?>">On-demand diagnostics</a><a class="nms-panel-action" href="<?php print nms_h(nms_cacti_url('graph_view.php?action=tree&host_id=' . (int) $edit_device['id'])); ?>">Open Cacti graphs</a><a class="nms-panel-action" href="devices.php?tab=edit&amp;id=<?php print (int) $edit_device['id']; ?>">Back to device</a></div></div>
	<div class="nms-reading-facts">
		<div><span>Device</span><strong><?php print nms_h($edit_device['description']); ?></strong><small><?php print nms_h($serial_status ? $serial_status['connection'] : $edit_device['hostname'] . ' · SNMPv' . $edit_device['snmp_version']); ?></small></div>
		<div><span><?php print $serial_status ? 'Serial response' : 'SNMP port'; ?></span><strong><?php print $serial_status ? nms_h($serial_status['status']) : (int) $edit_device['snmp_port']; ?></strong><small><?php print nms_h($edit_device['poller_name'] ?: 'Assigned collector'); ?></small></div>
		<div><span>Last read</span><strong><?php print nms_h($lastRead); ?></strong><small><?php print $serial_status ? 'Last serial collection attempt' : (nms_parameter_is_fresh($lastRead) ? 'Current poller evidence' : 'Waiting for a fresh poll'); ?></small></div>
		<div><span>Interfaces</span><strong><?php print $interfaces; ?></strong><small>RRD-backed readings</small></div>
		<div class="<?php print $unknown || $failed || $serial_problems ? 'warning' : 'success'; ?>"><span>Problems</span><strong><?php print $unknown + count($failed) + $serial_problems; ?></strong><small>Unknown values or discovery failures</small></div>
	</div>
</section>

<?php if (empty($nms_workspace_embedded)) { ?>
<?php
require_once __DIR__ . '/../../includes/topology/discovery.php';
$address_discovery = nms_topology_discovery((int) $edit_device['site_id']);
$address_hosts = $address_discovery['hosts'];
$address_identities = nms_nd_device_identities($address_hosts, $address_discovery['snapshots']);
$address_identity = $address_identities[(int) $edit_device['id']] ?? ['addresses' => [], 'matches' => [], 'message' => 'Assign an enabled discovery preset to collect device addresses.', 'checked_at' => ''];
?>
<section class="nms-panel" id="device-addresses">
<div class="nms-panel-head"><div><h2>Device IP addresses and identity</h2><p>Management address: <?php print nms_h($edit_device['hostname']); ?>. Reported addresses belong to this SNMP agent; shared or virtual addresses may also appear on other devices.</p></div></div>
<?php require __DIR__ . '/address_identity.php'; ?>
</section>

<?php } ?>
<?php if (!$serial_status) { ?>
<section class="nms-reading-live"><strong>● Live reading</strong><span>Values come from each graph data source’s latest RRD sample.</span><code>Last response: <?php print nms_h($lastRead); ?></code></section>
<?php } ?>

<?php if(!empty($nms_workspace_embedded)) {
    require __DIR__.'/../workspace/reading_tabs.php';
    if(!in_array($workspace_section,['overview','neighbours'],true)) {
        require __DIR__.'/../workspace/'.$workspace_section.'.php';
        return;
    }
} ?>
<section class="nms-panel nms-reading-table-panel">
    <div class="nms-panel-head"><div><h2>Readings &amp; discovery evidence</h2></div></div>
	<div class="nms-reading-table-wrap"><table class="nms-table nms-reading-table"><thead><tr><th>Status</th><th>Area</th><th>OID / Source</th><th>Reading</th><th>Explanation</th><th>What NMS does</th><th>Last checked</th></tr></thead><tbody>
		<?php foreach ($serial_samples as $entry) { $sample = $entry['sample']; $field = $entry['field']; $current = $sample['status'] === 'current'; ?>
			<tr data-reading-row data-category="serial" data-protocol="serial" data-problem="<?php print $current ? '0' : '1'; ?>">
				<td><span class="nms-reading-state <?php print $current ? 'success' : 'warning'; ?>"><?php print nms_h(ucfirst($sample['status'])); ?></span></td><td><?php print nms_h($field['label']); ?></td><td>Serial collector</td><td><strong><?php print $current ? nms_h($sample['value'] . ' ' . $field['unit']) : 'Not available'; ?></strong></td><td><?php print $current ? 'The device returned this value.' : 'No current value is available.'; ?></td><td>Uses the assigned connection and equipment profile.</td><td><?php print nms_h($sample['observed_at'] ?: 'Not recorded'); ?></td>
			</tr>
		<?php } ?>
		<?php foreach ($device_readings as $reading) {
			$p = nms_reading_presentation($reading);
			$category = nms_reading_category($reading);
			$label = nms_reading_label($reading);
			$source = nms_reading_source($reading);
			$action = nms_reading_action($reading);
		?>
			<tr data-reading-row data-category="<?php print nms_h($category); ?>" data-protocol="snmp" data-problem="<?php print $p['tone'] === 'warning' ? '1' : '0'; ?>">
				<td><span class="nms-reading-state <?php print nms_h($p['tone']); ?>"><?php print nms_h($p['state']); ?></span></td><td><?php print nms_h($label); ?></td><td><code><?php print nms_h($source); ?></code></td><td><strong><?php print nms_h($p['value']); ?></strong></td><td><strong><?php print nms_h($p['meaning']); ?></strong></td><td><?php print nms_h($action); ?></td><td><?php print nms_h($reading['last_seen']); ?></td>
			</tr>
		<?php } foreach ($device_discovery_readings as $snapshot) { $isFailed = $snapshot['status'] !== 'success'; ?>
			<tr data-reading-row data-category="discovery" data-protocol="<?php print nms_h($snapshot['protocol']); ?>" data-problem="<?php print $isFailed ? '1' : '0'; ?>">
				<td><span class="nms-reading-state <?php print $isFailed ? 'warning' : 'success'; ?>"><?php print nms_h(strtoupper($snapshot['status'])); ?></span></td><td><?php print nms_h(strtoupper($snapshot['protocol'])); ?> discovery</td><td><code><?php print nms_h($snapshot['protocol'] === 'lldp' ? 'LLDP-MIB' : ($snapshot['protocol'] === 'cdp' ? 'Cisco CDP-MIB' : 'NMS discovery')); ?></code></td><td><?php print nms_h(nms_reading_snapshot_summary($snapshot)); ?></td><td><strong><?php print $isFailed ? 'Collection needs attention.' : 'Discovery evidence was collected.'; ?></strong><small><?php print nms_h($snapshot['error'] ?: 'NMS retains this evidence for topology matching.'); ?></small></td><td><?php print $isFailed ? 'Keeps the error visible and continues with other methods.' : 'Uses evidence to build topology and endpoint relationships.'; ?></td><td><?php print nms_h($snapshot['succeeded_at'] ?: $snapshot['attempted_at']); ?></td>
			</tr>
		<?php } ?>
	</tbody></table></div>
</section>

<section class="nms-panel nms-reading-raw">
<div class="nms-panel-head"><h2 data-nms-tip="Evidence follows the selected tab. RRD commands may return multiple data sources from the same file. Credentials are excluded.">Collector output</h2></div>
<pre><?php foreach($device_readings as $reading) {
    $category=nms_reading_category($reading);$problem=nms_reading_presentation($reading)['tone']==='warning'?'1':'0';
    $command=trim((string)($reading['collector_command']??''));$output=trim((string)($reading['collector_output']??''));
    if($command!=='' && $output!=='') { ?><span data-reading-evidence data-command="1" data-category="<?php print nms_h($category); ?>" data-protocol="snmp" data-problem="<?php print $problem; ?>"><?php print nms_h('$ '.$command."\n".$output."\n\n"); ?></span><?php } ?>
<span data-reading-evidence data-category="<?php print nms_h($category); ?>" data-protocol="snmp" data-problem="<?php print $problem; ?>"><?php print nms_h(nms_reading_raw_evidence([$reading],[])."\n"); ?></span><?php }
foreach($device_discovery_readings as $snapshot) { ?><span data-reading-evidence data-category="discovery" data-protocol="<?php print nms_h($snapshot['protocol']); ?>" data-problem="<?php print $snapshot['status']!=='success'?'1':'0'; ?>"><?php print nms_h(strtoupper($snapshot['protocol']).' · '.$snapshot['status'].' · '.($snapshot['succeeded_at']?:$snapshot['attempted_at'])."\n".($snapshot['error']?:nms_reading_raw_evidence([],[$snapshot]))."\n\n"); ?></span><?php }
foreach($serial_samples as $entry) { ?><span data-reading-evidence data-category="serial" data-protocol="serial" data-problem="<?php print $entry['sample']['status']==='current'?'0':'1'; ?>"><?php print nms_h($entry['field']['label'].' · '.$entry['sample']['status'].' · '.($entry['sample']['value']??'Not available').' · '.($entry['sample']['observed_at']??'Not recorded')."\n"); ?></span><?php } ?><span data-reading-evidence-empty hidden>No collector output for this selection.</span></pre>
</section>
<?php if(!empty($nms_workspace_embedded) && $workspace_section==='neighbours') require __DIR__.'/../workspace/neighbours.php'; ?>
