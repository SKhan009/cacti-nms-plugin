<?php
/** Category-rule applicability, using native device/collector identities rather than a guessed capability catalogue. */
require_once(__DIR__ . '/categories.php');

/** Preserve legacy rule breadth explicitly; defaults are persisted during lifecycle migration. */
function nms_rule_scope_defaults() {
	return array('scope_kind' => 'all', 'scope_host_template_id' => 0, 'scope_device_type' => '', 'scope_sys_object_id' => '',
		'scope_data_input_id' => 0, 'scope_snmp_version' => 'any', 'scope_host_id' => 0,
		'scope_local_data_id' => 0, 'scope_query_id' => 0, 'scope_index' => '');
}

/** Select policy columns explicitly so native host IDs cannot collide with a rule's ID in joined rows. */
function nms_rule_scope_select_sql() {
	$columns = array('r.metric');
	foreach (array_keys(nms_rule_scope_defaults()) as $field) $columns[] = 'r.' . $field;
	return implode(', ', $columns);
}

/** Extend only the existing plugin policy table; Cacti schemas and collector settings remain unchanged. */
function nms_rule_scope_schema() {
	$columns = array('scope_kind' => "VARCHAR(16) NOT NULL DEFAULT 'all'",
		'scope_host_template_id' => 'MEDIUMINT UNSIGNED NOT NULL DEFAULT 0',
		'scope_device_type' => "VARCHAR(150) NOT NULL DEFAULT ''",
		'scope_sys_object_id' => "VARCHAR(128) NOT NULL DEFAULT ''",
		'scope_data_input_id' => 'MEDIUMINT UNSIGNED NOT NULL DEFAULT 0',
		'scope_snmp_version' => "VARCHAR(8) NOT NULL DEFAULT 'any'",
		'scope_host_id' => 'MEDIUMINT UNSIGNED NOT NULL DEFAULT 0',
		'scope_local_data_id' => 'INT UNSIGNED NOT NULL DEFAULT 0',
		'scope_query_id' => 'MEDIUMINT UNSIGNED NOT NULL DEFAULT 0',
		'scope_index' => "VARCHAR(191) NOT NULL DEFAULT ''");
	foreach ($columns as $name => $definition) {
		if (!(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM information_schema.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_nms_fault_rules' AND COLUMN_NAME = ?", array($name))) {
			nms_category_execute('ALTER TABLE plugin_nms_fault_rules ADD ' . $name . ' ' . $definition);
		}
	}
}

/** Validate native IDs without accepting array coercion, negative values or numeric suffixes. */
function nms_rule_scope_id($value) {
	if ((!is_string($value) && !is_int($value)) || !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value) ||
		strlen((string) $value) > 10 || (float) $value > 4294967295) {
		throw new InvalidArgumentException('Select a valid native Cacti object ID.');
	}
	return (int) $value;
}

