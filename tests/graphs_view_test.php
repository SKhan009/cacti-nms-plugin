<?php
/** Static regressions for the read-only, core-backed graph browser. */
function graph_view_assert($pass, $message) { if (!$pass) throw new RuntimeException($message); }
$controller = file_get_contents(__DIR__ . '/../graphs.php');
$adapter = file_get_contents(__DIR__ . '/../includes/graphs.php');
$template = file_get_contents(__DIR__ . '/../templates/graphs.php');
$navigation = file_get_contents(__DIR__ . '/../templates/navigation.php');
graph_view_assert(strpos($adapter, 'get_allowed_trees') !== false, 'Graph trees bypass core permissions');
graph_view_assert(strpos($adapter, 'get_allowed_tree_level') !== false, 'Graph hierarchy is not read from Cacti');
graph_view_assert(strpos($adapter, 'get_allowed_graphs') !== false, 'Graphs bypass core permissions');
graph_view_assert(strpos($controller . $adapter, 'INSERT ') === false && strpos($controller . $adapter, 'UPDATE ') === false, 'Graph view must remain read-only');
graph_view_assert(strpos($template, 'graph_image.php?local_graph_id=') !== false, 'Graph preview missing');
$header = substr($navigation, strpos($navigation, '<header'), strpos($navigation, '</header>') - strpos($navigation, '<header'));
foreach (array('Topology', 'Devices', 'Graphs') as $label) graph_view_assert(strpos($header, '>' . $label . '</a>') !== false, 'Header item missing: ' . $label);
graph_view_assert(strpos($header, '>Faults</a>') === false && strpos($header, '>Fault Configuration</a>') === false, 'Fault links remain in header');
graph_view_assert(strpos($navigation, 'aria-label="Fault sections"') !== false, 'Fault submenu missing');
print "Core-backed graph browser and navigation checks passed.\n";
