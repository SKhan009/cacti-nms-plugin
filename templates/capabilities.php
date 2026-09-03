<?php
/** Read-only per-device FCAPS evidence view. */
function nms_capability_tone($state) {
	$state = strtolower((string) $state);
	if (in_array($state, array('healthy', 'collecting', 'available'), true)) return 'success';
	if (in_array($state, array('failed', 'unavailable'), true)) return 'danger';
	if (in_array($state, array('stale', 'integration pending', 'no current samples'), true)) return 'warning';
	return 'muted';
}
$device_state = $capability_device ? nms_device_status_name($capability_device) : 'Unavailable';
?>
<main class="nms-shell nms-capabilities-page">
	<header class="nms-heading nms-capabilities-heading">
		<div><p class="nms-eyebrow">NMS / Faults</p><h1>FCAPS capabilities and evidence</h1><p>Configured collection is not proof that every function is supported by this model. Missing integrations remain unavailable.</p></div>
		<form method="get"><label>Device<select name="host_id" onchange="this.form.submit()" <?php print !$capability_devices ? 'disabled' : ''; ?>><?php if (!$capability_devices) { ?><option>No permitted devices</option><?php } ?><?php foreach ($capability_devices as $device) { ?><option value="<?php print (int) $device['id']; ?>" <?php print (int) $device['id'] === $device_id ? 'selected' : ''; ?>><?php print nms_h($device['description'] . ' (' . $device['hostname'] . ')'); ?></option><?php } ?></select></label><noscript><button type="submit">Show</button></noscript></form>
	</header>

	<?php if (!$capability_device) { ?><section class="nms-panel nms-empty"><p>No permitted Cacti devices are available.</p></section><?php } else { ?>
	<section class="nms-panel nms-capability-summary">
		<header class="nms-section-title"><div><h2><?php print nms_h($capability_device['description']); ?></h2><p><?php print nms_h($capability_device['hostname']); ?> · evidence read from Cacti and installed integrations</p></div><a class="nms-button" href="devices.php?tab=edit&amp;id=<?php print $device_id; ?>">Device settings</a></header>
		<div class="nms-capability-facts">
			<div><span>State</span><strong class="<?php print strtolower($device_state) === 'up' ? 'success' : 'danger'; ?>"><?php print nms_h($device_state); ?></strong></div>
			<div><span>SNMP identity</span><strong><?php print nms_h($capability_device['snmp_sysName'] ?: 'Not reported'); ?></strong></div>
			<div><span>Availability</span><strong><?php print nms_h(number_format((float) $capability_device['availability'], 1)); ?>%</strong></div>
			<div><span>Last Cacti update</span><strong><?php print nms_h($capability_device['last_updated'] ?: 'Not reported'); ?></strong></div>
		</div>
	</section>

	<section class="nms-panel">
		<header class="nms-section-title"><div><h2>Capability evidence</h2><p>Each state describes this device and the currently configured provider.</p></div></header>
		<div class="nms-capability-grid" role="list">
		<?php foreach ($device_capabilities['capabilities'] as $capability) { $tone = nms_capability_tone($capability['state']); ?>
			<article class="<?php print $tone; ?>" role="listitem"><div><span><?php print nms_h($capability['area']); ?></span><i><?php print nms_h($capability['state']); ?></i></div><h3><?php print nms_h($capability['capability']); ?></h3><strong><?php print nms_h($capability['provider']); ?></strong><p><?php print nms_h($capability['detail']); ?></p></article>
		<?php } ?>
		</div>
	</section>

	<section class="nms-panel nms-capability-methods">
		<header class="nms-section-title"><div><h2>Native data input methods</h2><p>Methods currently attached to this device through Cacti data sources.</p></div></header>
		<?php if (!$device_capabilities['methods']) { ?><p class="nms-empty">No data input methods are attached. Configure native Cacti data sources or data queries.</p><?php } else { ?><ul><?php foreach ($device_capabilities['methods'] as $method) { ?><li><strong><?php print nms_h($method['name']); ?></strong><span><?php print nms_h($input_types[$method['type_id']] ?? ('Cacti input type ' . $method['type_id'])); ?></span></li><?php } ?></ul><?php } ?>
	</section>
	<?php } ?>
</main>
