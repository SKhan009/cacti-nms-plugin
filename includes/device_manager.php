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

function nms_device_create_graph_from_data_source($device_id, $local_rrd_id, $graph_name, $vertical_label) {
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

	$already_graphed = (int) db_fetch_cell_prepared('SELECT COUNT(*)
		FROM graph_templates_item AS gti
		INNER JOIN graph_local AS gl ON gl.id = gti.local_graph_id
		WHERE gl.host_id = ? AND gti.task_item_id = ?', array($device_id, $local_rrd_id));
	if ($already_graphed) throw new InvalidArgumentException('This data-source item is already used by a graph for this device.');

	$default_name = 'NMS ' . $source['data_template_name'] . ' - ' . $source['data_source_name'] . ' - DS ' . (int) $source['local_data_id'];
	$graph_name = trim((string) $graph_name) === '' ? $default_name : nms_template_clean_name($graph_name, 190);
	$vertical_label = trim((string) $vertical_label);
	if ($vertical_label === '') $vertical_label = substr($source['data_source_name'], 0, 20);
	$vertical_label = substr(preg_replace('/[^A-Za-z0-9 _\/%.-]/', '', $vertical_label), 0, 20);
	if ($vertical_label === '') $vertical_label = 'Value';
	if ((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_templates WHERE name = ?', array($graph_name))) {
		throw new InvalidArgumentException('A Cacti graph template with this name already exists. Choose another name.');
	}

	$base_graph_template_id = (int) db_fetch_cell("SELECT id FROM graph_templates WHERE name = 'SNMP - Generic OID Template'");
	if ($base_graph_template_id < 1) throw new RuntimeException('Cacti Generic OID graph template is not installed.');

	db_execute('START TRANSACTION');
	try {
		$graph_template_id = (int) api_duplicate_graph(0, $base_graph_template_id, $graph_name, false);
		if ($graph_template_id < 1) throw new RuntimeException('Cacti could not create the graph template.');

		db_execute_prepared('UPDATE graph_templates_graph SET title = ?, vertical_label = ?
			WHERE graph_template_id = ? AND local_graph_id = 0',
			array('|host_description| - ' . $graph_name, $vertical_label, $graph_template_id));
		db_execute_prepared('UPDATE graph_templates_item SET task_item_id = ?
			WHERE graph_template_id = ? AND local_graph_id = 0',
			array($template_rrd_id, $graph_template_id));

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
