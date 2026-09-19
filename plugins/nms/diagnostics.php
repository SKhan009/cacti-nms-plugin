<?php
/**
 * @file diagnostics.php
 * Controller for saved diagnostic profiles and one on-demand collector test.
 */

require __DIR__ . "/../../include/auth.php";
require_once __DIR__ . "/includes/database.php";
require_once __DIR__ . "/includes/diagnostics.php";

nms_require_database();
nms_require_management(3);

$section = isset_request_var("section") ? get_nfilter_request_var("section") : "run";
if (!in_array($section, ["run", "profiles"], true)) {
	$section = "run";
}

$error = '';
$result = null;
$selected_diagnostic_host_id = isset_request_var('host_id') ? (int) get_filter_request_var('host_id') : 0;
$selected_diagnostic_tool = isset_request_var('tool') ? get_nfilter_request_var('tool') : 'ping';
$notice = $_SESSION["nms_diagnostic_notice"] ?? "";
unset($_SESSION["nms_diagnostic_notice"]);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
	try {
		if (!csrf_check_tokens($_POST["__csrf_magic"] ?? "")) {
			throw new RuntimeException("Invalid request token.");
		}

		$action = $_POST["nms_action"] ?? "";
		if ($action === "save_diagnostic_profile") {
			nms_diag_profile_save($_POST);
			$_SESSION["nms_diagnostic_notice"] = "Diagnostic profile saved.";
			header("Location: diagnostics.php?section=profiles", true, 303);
			exit();
		}

		if ($action === "run_diagnostic") {
			$selected_diagnostic_host_id = (int) ($_POST['host_id'] ?? 0);
			$selected_diagnostic_tool = (string) ($_POST['tool'] ?? 'ping');
			$result = nms_diag_run($selected_diagnostic_host_id, $selected_diagnostic_tool);
		} else {
			throw new RuntimeException("Unsupported diagnostic action.");
		}
	} catch (Throwable $exception) {
		$error = $exception->getMessage();
	}
}

$profiles = db_fetch_assoc("SELECT * FROM plugin_nms_diagnostic_profiles ORDER BY name");
$devices = db_fetch_assoc(
	"SELECT h.id, h.description, h.hostname, p.id AS profile_id, p.name AS profile_name, p.tools
	FROM host AS h
	JOIN plugin_nms_diagnostic_devices AS d ON d.host_id = h.id
	JOIN plugin_nms_diagnostic_profiles AS p ON p.id = d.profile_id
	WHERE h.deleted = ''
	ORDER BY h.description",
);

$nms_diagnostic_readiness = [
	"ping" => [
		"ready" => (bool) nms_diag_program("ping"),
		"purpose" => "Tests collector-to-device reachability.",
		"requirement" => "Requires ICMP permission for the Apache collector.",
	],
	"traceroute" => [
		"ready" => (bool) (nms_diag_program("traceroute") ?: nms_diag_program("tracepath")),
		"purpose" => "Shows the route and responding hops.",
		"requirement" => "Runs from the collector; no remote service is needed.",
	],
	"arp" => [
		"ready" => (bool) nms_diag_program("ip"),
		"purpose" => "Reads the collector neighbour cache.",
		"requirement" => "Shows only entries already learned by the collector.",
	],
	"iperf3" => [
		"ready" => (bool) nms_diag_program("iperf3"),
		"purpose" => "Measures TCP throughput.",
		"requirement" => "Remote endpoint must run iperf3 server on TCP 5201.",
	],
	"netperf" => [
		"ready" => (bool) nms_diag_program("netperf"),
		"purpose" => "Measures TCP stream throughput.",
		"requirement" =>
			"Offline requirement: install the signed matching netperf RPM and dependencies, then run netserver on the remote endpoint.",
	],
	"pathchar" => [
		"ready" => (bool) nms_diag_program("pathchar"),
		"purpose" => "Estimates path capacity by hop.",
		"requirement" =>
			"No matching RPM is in this collector source. Keep disabled until an approved RHEL 9 aarch64 package is supplied.",
	],
];

$editing_profile = null;
$profile_id = isset_request_var("profile_id") ? (int) get_nfilter_request_var("profile_id") : 0;
if ($profile_id) {
	$editing_profile = db_fetch_row_prepared("SELECT * FROM plugin_nms_diagnostic_profiles WHERE id = ?", [
		$profile_id,
	]);
}
if (!$editing_profile && $profile_id) {
	$error = "Diagnostic profile no longer exists.";
}

$open_profile_modal = $section === "profiles" && (isset_request_var("profile_new") || $editing_profile);
$open_profile_modal =
	$open_profile_modal || ($error !== "" && ($_POST["nms_action"] ?? "") === "save_diagnostic_profile");

nms_prepare_page("diagnostics", "NMS · Protocol checks", "css/nms-topology-config.css", "");
require __DIR__ . "/templates/app_header.php";
require __DIR__ . "/templates/diagnostics.php";
require __DIR__ . "/templates/app_footer.php";
