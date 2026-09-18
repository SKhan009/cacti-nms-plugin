<?php
/** Read-only Cacti graph-tree adapter. No graph or hierarchy data is copied into plugin tables. */

/** Return trees permitted by Cacti's native graph authorization layer. */
function nms_graph_trees()
{
	$total = 0;
	return get_allowed_trees(false, false, "enabled = 'on'", "sequence, name", "", $total);
}

/** Return allowed graphs for one host tree leaf. */
function nms_graphs_for_host($host_id)
{
	$total = 0;
	return get_allowed_graphs("gl.host_id = " . (int) $host_id, "gtg.title_cache", "", $total);
}

/** Expand the same branch, host and graph leaf types stored by Cacti graph trees. */
function nms_graph_tree_nodes($tree_id, $parent_id = 0, $depth = 0, &$seen = [])
{
	if ($depth > 30 || isset($seen[$parent_id])) {
		return [];
	}
	$seen[$parent_id] = true;
	$nodes = [];
	foreach (get_allowed_tree_level((int) $tree_id, (int) $parent_id) as $item) {
		$node = $item;
		$node["depth"] = $depth;
		$node["kind"] = (int) $item["local_graph_id"] > 0 ? "graph" : ((int) $item["host_id"] > 0 ? "host" : "branch");
		$node["graphs"] = $node["kind"] === "host" ? nms_graphs_for_host($item["host_id"]) : [];
		$node["children"] =
			$node["kind"] === "branch" ? nms_graph_tree_nodes($tree_id, $item["id"], $depth + 1, $seen) : [];
		if ($node["kind"] === "graph") {
			$total = 0;
			$allowed = get_allowed_graphs("", "gtg.title_cache", "1", $total, 0, (int) $item["local_graph_id"]);
			$node["graph"] = count($allowed) ? $allowed[0] : null;
		}
		$nodes[] = $node;
	}
	return $nodes;
}

/** Count all rendered graph leaves in a nested tree result. */
function nms_graph_node_count($nodes)
{
	$count = 0;
	foreach ($nodes as $node) {
		$count +=
			count($node["graphs"] ?? []) +
			(!empty($node["graph"]) ? 1 : 0) +
			nms_graph_node_count($node["children"] ?? []);
	}
	return $count;
}
