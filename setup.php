<?php
/**
 * @file setup.php
 * Cacti plugin lifecycle entry points: register hooks and access realms, apply schema upgrades, and report plugin metadata.
 */

/** Register Cacti hooks and access realms, then initialize the plugin's database schema. */
function plugin_nms_install() {
	global $config;

	api_plugin_register_hook('nms', 'top_header_tabs', 'nms_show_tab', 'includes/navigation.php');
	api_plugin_register_hook('nms', 'top_graph_header_tabs', 'nms_show_tab', 'includes/navigation.php');
	api_plugin_register_hook('nms', 'draw_navigation_text', 'nms_draw_navigation_text', 'includes/navigation.php');
	api_plugin_register_hook('nms', 'page_head', 'nms_page_head', 'setup.php');
	api_plugin_register_hook('nms', 'poller_output', 'nms_poller_output', 'includes/polling.php');
	api_plugin_register_hook('nms', 'poller_bottom', 'nms_poller_bottom', 'includes/polling.php');

	api_plugin_register_realm('nms', 'nms.php,devices.php,fault_config.php,topology.php', 'View NMS Faults, Devices, Rules, and Topology', 1);

	include_once($config['base_path'] . '/plugins/nms/includes/database.php');
	nms_setup_database();
}

/** Remove NMS database storage using the plugin's uninstall helper. */
function plugin_nms_uninstall() {
	global $config;

	include_once($config['base_path'] . '/plugins/nms/includes/database.php');
	nms_drop_database();
}

/** Ensure the plugin schema and configuration migrations are applied, then report success. */
function plugin_nms_check_config() {
	global $config;

	include_once($config['base_path'] . '/plugins/nms/includes/database.php');
	nms_setup_database();

	/* Cacti retains installed metadata until the plugin refreshes its own row. */
	$info = plugin_nms_version();
	db_execute_prepared('UPDATE plugin_config SET version = ?, name = ?, author = ?, webpage = ? WHERE directory = ?',
		array($info['version'], $info['longname'], $info['author'], $info['homepage'], 'nms'));

	return true;
}

/** Apply configuration migrations and refresh the NMS access realm during a plugin upgrade. */
function plugin_nms_upgrade() {
	plugin_nms_check_config();
	api_plugin_register_realm('nms', 'nms.php,devices.php,fault_config.php,topology.php', 'View NMS Faults, Devices, Rules, and Topology', 1);
}

/** Return the shared INFO metadata required by Cacti's plugin manager. */
function plugin_nms_version() {
	global $config;

	require_once($config['base_path'] . '/plugins/nms/includes/functions.php');
	return nms_plugin_info();
}

/** Report the plugin's unconditional dependency-hook result; this hook performs no runtime checks. */
function nms_check_dependencies() {
	return true;
}

/** Include the versioned shared stylesheet only on NMS application pages. */
function nms_page_head() {
	global $config;

	if (in_array(get_current_page(), array('nms.php', 'devices.php', 'fault_config.php', 'topology.php'), true)) {
		require_once($config['base_path'] . '/plugins/nms/includes/functions.php');
		print '<link rel="stylesheet" href="' . html_escape(nms_asset_url('css/nms-v1.1.css')) . '">';
	}
}
