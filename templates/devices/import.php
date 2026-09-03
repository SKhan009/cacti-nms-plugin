<?php
/**
 * @file import.php
 * Render SNMP-record upload, prior import details, and explicit simulator-health/probe controls.
 * Record samples describe a simulator; live device readings still require successful SNMP polling.
 */
?><?php $simulator_health = nms_snmpsim_health(); ?>
<section class="nms-panel nms-form-panel">
	<div class="nms-panel-head"><div><h2>SNMPSim configuration and health</h2><p>One server configuration for all newly imported simulated devices. No responder is launched by this page.</p></div></div>
	<div class="nms-device-form">
	<?php if ($simulator_health['error'] !== '') { ?>
		<p><?php print nms_h($simulator_health['error']); ?></p>
	<?php } else { $simulator_config = $simulator_health['config']; ?>
		<?php if ($simulator_health['service_state'] === 'manual') { ?>
		<p>Activation: administrator-managed. After each import, reload your SNMPSim responder using your operating system's service manager, then use Check live SNMP. NMS does not start services or queue automatic reloads in this mode.</p>
		<?php } else { ?>
		<p>Executable: <?php print nms_h($simulator_config['executable']); ?> — <?php print $simulator_health['executable'] ? 'accessible' : 'missing or not executable by the web user'; ?></p>
		<p>Service: <?php print nms_h($simulator_config['service'] . ' — ' . $simulator_health['service_state']); ?></p>
		<p>Activation queue: <?php print $simulator_health['reload_pending'] ? 'pending' : 'no pending marker'; ?>. Service status alone does not confirm SNMP; use Check live SNMP for a record below.</p>
		<?php } ?>
		<p>Data directory: <?php print nms_h($simulator_config['data_dir']); ?> — <?php print $simulator_health['data_writable'] ? 'writable' : 'missing or not writable'; ?></p>
		<p>Client endpoint: <?php print nms_h($simulator_config['client_address'] . ':' . $simulator_config['port']); ?></p>
		<?php if ($simulator_health['service_state'] === 'unavailable') { ?><p>Service status cannot be read by the web process. This does not mean SNMP is offline. Use Check live SNMP below; an administrator can verify the service on the server.</p><?php } ?>
	<?php } ?>
	<?php if ($simulator_message !== '') { ?><p role="status"><?php print nms_h($simulator_message); ?></p><?php } ?>
	</div>
</section>
<section class="nms-panel nms-form-panel">
	<div class="nms-panel-head"><div><h2>Upload an SNMP record</h2><p>Deploy a simulator community and generate native Cacti templates for numeric OIDs.</p></div><a class="nms-panel-action" download href="<?php print nms_h(nms_asset_url('snmpsim/examples/nms-device-demo.snmprec')); ?>">Download sample file</a></div>
	<div class="nms-import-explainer"><div><strong>1. Validate</strong><span>NMS checks every OID, type, value, filename, size, and community.</span></div><div><strong>2. Build templates</strong><span>Each numeric reading gets a Cacti Generic OID data-source and graph template.</span></div><div><strong>3. Activate</strong><span>The record is deployed to SNMPSim and linked to its Cacti host template and category.</span></div></div>
	<form method="post" action="devices.php?tab=import" enctype="multipart/form-data" class="nms-device-form nms-import-form">
		<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
		<input type="hidden" name="nms_action" value="import_snmprec">
		<div class="nms-form-grid">
			<label class="nms-file-field"><span>SNMP record file</span><input required type="file" name="snmprec_file" accept=".snmprec,text/plain"><small>Try the sample above. Maximum 2 MB and 5,000 lines.</small></label>
			<label><span>Simulator community</span><input required name="community" placeholder="serial-device-server"><small>The community becomes the SNMPSim record name.</small></label>
			<label><span>New Cacti host template</span><input required name="template_name" placeholder="Serial Device Server"><small>If this exact template exists, NMS safely adds the new graphs to it.</small></label>
			<label><span>Equipment category</span><select required name="category_id"><option value="">Select an equipment category</option><?php foreach ($categories as $category) { ?><option value="<?php print (int) $category['id']; ?>"><?php print nms_h($category['name']); ?></option><?php } ?></select></label>
		</div>
		<div class="nms-form-actions"><button type="submit">Upload and create templates</button></div>
	</form>
</section>

<section class="nms-panel">
	<div class="nms-panel-head"><div><h2>Imported SNMP records</h2><p>Generated objects remain editable in the Cacti backend.</p></div></div>
	<div class="nms-table-wrap nms-import-table-wrap"><table class="nms-table nms-import-table"><thead><tr><th>Community and file</th><th>Cacti host template</th><th>Category</th><th>Records</th><th>Generated templates</th><th>Imported</th><th>Next step</th></tr></thead><tbody>
	<?php if (!count($imports)) { ?><tr><td colspan="7" class="nms-empty">No SNMP record files have been imported.</td></tr><?php } ?>
	<?php foreach ($imports as $import) { ?><tr>
		<td data-label="Community and file"><strong><?php print nms_h($import['community']); ?></strong><small><?php print nms_h($import['original_name']); ?></small></td>
		<td data-label="Cacti host template"><strong><?php print nms_h($import['host_template_name']); ?></strong><small>ID <?php print (int) $import['host_template_id']; ?></small></td>
		<td data-label="Category"><?php print nms_h($import['category_name']); ?></td>
		<td data-label="Records"><strong><?php print (int) $import['record_count']; ?> OIDs</strong><small><?php print (int) $import['graphable_count']; ?> numeric readings</small></td>
		<td data-label="Generated templates"><strong><?php print (int) $import['graphable_count']; ?> data-source</strong><small><?php print (int) $import['graphable_count']; ?> graph templates</small></td>
		<td data-label="Imported"><?php print nms_h(nms_time_ago($import['created_at'])); ?><small>by <?php print nms_h($import['uploaded_by_name'] ?: 'system'); ?></small></td>
		<td data-label="Next step"><div class="nms-import-actions"><a class="nms-row-link" href="?tab=add&amp;snmpsim_import_id=<?php print (int) $import['id']; ?>">Add device</a>
		<form method="post" action="devices.php?tab=import">
			<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
			<input type="hidden" name="nms_action" value="check_snmpsim">
			<input type="hidden" name="import_id" value="<?php print (int) $import['id']; ?>">
			<button class="nms-row-link" type="submit">Check live SNMP</button>
		</form></div></td>
	</tr><?php } ?>
	</tbody></table></div>
</section>
