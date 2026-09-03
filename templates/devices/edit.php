<?php
/**
 * @file edit.php
 * Render existing core device settings and graph/data-query associations with their management controls.
 * Submitted actions return to devices.php; this template does not implement a separate device store.
 */
$device_id = (int) $edit_device['id'];
$core_base = $config['url_path'];
$snmp_uptime_ticks = (int) ($edit_device['snmp_sysUpTimeInstance'] ?? 0);
$snmp_uptime_text = $snmp_uptime_ticks > 0 && function_exists('get_uptime')
	? get_uptime($edit_device)
	: 'Not reported';
$device_actions = array(
	array('Create New Device', $core_base . 'host.php?action=edit', 'Open the Cacti device creation form.'),
	array('Create Graphs for this Device', $core_base . 'graphs_new.php?reset=true&host_id=' . $device_id, 'Select graph templates and data queries for this device.'),
	array('Re-Index Device', '', 'Refresh indexed SNMP data such as interfaces and sensors.', 'reindex_device'),
	array('Enable Device Debug', $core_base . 'host.php?action=enable_debug&host_id=' . $device_id, 'Enable detailed Cacti troubleshooting for this device.'),
	array('Repopulate Poller Cache', $core_base . 'host.php?action=repopulate&host_id=' . $device_id, 'Rebuild the poller entries for this device.'),
	array('View Poller Cache', $core_base . 'utilities.php?poller_action=-1&action=view_poller_cache&host_id=' . $device_id . '&template_id=-1&filter=&rows=-1', 'Inspect the poller items currently generated for this device.'),
	array('Data Source List', $core_base . 'data_sources.php?reset=true&host_id=' . $device_id . '&ds_rows=30&filter=&template_id=-1&method_id=-1&page=1', 'View all Cacti data sources linked to this device.'),
	array('Graph List', $core_base . 'graphs.php?reset=true&host_id=' . $device_id . '&graph_rows=30&filter=&template_id=-1&page=1', 'View all Cacti graphs linked to this device.')
);
?>
<nav class="nms-page-tabs" aria-label="Device sections">
	<a href="#device-overview">Device overview</a>
	<a href="#classification">Classification</a>
	<a href="#operational-groups">Groups</a>
	<a href="#capabilities">FCAPS capabilities</a>
	<a href="#nms-device-form">Device settings</a>
	<a href="#graph-templates">Graph templates</a>
	<a href="#data-queries">Data queries</a>
