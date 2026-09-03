<?php
/** Sidebar-only template workspace. Native editors retain all core fields and APIs. */
require_once(__DIR__ . '/includes/template_workspace.php');
$nms_template_sections = nms_template_sections();
$nms_template_section = isset($_GET['section']) && is_string($_GET['section']) && isset($nms_template_sections[$_GET['section']]) ? $_GET['section'] : 'input';
if ($nms_template_section === 'graph' && ($_GET['view'] ?? '') === 'builder') {
	define('NMS_TEMPLATE_WORKSPACE', true);
	require(__DIR__ . '/devices.php');
	exit;
}
require(__DIR__ . '/../../include/auth.php');
require_once(__DIR__ . '/includes/functions.php');
$section = $nms_template_sections[$nms_template_section];
try {
	$route = nms_template_core_route($nms_template_section, is_string($_GET['core'] ?? '') ? ($_GET['core'] ?? '') : '');
} catch (InvalidArgumentException $exception) {
	http_response_code(400);
	die(htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'));
}
// Native editors stay top-level; Cacti's page-head hook supplies NMS navigation.
header('Location: ' . $config['url_path'] . $route . (strpos($route, '?') === false ? '?' : '&') . 'nms_workspace=templates');
exit;
