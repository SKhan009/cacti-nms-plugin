<?php
/**
 * @file navigation.php
 * Integrate NMS tabs and navigation labels into Cacti, respecting the registered access realm.
 */
require_once __DIR__ . "/functions.php";

/** Add native Console menu links; Cacti filters them using the registered page realms. */
function nms_config_arrays()
{
	global $menu, $menu_glyphs;

	// Cacti keeps config_arrays active even while a plugin is disabled.
	if (!api_plugin_is_enabled("nms")) {
		return;
	}
	require_once __DIR__ . "/template_native.php";
	nms_native_template_request();

	// Temporarily hidden UI: $menu['NMS']['plugins/nms/nms.php'] = 'Device readings';
	$menu["NMS"]["plugins/nms/devices.php"] = "Devices";
	// Temporarily hidden UI: $menu['NMS']['plugins/nms/fault_config.php'] = 'Fault Configuration';
	// Temporarily hidden UI: $menu['NMS']['plugins/nms/capabilities.php'] = 'FCAPS Capabilities';
	$menu["NMS"]["plugins/nms/topology.php"] = "Topology";
	// Temporarily hidden UI: $menu['NMS']['plugins/nms/graphs.php'] = 'Graphs';
	// Temporarily hidden UI: $menu['NMS']['plugins/nms/templates.php'] = 'Templates';
	$menu["NMS"]["plugins/nms/discovery_presets.php"] = "Presets";
	// Temporarily hidden UI: $menu['NMS']['plugins/nms/ssh_presets.php'] = 'Presets — SSH';
	// Temporarily hidden UI: $menu['NMS']['plugins/nms/ssh_console.php'] = 'SSH Console';
	$menu_glyphs["NMS"] = "fas fa-network-wired";
}

/** Render the NMS navigation tab when permitted, reflecting the current page selection. */
function nms_show_tab()
{
	if (!api_plugin_is_enabled("nms") || !api_user_realm_auth("nms.php")) {
		return;
	}

	$selected = in_array(
		get_current_page(),
		[
			"nms.php",
			"devices.php",
			"diagnostics.php",
			"fault_config.php",
			"capabilities.php",
			"topology.php",
			"graphs.php",
			"templates.php",
			"ssh_presets.php",
			"ssh_device.php",
			"ssh_console.php",
		],
		true,
	)
		? " class='selected'"
		: "";
	$url = html_escape(nms_plugin_url("devices.php")); // Active NMS landing page while fault UI is hidden.
	$icon = html_escape(nms_plugin_url("images/nms.svg"));

	print "<a id='tab-nms'$selected href='$url'><img src='$icon' alt='NMS'></a>";
}

/** Extend Cacti's navigation entries for the current NMS page and return the updated array. */
function nms_draw_navigation_text($nav)
{
	foreach (
		[
			"nms.php" => "Device readings",
			"devices.php" => "Devices",
			"diagnostics.php" => "Presets — Protocol checks",
			"fault_config.php" => "Fault Configuration",
			"capabilities.php" => "FCAPS Capabilities",
			"topology.php" => "Topology",
			"graphs.php" => "Graphs",
			"templates.php" => "Templates",
			"ssh_presets.php" => "SSH presets",
			"ssh_device.php" => "SSH settings",
			"ssh_console.php" => "SSH console",
		]
		as $page => $title
	) {
		$nav[$page . ":"] = [
			"title" => $title,
			"mapping" => "index.php:",
			"url" => nms_plugin_url($page),
			"level" => "1",
		];
	}

	return $nav;
}
