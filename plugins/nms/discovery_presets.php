<?php
/** NMS-owned preset controller, separate from device protocol selection. */
require __DIR__ . "/../../include/auth.php";
require_once __DIR__ . "/includes/database.php";
require_once __DIR__ . "/includes/discovery.php";
nms_require_database();
$error = "";
$notice = isset_request_var("saved") ? "Discovery preset saved." : "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
	try {
		if (!csrf_check_tokens($_POST["__csrf_magic"] ?? "")) {
			throw new RuntimeException("Invalid request token.");
		}
		if (($_POST["nms_action"] ?? "") === "discovery_bulk") {
			$_SESSION["nms_discovery_bulk"] = nms_nd_bulk_assign($_POST);
		} elseif (($_POST["nms_action"] ?? "") === "discovery_preset") {
			nms_nd_save("discovery_preset", 0, $_POST);
		} else {
			throw new InvalidArgumentException("Unknown preset action.");
		}
		$target = ($_POST["nms_action"] ?? "") === "discovery_bulk" ? "assignments" : "presets";
		header("Location: discovery_presets.php?saved=1&section=" . $target);
		exit();
	} catch (Throwable $e) {
		$error = $e->getMessage();
	}
}
$base_url = "discovery_presets.php";
nms_prepare_page("presets", "NMS · Connection discovery presets", "css/nms-topology-config.css", "js/nms-upload.js");
require __DIR__ . "/templates/app_header.php";
/**
 * Handles topology form fields.
 */
function nms_topology_form_fields($action)
{
	global $nms_csrf_token;
	print '<input type="hidden" name="__csrf_magic" value="' .
		nms_h($nms_csrf_token) .
		'"><input type="hidden" name="nms_action" value="' .
		nms_h($action) .
		'">';
}
print '<main class="nms-shell nms-topology-config"><h1>Connection discovery presets</h1>';
if (isset($_SESSION["nms_discovery_bulk"])) {
	$r = $_SESSION["nms_discovery_bulk"];
	unset($_SESSION["nms_discovery_bulk"]);
	$notice =
		$r["saved"] .
		" assigned; " .
		$r["excluded"] .
		" excluded; " .
		count($r["errors"]) .
		" errors. " .
		implode(" ", $r["errors"]);
}
if ($notice) {
	print '<p role="status">' . nms_h($notice) . "</p>";
}
if ($error) {
	print '<p role="alert">' . nms_h($error) . "</p>";
}
require __DIR__ . "/templates/discovery/presets.php";
print "</main>";
require __DIR__ . "/templates/app_footer.php";
