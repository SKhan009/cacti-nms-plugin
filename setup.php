<?php

function plugin_nms_install() {
	global $config;

	api_plugin_register_hook('nms', 'top_header_tabs', 'nms_show_tab', 'includes/navigation.php');
	api_plugin_register_hook('nms', 'top_graph_header_tabs', 'nms_show_tab', 'includes/navigation.php');
	api_plugin_register_hook('nms', 'draw_navigation_text', 'nms_draw_navigation_text', 'includes/navigation.php');
	api_plugin_register_hook('nms', 'page_head', 'nms_page_head', 'setup.php');
	api_plugin_register_hook('nms', 'poller_output', 'nms_poller_output', 'includes/polling.php');
	api_plugin_register_hook('nms', 'poller_bottom', 'nms_poller_bottom', 'includes/polling.php');

	api_plugin_register_realm('nms', 'nms.php,topology.php', 'View NMS Faults and Configure Topology', 1);

	include_once($config['base_path'] . '/plugins/nms/includes/database.php');
	nms_setup_database();
}

function plugin_nms_uninstall() {
	global $config;

	include_once($config['base_path'] . '/plugins/nms/includes/database.php');
	nms_drop_database();
}

function plugin_nms_check_config() {
	global $config;

	include_once($config['base_path'] . '/plugins/nms/includes/database.php');
	nms_setup_database();

	return true;
}

function plugin_nms_upgrade() {
	plugin_nms_check_config();
	api_plugin_register_realm('nms', 'nms.php,topology.php', 'View NMS Faults and Configure Topology', 1);
}

function plugin_nms_version() {
	global $config;

	$info = parse_ini_file($config['base_path'] . '/plugins/nms/INFO', true);
	return $info['info'];
}

function nms_check_dependencies() {
	return true;
}

function nms_page_head() {
	global $config;

	if (in_array(get_current_page(), array('nms.php', 'topology.php'), true)) {
		print '<link rel="stylesheet" href="' . html_escape($config['url_path'] . 'plugins/nms/css/nms-v1.1.css?v=1.6.0') . '">';
	}
}
