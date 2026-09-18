<?php
require_once __DIR__ . "/canvas.php";
require_once __DIR__ . "/appearance.php";
$topology_tab = $requested_tab;
$error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
	try {
		if (!csrf_check_tokens($_POST["__csrf_magic"] ?? "")) {
			throw new InvalidArgumentException("Invalid request token.");
		}
		nms_appearance_save($_POST);
		header(
			"Location: topology.php?tab=appearance&section=" .
				(strpos($_POST["nms_action"] ?? "", "type_") === 0 ? "types" : "segments") .
				"&saved=1",
		);
		exit();
	} catch (Throwable $e) {
		$error = $e->getMessage();
	}
}
nms_prepare_page(
	"topology",
	"NMS · " . ($topology_tab === "appearance" ? "Device appearance" : "Discovery inventory"),
	"css/nms-topology-config.css",
	"js/nms-appearance.js",
);
require __DIR__ . "/../../templates/app_header.php";
/**
 * Handles catalog fields.
 */
function nms_catalog_fields($action, $id = "")
{
	global $nms_csrf_token;
	print '<input type="hidden" name="__csrf_magic" value="' .
		nms_h($nms_csrf_token) .
		'"><input type="hidden" name="nms_action" value="' .
		nms_h($action) .
		'"><input type="hidden" name="id" value="' .
		nms_h($id) .
		'">';
}
require __DIR__ . "/../../templates/topology/catalog.php";
require __DIR__ . "/../../templates/app_footer.php";
