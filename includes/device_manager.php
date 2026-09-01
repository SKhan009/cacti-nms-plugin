<?php

require_once($config['base_path'] . '/lib/api_device.php');
require_once($config['base_path'] . '/lib/api_automation.php');

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
