<?php

require_once($config['base_path'] . '/lib/api_device.php');
require_once($config['base_path'] . '/lib/api_automation.php');
require_once($config['base_path'] . '/lib/api_graph.php');
require_once($config['base_path'] . '/lib/template.php');

function nms_device_require($device_id) {
	$device_id = (int) $device_id;
	if ($device_id < 1 || !(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM host WHERE id = ? AND deleted = ''", array($device_id))) {
		throw new InvalidArgumentException('Select a valid Cacti device.');
	}
	return $device_id;
}

function nms_device_add_graph_template($device_id, $graph_template_id) {
	$device_id = nms_device_require($device_id);
	$graph_template_id = (int) $graph_template_id;
	if ($graph_template_id < 1 || !(int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_templates WHERE id = ?', array($graph_template_id))) {
		throw new InvalidArgumentException('Select a valid Cacti graph template.');
	}

	db_execute_prepared('REPLACE INTO host_graph (host_id, graph_template_id) VALUES (?, ?)', array($device_id, $graph_template_id));
	automation_hook_graph_template($device_id, $graph_template_id);
	api_plugin_hook_function('add_graph_template_to_host', array('host_id' => $device_id, 'graph_template_id' => $graph_template_id));
	return $graph_template_id;
}

function nms_device_create_graph_from_data_source($device_id, $local_rrd_id, $graph_name, $vertical_label, $options = array()) {
	$device_id = nms_device_require($device_id);
	$local_rrd_id = (int) $local_rrd_id;
	$source = db_fetch_row_prepared('SELECT dl.id AS local_data_id, dl.snmp_query_id, dl.snmp_index,
		dt.id AS data_template_id, dt.name AS data_template_name, dtr.id AS local_rrd_id,
		dtr.local_data_template_rrd_id, dtr.data_source_name, dtd.name AS data_source_title
		FROM data_local AS dl
		INNER JOIN data_template AS dt ON dt.id = dl.data_template_id
		INNER JOIN data_template_rrd AS dtr ON dtr.local_data_id = dl.id
		INNER JOIN data_template_data AS dtd ON dtd.local_data_id = dl.id
		WHERE dl.host_id = ? AND dtr.id = ?', array($device_id, $local_rrd_id));
	if (!$source) throw new InvalidArgumentException('Select a data-source item from this device.');

	$template_rrd_id = (int) $source['local_data_template_rrd_id'];
	if ($template_rrd_id < 1) {
		$template_rrd_id = (int) db_fetch_cell_prepared('SELECT id FROM data_template_rrd
			WHERE data_template_id = ? AND local_data_id = 0 AND data_source_name = ? LIMIT 1',
			array($source['data_template_id'], $source['data_source_name']));
	}
	if ($template_rrd_id < 1) throw new RuntimeException('This Cacti data source is not linked to a reusable data template item.');

	$default_name = 'NMS ' . $source['data_template_name'] . ' - ' . $source['data_source_name'] . ' - DS ' . (int) $source['local_data_id'];
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

		db_execute_prepared('UPDATE graph_templates_graph SET title = ?, vertical_label = ?, width = ?, height = ?, base_value = ?,
			image_format_id = ?, slope_mode = ?, auto_scale = ?, auto_scale_opts = ?, lower_limit = ?, upper_limit = ?,
			auto_scale_log = ?, auto_scale_rigid = ?, auto_padding = ?
			WHERE graph_template_id = ? AND local_graph_id = 0',
			array('|host_description| - ' . $graph_name, $vertical_label, $width, $height, $base_value,
				$image_format_id, $slope_mode, $auto_scale, $auto_scale_opts, $lower_limit, $upper_limit,
				$auto_scale_log, $auto_scale_rigid, $auto_padding, $graph_template_id));
		db_execute_prepared('UPDATE graph_templates_item SET task_item_id = ?
			WHERE graph_template_id = ? AND local_graph_id = 0',
			array($template_rrd_id, $graph_template_id));
		db_execute_prepared('UPDATE graph_templates_item SET graph_type_id = ?, line_width = ?, color_id = ?, alpha = ?, cdef_id = ?, consolidation_function_id = ?
			WHERE graph_template_id = ? AND local_graph_id = 0 AND graph_type_id != 9',
			array($style_options[$graph_style][0], $style_options[$graph_style][1], $color_id, $alpha, $cdef_id,
				$consolidation_options[$consolidation], $graph_template_id));
		db_execute_prepared('UPDATE graph_templates_item SET gprint_id = ?
			WHERE graph_template_id = ? AND local_graph_id = 0 AND graph_type_id = 9',
			array($gprint_id, $graph_template_id));
		db_execute_prepared("UPDATE graph_template_input SET name = ?
			WHERE graph_template_id = ? AND column_name = 'task_item_id'",
			array('Data Source [' . $source['data_template_name'] . ']', $graph_template_id));

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

		$local_graph_id = (int) sql_save(array(
			'id' => 0,
			'graph_template_id' => $graph_template_id,
			'host_id' => $device_id,
			'snmp_query_id' => (int) $source['snmp_query_id'],
			'snmp_query_graph_id' => 0,
			'snmp_index' => (string) $source['snmp_index']
		), 'graph_local');
		if ($local_graph_id < 1) throw new RuntimeException('Cacti could not create the device graph.');

		change_graph_template($local_graph_id, $graph_template_id, true);
		db_execute_prepared('UPDATE graph_templates_item SET task_item_id = ? WHERE local_graph_id = ?',
			array($local_rrd_id, $local_graph_id));
		db_execute_prepared('REPLACE INTO host_graph (host_id, graph_template_id) VALUES (?, ?)',
			array($device_id, $graph_template_id));
		update_graph_title_cache($local_graph_id);
		set_config_option('time_last_change_graph', time());
		automation_hook_graph_create_tree(array(
			'id' => $local_graph_id,
			'graph_template_id' => $graph_template_id,
			'host_id' => $device_id,
			'snmp_query_id' => (int) $source['snmp_query_id'],
			'snmp_query_graph_id' => 0,
			'snmp_index' => (string) $source['snmp_index']
		));
		api_plugin_hook_function('create_complete_graph_from_template', array(
			'id' => $local_graph_id,
			'graph_template_id' => $graph_template_id,
			'host_id' => $device_id,
			'snmp_query_id' => (int) $source['snmp_query_id'],
			'snmp_query_graph_id' => 0,
			'snmp_index' => (string) $source['snmp_index']
		));
		db_execute('COMMIT');
		return array('graph_template_id' => $graph_template_id, 'local_graph_id' => $local_graph_id);
	} catch (Throwable $exception) {
		db_execute('ROLLBACK');
		throw $exception;
	}
}

function nms_device_add_data_query($device_id, $data_query_id, $reindex_method) {
	global $reindex_types;
	$device_id = nms_device_require($device_id);
	$data_query_id = (int) $data_query_id;
	$reindex_method = (int) $reindex_method;
	$snmp_version = (int) db_fetch_cell_prepared("SELECT snmp_version FROM host WHERE id = ? AND deleted = ''", array($device_id));
	$sql = 'SELECT COUNT(*) FROM snmp_query WHERE id = ?';
	if ($snmp_version === 0) $sql .= ' AND data_input_id != 2';
	if ($data_query_id < 1 || !(int) db_fetch_cell_prepared($sql, array($data_query_id))) {
		throw new InvalidArgumentException('Select a valid Cacti data query for this device.');
	}
	if (!isset($reindex_types[$reindex_method])) throw new InvalidArgumentException('Select a valid re-index method.');

	api_device_dq_add($device_id, $data_query_id, $reindex_method);
	return $data_query_id;
}

function nms_device_require_data_query($device_id, $data_query_id) {
	$device_id = nms_device_require($device_id);
	$data_query_id = (int) $data_query_id;
	if ($data_query_id < 1 || !(int) db_fetch_cell_prepared(
		'SELECT COUNT(*) FROM host_snmp_query WHERE host_id = ? AND snmp_query_id = ?',
		array($device_id, $data_query_id))) {
		throw new InvalidArgumentException('Select a data query associated with this device.');
	}
	return array($device_id, $data_query_id);
}

function nms_device_change_data_query($device_id, $data_query_id, $reindex_method) {
	global $reindex_types;
	list($device_id, $data_query_id) = nms_device_require_data_query($device_id, $data_query_id);
	$reindex_method = (int) $reindex_method;
	if (!isset($reindex_types[$reindex_method])) throw new InvalidArgumentException('Select a valid re-index method.');
	api_device_dq_change($device_id, $data_query_id, $reindex_method);
	return $data_query_id;
}

function nms_device_reload_data_query($device_id, $data_query_id) {
	list($device_id, $data_query_id) = nms_device_require_data_query($device_id, $data_query_id);
	run_data_query($device_id, $data_query_id);
	return $data_query_id;
}

function nms_device_remove_data_query($device_id, $data_query_id) {
	list($device_id, $data_query_id) = nms_device_require_data_query($device_id, $data_query_id);
	api_device_dq_remove($device_id, $data_query_id);
	return $data_query_id;
}

function nms_device_create($input) {
	return nms_device_save(0, $input);
}

function nms_device_update($device_id, $input) {
	$device_id = nms_device_require($device_id);
	return nms_device_save($device_id, $input);
}

function nms_device_save($device_id, $input) {
	$device_id = (int) $device_id;
	$description = trim((string) $input['description']);
	$hostname = trim((string) $input['hostname']);
	$template_id = (int) $input['host_template_id'];
	$site_id = (int) $input['site_id'];
	$poller_id = (int) $input['poller_id'];
	$snmp_version = (int) $input['snmp_version'];
	$snmp_port = (int) $input['snmp_port'];
	$snmp_timeout = (int) $input['snmp_timeout'];
	$community = trim((string) $input['snmp_community']);
	$snmp_username = trim((string) $input['snmp_username']);
	$snmp_password = (string) $input['snmp_password'];
	$snmp_auth_protocol = trim((string) $input['snmp_auth_protocol']);
	$snmp_priv_protocol = trim((string) $input['snmp_priv_protocol']);
	$snmp_priv_passphrase = (string) $input['snmp_priv_passphrase'];
	$proxy = !empty($input['proxy']);

	if ($description === '' || $hostname === '') throw new InvalidArgumentException('Device name and hostname are required.');
	if (!in_array($snmp_version, array(1, 2, 3), true)) throw new InvalidArgumentException('Select SNMP version 1, 2c, or 3.');
	if ($snmp_port < 1 || $snmp_port > 65535) throw new InvalidArgumentException('SNMP port must be between 1 and 65535.');
	if ($snmp_timeout < 100 || $snmp_timeout > 10000) throw new InvalidArgumentException('SNMP timeout must be between 100 and 10000 milliseconds.');
	if ($snmp_version < 3 && $community === '') throw new InvalidArgumentException('Enter the SNMP community for version 1 or 2c.');
	if ($snmp_version === 3) {
		$allowed_auth_protocols = array('[None]', 'MD5', 'SHA', 'SHA224', 'SHA256', 'SHA392', 'SHA512');
		$allowed_priv_protocols = array('[None]', 'DES', 'AES', 'AES128', 'AES192', 'AES192C', 'AES256', 'AES256C');
		if ($snmp_username === '') throw new InvalidArgumentException('Enter the SNMP v3 username.');
		if (!in_array($snmp_auth_protocol, $allowed_auth_protocols, true)) throw new InvalidArgumentException('Select a valid SNMP v3 authentication method.');
		if (!in_array($snmp_priv_protocol, $allowed_priv_protocols, true)) throw new InvalidArgumentException('Select a valid SNMP v3 privacy method.');
		if ($snmp_auth_protocol !== '[None]' && strlen($snmp_password) < 8) throw new InvalidArgumentException('SNMP v3 authentication password must contain at least 8 characters.');
		if ($snmp_auth_protocol === '[None]' && $snmp_priv_protocol !== '[None]') throw new InvalidArgumentException('SNMP v3 privacy requires authentication.');
		if ($snmp_priv_protocol !== '[None]' && strlen($snmp_priv_passphrase) < 8) throw new InvalidArgumentException('SNMP v3 privacy passphrase must contain at least 8 characters.');
		$community = '';
	} else {
		$snmp_username = '';
		$snmp_password = '';
		$snmp_auth_protocol = '[None]';
		$snmp_priv_protocol = '[None]';
		$snmp_priv_passphrase = '';
	}
	if (!(int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host_template WHERE id = ?', array($template_id))) throw new InvalidArgumentException('Select a valid Cacti host template.');
	if (!(int) db_fetch_cell_prepared('SELECT COUNT(*) FROM poller WHERE id = ?', array($poller_id))) throw new InvalidArgumentException('Select a valid data collector.');
	if ((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM host WHERE description = ? AND id != ? AND deleted = ''", array($description, $device_id))) throw new InvalidArgumentException('A Cacti device already uses this name.');
	if (!$proxy && (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM host WHERE hostname = ? AND snmp_port = ? AND snmp_community = ? AND id != ? AND deleted = ''",
		array($hostname, $snmp_port, $community, $device_id))) {
		throw new InvalidArgumentException('This SNMP endpoint already exists. Enable proxy/simulator mode to share an address.');
	}

	$availability = (int) $input['availability_method'];
	$allowed_availability = array(AVAIL_NONE, AVAIL_PING, AVAIL_SNMP, AVAIL_SNMP_AND_PING, AVAIL_SNMP_OR_PING);
	if (!in_array($availability, $allowed_availability, true)) $availability = AVAIL_SNMP;
	$ping_method = (int) $input['ping_method'];
	if (!in_array($ping_method, array(PING_ICMP, PING_TCP, PING_UDP), true)) $ping_method = PING_ICMP;

	$saved_device_id = api_device_save($device_id, $template_id, $description, $hostname,
		$community, $snmp_version,
		$snmp_username, $snmp_password,
		$snmp_port, $snmp_timeout, !empty($input['disabled']) ? 'on' : '',
		$availability, $ping_method, (int) $input['ping_port'],
		(int) $input['ping_timeout'], (int) $input['ping_retries'],
		trim((string) $input['notes']), $snmp_auth_protocol,
		$snmp_priv_passphrase, $snmp_priv_protocol,
		trim((string) $input['snmp_context']), trim((string) $input['snmp_engine_id']),
		(int) $input['max_oids'], (int) $input['device_threads'], $poller_id, $site_id,
		trim((string) $input['external_id']), trim((string) $input['location']), -1);
	if (!$saved_device_id) throw new RuntimeException('Cacti could not save the device. Check the submitted SNMP settings.');
	return (int) $saved_device_id;
}
