<?php
/**
 * @file graph_template_manager.php
 * Create reusable styled graph templates from native Cacti data-template items and delete eligible NMS-managed templates.
 */

require_once($config['base_path'] . '/lib/api_graph.php');
require_once($config['base_path'] . '/lib/template.php');
require_once($config['base_path'] . '/include/global_form.php');
require_once(__DIR__ . '/graph_item_options.php');
require_once(__DIR__ . '/core_form_options.php');

/** Create a styled reusable graph template using Cacti's native tables and form metadata and record NMS ownership. */
function nms_graph_template_create($data_template_rrd_id, $graph_name, $vertical_label, $options = array()) {
	global $graph_item_types, $struct_graph_item, $fields_graph_template_template_edit;
	if (!is_scalar($graph_name)) throw new InvalidArgumentException('Enter a graph template name.');
	$data_template_rrd_id = (int) $data_template_rrd_id;
	$source = db_fetch_row_prepared('SELECT dt.id AS data_template_id, dt.name AS data_template_name,
		dtr.id AS data_template_rrd_id, dtr.data_source_name, dtd.name AS data_source_title
		FROM data_template_rrd AS dtr
		INNER JOIN data_template AS dt ON dt.id = dtr.data_template_id
		INNER JOIN data_template_data AS dtd ON dtd.data_template_id = dt.id AND dtd.local_data_id = 0
		WHERE dtr.id = ? AND dtr.local_data_id = 0', array($data_template_rrd_id));
	if (!$source) throw new InvalidArgumentException('Select a reusable Cacti data-template item.');

	$name_length = (int) $fields_graph_template_template_edit['name']['max_length'];
	$default_name = mb_substr('NMS ' . $source['data_template_name'] . ' - ' . $source['data_source_name'], 0, $name_length);
	if (trim((string) $graph_name) === '') {
		$graph_name = $default_name;
		$suffix = 2;
		while ((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_templates WHERE name = ?', array($graph_name))) {
			$ending = ' ' . $suffix++;
			$graph_name = mb_substr($default_name, 0, $name_length - strlen($ending)) . $ending;
		}
	} else {
		$graph_name = trim((string) $graph_name);
	}
	// Preserve native labels (including Unicode) and use Cacti's actual length limit.
	$options['vertical_label'] = $vertical_label;
	if (!array_key_exists('title', $options)) $options['title'] = '|host_description| - ' . $graph_name;
	$graph_values = nms_core_graph_values($options);
	$graph_name = nms_core_field_value('name', $fields_graph_template_template_edit['name'], $graph_name);
	if ((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_templates WHERE name = ?', array($graph_name))) {
		throw new InvalidArgumentException('A Cacti graph template with this name already exists. Choose another name.');
	}

	// Native defaults and database choices must not silently turn into fixed IDs.
	foreach (array('color_id', 'cdef_id', 'gprint_id', 'vdef_id') as $key) {
		$field = $struct_graph_item[$key];
		$value = $options[$key] ?? $field['default'];
		if ($key === 'color_id') {
			if (!is_scalar($value) || !ctype_digit((string) $value) || ((int) $value && !db_fetch_cell_prepared('SELECT id FROM colors WHERE id = ?', array($value)))) throw new InvalidArgumentException('Select a color from Cacti.');
		} else {
			$value = nms_core_field_value($key, $field, $value);
		}
		$options[$key] = $value;
	}
	$options['alpha'] = nms_core_field_value('alpha', $struct_graph_item['alpha'], $options['alpha'] ?? $struct_graph_item['alpha']['default']);
	if (isset($options['consolidation_function_id'])) $options['consolidation_function_id'] = nms_core_field_value('consolidation_function_id', $struct_graph_item['consolidation_function_id'], $options['consolidation_function_id']);
	$item_rows = nms_graph_item_rows($graph_item_types, $data_template_rrd_id, $source['data_source_name'], $options);
	foreach ($item_rows as $item_row) {
		if ($item_row['task_item_id'] && !(int) db_fetch_cell_prepared('SELECT COUNT(*) FROM data_template_rrd WHERE id = ? AND local_data_id = 0', array($item_row['task_item_id']))) {
			throw new InvalidArgumentException('Select a reusable data-template item for the stack base.');
		}
	}
	// Match the native editor: save core records directly; no named seed template is required.
	db_execute('START TRANSACTION');
	try {
		$parent = array('id' => 0, 'hash' => get_hash_graph_template(0), 'name' => $graph_name);
		foreach (array('multiple', 'test_source') as $key) $parent[$key] = nms_core_field_value($key, $fields_graph_template_template_edit[$key], $options[$key] ?? '');
		$graph_template_id = (int) sql_save($parent, 'graph_templates');
		if (!$graph_template_id) throw new RuntimeException('Cacti could not create the graph template.');
		nms_managed_object_record('graph_template', $graph_template_id);
		$graph_values += array('id' => 0, 'graph_template_id' => $graph_template_id, 'local_graph_id' => 0, 'local_graph_template_graph_id' => 0);
		if (!sql_save($graph_values, 'graph_templates_graph')) throw new RuntimeException('Cacti could not save graph options.');
		$seed = array('graph_template_id' => $graph_template_id, 'local_graph_id' => 0, 'local_graph_template_item_id' => 0);
		$input_ids = array();
		foreach ($item_rows as $index => $item_row) {
			$item = array_merge($seed, $item_row, array('id' => 0, 'hash' => get_hash_graph_template(0, 'graph_template_item'), 'sequence' => $index + 1));
			$item_id = (int) sql_save($item, 'graph_templates_item');
			if (!$item_id) throw new RuntimeException('Cacti could not save the graph item.');
			$task_id = (int) $item['task_item_id'];
			if (!$task_id) continue;
			if (!isset($input_ids[$task_id])) {
				$ds_name = db_fetch_cell_prepared('SELECT data_source_name FROM data_template_rrd WHERE id = ?', array($task_id));
				$input_ids[$task_id] = (int) sql_save(array('id' => 0, 'hash' => get_hash_graph_template(0, 'graph_template_input'),
					'graph_template_id' => $graph_template_id, 'name' => 'Data Source [' . $ds_name . ']', 'column_name' => 'task_item_id'), 'graph_template_input');
				if (!$input_ids[$task_id]) throw new RuntimeException('Cacti could not save the graph input.');
			}
			db_execute_prepared('INSERT INTO graph_template_input_defs (graph_template_input_id, graph_template_item_id) VALUES (?, ?)', array($input_ids[$task_id], $item_id));
		}
		set_config_option('time_last_change_graph', time());
		db_execute('COMMIT');
		return $graph_template_id;
	} catch (Throwable $exception) {
		db_execute('ROLLBACK');
		throw $exception;
	}
}

/** Validate ownership and usage before deleting an NMS-created graph template. */
function nms_graph_template_delete($graph_template_id) {
	$graph_template_id = (int) $graph_template_id;
	if ($graph_template_id < 1 || !nms_managed_object_exists('graph_template', $graph_template_id)) {
		throw new InvalidArgumentException('Only a graph template created through NMS can be deleted here.');
	}
	if ((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_local WHERE graph_template_id = ?', array($graph_template_id)) > 0) {
		throw new InvalidArgumentException('This graph template is in use. Remove its device graphs before deleting it.');
	}
	$input_ids = db_fetch_assoc_prepared('SELECT id FROM graph_template_input WHERE graph_template_id = ?', array($graph_template_id));
	foreach ($input_ids as $input) {
		db_execute_prepared('DELETE FROM graph_template_input_defs WHERE graph_template_input_id = ?', array((int) $input['id']));
	}
	foreach (array('graph_template_input', 'graph_templates_graph', 'graph_templates_item', 'host_template_graph', 'host_graph') as $table) {
		db_execute_prepared('DELETE FROM ' . $table . ' WHERE graph_template_id = ?', array($graph_template_id));
	}
	db_execute_prepared('UPDATE plugin_nms_snmprec_oids SET graph_template_id = 0 WHERE graph_template_id = ?', array($graph_template_id));
	db_execute_prepared('DELETE FROM graph_templates WHERE id = ?', array($graph_template_id));
	nms_managed_object_forget('graph_template', $graph_template_id);
	set_config_option('time_last_change_graph', time());
	return $graph_template_id;
}