</nav>
<section class="nms-panel nms-device-overview" id="device-overview">
	<div class="nms-panel-head">
		<div><h2>Device overview</h2><p>Current state and collection details read directly from Cacti.</p></div>
		<div class="nms-overview-actions">
			<a class="nms-panel-action" href="<?php print nms_h($core_base . 'host.php?action=edit&id=' . $device_id); ?>">Open in Cacti</a>
			<details class="nms-device-tools-menu">
				<summary><span>Device actions</span><small>ID <?php print $device_id; ?></small></summary>
				<nav aria-label="Cacti device actions"><?php foreach ($device_actions as $device_action) { ?><?php if (!empty($device_action[3])) { ?><form method="post" action="devices.php?tab=edit&id=<?php print $device_id; ?>"><input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="<?php print nms_h($device_action[3]); ?>"><input type="hidden" name="id" value="<?php print $device_id; ?>"><button type="submit" title="<?php print nms_h($device_action[2]); ?>"><?php print nms_h($device_action[0]); ?><span>↻</span></button></form><?php } else { ?><a href="<?php print nms_h($device_action[1]); ?>" title="<?php print nms_h($device_action[2]); ?>"><?php print nms_h($device_action[0]); ?><span>↗</span></a><?php } ?><?php } ?></nav>
			</details>
		</div>
	</div>
	<div class="nms-device-facts">
		<div><span>State</span><strong><?php print nms_h(nms_device_status_name($edit_device)); ?></strong></div>
		<div><span>SNMP identity</span><strong><?php print nms_h($edit_device['snmp_sysName'] ?: 'Pending'); ?></strong></div>
		<div data-nms-tip="Current chassis serial number comes from a successful read of the mapped serial OID, never the imported sample.">
			<span>Serial number (SNMP)</span>
			<strong><?php print nms_h($edit_device['serial_number'] !== '' && $edit_device['serial_number'] !== null
				? $edit_device['serial_number']
				: (!empty($edit_device['serial_configured']) ? ucfirst($edit_device['serial_status']) . ' — no current value' : 'Not available')); ?></strong>
			<?php if ($edit_device['serial_status'] === 'changed') { ?><small>Changed from baseline</small>
			<?php } elseif ($edit_device['serial_status'] === 'unconfigured') { ?><small>SNMP is disabled in Cacti; collection was not attempted</small>
			<?php } elseif ($edit_device['serial_status'] === 'failed') { ?><small>SNMP collection failed or the device is unavailable</small><?php } ?>
		</div>
		<div><span>Poller items</span><strong><?php print (int) $edit_device['poller_item_count']; ?></strong></div>
		<div><span>Data sources</span><strong><?php print (int) $edit_device['data_source_count']; ?></strong></div>
		<div><span>Graphs</span><strong><?php print (int) $edit_device['graph_count']; ?></strong></div>
		<div><span>Availability</span><strong><?php print nms_h(number_format((float) $edit_device['availability'], 1)); ?>%</strong></div>
	</div>
	<section class="nms-snmp-information" aria-labelledby="nms-snmp-information-title">
		<div class="nms-snmp-information-head">
			<div><h3 id="nms-snmp-information-title">SNMP information</h3><p>Identity values last collected and stored by Cacti.</p></div>
			<strong><?php print nms_h($edit_device['description']); ?> <small>(<?php print nms_h($edit_device['hostname']); ?>)</small></strong>
		</div>
		<dl>
			<div class="system"><dt>System</dt><dd><?php print nms_h($edit_device['snmp_sysDescr'] ?: 'Not reported'); ?></dd></div>
			<div><dt>Uptime</dt><dd><?php print $snmp_uptime_ticks > 0 ? nms_h((string) $snmp_uptime_ticks . ' (' . $snmp_uptime_text . ')') : 'Not reported'; ?></dd></div>
			<div><dt>Hostname</dt><dd><?php print nms_h($edit_device['snmp_sysName'] ?: 'Not reported'); ?></dd></div>
			<div><dt>Location</dt><dd><?php print nms_h($edit_device['snmp_sysLocation'] ?: 'Not reported'); ?></dd></div>
			<div><dt>Contact</dt><dd><?php print nms_h($edit_device['snmp_sysContact'] ?: 'Not reported'); ?></dd></div>
		</dl>
	</section>
</section>

<?php
// A fresh linked SNMP value is only an editable suggestion until the operator saves it in NMS.
$manual_serial_value = $serial_prefill['value'];
$serial_value_source = $serial_prefill['source'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && get_nfilter_request_var('nms_action') === 'save_manual_serial' && isset_request_var('manual_serial_number')) {
	$submitted_serial = get_nfilter_request_var('manual_serial_number');
	// Preserve the attempted edit, including an intentional blank, instead of reapplying a detected value.
	$manual_serial_value = is_string($submitted_serial) ? $submitted_serial : '';
	$serial_value_source = 'submitted';
}
?>
<section class="nms-panel nms-form-panel" id="manual-serial">
	<div class="nms-panel-head"><div><h2>Device serial number (NMS)</h2><p>Record the serial printed on the device. This section saves only NMS metadata, not Cacti core settings.</p></div></div>
	<form method="post" action="devices.php?tab=edit&amp;id=<?php print $device_id; ?>#manual-serial" class="nms-device-form">
		<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
		<input type="hidden" name="nms_action" value="save_manual_serial">
		<input type="hidden" name="id" value="<?php print $device_id; ?>">
		<?php if (isset_request_var('serial_saved')) { ?><p role="status">Manual serial number saved in NMS.</p><?php } ?>
		<?php if ($serial_value_source === 'snmp') { ?>
		<p role="status">Prefilled from the linked serial OID's successful SNMP read at <?php print nms_h($serial_prefill['last_success']); ?>. Review it and click Save serial number to record it in NMS; it is not saved yet.</p>
		<?php } elseif ($serial_value_source === 'saved') { ?>
		<p>Showing your saved NMS serial number. Automatic SNMP readings do not overwrite it.</p>
		<?php } elseif ($serial_value_source === 'empty' && !isset_request_var('serial_saved')) { ?>
		<p><?php print $serial_prefill['oid'] !== '' ? 'Waiting for a fresh, successful SNMP serial reading. You can still enter the serial manually.' : 'No serial OID is linked to this device\'s current imported template. Enter the serial manually, or use an imported template that defines its serial OID.'; ?></p>
		<?php } ?>
		<?php if ($serial_prefill['oid'] !== '') { ?><p>Linked serial OID: <code><?php print nms_h($serial_prefill['oid']); ?></code></p><?php } ?>
		<fieldset><legend>Recorded device identity</legend><div class="nms-form-grid"><label for="nmsManualSerial"><span>Serial number (manual)</span><input id="nmsManualSerial" name="manual_serial_number" maxlength="191" value="<?php print nms_h($manual_serial_value); ?>" placeholder="Serial printed on the device"><small>Leave blank and save to clear. This does not replace the SNMP serial, change its baseline, or imply that the device is responding.</small></label></div></fieldset>
		<div class="nms-form-actions"><button type="submit">Save serial number</button></div>
	</form>
