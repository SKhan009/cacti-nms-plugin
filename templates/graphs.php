<?php
/** Render nested Cacti tree nodes and graph cards recursively. */
function nms_render_graph_card($graph, $url_path) {
	if (!$graph || empty($graph['local_graph_id'])) return;
	$id = (int) $graph['local_graph_id'];
	$title = $graph['title_cache'] ?: ('Graph ' . $id);
	?><article class="nms-graph-card" data-nms-page-item>
		<a class="nms-graph-preview" href="<?php print nms_h($url_path . 'graph.php?action=view&amp;local_graph_id=' . $id); ?>">
			<img loading="lazy" src="<?php print nms_h($url_path . 'graph_image.php?local_graph_id=' . $id . '&amp;rra_id=0'); ?>" alt="<?php print nms_h($title); ?>">
		</a><div><strong><?php print nms_h($title); ?></strong><?php if (!empty($graph['description'])) { ?><small><?php print nms_h($graph['description']); ?></small><?php } ?></div>
	</article><?php
}
function nms_render_graph_nodes($nodes, $url_path) {
	foreach ($nodes as $node) {
		if ($node['kind'] === 'branch') { ?><section class="nms-graph-branch"><h2><?php print nms_h($node['title'] ?: 'Untitled branch'); ?></h2><?php nms_render_graph_nodes($node['children'], $url_path); ?></section><?php }
		elseif ($node['kind'] === 'host') { ?><section class="nms-graph-host"><h2><?php print nms_h($node['hostname'] ?: ('Device ' . (int) $node['host_id'])); ?></h2><div class="nms-graph-grid"><?php foreach ($node['graphs'] as $graph) nms_render_graph_card($graph, $url_path); ?></div></section><?php }
		else nms_render_graph_card($node['graph'], $url_path);
	}
}
?>
<main class="nms-page nms-graphs-page">
	<header class="nms-heading"><div><p>NMS / Graphs</p><h1>Cacti graph trees</h1><span>Live graph hierarchy and permissions come directly from Cacti core.</span></div>
	<form method="get"><label>Cacti graph tree<select name="tree_id" onchange="this.form.submit()"><?php foreach ($trees as $tree) { ?><option value="<?php print (int) $tree['id']; ?>" <?php if ((int) $tree['id'] === $tree_id) print 'selected'; ?>><?php print nms_h($tree['name']); ?></option><?php } ?></select></label><noscript><button type="submit">Show</button></noscript></form></header>
	<section class="nms-panel"><header class="nms-section-title"><div><h2><?php print nms_h($selected_tree['name'] ?? 'Graphs'); ?></h2><p><?php print (int) $graph_count; ?> permitted graph(s) in this tree</p></div><a class="nms-button" href="<?php print nms_h($config['url_path'] . 'graph_view.php?action=tree&tree_id=' . $tree_id); ?>">Open in Cacti</a></header>
		<div class="nms-graph-content"><?php if (!$tree_nodes) { ?><p class="nms-empty">No graph items are configured in this tree.</p><?php } else nms_render_graph_nodes($tree_nodes, $config['url_path']); ?></div>
	</section>
</main>
