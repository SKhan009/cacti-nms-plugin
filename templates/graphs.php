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
function nms_render_graph_preview($graph, $url_path, $graph_start, $graph_end) {
	$id = (int) ($graph['local_graph_id'] ?? 0);
	if ($id < 1) return;
	$title = trim((string) ($graph['title_cache'] ?? '')) ?: ('Graph ' . $id);
	?><article class="nms-graph-card" id="nms-graph-<?php print $id; ?>">
		<a class="nms-graph-preview" href="<?php print nms_h($url_path . 'graph.php?action=view&amp;local_graph_id=' . $id); ?>" aria-label="Open <?php print nms_h($title); ?> in Cacti">
			<img loading="lazy" src="<?php print nms_h($url_path . 'graph_image.php?local_graph_id=' . $id . '&amp;rra_id=0&amp;graph_start=' . (int) $graph_start . '&amp;graph_end=' . (int) $graph_end); ?>" alt="<?php print nms_h($title); ?>">
		</a>
		<footer><strong><?php print nms_h($title); ?></strong><?php if (!empty($graph['description'])) { ?><small><?php print nms_h($graph['description']); ?></small><?php } ?></footer>
	</article><?php
}

$graphs = array();
$seen_graphs = array();
nms_collect_tree_graphs($tree_nodes, $graphs, $seen_graphs);
$selected_tree_name = $selected_tree['name'] ?? 'Default Tree';
$all_graphs = $graphs;
$device_id = isset_request_var('device_id') ? get_filter_request_var('device_id') : 0;
$template_filter = isset_request_var('template') ? trim((string) get_nfilter_request_var('template')) : '';
$search = isset_request_var('search') ? trim((string) get_nfilter_request_var('search')) : '';
$per_page = isset_request_var('graphs') ? get_filter_request_var('graphs') : 20;
$columns = isset_request_var('columns') ? get_filter_request_var('columns') : 2;
$page = isset_request_var('page') ? get_filter_request_var('page') : 1;
$thumbnails = isset_request_var('thumbnails');
if (!in_array($per_page, array(10, 20, 30, 50), true)) $per_page = 20;
if (!in_array($columns, array(1, 2, 3), true)) $columns = 2;

$device_options = array();
$template_options = array();
foreach ($all_graphs as $graph) {
	$host_id = (int) ($graph['host_id'] ?? 0);
	if ($host_id > 0) $device_options[$host_id] = trim((string) ($graph['description'] ?? '')) ?: ('Device ' . $host_id);
	$template_name = trim((string) ($graph['template_name'] ?? ''));
	if ($template_name !== '') $template_options[$template_name] = $template_name;
}
natcasesort($device_options);
natcasesort($template_options);
$graphs = array_values(array_filter($all_graphs, function ($graph) use ($device_id, $template_filter, $search) {
	if ($device_id > 0 && (int) ($graph['host_id'] ?? 0) !== $device_id) return false;
	if ($template_filter !== '' && (string) ($graph['template_name'] ?? '') !== $template_filter) return false;
	if ($search !== '') {
		$haystack = (string) ($graph['title_cache'] ?? '') . ' ' . (string) ($graph['description'] ?? '') . ' ' . (string) ($graph['template_name'] ?? '');
		if (stripos($haystack, $search) === false) return false;
	}
	return true;
}));