</section>

<section class="nms-panel nms-form-panel" id="classification">
	<div class="nms-panel-head"><div><h2>Equipment classification</h2><p>Saved independently from Cacti trees, site, template and SNMP access. Changing this category changes this device's fault-rule scope.</p></div></div>
	<form method="post" action="devices.php?tab=edit&amp;id=<?php print $device_id; ?>#classification" class="nms-device-form">
		<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="save_classification"><input type="hidden" name="id" value="<?php print $device_id; ?>">
		<?php if (isset_request_var('classification_saved')) { ?><p role="status">Equipment classification saved. Cacti core settings were not changed.</p><?php } ?>
		<p>Native Cacti template class: <?php print nms_h($device_classes[$native_template_class] ?? ($native_template_class ?: 'Not assigned')); ?>.</p>
		<div class="nms-form-grid">
			<label><span>Equipment category</span><select name="equipment_category_id"><option value="0">Unclassified (no category rules)</option><?php foreach ($categories as $category) { ?><option value="<?php print (int) $category['id']; ?>" <?php print (int) ($device_classification['category_id'] ?? 0) === (int) $category['id'] ? 'selected' : ''; ?>><?php print nms_h($category['name']); ?></option><?php } ?></select></label>
			<label><span>Device type</span><input name="device_type" maxlength="150" value="<?php print nms_h($device_classification['device_type'] ?? ''); ?>" placeholder="UPS, switch, sensor"></label>
			<label><span>Device role</span><input name="device_role" maxlength="150" value="<?php print nms_h($device_classification['device_role'] ?? ''); ?>" placeholder="Access, core, backup power"></label>
		</div><div class="nms-form-actions"><button type="submit">Save classification</button></div>
	</form>
</section>

<section class="nms-panel nms-form-panel" id="operational-groups">
	<div class="nms-panel-head"><div><h2>Operational groups</h2><p>A device can belong to several groups, independently of its equipment category, site and Cacti trees.</p></div></div>
	<form method="post" action="devices.php?tab=edit&amp;id=<?php print $device_id; ?>#operational-groups" class="nms-device-form">
		<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="save_groups"><input type="hidden" name="id" value="<?php print $device_id; ?>">
		<?php if (isset_request_var('groups_saved')) { ?><p role="status">Group memberships saved.</p><?php } ?>
		<div class="nms-form-grid"><?php foreach ($operational_groups as $group) { ?><label><input type="checkbox" name="group_ids[]" value="<?php print (int) $group['id']; ?>" <?php print in_array((int) $group['id'], $device_group_ids, true) ? 'checked' : ''; ?>><?php print nms_h($group['name']); ?></label><?php } ?></div>
		<p><a href="fault_config.php?tab=groups">Manage operational group labels</a>. No group is inferred from IP address, template name or category.</p>
		<div class="nms-form-actions"><button type="submit">Save groups</button></div>
	</form>