/** Validate a reviewed applicability filter; component/service declarations require an exact native data source. */
function nms_rule_scope_validate($input, $rule) {
	global $snmp_versions;
	$values = array();
	foreach (nms_rule_scope_defaults() as $name => $default) {
		if (!array_key_exists($name, $input)) throw new InvalidArgumentException('Submit every rule applicability field.');
		$limit = $name === 'scope_device_type' ? 150 : ($name === 'scope_sys_object_id' ? 128 : 191);
		$values[$name] = is_int($default) ? nms_rule_scope_id($input[$name]) : nms_classification_text($input[$name], $limit);
	}
	if (!in_array($values['scope_kind'], array('all', 'device', 'interface', 'component', 'service'), true)) {
		throw new InvalidArgumentException('Select a supported target scope.');
	}
	foreach (array('scope_host_template_id' => 'host_template', 'scope_data_input_id' => 'data_input', 'scope_query_id' => 'snmp_query') as $key => $table) {
		if ($values[$key] && !(int) db_fetch_cell_prepared('SELECT COUNT(*) FROM ' . $table . ' WHERE id = ?', array($values[$key]))) {
			throw new InvalidArgumentException('A selected native template, input method or query no longer exists.');
		}
	}
	$version = $values['scope_snmp_version'];
	if ($version !== 'any' && (!is_array($snmp_versions) || !array_key_exists($version, $snmp_versions) || (int) $version === 0)) {
		throw new InvalidArgumentException('Select an enabled SNMP version from the installed Cacti options, or Any.');
	}
	if ($rule['metric'] !== 'parameter') {
		if (!in_array($values['scope_kind'], array('all', 'device'), true) || $values['scope_data_input_id'] ||
			$values['scope_local_data_id'] || $values['scope_query_id'] || $values['scope_index'] !== '') {
			throw new InvalidArgumentException('This device-level rule does not have a native indexed data-source target.');
		}
		if ($rule['metric'] === 'core_status' && $version !== 'any') {
			throw new InvalidArgumentException('Core availability follows the native availability method; an SNMP credential setting alone does not prove SNMP is used.');
		}
	}
	if ($values['scope_host_id']) {
		nms_require_device_access($values['scope_host_id']);
		if (!(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM host AS h
			INNER JOIN plugin_nms_device_classification AS c ON c.host_id = h.id
			WHERE h.id = ? AND h.deleted = '' AND c.category_id = ?", array($values['scope_host_id'], $rule['category_id']))) {
			throw new InvalidArgumentException('Select a device assigned to this equipment category.');
		}
	}
	if ($values['scope_index'] !== '' && (!$values['scope_host_id'] || !$values['scope_query_id'])) {
		throw new InvalidArgumentException('An index needs both its native device and data query; it is not a TCP/UDP port.');
	}
	if ($values['scope_kind'] === 'device' && ($values['scope_query_id'] || $values['scope_index'] !== '')) {
		throw new InvalidArgumentException('Use interface/component scope for indexed targets.');
	}
	if (in_array($values['scope_kind'], array('component', 'service'), true) && !$values['scope_local_data_id']) {
		throw new InvalidArgumentException('Select an exact native data source for a reviewed component or service; NMS cannot infer that role from a name.');
	}
	if ($values['scope_local_data_id']) {
		if (!$values['scope_host_id']) throw new InvalidArgumentException('A local data source requires its native device.');
		if (!preg_match('/^dtrr:([1-9][0-9]*)$/D', $rule['parameter_key'], $match) ||
			!(int) db_fetch_cell_prepared('SELECT COUNT(*) FROM data_local AS dl
			INNER JOIN data_template_rrd AS dtr ON dtr.local_data_id = dl.id
			WHERE dl.id = ? AND dl.host_id = ? AND dtr.local_data_template_rrd_id = ?',
			array($values['scope_local_data_id'], $values['scope_host_id'], (int) $match[1]))) {
			throw new InvalidArgumentException('The selected data source does not provide this rule parameter on this device.');
		}
	}
	if ($values['scope_index'] !== '' && !(int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host_snmp_cache
		WHERE host_id = ? AND snmp_query_id = ? AND snmp_index = ?',
		array($values['scope_host_id'], $values['scope_query_id'], $values['scope_index']))) {
		throw new InvalidArgumentException('That index is not present in this device’s native query cache.');
	}
	return $values;
}

/** Save filters on an existing category rule without changing its threshold, ID, history or native collectors. */
function nms_rule_scope_save($category_id, $rule_id, $input) {
	nms_require_category_policy_access($category_id);
	$rule = db_fetch_row_prepared('SELECT * FROM plugin_nms_fault_rules WHERE id = ? AND category_id = ?', array((int) $rule_id, (int) $category_id));
	if (!$rule) throw new InvalidArgumentException('Select an existing rule in this category.');
	$values = nms_rule_scope_validate($input, $rule);
	$sets = array();
	foreach (array_keys($values) as $key) $sets[] = $key . ' = ?';
	nms_category_execute('UPDATE plugin_nms_fault_rules SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ? AND category_id = ?',
		array_merge(array_values($values), array((int) $rule_id, (int) $category_id)));
}

/** A category-wide policy write must not affect devices hidden by native Cacti permissions. */
function nms_require_category_policy_access($category_id) {
	nms_require_management();
	if (!nms_category_exists($category_id)) throw new InvalidArgumentException('Select an existing equipment category.');
	$visible = nms_visible_host_sql();
	if ((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM host AS h
		INNER JOIN plugin_nms_device_classification AS c ON c.host_id = h.id
		WHERE c.category_id = ? AND h.deleted = '' AND NOT ($visible)", array((int) $category_id))) {
		throw new RuntimeException('This category includes devices outside your Cacti permissions. Ask an administrator with access to the complete category to change shared policy.');
	}
}

/** Match persisted applicability against native context; a missing schema field is an error, never a wider rule. */
function nms_rule_scope_matches($rule, $context) {
	foreach (array_keys(nms_rule_scope_defaults()) as $key) {
		if (!array_key_exists($key, $rule)) throw new RuntimeException('NMS rule applicability schema is incomplete; run the reviewed plugin upgrade.');
	}
	foreach (array('scope_host_template_id' => 'host_template_id', 'scope_data_input_id' => 'data_input_id',
		'scope_host_id' => 'host_id', 'scope_local_data_id' => 'local_data_id', 'scope_query_id' => 'snmp_query_id') as $key => $native) {
		if ((int) $rule[$key] && (int) $rule[$key] !== (int) ($context[$native] ?? 0)) return false;
	}
	if ($rule['scope_device_type'] !== '' && $rule['scope_device_type'] !== ($context['device_type'] ?? '')) return false;
	if ($rule['scope_sys_object_id'] !== '' && $rule['scope_sys_object_id'] !== ($context['snmp_sysObjectID'] ?? '')) return false;
	if ($rule['scope_index'] !== '' && $rule['scope_index'] !== (string) ($context['snmp_index'] ?? '')) return false;
	if ($rule['scope_snmp_version'] !== 'any') {
		$is_snmp = ($rule['metric'] === 'inventory_status');
		if ($rule['metric'] === 'parameter') {
			if (!defined('DATA_INPUT_TYPE_SNMP') || !defined('DATA_INPUT_TYPE_SNMP_QUERY')) throw new RuntimeException('Cacti SNMP input-type definitions are unavailable.');
			$is_snmp = in_array((int) ($context['input_type_id'] ?? 0), array(DATA_INPUT_TYPE_SNMP, DATA_INPUT_TYPE_SNMP_QUERY), true);
		}
		if (!$is_snmp || (string) $rule['scope_snmp_version'] !== (string) ($context['snmp_version'] ?? '')) return false;
	}
	if ($rule['scope_kind'] === 'interface') return !empty($context['is_interface']);
	if ($rule['scope_kind'] === 'device') return empty($context['snmp_query_id']) && (string) ($context['snmp_index'] ?? '') === '';
	if (in_array($rule['scope_kind'], array('component', 'service'), true)) return (int) $rule['scope_local_data_id'] > 0;
	if ($rule['scope_kind'] !== 'all') throw new RuntimeException('Unknown persisted rule scope; no default scope was substituted.');
	return true;
}
