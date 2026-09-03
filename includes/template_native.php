<?php
/** Opt-in presentation hook for native editors; core authentication, CSRF,
 * controllers and input-dependent scripts run without being intercepted. */
function nms_native_template_pages() {
	return array('data_input.php' => 'input', 'data_queries.php' => 'query', 'data_templates.php' => 'source',
		'graph_templates.php' => 'graph', 'graph_templates_items.php' => 'graph', 'graph_templates_inputs.php' => 'graph', 'host_templates.php' => 'device');
}
function nms_native_template_active() {
	global $config;
	$page = basename($_SERVER['SCRIPT_NAME'] ?? '');
	if (!isset(nms_native_template_pages()[$page])) return false;
	if (isset($_REQUEST['nms_workspace'])) return $_REQUEST['nms_workspace'] === 'templates';
	// Native redirects omit UI context. Carry it only from a same-host,
	// same-installation template editor, never from an arbitrary referrer.
	$ref = parse_url($_SERVER['HTTP_REFERER'] ?? '');
	if (!$ref || ($ref['host'] ?? '') !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) return false;
	if (($ref['path'] ?? '') !== $config['url_path'] . basename($ref['path'] ?? '')) return false;
	if (!isset(nms_native_template_pages()[basename($ref['path'] ?? '')])) return false;
	parse_str($ref['query'] ?? '', $query);
	return ($query['nms_workspace'] ?? '') === 'templates';
}
function nms_native_template_request() {
	if (!nms_native_template_active()) return;
	// A regular browser redirect needs Cacti's full document; AJAX requests
	// keep their native fragments and are still handled by Cacti itself.
	if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
		unset($_GET['header'], $_POST['header'], $_REQUEST['header']);
		if (function_exists('unset_request_var')) unset_request_var('header');
	}
}
function nms_native_template_head() {
	global $config;
	if (!nms_native_template_active()) return;
	require_once(__DIR__ . '/functions.php');
	$nms_active_module = 'templates';
	$nms_template_section = nms_native_template_pages()[basename($_SERVER['SCRIPT_NAME'])];
	$nms_backend_url = $config['url_path'] . 'index.php';
	ob_start(); require(__DIR__ . '/../templates/navigation.php'); $navigation = ob_get_clean();
	foreach (array('css/nms-v1.1.css', 'css/nms-layout.css', 'css/nms-core-editor.css', 'css/nms-tooltips.css') as $asset) {
		print '<link rel="stylesheet" href="' . nms_h(nms_asset_url($asset)) . '">';
	}
	print '<script type="application/json" id="nmsNativeConfig">' . json_encode(array(
		'navigation' => $navigation, 'base' => $config['url_path'], 'pages' => nms_native_template_pages(),
		'workspace' => $config['url_path'] . 'plugins/nms/templates.php?section=' . $nms_template_section,
		'section' => $nms_template_section
	), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
	print '<script defer src="' . nms_h(nms_asset_url('js/nms-templates.js')) . '"></script>';
}