</section>
<section class="nms-panel" id="capabilities">
	<div class="nms-panel-head"><div><h2>FCAPS capabilities and evidence</h2><p>Configured collection is not proof that every function is supported by this model. Missing integrations remain unavailable.</p></div></div>
	<div class="nms-table-wrap"><table class="nms-table"><thead><tr><th>Area / capability</th><th>Provider</th><th>State</th><th>Evidence / next requirement</th></tr></thead><tbody>
	<?php foreach ($device_capabilities['capabilities'] as $capability) { ?><tr><td><?php print nms_h($capability['area'] . ' · ' . $capability['capability']); ?></td><td><?php print nms_h($capability['provider']); ?></td><td><?php print nms_h($capability['state']); ?></td><td><?php print nms_h($capability['detail']); ?></td></tr><?php } ?>
	</tbody></table></div>
	<div class="nms-panel-head"><div><h3>Native data input methods configured for this device</h3><?php if (!$device_capabilities['methods']) { ?><p>No data input methods are attached. Configure native data sources or data queries.</p><?php } else { ?><ul><?php foreach ($device_capabilities['methods'] as $method) { ?><li><?php print nms_h($method['name']); ?> · <?php print nms_h($input_types[$method['type_id']] ?? ('Cacti input type ' . $method['type_id'])); ?></li><?php } ?></ul><?php } ?></div></div>
</section>

<?php require($config['base_path'] . '/plugins/nms/templates/devices/add.php'); ?>

