<?php
/** Standalone registration/menu regressions; no Cacti database or network required. */
require_once(__DIR__ . '/../setup.php');
require_once(__DIR__ . '/../includes/navigation.php');

$config = array('url_path' => '/monitor/cacti/');
$status = 1;
$allowed = true;
$page = 'index.php';
$hooks = array();
$realm = array();

function verify_menu($condition, $message) {
	if (!$condition) throw new RuntimeException($message);
}
function db_fetch_cell_prepared($sql, $params) {
	global $status;
	verify_menu($params === array('nms'), 'Only NMS status may be queried');
	return $status;
}
function api_plugin_register_hook($plugin, $hook, $callback, $file) {
	global $hooks;
	$trace = debug_backtrace();
	// Cacti 1.2.31 accepts registration only from install/upgrade/setup functions.
	verify_menu((bool) preg_match('/install|upgrade|setup/i', $trace[1]['function']), 'Invalid lifecycle caller');
	verify_menu($plugin === 'nms', 'Wrong plugin');
	verify_menu(is_file(__DIR__ . '/../' . $file), 'Missing hook file');
	$hooks[$hook] = array('callback' => $callback, 'file' => $file, 'status' => 1);
}
function api_plugin_register_realm($plugin, $files, $label, $admin) {
	global $realm;
	$trace = debug_backtrace();
	verify_menu((bool) preg_match('/install|upgrade|setup/i', $trace[1]['function']), 'Invalid realm lifecycle caller');
	verify_menu($plugin === 'nms' && $admin === 1, 'Default grant must remain administrator only');
	$realm = explode(',', $files);
}
function api_plugin_enable_hooks($plugin) {
	global $hooks;
	foreach ($hooks as &$hook) $hook['status'] = 1;
}
function api_plugin_disable_hooks($plugin) {
	global $hooks;
	foreach ($hooks as $name => &$hook) $hook['status'] = $name === 'config_arrays' ? 1 : 4;
}
function api_plugin_is_enabled($plugin) { return $GLOBALS['status'] === 1; }
function api_user_realm_auth($filename) { return $GLOBALS['allowed'] && in_array($filename, $GLOBALS['realm'], true); }
function get_current_page() { return $GLOBALS['page']; }
function html_escape($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }

foreach (array(0, 4, 1) as $status) {
	$hooks = array();
	nms_setup_registration();
	verify_menu(count($hooks) === 7, 'Missing registration');
	$first = $hooks;
	nms_setup_registration();
	verify_menu($hooks === $first, 'Registration must be repeatable without duplicates');
	verify_menu($hooks['top_graph_header_tabs']['status'] === ($status === 1 ? 1 : 4), 'Disabled plugin accidentally activated');
	verify_menu(count($realm) === 7 && in_array('graphs.php', $realm, true) && in_array('capabilities.php', $realm, true), 'Incomplete access realm');
	$menu = array('Management' => array('host.php' => 'Devices'));
	$menu_glyphs = array();
	nms_config_arrays();
	nms_config_arrays();
	verify_menu(isset($menu['Management']['host.php']), 'Core menu overwritten');
	verify_menu(isset($menu['NMS']) === ($status === 1), 'Incorrect disabled menu visibility');
	if ($status === 1) verify_menu(count($menu['NMS']) === 7, 'Missing or duplicate sidebar link');
}
foreach (array(false, true) as $allowed) {
	foreach (array(4, 1) as $status) {
		ob_start(); nms_show_tab(); $html = ob_get_clean();
		verify_menu(($html !== '') === ($allowed && $status === 1), 'Tab bypassed realm or disabled state');
		if ($html !== '') verify_menu(strpos($html, '/monitor/cacti/plugins/nms/nms.php') !== false, 'URL prefix lost');
	}
}
$status = 1; $allowed = true; $page = 'topology.php';
ob_start(); nms_show_tab(); $html = ob_get_clean();
verify_menu(strpos($html, "class='selected'") !== false, 'Active tab not selected');
$nav = nms_draw_navigation_text(array('index.php:' => array('title' => 'Console')));
verify_menu($nav['index.php:']['title'] === 'Console', 'Core breadcrumb overwritten');
foreach ($realm as $file) {
	verify_menu(isset($nav[$file . ':']['title'], $nav[$file . ':']['mapping'], $nav[$file . ':']['url'], $nav[$file . ':']['level']), 'Malformed breadcrumb');
	verify_menu($nav[$file . ':']['url'] === '/monitor/cacti/plugins/nms/' . $file, 'Incorrect breadcrumb URL');
}
print "Plugin registration and navigation tests passed.\n";
