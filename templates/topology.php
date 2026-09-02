<?php
/**
 * @file topology.php
 * Render the site selector, required-configuration states, map canvas, inventory, and detail-panel shell.
 * The topology controller supplies core-backed data; js/nms-topology.js handles map interactions.
 */
?><main class="nms-shell nms-topology-shell">
	<div class="nms-heading nms-topology-heading">
		<div><p class="nms-eyebrow">NMS / Dynamic Topology</p><h1><?php print $selected_site ? nms_h($selected_site['name']) : 'Topology'; ?></h1><p>Device facts come directly from Cacti. Only map position and parent connection are stored by this plugin.</p></div>
		<form class="nms-site-picker" method="get" action="topology.php">
			<label for="site_id" data-nms-tip="Select the real Cacti site whose enabled devices should appear on this topology map.">Cacti site</label>
			<select id="site_id" name="site_id" onchange="this.form.submit()">
				<?php foreach ($sites as $site) { ?><option value="<?php print (int) $site['id']; ?>" <?php print (int) $site['id'] === $site_id ? 'selected' : ''; ?>><?php print nms_h($site['name']); ?> (<?php print (int) $site['device_count']; ?>)</option><?php } ?>
			</select>
		</form>
	</div>

	<?php if (!count($sites)) { ?>
	<section class="nms-panel nms-configuration-required"><h2>No Cacti site with devices is available</h2><p>Create a Site and assign enabled devices under <strong>Cacti → Management → Devices</strong>. This plugin intentionally does not create demo or fallback devices.</p><a href="<?php print nms_h($config['url_path'] . 'sites.php'); ?>">Open Cacti Sites</a></section>
	<?php } elseif (!count($topology_devices)) { ?>
	<section class="nms-panel nms-configuration-required"><h2>This Cacti site has no enabled devices</h2><p>Assign at least one device to this site in Cacti before building its topology.</p><a href="<?php print nms_h($config['url_path'] . 'host.php'); ?>">Open Cacti Devices</a></section>
	<?php } else { ?>
	<div class="nms-topology-summary">
		<div><span>Core devices</span><strong><?php print $root_device ? '1 configured' : 'Configuration required'; ?></strong></div>
		<div><span>Mapped</span><strong><?php print (int) $mapped_count; ?></strong></div>
		<div><span>Inventory</span><strong><?php print count($topology_devices); ?></strong></div>
		<div><span>Configured faults</span><strong><?php print (int) $fault_count; ?> devices</strong></div>
	</div>

	<section class="nms-panel nms-root-config">
		<div><h2>Topology root</h2><p>Select the main switch or gateway. It remains fixed while other Cacti devices can be dragged around it.</p></div>
		<form method="post" action="topology.php?site_id=<?php print $site_id; ?>">
			<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
			<input type="hidden" name="nms_action" value="set_root">
			<select name="host_id" required data-nms-tip="Choose the main switch or gateway. This device stays fixed and becomes the default parent for newly mapped devices."><option value="">Choose a Cacti device</option><?php foreach ($topology_devices as $device) { ?><option value="<?php print (int) $device['id']; ?>" <?php print $root_device && (int) $root_device['id'] === (int) $device['id'] ? 'selected' : ''; ?>><?php print nms_h($device['description']); ?> · <?php print nms_h($device['hostname']); ?></option><?php } ?></select>
			<button type="submit" data-nms-tip="Save the selected root for this Cacti site.">Save root device</button>
		</form>
	</section>

	<?php if (!$root_device) { ?>
	<section class="nms-panel nms-configuration-required"><h2>Select a topology root to continue</h2><p>No device is guessed automatically. Select the actual core switch or gateway above, then drag other devices from Inventory.</p></section>
	<?php } else { ?>
	<div class="nms-topology-grid">
		<aside class="nms-panel nms-inventory-panel">
			<div class="nms-topology-panel-head"><span>INVENTORY</span><h2>Cacti devices</h2><p>Drag an item onto the map.</p></div>
			<label class="nms-topology-search"><span>⌕</span><input id="nmsTopologySearch" type="search" placeholder="Search name, IP or poller"></label>
			<div id="nmsTopologyInventory" class="nms-inventory-list"></div>
		</aside>
		<section class="nms-panel nms-map-panel">
			<div class="nms-topology-panel-head nms-map-head">
				<div><span>LIVE CACTI DATA</span><h2>Network topology</h2></div>
				<div class="nms-map-actions">
					<div class="nms-zoom-controls" role="group" aria-label="Topology zoom controls">
						<button id="nmsZoomOut" type="button" data-nms-tip="Zoom out to show more of the topology canvas." aria-label="Zoom out">−</button>
						<output id="nmsZoomLevel" aria-live="polite">100%</output>
						<button id="nmsZoomIn" type="button" data-nms-tip="Zoom in for a closer view of mapped devices." aria-label="Zoom in">+</button>
						<button id="nmsZoomFit" class="fit" type="button" data-nms-tip="Fit every mapped device inside the visible canvas.">Fit</button>
					</div>
					<button id="nmsRefreshTopology" class="refresh" type="button" data-nms-tip="Reload device status, availability, interfaces, graph totals, and faults from Cacti core.">Refresh from Cacti</button>
				</div>
			</div>
			<div id="nmsTopologyCanvas" class="nms-topology-canvas"><div id="nmsTopologyWorld" class="nms-topology-world"><svg id="nmsTopologyLinks" aria-hidden="true"></svg></div><div class="nms-drop-guide">Drop Cacti devices here</div></div>
		</section>
		<aside class="nms-panel nms-device-detail" id="nmsTopologyDetail"><div class="nms-detail-empty">Select a mapped device to see its Cacti information.</div></aside>
	</div>
	<?php } ?>
	<?php } ?>
</main>