$range = isset_request_var('range') ? (string) get_nfilter_request_var('range') : 'day';
$range_seconds = array('hour' => 3600, 'day' => 86400, 'week' => 604800, 'month' => 2592000);
if (!isset($range_seconds[$range]) && $range !== 'custom') $range = 'day';
$graph_end = time();
$graph_start = $graph_end - ($range_seconds[$range] ?? $range_seconds['day']);
if ($range === 'custom' && isset_request_var('from') && isset_request_var('to')) {
	$requested_start = strtotime((string) get_nfilter_request_var('from'));
	$requested_end = strtotime((string) get_nfilter_request_var('to'));
	if ($requested_start !== false && $requested_end !== false && $requested_start < $requested_end) {
		$graph_start = $requested_start;
		$graph_end = $requested_end;
	}
}
$filtered_count = count($graphs);
$page_count = max(1, (int) ceil($filtered_count / $per_page));
$page = max(1, min($page, $page_count));
$graphs = array_slice($graphs, ($page - 1) * $per_page, $per_page);
$query_values = array('tree_id' => $tree_id, 'device_id' => $device_id, 'template' => $template_filter, 'search' => $search, 'graphs' => $per_page, 'columns' => $columns, 'range' => $range);
if ($thumbnails) $query_values['thumbnails'] = 1;
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
			<header class="nms-panel-head"><div><h2><?php print nms_h($selected_tree_name); ?></h2><p><?php print $filtered_count; ?> of <?php print count($all_graphs); ?> permitted graph(s)</p></div><a class="nms-panel-action" href="<?php print nms_h($config['url_path'] . 'graph_view.php?action=tree&tree_id=' . $tree_id); ?>">Open in Cacti</a></header>
			<form class="nms-graph-filters" method="get" action="graphs.php">
				<input type="hidden" name="tree_id" value="<?php print $tree_id; ?>">
				<label>Device<select name="device_id"><option value="0">All devices</option><?php foreach ($device_options as $id => $name) { ?><option value="<?php print (int) $id; ?>" <?php print (int) $id === $device_id ? 'selected' : ''; ?>><?php print nms_h($name); ?></option><?php } ?></select></label>
				<label>Template<select name="template"><option value="">All graphs &amp; templates</option><?php foreach ($template_options as $name) { ?><option value="<?php print nms_h($name); ?>" <?php print $name === $template_filter ? 'selected' : ''; ?>><?php print nms_h($name); ?></option><?php } ?></select></label>
				<label class="nms-graph-search">Search<input type="search" name="search" value="<?php print nms_h($search); ?>" placeholder="Graph, device or template"></label>
				<label>Graphs<select name="graphs"><?php foreach (array(10, 20, 30, 50) as $size) { ?><option value="<?php print $size; ?>" <?php print $size === $per_page ? 'selected' : ''; ?>><?php print $size; ?></option><?php } ?></select></label>
				<label>Columns<select name="columns"><?php foreach (array(1, 2, 3) as $count) { ?><option value="<?php print $count; ?>" <?php print $count === $columns ? 'selected' : ''; ?>><?php print $count; ?> column<?php print $count === 1 ? '' : 's'; ?></option><?php } ?></select></label>
				<label class="nms-thumbnail-filter"><input type="checkbox" name="thumbnails" value="1" <?php print $thumbnails ? 'checked' : ''; ?>> Thumbnails</label>
				<label>Preset<select name="range"><option value="hour" <?php print $range === 'hour' ? 'selected' : ''; ?>>Last hour</option><option value="day" <?php print $range === 'day' ? 'selected' : ''; ?>>Last day</option><option value="week" <?php print $range === 'week' ? 'selected' : ''; ?>>Last week</option><option value="month" <?php print $range === 'month' ? 'selected' : ''; ?>>Last month</option><option value="custom" <?php print $range === 'custom' ? 'selected' : ''; ?>>Custom</option></select></label>
				<label>From<input type="datetime-local" name="from" value="<?php print nms_h(date('Y-m-d\\TH:i', $graph_start)); ?>"></label>
				<label>To<input type="datetime-local" name="to" value="<?php print nms_h(date('Y-m-d\\TH:i', $graph_end)); ?>"></label>
				<div class="nms-graph-filter-actions"><button type="submit">Go</button><a href="graphs.php?tree_id=<?php print $tree_id; ?>">Clear</a></div>
			</form>
			<div class="nms-graph-grid columns-<?php print $columns; ?><?php print $thumbnails ? ' thumbnails' : ''; ?>"><?php if (!$graphs) { ?><p class="nms-empty">No graphs match these filters.</p><?php } else foreach ($graphs as $graph) nms_render_graph_preview($graph, $config['url_path'], $graph_start, $graph_end); ?></div>
			<?php if ($page_count > 1) { ?><nav class="nms-graph-pagination" aria-label="Graph pages"><span><?php print (($page - 1) * $per_page) + 1; ?>–<?php print min($page * $per_page, $filtered_count); ?> of <?php print $filtered_count; ?></span><div><?php if ($page > 1) { $query_values['page'] = $page - 1; ?><a href="?<?php print nms_h(http_build_query($query_values)); ?>">Previous</a><?php } ?><strong><?php print $page; ?> / <?php print $page_count; ?></strong><?php if ($page < $page_count) { $query_values['page'] = $page + 1; ?><a href="?<?php print nms_h(http_build_query($query_values)); ?>">Next</a><?php } ?></div></nav><?php } ?>
		</section>
	</section>
</main>
