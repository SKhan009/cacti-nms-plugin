<?php
/**
 * @file topology.php
 * Topology controller: select a core Cacti site, accept map-layout actions, and build the browser configuration.
 * NMS owns visual coordinates and parent selections; device health and interface indexes come from Cacti.
 */

require __DIR__ . "/../../include/auth.php";
require_once $config["base_path"] . "/plugins/nms/includes/functions.php";
require_once $config["base_path"] . "/plugins/nms/includes/database.php";
require_once $config["base_path"] . "/plugins/nms/includes/topology/service.php";
require_once __DIR__ . "/includes/relationships.php";

nms_require_database();

if (($_GET['tab'] ?? '') === 'connections') {
    require __DIR__ . '/includes/topology/connections_page.php';
    exit;
}
// Assignments now have one owner in Add/Edit Device. Preserve bookmarks without
// executing obsolete assignment POSTs or requiring their former site filter.
$requested_tab = isset_request_var("tab") ? get_nfilter_request_var("tab") : "discovered";
if (in_array($requested_tab, ["appearance", "inventory"], true)) {
	require __DIR__ . "/includes/topology/catalog_page.php";
	exit();
}
if ($requested_tab === "configuration") {
	header("Location: " . nms_plugin_url("devices.php?tab=inventory"), true, 303);
	exit();
}

// Site membership comes from Cacti core; choose a valid site before loading or changing map layout.
$sites = nms_topology_sites();
$site_id = isset_request_var("site_id") ? get_filter_request_var("site_id") : 0;
if ($site_id <= 0 && count($sites)) {
	$site_id = (int) $sites[0]["id"];
}

$selected_site = null;
foreach ($sites as $site) {
	if ((int) $site["id"] === $site_id) {
		$selected_site = $site;
	}
}
if (!$selected_site && $site_id > 0 && !in_array($_GET["tab"] ?? "discovered", ["discovered", "map"], true)) {
	http_response_code(404);
	die("Select an accessible Cacti site.");
}

// Keep the consolidated canvas and the retained, hidden results view.
$topology_tab = isset_request_var("tab") ? get_nfilter_request_var("tab") : "discovered";
if ($topology_tab === "map") {
	$topology_tab = "discovered";
}
if (!in_array($topology_tab, ["discovery", "discovered"], true)) {
	$topology_tab = "discovered";
}
require __DIR__ . "/includes/topology/config_page.php";
