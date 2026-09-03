<?php
/** Collect authorized graphs once, without turning Cacti tree branches into category cards. */
function nms_collect_tree_graphs($nodes, &$graphs, &$seen_graphs) {
	foreach ($nodes as $node) {
		if (($node['kind'] ?? '') === 'branch') {
			nms_collect_tree_graphs($node['children'] ?? array(), $graphs, $seen_graphs);
			continue;
		}
		$node_graphs = ($node['kind'] ?? '') === 'host' ? ($node['graphs'] ?? array()) : array($node['graph'] ?? null);
		foreach ($node_graphs as $graph) {
			$id = (int) ($graph['local_graph_id'] ?? 0);
			if ($id < 1 || isset($seen_graphs[$id])) continue;
			$seen_graphs[$id] = true;
			$graphs[] = $graph;
		}
	}
}

/** Render an authorized Cacti tree recursively without treating its hierarchy as network topology. */
function nms_render_graph_tree_nav($nodes, $url_path) {
	if (!$nodes) return;
	?><ul><?php foreach ($nodes as $node) {
		$kind = $node['kind'] ?? 'branch';
		if ($kind === 'branch') {
			$title = trim((string) ($node['title'] ?? '')) ?: 'Untitled branch';
			?><li class="nms-tree-branch"><span><span aria-hidden="true">▾</span><?php print nms_h($title); ?></span><?php nms_render_graph_tree_nav($node['children'] ?? array(), $url_path); ?></li><?php
		} elseif ($kind === 'host') {
			$title = trim((string) ($node['hostname'] ?? '')) ?: ('Device ' . (int) ($node['host_id'] ?? 0));
			?><li class="nms-tree-host"><span><span aria-hidden="true">●</span><?php print nms_h($title); ?></span><ul><?php foreach ($node['graphs'] ?? array() as $graph) {
				$id = (int) ($graph['local_graph_id'] ?? 0);
				if ($id < 1) continue;
				$title = trim((string) ($graph['title_cache'] ?? '')) ?: ('Graph ' . $id);
				?><li class="nms-tree-graph"><a href="#nms-graph-<?php print $id; ?>"><?php print nms_h($title); ?></a></li><?php
			} ?></ul></li><?php
		} else {
			$graph = $node['graph'] ?? null;
			$id = (int) ($graph['local_graph_id'] ?? 0);
			if ($id < 1) continue;
			$title = trim((string) ($graph['title_cache'] ?? '')) ?: ('Graph ' . $id);
			?><li class="nms-tree-graph"><a href="#nms-graph-<?php print $id; ?>"><?php print nms_h($title); ?></a></li><?php
		}
	} ?></ul><?php
}

/** Render one authorized native graph as a linked live-image card. */
function nms_render_graph_preview($graph, $url_path) {
	$id = (int) ($graph['local_graph_id'] ?? 0);
	if ($id < 1) return;
	$title = trim((string) ($graph['title_cache'] ?? '')) ?: ('Graph ' . $id);
	?><article class="nms-graph-card" id="nms-graph-<?php print $id; ?>">
		<a class="nms-graph-preview" href="<?php print nms_h($url_path . 'graph.php?action=view&amp;local_graph_id=' . $id); ?>" aria-label="Open <?php print nms_h($title); ?> in Cacti">
			<img loading="lazy" src="<?php print nms_h($url_path . 'graph_image.php?local_graph_id=' . $id . '&amp;rra_id=0'); ?>" alt="<?php print nms_h($title); ?>">
		</a>
		<footer><strong><?php print nms_h($title); ?></strong><?php if (!empty($graph['description'])) { ?><small><?php print nms_h($graph['description']); ?></small><?php } ?></footer>
	</article><?php
}

$graphs = array();
$seen_graphs = array();
nms_collect_tree_graphs($tree_nodes, $graphs, $seen_graphs);
$selected_tree_name = $selected_tree['name'] ?? 'Default Tree';
?>
<main class="nms-shell nms-graphs-page">
	<header class="nms-heading">
		<div><p class="nms-eyebrow">NMS / Graphs</p><h1>Graphs</h1><p>Live graph hierarchy, images and permissions come directly from Cacti core.</p></div>
	</header>
	<section class="nms-panel nms-graph-workspace">
		<aside class="nms-graph-tree" aria-label="Cacti graph tree">
			<header><span>Cacti graph tree</span><strong><?php print nms_h($selected_tree_name); ?></strong></header>
			<?php if (count($trees) > 1) { ?><form method="get"><label for="nms-tree-select">Tree</label><select id="nms-tree-select" name="tree_id" onchange="this.form.submit()"><?php foreach ($trees as $tree) { ?><option value="<?php print (int) $tree['id']; ?>" <?php if ((int) $tree['id'] === $tree_id) print 'selected'; ?>><?php print nms_h($tree['name']); ?></option><?php } ?></select><noscript><button type="submit">Show</button></noscript></form><?php } ?>
			<nav><?php if ($tree_nodes) nms_render_graph_tree_nav($tree_nodes, $config['url_path']); else { ?><p>No items in this tree.</p><?php } ?></nav>
		</aside>
		<section class="nms-graph-results">
			<header class="nms-panel-head"><div><h2><?php print nms_h($selected_tree_name); ?></h2><p><?php print count($graphs); ?> permitted graph(s)</p></div><a class="nms-panel-action" href="<?php print nms_h($config['url_path'] . 'graph_view.php?action=tree&tree_id=' . $tree_id); ?>">Open in Cacti</a></header>
			<div class="nms-graph-grid"><?php if (!$graphs) { ?><p class="nms-empty">No graph items are configured in this tree.</p><?php } else foreach ($graphs as $graph) nms_render_graph_preview($graph, $config['url_path']); ?></div>
		</section>
	</section>
</main>
