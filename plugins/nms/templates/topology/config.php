<?php
/** Live NMS discovery flow; no node, rack, or manual capacity prerequisites. */
?>
<main class="nms-shell nms-topology-config">
<div class="nms-heading"><div><p class="nms-eyebrow">NMS / Topology</p><h1><?php print $config_section === "view"
	? "Topology view"
	: ($config_section === "results"
		? "Discovery results"
		: "Topology configuration"); ?></h1><p>Select real Cacti devices and discover their advertised LLDP/CDP neighbours.</p></div></div>
<?php if ($config_section === "view") { ?><nav class="nms-map-tabs" aria-label="Topology views"><a href="topology.php?tab=discovered" aria-current="page">Network view</a><a href="topology.php?tab=map">Map view</a></nav><?php } ?>
<?php
if ($notice) { ?><div class="nms-config-notice" role="status"><?php print nms_h($notice); ?></div><?php }
if ($page_error) { ?><div class="nms-config-error" role="alert"><?php print nms_h($page_error); ?></div><?php }
?>
<?php if ($config_section === "devices") { ?>
<section class="nms-panel"><div class="nms-panel-head"><h2>Configure live topology</h2></div><ol class="nms-live-flow">
<li><a href="devices.php?tab=inventory">Check Cacti devices</a>: correct site, SNMP settings and assigned collector. Enable LLDP/CDP and permit SNMP access to neighbour tables on the equipment.</li>
<li><a href="discovery_presets.php">Create an LLDP/CDP preset</a>: select protocol and collection timing.</li>
<li>Select the site below, assign a preset to each device, and save.</li>
<li>Choose <strong>Test discovery</strong>, then inspect the topology after the next poll cycle.</li>
<li>Open <a href="topology.php?tab=discovered&amp;site_id=<?php print (int) $site_id; ?>">Topology view</a> for discovered connections.</li>
</ol><p class="nms-live-help">Interfaces and connected ports come from SNMP. A device with no advertised neighbours will have no discovered links.</p></section>
<?php } ?>
<?php if (
	$config_section !== "view"
) { ?><form class="nms-config-picker nms-live-picker" method="get" action="topology.php"><input type="hidden" name="tab" value="<?php print nms_h(
	$topology_tab,
); ?>">
<label>Cacti site<select name="site_id" onchange="this.form.submit()"><option value="">Select site</option><?php foreach (
	$sites
	as $site
) { ?><option value="<?php print (int) $site["id"]; ?>" <?php if ((int) $site["id"] === $site_id) {
	print "selected";
} ?>><?php print nms_h(
	$site["name"],
); ?></option><?php } ?></select></label><button type="submit">Show devices</button></form><?php } ?>
<?php if ($config_section === "view") {
	require __DIR__ . "/canvas.php";
} elseif (
	!$selected_site
) { ?><section class="nms-panel"><p>Add enabled devices to a Cacti site to configure discovery.</p></section><?php } elseif (
	$config_section === "devices"
) {
	require __DIR__ . "/../discovery/devices.php";
} else {
	require __DIR__ . "/discovery.php";
} ?>
</main>
