<section class="nms-panel nms-form-panel">
	<div class="nms-panel-head"><div><h2>Upload an SNMP record</h2><p>Deploy a simulator community and generate native Cacti templates for numeric OIDs.</p></div><a class="nms-panel-action" download href="<?php print nms_h($nms_asset_base . 'snmpsim/examples/nms-device-demo.snmprec'); ?>">Download sample file</a></div>
	<div class="nms-import-explainer"><div><strong>1. Validate</strong><span>NMS checks every OID, type, value, filename, size, and community.</span></div><div><strong>2. Build templates</strong><span>Each numeric reading gets a Cacti Generic OID data-source and graph template.</span></div><div><strong>3. Activate</strong><span>The record is deployed to SNMPSim and linked to its Cacti host template and category.</span></div></div>
	<form method="post" action="devices.php?tab=import" enctype="multipart/form-data" class="nms-device-form nms-import-form">
		<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
		<input type="hidden" name="nms_action" value="import_snmprec">
		<div class="nms-form-grid">
			<label class="nms-file-field"><span>SNMP record file</span><input required type="file" name="snmprec_file" accept=".snmprec,text/plain"><small>Try the sample above. Maximum 2 MB and 5,000 lines.</small></label>
			<label><span>Simulator community</span><input required name="community" placeholder="serial-device-server"><small>The community becomes the SNMPSim record name.</small></label>
			<label><span>New Cacti host template</span><input required name="template_name" placeholder="Serial Device Server"><small>If this exact template exists, NMS safely adds the new graphs to it.</small></label>
			<label><span>Device category (Cacti Tree)</span><select required name="category_id"><option value="">Select a Cacti Tree</option><?php foreach ($categories as $category) { ?><option value="<?php print (int) $category['id']; ?>"><?php print nms_h($category['name']); ?></option><?php } ?></select></label>
		</div>
		<div class="nms-form-actions"><button type="submit">Upload and create templates</button></div>
	</form>
</section>

<section class="nms-panel">
	<div class="nms-panel-head"><div><h2>Imported SNMP records</h2><p>Generated objects remain editable in the Cacti backend.</p></div></div>
	<div class="nms-table-wrap"><table class="nms-table nms-import-table"><thead><tr><th>Community and file</th><th>Cacti host template</th><th>Category</th><th>Records</th><th>Generated templates</th><th>Imported</th><th>Next step</th></tr></thead><tbody>
	<?php if (!count($imports)) { ?><tr><td colspan="7" class="nms-empty">No SNMP record files have been imported.</td></tr><?php } ?>
	<?php foreach ($imports as $import) { ?><tr>
		<td><strong><?php print nms_h($import['community']); ?></strong><small><?php print nms_h($import['original_name']); ?></small></td>
		<td><strong><?php print nms_h($import['host_template_name']); ?></strong><small>ID <?php print (int) $import['host_template_id']; ?></small></td>
		<td><?php print nms_h($import['category_name']); ?></td>
		<td><strong><?php print (int) $import['record_count']; ?> OIDs</strong><small><?php print (int) $import['graphable_count']; ?> numeric readings</small></td>
		<td><strong><?php print (int) $import['graphable_count']; ?> data-source</strong><small><?php print (int) $import['graphable_count']; ?> graph templates</small></td>
		<td><?php print nms_h(nms_time_ago($import['created_at'])); ?><small>by <?php print nms_h($import['uploaded_by_name'] ?: 'system'); ?></small></td>
		<td><a class="nms-row-link" href="?tab=add&host_template_id=<?php print (int) $import['host_template_id']; ?>&snmp_community=<?php print rawurlencode($import['community']); ?>&snmp_port=1161">Add device</a></td>
	</tr><?php } ?>
	</tbody></table></div>
</section>
