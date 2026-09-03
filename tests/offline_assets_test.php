<?php
/** Offline asset contract: execute with PHP only; no Cacti instance or downloads. */
require_once(__DIR__ . '/../includes/functions.php');
function html_escape($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function offline_check($condition, $message) {
	if (!$condition) throw new RuntimeException($message);
}
$config = array('url_path' => '/private/cacti/');
$nms_backend_url = '/private/cacti/index.php';
$root = dirname(__DIR__);
$modules = array(
	'faults' => array('', ''),
	'devices' => array('css/nms-devices.css', 'js/nms-devices.js'),
	'configuration' => array('css/nms-fault-config.css', ''),
	'topology' => array('css/nms-topology.css', 'js/nms-topology.js')
);
foreach ($modules as $nms_active_module => $assets) {
	list($nms_extra_css, $nms_extra_js) = $assets;
	ob_start();
	include $root . '/templates/app_header.php';
	include $root . '/templates/app_footer.php';
	$html = ob_get_clean();
	preg_match_all('~<(?:script|link|img)\b[^>]*(?:src|href)="([^"]+)"~i', $html, $matches);
	offline_check(count($matches[1]) >= 6, 'Shared shell assets were not captured');
	foreach ($matches[1] as $url) {
		$prefix = '/private/cacti/plugins/nms/';
		offline_check(strpos($url, $prefix) === 0, 'Non-local resource: ' . $url);
		$relative = explode('?', substr($url, strlen($prefix)))[0];
		offline_check(is_file($root . '/' . $relative), 'Missing bundled asset: ' . $relative);
	}
}
foreach (glob($root . '/css/*.css') as $file) {
	$css = file_get_contents($file);
	offline_check(!preg_match('~@import\b~i', $css), 'CSS import needs review: ' . $file);
	preg_match_all('~url\(\s*["\x27]?([^\)]+)\)~i', $css, $matches);
	foreach ($matches[1] as $url) {
		$url = rtrim($url, "\"' \t\r\n");
		offline_check(strpos($url, 'data:') === 0 || is_file(dirname($file) . '/' . $url), 'Unbundled CSS resource: ' . $url);
	}
}
$js = file_get_contents($root . '/js/nms-topology.js');
offline_check(strpos($js, "mode: 'same-origin'") !== false, 'Topology must reject cross-origin requests');
offline_check(is_file($root . '/snmpsim/examples/nms-device-demo.snmprec'), 'Missing local sample download');
print "Offline shell assets, CSS resources, local sample and same-origin request checks passed.\n";
