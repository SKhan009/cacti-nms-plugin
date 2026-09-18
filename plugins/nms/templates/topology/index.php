<?php
/**
 * @file topology.php
 * Render the required-configuration states, map canvas, inventory, and detail-panel shell.
 * The controller keeps the authorized Cacti site as an internal data boundary; one site may contain many nodes/vehicles.
 */
?><main class="nms-shell nms-topology-shell">
	<?php require __DIR__ . "/discovery.php"; ?>
	<?php if ($page_error !== "") { ?><section class="nms-panel" role="alert"><p><?php print nms_h(
	$page_error,
); ?></p></section><?php } ?>
	<?php if (!count($sites)) { ?>
	<section class="nms-panel nms-configuration-required"><h2>No Cacti site with devices is available</h2><p>Create a Site and assign enabled devices under <strong>Cacti → Management → Devices</strong>. This plugin intentionally does not create demo or fallback devices.</p><a href="<?php print nms_h(
 	nms_cacti_url("sites.php"),
 ); ?>">Open Cacti Sites</a></section>
	<?php } elseif (!count($topology_devices)) { ?>
	<section class="nms-panel nms-configuration-required"><h2>This Cacti site has no enabled devices</h2><p>Assign at least one device to this site in Cacti before building its topology.</p><a href="<?php print nms_h(
 	nms_cacti_url("host.php"),
 ); ?>">Open Cacti Devices</a></section>
	<?php } else { ?>
	<div class="nms-topology-summary">
		<div><span>Core devices</span><strong><?php print $root_device
  	? "1 configured"
  	: "Configuration required"; ?></strong></div>
		<div><span>Mapped</span><strong><?php print (int) $mapped_count; ?></strong></div>
		<div><span>Inventory</span><strong><?php print count($topology_devices); ?></strong></div>
		<div><span>Configured faults</span><strong><?php print (int) $fault_count; ?> devices</strong></div>
	</div>

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
	<?php if ($site_id > 0) { ?>
	<section class="nms-panel nms-form-panel" id="connections">
		<div class="nms-panel-head"><div><h2>Connections</h2><p>Record network, power and containment relationships independently of map placement. Multiple connections and cross-site endpoints are supported. LLDP/CDP discovery is not connected; manual records never get a discovered timestamp.</p></div></div>
		<form method="post" action="topology.php?site_id=<?php print $site_id; ?>#connections" class="nms-device-form">
			<input type="hidden" name="__csrf_magic" value="<?php print nms_h(
   	$nms_csrf_token,
   ); ?>"><input type="hidden" name="nms_action" value="save_relationship">
			<div class="nms-form-grid"><?php foreach (
   	["source" => "Source / supplying / containing endpoint", "target" => "Target / supplied / contained endpoint"]
   	as $key => $label
   ) { ?><label><?php print nms_h(
	$label,
); ?><select name="<?php print $key; ?>_endpoint" required><option value="">Select a device or interface</option><?php foreach (
	$relationship_options
	as $value => $name
) { ?><option value="<?php print nms_h($value); ?>"><?php print nms_h(
	$name,
); ?></option><?php } ?></select></label><?php } ?>
			<label>Relationship type<select name="relation_type"><option value="network">Network</option><option value="power">Power supply</option><option value="containment">Containment</option></select></label></div>
			<p>Interface choices contain a Cacti data-query ID and SNMP interface index, not UDP/TCP ports. Only connections whose two endpoints are mapped on this site are drawn; cross-site connections remain in the list.</p>
			<div class="nms-form-actions"><button type="submit">Save manual connection</button></div>
		</form>
		<div class="nms-table-wrap"><table class="nms-table"><thead><tr><th>Source</th><th>Target</th><th>Type / source</th><th>Evidence</th><th>Action</th></tr></thead><tbody>
		<?php if (
  	!$relationships
  ) { ?><tr><td colspan="5">No explicit connections recorded. Old layout parents are retained as layout metadata, not promoted to verified network links.</td></tr><?php } ?>
		<?php foreach ($relationships as $edge) { ?><tr><td><?php print nms_h($edge["source_name"]); ?><small><?php print nms_h(
	$edge["source_identity"],
); ?></small></td><td><?php print nms_h($edge["target_name"]); ?><small><?php print nms_h(
	$edge["target_identity"],
); ?></small></td><td><?php print nms_h(
	$edge["relation_type"] . " · " . $edge["provenance"],
); ?></td><td><?php print nms_h($edge["identity_state"]); ?><small><?php print $edge["last_seen"]
	? "Last observed: " . nms_h($edge["last_seen"])
	: "Manual record; not discovered"; ?></small><small>Recorded: <?php print nms_h(
	$edge["updated_at"],
); ?></small></td><td><?php if (
	$edge["provenance"] === "manual"
) { ?><form method="post" action="topology.php?site_id=<?php print $site_id; ?>#connections"><input type="hidden" name="__csrf_magic" value="<?php print nms_h(
	$nms_csrf_token,
); ?>"><input type="hidden" name="nms_action" value="archive_relationship"><input type="hidden" name="relationship_id" value="<?php print (int) $edge[
	"id"
]; ?>"><button type="submit">Archive</button></form><?php } ?></td></tr><?php } ?>
		</tbody></table></div><p>Archived manual connections remain in NMS storage. Saving the same endpoints and type restores the record.</p>
	</section>
	<?php } ?>
</main>
