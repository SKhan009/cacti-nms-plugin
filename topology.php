<?php

require('../../include/auth.php');
require_once($config['base_path'] . '/plugins/nms/includes/functions.php');
require_once($config['base_path'] . '/plugins/nms/includes/database.php');
require_once($config['base_path'] . '/plugins/nms/includes/topology.php');

nms_setup_database();

$sites = nms_topology_sites();
$site_id = isset_request_var('site_id') ? get_filter_request_var('site_id') : 0;
if ($site_id <= 0 && count($sites)) $site_id = (int) $sites[0]['id'];

$selected_site = null;
foreach ($sites as $site) {
	if ((int) $site['id'] === $site_id) $selected_site = $site;
}
if (!$selected_site && count($sites)) {
	$selected_site = $sites[0];
	$site_id = (int) $selected_site['id'];
}

$user_id = isset($_SESSION['sess_user_id']) ? (int) $_SESSION['sess_user_id'] : 0;
$action = isset_request_var('nms_action') ? get_nfilter_request_var('nms_action') : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $site_id > 0) {
	$host_id = get_filter_request_var('host_id');
	$ok = false;

	if ($action === 'set_root') {
		$ok = nms_topology_set_root($host_id, $site_id, $user_id);
	} elseif ($action === 'save_position') {
		$parent_host_id = get_filter_request_var('parent_host_id');
		$parent_snmp_index = isset_request_var('parent_snmp_index') ? get_nfilter_request_var('parent_snmp_index') : '';
		$x = isset_request_var('x') ? (float) get_nfilter_request_var('x') : 50;
		$y = isset_request_var('y') ? (float) get_nfilter_request_var('y') : 50;
		$ok = nms_topology_save_position($host_id, $site_id, $parent_host_id, $parent_snmp_index, $x, $y, $user_id);
	} elseif ($action === 'remove_device') {
		$ok = nms_topology_remove_device($host_id, $site_id);
	}

	if (isset_request_var('ajax')) {
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		print json_encode(array('ok' => (bool) $ok));
		exit;
	}

	header('Location: topology.php?site_id=' . $site_id);
	exit;
}

$topology_devices = $site_id > 0 ? nms_topology_devices($site_id) : array();
$topology_interfaces = $site_id > 0 ? nms_topology_interfaces($site_id) : array();
$root_device = null;
$mapped_count = 0;
$up_count = 0;
foreach ($topology_devices as $device) {
	if ($device['locked'] === 'on') $root_device = $device;
	if ((int) $device['is_mapped'] === 1) $mapped_count++;
	if ((int) $device['status'] === HOST_UP) $up_count++;
}

$nms_csrf_token = csrf_get_tokens();
$nms_asset_base = $config['url_path'] . 'plugins/nms/';
$nms_backend_url = $config['url_path'] . 'index.php';
$nms_active_module = 'topology';
$nms_page_title = 'NMS · Dynamic Topology';
$nms_extra_css = 'css/nms-topology.css?v=1.0.0';
$nms_extra_js = 'js/nms-topology.js?v=1.0.0';

require($config['base_path'] . '/plugins/nms/templates/app_header.php');

if ($root_device) {
	print '<script>window.NMS_TOPOLOGY = ' . json_encode(array(
		'siteId' => $site_id,
		'rootId' => (int) $root_device['id'],
		'csrfToken' => $nms_csrf_token,
		'saveUrl' => $config['url_path'] . 'plugins/nms/topology.php?site_id=' . $site_id . '&ajax=1',
		'devices' => nms_topology_json_devices($topology_devices, $topology_interfaces, $config['url_path'])
	), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
}

require($config['base_path'] . '/plugins/nms/templates/topology.php');
require($config['base_path'] . '/plugins/nms/templates/app_footer.php');
