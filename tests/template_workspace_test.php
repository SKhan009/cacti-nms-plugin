<?php
require_once(__DIR__ . '/../includes/template_workspace.php');
function workspace_assert($pass, $message) { if (!$pass) throw new RuntimeException($message); }
$sections = nms_template_sections();
workspace_assert(array_keys($sections) === array('input', 'query', 'source', 'graph', 'device'), 'Missing template sections');
foreach ($sections as $key => $section) workspace_assert(nms_template_core_route($key) === $section['page'], 'Wrong core route');
workspace_assert(nms_template_core_route('graph', 'graph_templates_items.php?action=item_edit&id=29&graph_template_id=4&header=false') === 'graph_templates_items.php?action=item_edit&id=29&graph_template_id=4', 'Editor deep link lost or headerless fragment allowed');
foreach (array('https://evil.example/graph_templates.php', '//evil.example/graph_templates.php', '../include/config.php', 'graph_templates.php?action=remove', 'data_input.php?action=save') as $bad) {
	$rejected = false;
	try { nms_template_core_route('graph', $bad); } catch (InvalidArgumentException $e) { $rejected = true; }
	workspace_assert($rejected, 'Unsafe workspace entry accepted: ' . $bad);
}
$header = file_get_contents(__DIR__ . '/../templates/navigation.php');
$top = substr($header, strpos($header, '<header'), strpos($header, '</header>') - strpos($header, '<header'));
workspace_assert(strpos($top, 'templates.php') === false, 'Templates must not appear in top header');
workspace_assert(strpos($header, 'aria-label="Template sections"') !== false, 'Sidebar submenu missing');
workspace_assert(strpos($header, '<details class="nms-template-menu"') !== false, 'Templates submenu must expand and collapse');
require_once(__DIR__ . '/../includes/template_native.php');
$config = array('url_path' => '/cacti/');
$_SERVER = array('SCRIPT_NAME' => '/cacti/data_input.php', 'HTTP_HOST' => '127.0.0.1:8080');
$_REQUEST = array('nms_workspace' => 'templates');
workspace_assert(nms_native_template_active(), 'Native workspace marker not recognized');
$_REQUEST['nms_workspace'] = 'off';
workspace_assert(!nms_native_template_active(), 'Explicit native mode ignored');
$_REQUEST = array();
$_SERVER['HTTP_REFERER'] = 'http://127.0.0.1:8080/cacti/data_templates.php?nms_workspace=templates';
workspace_assert(nms_native_template_active(), 'Native redirect lost workspace');
$_SERVER['HTTP_REFERER'] = 'https://other.example/cacti/data_templates.php?nms_workspace=templates';
workspace_assert(!nms_native_template_active(), 'Foreign referrer enabled workspace');
$_REQUEST = array('nms_workspace' => 'templates', 'header' => 'false');
$_GET = $_POST = array('header' => 'false');
nms_native_template_request();
workspace_assert(!isset($_REQUEST['header']) && !isset($_GET['header']) && !isset($_POST['header']), 'Full-page navigation kept headerless fragment');
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
$_REQUEST['header'] = 'false';
nms_native_template_request();
workspace_assert(isset($_REQUEST['header']), 'Native AJAX fragment changed');
$_SERVER['SCRIPT_NAME'] = '/cacti/host.php';
workspace_assert(!nms_native_template_active(), 'Non-template core page intercepted');
$graph = file_get_contents(__DIR__ . '/../templates/devices/graphs.php');
workspace_assert(strpos($graph, 'action="devices.php?tab=graphs') === false, 'Builder posts to old device route');
workspace_assert(strpos($graph, 'templates.php?section=graph&amp;view=builder#graph-builder') !== false, 'Missing builder POST route');
print "Template workspace routes, native sections, deep links and sidebar-only navigation passed.\n";
$native_js = file_get_contents(__DIR__ . '/../js/nms-templates.js');
workspace_assert(strpos($native_js, "main.querySelectorAll('.actionsDropdown')") !== false, 'Bulk action placement must cover all native lists');
workspace_assert(strpos($native_js, 'form.insertBefore(actions, anchor)') !== false, 'Original action controls must remain inside the list form');
workspace_assert(strpos($native_js, 'actions.nextElementSibling !== anchor') !== false, 'Repeated AJAX decoration must not continually move controls');
print "Shared top-of-list bulk action decoration checks passed.\n";