<div class="nms-device-associations">
	<section class="nms-panel" id="graph-templates">
		<div class="nms-panel-head"><div><h2>Associated Graph Templates</h2><p>The same graph-template associations stored by Cacti.</p></div><a class="nms-panel-action" href="<?php print nms_h($core_base . 'graphs_new.php?reset=true&host_id=' . $device_id); ?>">Create graphs</a></div>
		<form class="nms-association-add top" method="post" action="devices.php?tab=edit&id=<?php print $device_id; ?>#graph-templates">
			<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
			<input type="hidden" name="nms_action" value="add_graph_template">
			<input type="hidden" name="id" value="<?php print $device_id; ?>">
			<label><span>Add Graph Template</span><select class="nms-search-select" data-search-placeholder="Search graph templates" required name="graph_template_id" <?php print !$available_graph_templates ? 'disabled' : ''; ?>><option value=""><?php print $available_graph_templates ? 'Select from all eligible Cacti graph templates' : 'All available templates are associated'; ?></option><?php foreach ($available_graph_templates as $available_template) { ?><option value="<?php print (int) $available_template['id']; ?>"><?php print nms_h($available_template['name']); ?></option><?php } ?></select></label>
			<button type="submit" <?php print !$available_graph_templates ? 'disabled' : ''; ?>>Add template</button>
		</form>
		<div class="nms-association-table">
			<div class="nms-association-row graph heading"><span>Graph template</span><span>Status</span><span>Action</span></div>
			<?php if (!$device_graph_templates) { ?><div class="nms-association-empty">No associated graph templates.</div><?php } ?>
			<?php foreach ($device_graph_templates as $graph_template) { ?>
			<div class="nms-association-row graph"><strong><?php print nms_h($graph_template['name']); ?></strong><span><?php if ((int) $graph_template['graph_count'] > 0) { ?><i class="nms-association-state active">Being graphed</i><small><?php print (int) $graph_template['graph_count']; ?> graph(s)</small><?php } else { ?><i class="nms-association-state pending">Not graphed</i><?php } ?></span><form class="nms-icon-action" method="post" action="devices.php?tab=edit&id=<?php print $device_id; ?>#graph-templates" onsubmit="return confirm('Remove this graph-template association from the device? Existing graphs are not deleted.');"><input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="remove_graph_template"><input type="hidden" name="id" value="<?php print $device_id; ?>"><input type="hidden" name="graph_template_id" value="<?php print (int) $graph_template['id']; ?>"><button type="submit" aria-label="Remove <?php print nms_h($graph_template['name']); ?> from this device" title="Remove association">×</button></form></div>
			<?php } ?>
		</div>
	</section>

	<section class="nms-panel" id="data-queries">
		<div class="nms-panel-head"><div><h2>Associated Data Queries</h2><p>Live indexed-query status from the Cacti SNMP cache.</p></div><a class="nms-panel-action" href="<?php print nms_h($core_base . 'host.php?action=edit&id=' . $device_id); ?>">Manage queries</a></div>
		<form class="nms-association-add query top" method="post" action="devices.php?tab=edit&id=<?php print $device_id; ?>#data-queries">
			<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
			<input type="hidden" name="nms_action" value="add_data_query">
			<input type="hidden" name="id" value="<?php print $device_id; ?>">
			<label><span>Add Data Query</span><select class="nms-search-select" data-search-placeholder="Search data queries" required name="snmp_query_id" <?php print !$available_data_queries ? 'disabled' : ''; ?>><option value=""><?php print $available_data_queries ? 'Select from all eligible Cacti data queries' : 'All available queries are associated'; ?></option><?php foreach ($available_data_queries as $available_query) { ?><option value="<?php print (int) $available_query['id']; ?>"><?php print nms_h($available_query['name']); ?></option><?php } ?></select></label>
			<label><span>Re-Index Method</span><select required name="reindex_method" <?php print !$available_data_queries ? 'disabled' : ''; ?>><?php foreach ($reindex_types as $reindex_id => $reindex_name) { ?><option value="<?php print (int) $reindex_id; ?>" <?php print (int) read_config_option('reindex_method') === (int) $reindex_id ? 'selected' : ''; ?>><?php print nms_h($reindex_name); ?></option><?php } ?></select></label>
			<button type="submit" <?php print !$available_data_queries ? 'disabled' : ''; ?>>Add query</button>
		</form>
		<div class="nms-association-table">
			<div class="nms-association-row query heading"><span>Data query</span><span>Re-index method</span><span>Status</span><span>Actions</span></div>
			<?php if (!$device_data_queries) { ?><div class="nms-association-empty">No associated data queries.</div><?php } ?>
			<?php foreach ($device_data_queries as $data_query) { ?>
			<div class="nms-association-row query">
				<strong><?php print nms_h($data_query['name']); ?></strong>
				<form class="nms-reindex-options" method="post" action="devices.php?tab=edit&id=<?php print $device_id; ?>#data-queries">
					<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
					<input type="hidden" name="nms_action" value="change_data_query">
					<input type="hidden" name="id" value="<?php print $device_id; ?>">
					<input type="hidden" name="snmp_query_id" value="<?php print (int) $data_query['id']; ?>">
					<?php foreach ($reindex_types as $reindex_id => $reindex_name) { $reindex_input_id = 'reindex-' . $device_id . '-' . (int) $data_query['id'] . '-' . (int) $reindex_id; ?><input type="radio" name="reindex_method" id="<?php print $reindex_input_id; ?>" value="<?php print (int) $reindex_id; ?>" <?php print (int) $data_query['reindex_method'] === (int) $reindex_id ? 'checked' : ''; ?> onchange="this.form.submit()"><label for="<?php print $reindex_input_id; ?>" title="<?php print nms_h($reindex_types_tips[$reindex_id] ?? $reindex_name); ?>"><?php print nms_h($reindex_name); ?></label><?php } ?>
				</form>
				<span><i class="nms-association-state active">Success</i><small><?php print (int) $data_query['item_count']; ?> items · <?php print (int) $data_query['row_count']; ?> rows</small></span>
				<div class="nms-query-actions">
					<form method="post" action="devices.php?tab=edit&id=<?php print $device_id; ?>#data-queries"><input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="reload_data_query"><input type="hidden" name="id" value="<?php print $device_id; ?>"><input type="hidden" name="snmp_query_id" value="<?php print (int) $data_query['id']; ?>"><button type="submit" class="reload" title="Reload this data query">Reload</button></form>
					<a class="verbose" href="<?php print nms_h($core_base . 'host.php?action=query_verbose&id=' . (int) $data_query['id'] . '&host_id=' . $device_id . '&header=true'); ?>" title="Run the query and show Cacti verbose output">Verbose</a>
					<form method="post" action="devices.php?tab=edit&id=<?php print $device_id; ?>#data-queries" onsubmit="return confirm('Remove this data query and its indexed cache from the device?');"><input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="remove_data_query"><input type="hidden" name="id" value="<?php print $device_id; ?>"><input type="hidden" name="snmp_query_id" value="<?php print (int) $data_query['id']; ?>"><button type="submit" class="remove nms-x-action" aria-label="Remove <?php print nms_h($data_query['name']); ?> from this device" title="Remove data query">×</button></form>
				</div>
			</div>
			<?php } ?>
		</div>
	</section>
</div>
