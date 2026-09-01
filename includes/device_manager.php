<?php

require_once($config['base_path'] . '/lib/api_device.php');

function nms_device_create($input) {
	$description = trim((string) $input['description']);
	$hostname = trim((string) $input['hostname']);
	$template_id = (int) $input['host_template_id'];
	$site_id = (int) $input['site_id'];
	$poller_id = (int) $input['poller_id'];
	$snmp_version = (int) $input['snmp_version'];
	$snmp_port = (int) $input['snmp_port'];
	$snmp_timeout = (int) $input['snmp_timeout'];
	$community = trim((string) $input['snmp_community']);
	$proxy = !empty($input['proxy']);

	if ($description === '' || $hostname === '') throw new InvalidArgumentException('Device name and hostname are required.');
	if (!in_array($snmp_version, array(1, 2, 3), true)) throw new InvalidArgumentException('Select SNMP version 1, 2c, or 3.');
	if ($snmp_port < 1 || $snmp_port > 65535) throw new InvalidArgumentException('SNMP port must be between 1 and 65535.');
	if ($snmp_timeout < 100 || $snmp_timeout > 10000) throw new InvalidArgumentException('SNMP timeout must be between 100 and 10000 milliseconds.');
	if (!(int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host_template WHERE id = ?', array($template_id))) throw new InvalidArgumentException('Select a valid Cacti host template.');
	if (!(int) db_fetch_cell_prepared('SELECT COUNT(*) FROM poller WHERE id = ?', array($poller_id))) throw new InvalidArgumentException('Select a valid data collector.');
	if ((int) db_fetch_cell_prepared("SELECT COUNT(*) FROM host WHERE description = ? AND deleted = ''", array($description))) throw new InvalidArgumentException('A Cacti device already uses this name.');
	if (!$proxy && (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM host WHERE hostname = ? AND snmp_port = ? AND snmp_community = ? AND deleted = ''",
		array($hostname, $snmp_port, $community))) {
		throw new InvalidArgumentException('This SNMP endpoint already exists. Enable proxy/simulator mode to share an address.');
	}

	$availability = (int) $input['availability_method'];
	$allowed_availability = array(AVAIL_NONE, AVAIL_PING, AVAIL_SNMP, AVAIL_SNMP_AND_PING, AVAIL_SNMP_OR_PING);
	if (!in_array($availability, $allowed_availability, true)) $availability = AVAIL_SNMP;
	$ping_method = (int) $input['ping_method'];
	if (!in_array($ping_method, array(PING_ICMP, PING_TCP, PING_UDP), true)) $ping_method = PING_ICMP;

	$device_id = api_device_save(0, $template_id, $description, $hostname,
		$community, $snmp_version,
		trim((string) $input['snmp_username']), (string) $input['snmp_password'],
		$snmp_port, $snmp_timeout, !empty($input['disabled']) ? 'on' : '',
		$availability, $ping_method, (int) $input['ping_port'],
		(int) $input['ping_timeout'], (int) $input['ping_retries'],
		trim((string) $input['notes']), trim((string) $input['snmp_auth_protocol']),
		(string) $input['snmp_priv_passphrase'], trim((string) $input['snmp_priv_protocol']),
		trim((string) $input['snmp_context']), trim((string) $input['snmp_engine_id']),
		(int) $input['max_oids'], (int) $input['device_threads'], $poller_id, $site_id,
		trim((string) $input['external_id']), trim((string) $input['location']), -1);
	if (!$device_id) throw new RuntimeException('Cacti could not create the device. Check the submitted SNMP settings.');
	return (int) $device_id;
}
