<?php
/**
 * @file graph_template_manager.php
 * Create reusable styled graph templates from native Cacti data-template items and delete eligible NMS-managed templates.
 */

require_once($config['base_path'] . '/lib/api_graph.php');
require_once($config['base_path'] . '/lib/template.php');

/** Create a styled reusable graph template from Cacti's Generic OID template and record NMS ownership. */
function nms_graph_template_create($data_template_rrd_id, $graph_name, $vertical_label, $options = array()) {
	$data_template_rrd_id = (int) $data_template_rrd_id;
	$source = db_fetch_row_prepared('SELECT dt.id AS data_template_id, dt.name AS data_template_name,
		dtr.id AS data_template_rrd_id, dtr.data_source_name, dtd.name AS data_source_title
		FROM data_template_rrd AS dtr
		INNER JOIN data_template AS dt ON dt.id = dtr.data_template_id
		INNER JOIN data_template_data AS dtd ON dtd.data_template_id = dt.id AND dtd.local_data_id = 0
		WHERE dtr.id = ? AND dtr.local_data_id = 0', array($data_template_rrd_id));
	if (!$source) throw new InvalidArgumentException('Select a reusable Cacti data-template item.');

	$default_name = 'NMS ' . $source['data_template_name'] . ' - ' . $source['data_source_name'];
	if (trim((string) $graph_name) === '') {
		$graph_name = $default_name;
		$suffix = 2;
		while ((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_templates WHERE name = ?', array($graph_name))) {
			$graph_name = substr($default_name, 0, 184) . ' ' . $suffix++;
		}
	} else {
		$graph_name = nms_template_clean_name($graph_name, 190);
	}
	$vertical_label = trim((string) $vertical_label);
	if ($vertical_label === '') $vertical_label = substr($source['data_source_name'], 0, 20);
	$vertical_label = substr(preg_replace('/[^A-Za-z0-9 _\/%.-]/', '', $vertical_label), 0, 20);
	if ($vertical_label === '') $vertical_label = 'Value';
	if ((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_templates WHERE name = ?', array($graph_name))) {
		throw new InvalidArgumentException('A Cacti graph template with this name already exists. Choose another name.');
	}

	$style_options = array('line1' => array(4, 1), 'line2' => array(5, 2), 'line3' => array(6, 3), 'area' => array(7, 0));
	$consolidation_options = array('average' => 1, 'minimum' => 2, 'maximum' => 3, 'last' => 4);
	$graph_style = isset($style_options[$options['graph_style'] ?? '']) ? $options['graph_style'] : 'line1';
	$consolidation = isset($consolidation_options[$options['consolidation'] ?? '']) ? $options['consolidation'] : 'average';
	$color_id = (int) ($options['color_id'] ?? 86);
	if (!(int) db_fetch_cell_prepared('SELECT COUNT(*) FROM colors WHERE id = ?', array($color_id))) $color_id = 86;
	$alpha_percent = max(0, min(100, (int) ($options['alpha_percent'] ?? 100)));
	$alpha = strtoupper(str_pad(dechex((int) round(255 * $alpha_percent / 100)), 2, '0', STR_PAD_LEFT));
	$cdef_id = (int) ($options['cdef_id'] ?? 0);
	if ($cdef_id > 0 && !(int) db_fetch_cell_prepared('SELECT COUNT(*) FROM cdef WHERE id = ?', array($cdef_id))) $cdef_id = 0;
	$gprint_id = (int) ($options['gprint_id'] ?? 2);
	if (!(int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_templates_gprint WHERE id = ?', array($gprint_id))) $gprint_id = 2;
	$legend_values = array(
		1 => !array_key_exists('show_average', $options) || !empty($options['show_average']),
		2 => !array_key_exists('show_minimum', $options) || !empty($options['show_minimum']),
		3 => !array_key_exists('show_maximum', $options) || !empty($options['show_maximum']),
		4 => !array_key_exists('show_current', $options) || !empty($options['show_current'])
	);
	$width = in_array((int) ($options['width'] ?? 700), array(300, 500, 700, 900, 1200), true) ? (int) $options['width'] : 700;
	$height = in_array((int) ($options['height'] ?? 200), array(120, 160, 200, 300, 400), true) ? (int) $options['height'] : 200;
	$base_value = in_array((int) ($options['base_value'] ?? 1000), array(1000, 1024), true) ? (int) $options['base_value'] : 1000;
	$image_format_id = in_array((int) ($options['image_format_id'] ?? 3), array(1, 3), true) ? (int) $options['image_format_id'] : 3;
	$slope_mode = !array_key_exists('slope_mode', $options) || !empty($options['slope_mode']) ? 'on' : '';
	$auto_scale = !array_key_exists('auto_scale', $options) || !empty($options['auto_scale']) ? 'on' : '';
	$auto_scale_opts = in_array((int) ($options['auto_scale_opts'] ?? 2), array(1, 2, 3, 4), true) ? (int) $options['auto_scale_opts'] : 2;
	$lower_limit = is_numeric($options['lower_limit'] ?? '0') ? (string) $options['lower_limit'] : '0';
	$upper_limit = is_numeric($options['upper_limit'] ?? '100') ? (string) $options['upper_limit'] : '100';
	$auto_scale_log = !empty($options['auto_scale_log']) ? 'on' : '';
	$auto_scale_rigid = !empty($options['auto_scale_rigid']) ? 'on' : '';
	$auto_padding = !array_key_exists('auto_padding', $options) || !empty($options['auto_padding']) ? 'on' : '';

	$base_graph_template_id = (int) db_fetch_cell("SELECT id FROM graph_templates WHERE name = 'SNMP - Generic OID Template'");
	if ($base_graph_template_id < 1) throw new RuntimeException('Cacti Generic OID graph template is not installed.');

	db_execute('START TRANSACTION');
	try {
		$graph_template_id = (int) api_duplicate_graph(0, $base_graph_template_id, $graph_name, false);
		if ($graph_template_id < 1) throw new RuntimeException('Cacti could not create the graph template.');
		nms_managed_object_record('graph_template', $graph_template_id);

		db_execute_prepared('UPDATE graph_templates_graph SET title = ?, vertical_label = ?, width = ?, height = ?, base_value = ?,
			image_format_id = ?, slope_mode = ?, auto_scale = ?, auto_scale_opts = ?, lower_limit = ?, upper_limit = ?,
			auto_scale_log = ?, auto_scale_rigid = ?, auto_padding = ?
			WHERE graph_template_id = ? AND local_graph_id = 0',
			array('|host_description| - ' . $graph_name, $vertical_label, $width, $height, $base_value,
				$image_format_id, $slope_mode, $auto_scale, $auto_scale_opts, $lower_limit, $upper_limit,
				$auto_scale_log, $auto_scale_rigid, $auto_padding, $graph_template_id));
		db_execute_prepared('UPDATE graph_templates_item SET task_item_id = ?
			WHERE graph_template_id = ? AND local_graph_id = 0',
			array($data_template_rrd_id, $graph_template_id));
		db_execute_prepared('UPDATE graph_templates_item SET graph_type_id = ?, line_width = ?, color_id = ?, alpha = ?, cdef_id = ?, consolidation_function_id = ?
			WHERE graph_template_id = ? AND local_graph_id = 0 AND graph_type_id != 9',
			array($style_options[$graph_style][0], $style_options[$graph_style][1], $color_id, $alpha, $cdef_id,
				$consolidation_options[$consolidation], $graph_template_id));
		db_execute_prepared('UPDATE graph_templates_item SET gprint_id = ?, cdef_id = ?
			WHERE graph_template_id = ? AND local_graph_id = 0 AND graph_type_id = 9',
			array($gprint_id, $cdef_id, $graph_template_id));
		db_execute_prepared("UPDATE graph_template_input SET name = ?
			WHERE graph_template_id = ? AND column_name = 'task_item_id'",
			array('Data Source [' . $source['data_source_name'] . ']', $graph_template_id));

		$minimum_item = db_fetch_row_prepared('SELECT * FROM graph_templates_item
			WHERE graph_template_id = ? AND local_graph_id = 0 AND graph_type_id = 9 AND consolidation_function_id = 1 LIMIT 1',
			array($graph_template_id));
		if ($minimum_item) {
			db_execute_prepared('UPDATE graph_templates_item SET sequence = sequence + 1
				WHERE graph_template_id = ? AND local_graph_id = 0 AND sequence >= 3', array($graph_template_id));
			$minimum_item['id'] = 0;
			$minimum_item['hash'] = get_hash_graph_template(0, 'graph_template_item');
			$minimum_item['text_format'] = 'Minimum:';
			$minimum_item['consolidation_function_id'] = 2;
			$minimum_item['sequence'] = 3;
			$minimum_item_id = (int) sql_save($minimum_item, 'graph_templates_item');
			$data_source_input_id = (int) db_fetch_cell_prepared("SELECT id FROM graph_template_input
				WHERE graph_template_id = ? AND column_name = 'task_item_id' LIMIT 1", array($graph_template_id));
			if ($minimum_item_id > 0 && $data_source_input_id > 0) {
				db_execute_prepared('INSERT IGNORE INTO graph_template_input_defs
					(graph_template_input_id, graph_template_item_id) VALUES (?, ?)',
					array($data_source_input_id, $minimum_item_id));
			}
		}

		$gprint_items = db_fetch_assoc_prepared('SELECT id, consolidation_function_id FROM graph_templates_item
			WHERE graph_template_id = ? AND local_graph_id = 0 AND graph_type_id = 9', array($graph_template_id));
		foreach ($gprint_items as $gprint_item) {
			$cf_id = (int) $gprint_item['consolidation_function_id'];
			if (isset($legend_values[$cf_id]) && !$legend_values[$cf_id]) {
				db_execute_prepared('DELETE FROM graph_template_input_defs WHERE graph_template_item_id = ?', array((int) $gprint_item['id']));
				db_execute_prepared('DELETE FROM graph_templates_item WHERE id = ?', array((int) $gprint_item['id']));
			}
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
