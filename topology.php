<?php
/**
 * @file topology.php
 * Topology controller: select a core Cacti site, accept map-layout actions, and build the browser configuration.
 * NMS owns visual coordinates and parent selections; device health and interface indexes come from Cacti.
 */

require(__DIR__ . '/../../include/auth.php');
require_once($config['base_path'] . '/plugins/nms/includes/functions.php');
require_once($config['base_path'] . '/plugins/nms/includes/database.php');
require_once($config['base_path'] . '/plugins/nms/includes/topology.php');
require_once(__DIR__ . '/includes/relationships.php');

nms_require_database();

// Site membership comes from Cacti core; choose a valid site before loading or changing map layout.
$sites = nms_topology_sites();
$site_id = isset_request_var('site_id') ? get_filter_request_var('site_id') : 0;
if ($site_id <= 0 && count($sites)) $site_id = (int) $sites[0]['id'];

$selected_site = null;
foreach ($sites as $site) {
	if ((int) $site['id'] === $site_id) $selected_site = $site;
}
if (!$selected_site && $site_id > 0) {
	http_response_code(404);
	die('Select an accessible Cacti site.');
}

$user_id = nms_current_user_id();
$topology_tab = isset_request_var('tab') ? get_nfilter_request_var('tab') : 'map';
if (in_array($topology_tab, array('configuration', 'racks'), true)) {
	require(__DIR__ . '/includes/topology_config_page.php');
	exit;
}
$action = isset_request_var('nms_action') ? get_nfilter_request_var('nms_action') : '';
$page_error = '';

// Route layout writes through shared membership checks; these actions do not create or delete core devices.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $site_id > 0) {
	try {
	nms_require_management();
	$host_id = get_filter_request_var('host_id');
	$ok = false;
	if ($host_id > 0) nms_require_device_access($host_id);

	if ($action === 'save_relationship') {
		nms_relationship_save(get_nfilter_request_var('source_endpoint'), get_nfilter_request_var('target_endpoint'), get_nfilter_request_var('relation_type'));
		$ok = true;
	} elseif ($action === 'archive_relationship') {
		nms_relationship_archive(get_filter_request_var('relationship_id'));
		$ok = true;
	} elseif ($action === 'set_root') {
		$ok = nms_topology_set_root($host_id, $site_id, $user_id);
	} elseif ($action === 'save_position') {
		// The parent port is an SNMP interface index, distinct from a transport port such as UDP 161.
		$parent_host_id = get_filter_request_var('parent_host_id');
		if ($parent_host_id > 0) nms_require_device_access($parent_host_id);
		$parent_snmp_index = isset_request_var('parent_snmp_index') ? get_nfilter_request_var('parent_snmp_index') : '';
		$x = isset_request_var('x') ? (float) get_nfilter_request_var('x') : 50;
		$y = isset_request_var('y') ? (float) get_nfilter_request_var('y') : 50;
		$ok = nms_topology_save_position($host_id, $site_id, $parent_host_id, $parent_snmp_index, $x, $y, $user_id);
	} elseif ($action === 'remove_device') {
		$ok = nms_topology_remove_device($host_id, $site_id);
	} else {
		throw new InvalidArgumentException('Unsupported topology action.');
	}
	if (!$ok) throw new RuntimeException('The topology change was not saved. Check the selected device and site membership.');

	if (isset_request_var('ajax')) {
		// Drag-and-drop saves expect JSON; ordinary form submissions use the redirect below.
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		print json_encode(array('ok' => (bool) $ok));
		exit;
	}

	header('Location: topology.php?site_id=' . $site_id);
	exit;
	} catch (Throwable $error) {
		$page_error = $error->getMessage();
		if (isset_request_var('ajax')) {
			http_response_code(400);
			header('Content-Type: application/json; charset=utf-8');
			print json_encode(array('ok' => false, 'error' => $page_error));
			exit;
		}
	}
}

// Combine live core device/interface facts with plugin-owned visual positions and parent selections.
$topology_devices = $site_id > 0 ? nms_topology_devices($site_id) : array();
$topology_interfaces = $site_id > 0 ? nms_topology_interfaces($site_id) : array();
$relationships = $site_id > 0 ? nms_relationships($site_id) : array();
$relationship_options = $site_id > 0 ? nms_relationship_options() : array();
$root_device = null;
$mapped_count = 0;
$up_count = 0;
$fault_count = 0;
foreach ($topology_devices as $device) {
	if ($device['locked'] === 'on') $root_device = $device;
	if ((int) $device['is_mapped'] === 1) $mapped_count++;
	if (nms_device_status_name($device) === 'Up') $up_count++;
	if ((int) $device['fault_count'] > 0) $fault_count++;
}

nms_prepare_page('topology', 'NMS · Dynamic Topology', 'css/nms-topology.css', 'js/nms-topology.js');

require($config['base_path'] . '/plugins/nms/templates/app_header.php');

// Publish client configuration only once a root exists; JSON hex escaping keeps device text out of script markup.
if ($root_device) {
	print '<script>window.NMS_TOPOLOGY = ' . json_encode(array(
		'siteId' => $site_id,
		'rootId' => (int) $root_device['id'],
		'csrfToken' => $nms_csrf_token,
		'saveUrl' => $config['url_path'] . 'plugins/nms/topology.php?site_id=' . $site_id . '&ajax=1',
		'devices' => nms_topology_json_devices($topology_devices, $topology_interfaces, $config['url_path']),
		// Expose only the connection identifiers needed to draw edges, not stored audit data.
		'relationships' => array_map(function($edge) {
			return array('id' => (int) $edge['id'], 'source' => (int) $edge['source_host_id'], 'target' => (int) $edge['target_host_id'],
				'type' => $edge['relation_type'], 'provenance' => $edge['provenance'], 'identityState' => $edge['identity_state']);
		}, $relationships)
	), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
}

require($config['base_path'] . '/plugins/nms/templates/topology.php');
require($config['base_path'] . '/plugins/nms/templates/app_footer.php');
