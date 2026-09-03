<?php
/** Render the current rule's explicit applicability without changing its measurement/threshold or any core object. */
$scope_values = array_intersect_key($rule, nms_rule_scope_defaults());
if (count($scope_values) !== count(nms_rule_scope_defaults())) throw new RuntimeException('Rule applicability schema is incomplete. Run the reviewed NMS upgrade.');
if ($page_error !== '' && isset_request_var('nms_action') && get_nfilter_request_var('nms_action') === 'save_rule_scope' &&
	(int) get_filter_request_var('rule_id') === (int) $rule['id']) {
	// Keep scalar attempted values after validation failure; arrays are not rendered as text.
	foreach (array_keys($scope_values) as $field) {
		if (!isset_request_var($field)) continue;
		$value = get_nfilter_request_var($field);
		if (is_string($value) || is_int($value)) $scope_values[$field] = $value;
	}
}
$scope_lists = array(
	'scope_host_template_id' => array('Cacti device template', $templates),
	'scope_data_input_id' => array('Native data input method', $scope_inputs),
	'scope_host_id' => array('Device in this category', $scope_devices),
	'scope_local_data_id' => array('Exact native data source', $scope_sources),
	'scope_query_id' => array('Native indexed data query', $scope_queries)
);
?>
<details class="nms-rule-applicability" <?php print $page_error !== '' ? 'open' : ''; ?>>
	<summary>Applicability: <?php print nms_h($scope_values['scope_kind']); ?> scope — <?php print nms_h($rule['name']); ?></summary>
	<form class="nms-add-rule" method="post" action="fault_config.php">
		<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
		<input type="hidden" name="nms_action" value="save_rule_scope">
		<input type="hidden" name="rule_id" value="<?php print (int) $rule['id']; ?>">
		<input type="hidden" name="equipment_category_id" value="<?php print (int) $category_id; ?>">
		<div class="nms-add-rule-title"><strong>Where this rule applies</strong><small>Filters are combined. They select configured Cacti measurements; they do not enable collectors or prove model/protocol support. Existing incidents outside a changed scope remain for review.</small></div>
		<label>Target scope<select name="scope_kind">
			<?php foreach (array('all' => 'All matching native instances', 'device' => 'Device / scalar measurement', 'interface' => 'Interface verified in native query cache', 'component' => 'Component — explicitly selected data source', 'service' => 'Service — explicitly selected data source') as $value => $label) { ?>
			<option value="<?php print nms_h($value); ?>" <?php print $scope_values['scope_kind'] === $value ? 'selected' : ''; ?>><?php print nms_h($label); ?></option>
			<?php } ?>
			<?php if (!in_array($scope_values['scope_kind'], array('all', 'device', 'interface', 'component', 'service'), true)) { ?><option selected value="<?php print nms_h($scope_values['scope_kind']); ?>">Unavailable scope — <?php print nms_h($scope_values['scope_kind']); ?></option><?php } ?>
		</select></label>
		<label>Declared device type (exact match)<input name="scope_device_type" maxlength="150" value="<?php print nms_h($scope_values['scope_device_type']); ?>"><small>Leave blank for any type. A template is a collection profile, not verified hardware identity.</small></label>
		<label>Reported sysObjectID (exact match)<input name="scope_sys_object_id" maxlength="128" list="nms-sysobject-<?php print (int) $rule['id']; ?>" value="<?php print nms_h($scope_values['scope_sys_object_id']); ?>"><small>Optional native reported product identity, not an invented model name or a guarantee of supported OIDs. Leave blank for any identity.</small></label>
		<datalist id="nms-sysobject-<?php print (int) $rule['id']; ?>"><?php foreach ($scope_model_ids as $model) { ?><option value="<?php print nms_h($model['snmp_sysObjectID']); ?>"></option><?php } ?></datalist>
		<?php foreach ($scope_lists as $field => $definition) { $found = (int) $scope_values[$field] === 0; ?>
		<label><?php print nms_h($definition[0]); ?><select name="<?php print nms_h($field); ?>">
			<option value="0" <?php print $found ? 'selected' : ''; ?>>Any / no restriction</option>
			<?php foreach ($definition[1] as $option) { $selected = (string) $scope_values[$field] === (string) $option['id']; if ($selected) $found = true; ?>
			<option value="<?php print (int) $option['id']; ?>" <?php print $selected ? 'selected' : ''; ?>><?php print nms_h($option['name'] . ' [ID ' . $option['id'] . ']'); ?></option>
			<?php } ?>
			<?php if (!$found) { ?><option selected value="<?php print nms_h($scope_values[$field]); ?>">Unavailable selection — <?php print nms_h($scope_values[$field]); ?></option><?php } ?>
		</select></label>
		<?php } ?>
		<label>Native SNMP version restriction<select name="scope_snmp_version">
			<option value="any" <?php print $scope_values['scope_snmp_version'] === 'any' ? 'selected' : ''; ?>>Any configured collection protocol</option>
			<?php foreach ($snmp_versions as $value => $label) { if ((int) $value === 0) continue; ?><option value="<?php print nms_h($value); ?>" <?php print (string) $scope_values['scope_snmp_version'] === (string) $value ? 'selected' : ''; ?>><?php print nms_h($label); ?></option><?php } ?>
			<?php if ($scope_values['scope_snmp_version'] !== 'any' && (!array_key_exists($scope_values['scope_snmp_version'], $snmp_versions) || (string) $scope_values['scope_snmp_version'] === '0')) { ?><option selected value="<?php print nms_h($scope_values['scope_snmp_version']); ?>">Unavailable version — <?php print nms_h($scope_values['scope_snmp_version']); ?></option><?php } ?>
		</select><small>Applies only to native SNMP input methods or live SNMP inventory, not scripts guessed to use SNMP. Core availability uses its own Cacti method.</small></label>
		<label>Exact native query index<input name="scope_index" maxlength="191" value="<?php print nms_h($scope_values['scope_index']); ?>"><small>Optional; requires device and query. This is not a management TCP/UDP port or the number of ports.</small></label>
		<button type="submit" class="nms-save-button">Save applicability</button>
	</form>
</details>
