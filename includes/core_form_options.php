<?php
/** Read Cacti's installed form metadata; never maintain a second catalogue of core choices. */
function nms_core_field_choices($field) {
	$choices = array();
	if (isset($field['none_value'])) $choices[0] = $field['none_value'];
	if (isset($field['array'])) return $choices + $field['array'];
	if ($field['method'] === 'radio') {
		foreach ($field['items'] as $item) $choices[$item['radio_value']] = $item['radio_caption'];
	} elseif ($field['method'] === 'drop_sql') {
		// The query comes only from installed Cacti metadata, never from a submitted form.
		foreach (db_fetch_assoc($field['sql']) as $row) $choices[$row['id']] = $row['name'];
	}
	return $choices;
}

/** Validate a scalar against the same field choices and lengths used by the core form. */
function nms_core_field_value($name, $field, $value) {
	if (!is_scalar($value)) throw new InvalidArgumentException('Invalid value for ' . $name . '.');
	if ($field['method'] === 'checkbox') return !empty($value) ? 'on' : '';
	$value = (string) $value;
	if (isset($field['max_length']) && mb_strlen($value) > (int) $field['max_length']) {
		throw new InvalidArgumentException($name . ' exceeds the Cacti field length.');
	}
	if (in_array($field['method'], array('drop_array', 'drop_sql', 'radio'), true)) {
		$choices = nms_core_field_choices($field);
		if ($value === '' && isset($field['none_value'])) $value = '0';
		if (!array_key_exists($value, $choices)) throw new InvalidArgumentException('Select a Cacti value for ' . $name . '.');
	}
	return $value;
}

/** Allowlist graph settings and per-graph override flags from the installed core structure. */
function nms_core_graph_values($options) {
	global $struct_graph;
	if (empty($struct_graph)) throw new RuntimeException('Cacti graph form definitions are unavailable.');
	$values = array();
	foreach ($struct_graph as $name => $field) {
		if ($field['method'] === 'spacer') continue;
		$value = $options[$name] ?? ($field['default'] ?? (isset($field['none_value']) ? '0' : ''));
		$value = nms_core_field_value($name, $field, $value);
		$override = false; // NMS-created graphs use template values, never per-graph overrides.
		// Core's save handler validates numeric text separately from its form metadata.
		$pattern = null;
		if (in_array($name, array('width', 'height', 'base_value', 'unit_length', 'tab_width'), true)) $pattern = '/^[0-9]+$/D';
		if ($name === 'unit_exponent_value') $pattern = '/^-?[0-9]+$/D';
		if (in_array($name, array('lower_limit', 'upper_limit'), true)) $pattern = '/^(?:-?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?|U)$/D';
		if ($name === 'right_axis') $pattern = '/^-?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+):-?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/D';
		if ($value !== '' && $pattern && !preg_match($pattern, $value)) throw new InvalidArgumentException('Invalid Cacti graph value for ' . $name . '.');
		if ($value === '' && in_array($name, array('title', 'width', 'height', 'base_value'), true)) throw new InvalidArgumentException($name . ' is required.');
		$values[$name] = $value;
		$values['t_' . $name] = $override ? 'on' : '';
	}
	return $values;
}

/** Collect only core-defined graph/template fields, including unchecked checkboxes. */
function nms_core_graph_request() {
	global $struct_graph, $fields_graph_template_template_edit;
	$values = array();
	foreach (array_merge($struct_graph, $fields_graph_template_template_edit) as $name => $field) {
		if (in_array($field['method'], array('spacer', 'hidden', 'hidden_zero'), true) || $name === 'name') continue;
		$values[$name] = isset_request_var($name) ? get_nfilter_request_var($name) : '';
		if (isset($struct_graph[$name])) $values['t_' . $name] = '';
	}
	return $values;
}

/** Render native field definitions in the NMS layout without copying core option lists. */
function nms_core_render_field($name, $field, $value, $override = null) {
	$method = $field['method'];
	$id = 'nmsCore_' . $name;
	print '<div class="nms-core-field"><label for="' . nms_h($id) . '"><span>' . nms_h(html_entity_decode($field['friendly_name'], ENT_QUOTES, 'UTF-8')) . '</span></label>';
	if (in_array($method, array('drop_array', 'drop_sql', 'radio'), true)) {
		print '<select id="' . nms_h($id) . '" name="' . nms_h($name) . '">';
		foreach (nms_core_field_choices($field) as $key => $caption) print '<option value="' . nms_h($key) . '"' . ((string) $key === (string) $value ? ' selected' : '') . '>' . nms_h($caption) . '</option>';
		print '</select>';
	} elseif ($method === 'checkbox') {
		print '<input type="checkbox" id="' . nms_h($id) . '" name="' . nms_h($name) . '" value="on"' . ($value === 'on' ? ' checked' : '') . '>';
	} elseif ($method === 'textbox') {
		print '<input id="' . nms_h($id) . '" name="' . nms_h($name) . '" value="' . nms_h($value) . '"' . (isset($field['max_length']) ? ' maxlength="' . (int) $field['max_length'] . '"' : '') . '>';
	} else {
		throw new RuntimeException('Unsupported Cacti field method: ' . $method . '. Use the native editor for this version.');
	}
	if (!empty($field['description'])) print '<small>' . nms_h(html_entity_decode(strip_tags(str_replace(array('<br>', '<br/>'), ' ', $field['description'])), ENT_QUOTES, 'UTF-8')) . '</small>';
	print '</div>';
}
