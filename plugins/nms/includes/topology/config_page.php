<?php
/** Included by the authenticated topology controller after accessible-site validation. */
require_once __DIR__ . "/config.php";
if (isset_request_var("canvas_api")) {
	header("Content-Type: application/json");
	header("Cache-Control: no-store");
	require_once __DIR__ . "/canvas.php";
	try {
		if ($_SERVER["REQUEST_METHOD"] === "POST") {
			if (!csrf_check_tokens($_POST["__csrf_magic"] ?? "")) {
				throw new RuntimeException("Invalid request token.");
			}
			nms_canvas_write(get_nfilter_request_var("nms_action"), $site_id, $_POST);
			print json_encode(["ok" => true]);
		} else {
			print json_encode(nms_canvas_data($site_id), JSON_THROW_ON_ERROR);
		}
	} catch (Throwable $e) {
		http_response_code(400);
		print json_encode([
			"ok" => false,
			"error" =>
				$e instanceof InvalidArgumentException
					? $e->getMessage()
					: "Canvas request failed. Check permissions and configuration.",
		]);
	}
	exit();
}
$page_error = "";
$config_section = $topology_tab === "discovered" ? "view" : ($topology_tab === "discovery" ? "results" : "devices");
if ($_SERVER["REQUEST_METHOD"] === "POST") {
	try {
		nms_require_management();
		if (!$selected_site) {
			throw new InvalidArgumentException("Select a Cacti site first.");
		}
		$action = get_nfilter_request_var("nms_action");
		if (!csrf_check_tokens($_POST["__csrf_magic"] ?? "")) {
			throw new RuntimeException("Invalid request token.");
		}
		if ($action === "discovery_test") {
			require_once __DIR__ . "/../discovery.php";
			nms_nd_test_queue(get_filter_request_var("host_id"), $site_id);
		} elseif (in_array($action, ["discovery_assignment"], true)) {
			require_once __DIR__ . "/../discovery.php";
			nms_nd_save($action, $site_id, $_POST);
		} else {
			throw new InvalidArgumentException(
				"Use device preset assignment or discovery test. Planning actions are no longer available.",
			);
		}
		$_SESSION["nms_topology_notice"] =
			$action === "discovery_test"
				? "Discovery test queued for the next native poll cycle. Inspect the topology after collection completes."
				: "Topology configuration saved successfully.";
		header("Location: topology.php?tab=" . rawurlencode($topology_tab) . "&site_id=" . $site_id);
		exit();
	} catch (Throwable $error) {
		$page_error = $error->getMessage();
	}
}
$notice = $_SESSION["nms_topology_notice"] ?? "";
unset($_SESSION["nms_topology_notice"]);
$visible = nms_visible_host_sql();
$devices = db_fetch_assoc_prepared(
	"SELECT h.id, h.description, h.hostname, h.status, h.disabled, h.last_updated,
	ct.category_id, ct.device_type, c.name AS category_name
	FROM host h LEFT JOIN plugin_nms_device_classification ct ON ct.host_id = h.id
	LEFT JOIN plugin_nms_categories c ON c.id = ct.category_id
	WHERE h.deleted = '' AND h.site_id = ? AND $visible ORDER BY h.description",
	[$site_id],
);
$base_url = "topology.php?tab=" . rawurlencode($topology_tab) . "&site_id=" . $site_id;
nms_prepare_page(
	"topology",
	"NMS · Topology configuration",
	"css/nms-topology-config.css",
	$config_section === "view" ? "js/nms-hybrid.js" : "js/nms-topology-config.js",
);
require __DIR__ . "/../../templates/app_header.php";
/** Output common authenticated POST fields without duplicating business data. */
function nms_topology_form_fields($action)
{
	global $nms_csrf_token;
	print '<input type="hidden" name="__csrf_magic" value="' .
		nms_h($nms_csrf_token) .
		'"><input type="hidden" name="nms_action" value="' .
		nms_h($action) .
		'">';
}
require __DIR__ . "/../../templates/topology/config.php";
require __DIR__ . "/../../templates/app_footer.php";
