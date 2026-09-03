<?php
/**
 * @file navigation.php
 * Integrate NMS tabs and navigation labels into Cacti, respecting the registered access realm.
 */

/** Add native Console menu links; Cacti filters them using the registered page realms. */
function nms_config_arrays() {
	global $menu, $menu_glyphs;

	// Cacti keeps config_arrays active even while a plugin is disabled.
	if (!api_plugin_is_enabled('nms')) return;
	require_once(__DIR__ . '/template_native.php');
	nms_native_template_request();

	$menu['NMS']['plugins/nms/nms.php'] = 'Device readings';
	$menu['NMS']['plugins/nms/devices.php'] = 'Devices';
	$menu['NMS']['plugins/nms/fault_config.php'] = 'Fault Configuration';
	$menu['NMS']['plugins/nms/capabilities.php'] = 'FCAPS Capabilities';
	$menu['NMS']['plugins/nms/topology.php'] = 'Topology';
	$menu['NMS']['plugins/nms/graphs.php'] = 'Graphs';
	$menu['NMS']['plugins/nms/templates.php'] = 'Templates';
	$menu_glyphs['NMS'] = 'fas fa-network-wired';
}

/** Render the NMS navigation tab when permitted, reflecting the current page selection. */
function nms_show_tab() {
	global $config;

	if (!api_plugin_is_enabled('nms') || !api_user_realm_auth('nms.php')) {
		return;
	}

	$selected = in_array(get_current_page(), array('nms.php', 'devices.php', 'fault_config.php', 'capabilities.php', 'topology.php', 'graphs.php', 'templates.php'), true) ? " class='selected'" : '';
	$url = html_escape($config['url_path'] . 'plugins/nms/nms.php');
	$icon = html_escape($config['url_path'] . 'plugins/nms/images/nms.svg');

	print "<a id='tab-nms'$selected href='$url'><img src='$icon' alt='NMS'></a>";
}

/** Extend Cacti's navigation entries for the current NMS page and return the updated array. */
function nms_draw_navigation_text($nav) {
	global $config;
	foreach (array('nms.php' => 'Device readings', 'devices.php' => 'Devices',
		'fault_config.php' => 'Fault Configuration', 'capabilities.php' => 'FCAPS Capabilities', 'topology.php' => 'Topology', 'graphs.php' => 'Graphs', 'templates.php' => 'Templates') as $page => $title) {
		$nav[$page . ':'] = array('title' => $title, 'mapping' => 'index.php:',
			'url' => $config['url_path'] . 'plugins/nms/' . $page, 'level' => '1');
	}

	return $nav;
}
