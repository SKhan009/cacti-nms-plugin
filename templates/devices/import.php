<?php
/**
 * @file import.php
 * Render SNMP-record upload, prior import details, and explicit simulator-health/probe controls.
 * Record samples describe a simulator; live device readings still require successful SNMP polling.
 */
?><?php
$simulator_health = nms_snmpsim_health();
$simulator_config = $simulator_health['error'] === '' ? $simulator_health['config'] : array();
$simulator_ready = $simulator_health['error'] === ''
	&& !empty($simulator_health['data_writable'])
	&& ($simulator_health['service_state'] === 'manual' || !empty($simulator_health['executable']));
?>
<section class="nms-panel nms-snmpsim-status<?php print $simulator_ready ? ' ready' : ' warning'; ?>">
	<div class="nms-snmpsim-status-copy">
		<span aria-hidden="true"></span>
		<strong><?php print $simulator_ready ? 'SNMPSim is working' : 'SNMPSim needs attention'; ?></strong>
		<small>Use Check live SNMP below to verify an imported record.</small>
	</div>
	<details class="nms-snmpsim-details">
		<summary>Details</summary>
		<div class="nms-snmpsim-details-popup">
			<strong>SNMPSim details</strong>
			<?php if ($simulator_health['error'] !== '') { ?>
				<p><?php print nms_h($simulator_health['error']); ?></p>
			<?php } else { ?>
				<?php if ($simulator_health['service_state'] === 'manual') { ?>
				<p><span>Activation</span>Administrator-managed</p>
				<?php } else { ?>
				<p><span>Executable</span><?php print nms_h($simulator_config['executable']); ?> — <?php print $simulator_health['executable'] ? 'accessible' : 'missing or inaccessible'; ?></p>
				<p><span>Service</span><?php print nms_h($simulator_config['service'] . ' — ' . $simulator_health['service_state']); ?></p>
				<p><span>Activation queue</span><?php print $simulator_health['reload_pending'] ? 'pending' : 'no pending marker'; ?></p>
				<?php } ?>
				<p><span>Data directory</span><?php print nms_h($simulator_config['data_dir']); ?> — <?php print $simulator_health['data_writable'] ? 'writable' : 'missing or not writable'; ?></p>
				<p><span>Client endpoint</span><?php print nms_h($simulator_config['client_address'] . ':' . $simulator_config['port']); ?></p>
				<?php if ($simulator_health['service_state'] === 'unavailable') { ?><small>The web process cannot read service status. Verify individual records with Check live SNMP.</small><?php } ?>
			<?php } ?>
		</div>
	</details>
	<?php if ($simulator_message !== '') { ?><p class="nms-snmpsim-message" role="status"><?php print nms_h($simulator_message); ?></p><?php } ?>
</section>
<div class="nms-upload-dialog" id="nmsUploadDialog" popover="auto" aria-labelledby="nmsUploadDialogTitle">
	<div class="nms-panel-head nms-upload-dialog-head"><div><h2 id="nmsUploadDialogTitle">Upload an SNMP record</h2><p>Deploy a simulator community and generate native Cacti templates for numeric OIDs.</p></div><button class="nms-upload-dialog-close" type="button" popovertarget="nmsUploadDialog" popovertargetaction="hide" aria-label="Close upload dialog">×</button></div>
	<div class="nms-upload-dialog-body">
	<a class="nms-panel-action nms-download-sample" download href="<?php print nms_h(nms_asset_url('snmpsim/examples/nms-device-demo.snmprec')); ?>">Download sample file</a>
	<div class="nms-import-explainer nms-import-workflow"><div><strong>1. Validate</strong><span>NMS checks every OID, type, value, filename, size, and community.</span></div><div><strong>2. Build data sources</strong><span>Numeric readings receive native Cacti data-source and starter graph templates automatically.</span></div><div><strong>3. Configure templates</strong><span>Review graph and device-template settings before creating a device.</span></div><div><strong>4. Verify and add</strong><span>Check live SNMP, then add the device as the final step.</span></div></div>
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
	</div>
</div>

<section class="nms-panel">
	<div class="nms-panel-head"><div><h2>Imported SNMP records</h2><p>Generated objects remain editable in the Cacti backend.</p></div><button class="nms-upload-trigger" id="nmsUploadTrigger" type="button" popovertarget="nmsUploadDialog" popovertargetaction="show">Upload MIB file or SNMPSim</button></div>
	<div class="nms-table-wrap nms-import-table-wrap"><table class="nms-table nms-import-table"><thead><tr><th>Community and file</th><th>Cacti host template</th><th>Category</th><th>Records</th><th>Generated templates</th><th>Imported</th><th>Next step</th></tr></thead><tbody>
	<?php if (!count($imports)) { ?><tr><td colspan="7" class="nms-empty">No SNMP record files have been imported.</td></tr><?php } ?>
	<?php foreach ($imports as $import) { ?><tr>
		<td data-label="Community and file"><strong><?php print nms_h($import['community']); ?></strong><small><?php print nms_h($import['original_name']); ?></small></td>
		<td data-label="Cacti host template"><strong><?php print nms_h($import['host_template_name']); ?></strong><small>ID <?php print (int) $import['host_template_id']; ?></small></td>
		<td data-label="Category"><?php print nms_h($import['category_name']); ?></td>
		<td data-label="Records"><strong><?php print (int) $import['record_count']; ?> OIDs</strong><small><?php print (int) $import['graphable_count']; ?> numeric readings</small></td>
		<td data-label="Generated templates"><strong><?php print (int) $import['graphable_count']; ?> data-source</strong><small><?php print (int) $import['graphable_count']; ?> graph templates</small></td>
		<td data-label="Imported"><?php print nms_h(nms_time_ago($import['created_at'])); ?><small>by <?php print nms_h($import['uploaded_by_name'] ?: 'system'); ?></small></td>
		<td data-label="Next step"><div class="nms-import-actions"><a class="nms-row-link nms-import-primary" href="templates.php?section=graph&amp;view=builder#graph-builder" title="Create or configure a Cacti graph template">Configure graph</a>
		<a class="nms-row-link" href="<?php print nms_h('templates.php?section=device&core=' . rawurlencode('host_templates.php?action=template_edit&id=' . (int) $import['host_template_id'])); ?>" title="Open the imported Cacti host template">Device template</a>
		<form method="post" action="devices.php?tab=import">
			<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
			<input type="hidden" name="nms_action" value="check_snmpsim">
			<input type="hidden" name="import_id" value="<?php print (int) $import['id']; ?>">
			<button class="nms-row-link" type="submit">Check live SNMP</button>
		</form>
		<a class="nms-row-link" href="?tab=add&amp;snmpsim_import_id=<?php print (int) $import['id']; ?>">Add device</a></div></td>
	</tr><?php } ?>
	</tbody></table></div>
</section>
