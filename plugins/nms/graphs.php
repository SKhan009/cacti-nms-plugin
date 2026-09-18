<?php
/** Read-only graph browser backed by Cacti's native graph trees and permissions. */
require __DIR__ . "/../../include/auth.php";
require_once $config["base_path"] . "/lib/auth.php";
require_once $config["base_path"] . "/plugins/nms/includes/functions.php";
require_once $config["base_path"] . "/plugins/nms/includes/database.php";
require_once $config["base_path"] . "/plugins/nms/includes/graphs.php";
nms_require_database();

$trees = nms_graph_trees();
$tree_id = isset_request_var("tree_id") ? get_filter_request_var("tree_id") : 0;
$default_tree_id = 0;
foreach ($trees as $tree) {
	if (strcasecmp(trim((string) ($tree["name"] ?? "")), "Default Tree") === 0) {
		$default_tree_id = (int) $tree["id"];
		break;
	}
}
if ($tree_id < 1) {
	$tree_id = $default_tree_id ?: (count($trees) ? (int) $trees[0]["id"] : 0);
}
$allowed_tree_ids = array_map("intval", array_column($trees, "id"));
if ($tree_id > 0 && !in_array($tree_id, $allowed_tree_ids, true)) {
	http_response_code(403);
	die("This Cacti graph tree is not available.");
}
$selected_tree = null;
foreach ($trees as $tree) {
	if ((int) $tree["id"] === $tree_id) {
		$selected_tree = $tree;
	}
}
$seen = [];
$tree_nodes = $tree_id > 0 ? nms_graph_tree_nodes($tree_id, 0, 0, $seen) : [];
$graph_count = nms_graph_node_count($tree_nodes);

nms_prepare_page("graphs", "NMS · Graphs", "css/nms-graphs.css");
require $config["base_path"] . "/plugins/nms/templates/app_header.php";
require $config["base_path"] . "/plugins/nms/templates/monitoring/graphs.php";
require $config["base_path"] . "/plugins/nms/templates/app_footer.php";
