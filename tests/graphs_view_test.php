<?php
/** Static regressions for the read-only, core-backed graph browser. */
function graph_view_assert($pass, $message) { if (!$pass) throw new RuntimeException($message); }
$controller = file_get_contents(__DIR__ . '/../graphs.php');
$adapter = file_get_contents(__DIR__ . '/../includes/graphs.php');
$template = file_get_contents(__DIR__ . '/../templates/graphs.php');
$styles = file_get_contents(__DIR__ . '/../css/nms-graphs.css');
$navigation = file_get_contents(__DIR__ . '/../templates/navigation.php');
graph_view_assert(strpos($adapter, 'get_allowed_trees') !== false, 'Graph trees bypass core permissions');
graph_view_assert(strpos($adapter, 'get_allowed_tree_level') !== false, 'Graph hierarchy is not read from Cacti');
graph_view_assert(strpos($adapter, 'get_allowed_graphs') !== false, 'Graphs bypass core permissions');
graph_view_assert(strpos($controller . $adapter, 'INSERT ') === false && strpos($controller . $adapter, 'UPDATE ') === false, 'Graph view must remain read-only');
graph_view_assert(strpos($template, 'graph_image.php?local_graph_id=') !== false, 'Graph preview missing');
graph_view_assert(strpos($controller, "'Default Tree'") !== false, 'Default Tree is not the preferred initial tree');
graph_view_assert(strpos($template, 'class="nms-shell nms-graphs-page"') !== false, 'Graph page does not use the shared shell');
graph_view_assert(strpos($template, 'class="nms-graph-tree"') !== false, 'Cacti-style tree navigator missing');
graph_view_assert(strpos($template, 'class="nms-graph-filters"') !== false, 'Cacti-style graph filters missing');
foreach (array('device_id', 'template', 'search', 'graphs', 'columns', 'thumbnails', 'range', 'from', 'to') as $filter) graph_view_assert(strpos($template, 'name="' . $filter . '"') !== false, 'Graph filter missing: ' . $filter);
graph_view_assert(strpos($template, '&amp;graph_start=') !== false && strpos($template, '&amp;graph_end=') !== false, 'Graph time range is not applied to images');
graph_view_assert(strpos($template, 'nms-graph-branch') === false, 'Tree branches are still rendered as category tiles');
graph_view_assert(strpos($styles, 'grid-template-columns:repeat(2,minmax(0,1fr))') !== false, 'Graph preview is not a two-column grid');
$header = substr($navigation, strpos($navigation, '<header'), strpos($navigation, '</header>') - strpos($navigation, '<header'));
foreach (array('Topology', 'Devices', 'Graphs') as $label) graph_view_assert(strpos($header, '>' . $label . '</a>') !== false, 'Header item missing: ' . $label);
graph_view_assert(strpos($header, '>Faults</a>') === false && strpos($header, '>Fault Configuration</a>') === false, 'Fault links remain in header');
graph_view_assert(strpos($navigation, 'aria-label="Fault sections"') !== false, 'Fault submenu missing');
graph_view_assert(strpos($navigation, 'href="capabilities.php"') !== false, 'FCAPS page missing from Fault submenu');
print "Core-backed graph browser and navigation checks passed.\n";
