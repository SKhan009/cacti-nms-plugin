<?php
/** Render plain-language evidence for the latest values captured from one Cacti device. */
$nan_count = 0;
$stale_count = 0;
foreach ($device_readings as $reading) {
	if (nms_reading_is_unknown($reading["raw_value"])) {
		$nan_count++;
	} elseif (!nms_parameter_is_fresh($reading["last_seen"])) {
		$stale_count++;
	}
}
?>
<section class="nms-panel nms-reading-summary">
	<div class="nms-panel-head"><div><p class="nms-eyebrow">NMS / Device monitoring</p><h2>Device readings</h2><p>Latest values captured from Cacti’s poller and stored against this device’s RRD data sources.</p></div><a class="nms-panel-action" href="devices.php?tab=edit&amp;id=<?php print (int) $edit_device["id"]; ?>">Back to device</a></div>
	<div class="nms-reading-facts"><div><span>Device</span><strong><?php print nms_h($edit_device["description"]); ?></strong><small><?php print nms_h($edit_device["hostname"]); ?></small></div><div><span>Latest readings</span><strong><?php print count($device_readings); ?></strong><small>Captured after native polling</small></div><div class="<?php print $nan_count ? "warning" : "success"; ?>"><span>Unknown / NaN</span><strong><?php print $nan_count; ?></strong><small><?php print $nan_count ? "Needs a poller or SNMP check" : "No unknown samples"; ?></small></div><div class="<?php print $stale_count ? "warning" : "success"; ?>"><span>Stale</span><strong><?php print $stale_count; ?></strong><small>Older than two poll intervals</small></div></div>
</section>

<section class="nms-panel nms-reading-diagnosis">
	<div class="nms-panel-head"><div><h2>Why a graph shows NaN</h2><p>NaN means RRDtool has no usable number for that point. It is not a real device measurement.</p></div></div>
	<div class="nms-reading-diagnosis-grid"><div><strong>1. Poller result</strong><span>Check Cacti’s device status, last error, and poller log for timeouts or authentication failures.</span></div><div><strong>2. SNMP value</strong><span>Walk the configured OID using the same version and credentials saved on the device.</span></div><div><strong>3. Data source</strong><span>Confirm the OID returns a numeric type. Text values belong in inventory, not an RRD graph.</span></div><div><strong>4. First sample</strong><span>Rates often need two polls before RRDtool can calculate a value. Wait for the next normal poll.</span></div></div>
</section>

<section class="nms-panel nms-reading-table-panel">
	<div class="nms-panel-head"><div><h2>Actual captured readings</h2><p>The raw value is the exact poller value. “Human meaning” explains its condition without changing it.</p></div></div>
	<?php if (!$device_readings) { ?><p class="nms-reading-empty">No RRD-backed readings have been captured yet. Confirm that the device has data sources and wait for a Cacti poll.</p><?php } else { ?>
	<div class="nms-reading-table-wrap"><table class="nms-table nms-reading-table"><thead><tr><th>Reading</th><th>Human value</th><th>State</th><th>Human meaning</th><th>Raw evidence</th><th>Last captured</th></tr></thead><tbody><?php foreach ($device_readings as $reading) {
		$presentation = nms_reading_presentation($reading); ?><tr><td><strong><?php print nms_h($reading["display_name"] ?: $reading["parameter_name"]); ?></strong><small><?php print nms_h($reading["data_source_name"] ?: $reading["parameter_key"]); ?></small></td><td><strong><?php print nms_h($presentation["value"]); ?></strong></td><td><span class="nms-reading-state <?php print nms_h($presentation["tone"]); ?>"><?php print nms_h($presentation["state"]); ?></span></td><td><strong><?php print nms_h($presentation["meaning"]); ?></strong><small><?php print nms_h($presentation["next"]); ?></small></td><td><code><?php print nms_h($reading["raw_value"] === "" ? "(empty)" : $reading["raw_value"]); ?></code><?php if ($reading["rrd_path"]) { ?><small><?php print nms_h($reading["rrd_path"]); ?></small><?php } ?></td><td><?php print nms_h($reading["last_seen"]); ?></td></tr><?php } ?></tbody></table></div><?php } ?>
</section>
