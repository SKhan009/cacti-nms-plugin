<?php
/**
 * @file diagnostics.php
 * Controller for saved diagnostic profiles and one on-demand collector test.
 */

require __DIR__ . "/../../include/auth.php";
require_once __DIR__ . "/includes/database.php";
require_once __DIR__ . "/includes/diagnostics_queue.php";

nms_require_database();
nms_require_management(3);

// Diagnostic pages always reflect current saved results and collector state.
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$section = isset_request_var("section") ? get_nfilter_request_var("section") : "run";
if (!in_array($section, ["run", "profiles"], true)) {
	$section = "run";
}

// Read-only progress endpoint uses the same requester and device permission checks.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['job_status'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    try {
        $job = nms_diag_job((int) ($_GET['job_id'] ?? 0));
        echo json_encode(['status' => $job['status'], 'finished' => !in_array($job['status'], ['queued','running'], true)]);
    } catch (Throwable $error) {
        http_response_code(403);
        echo json_encode(['error' => 'Result unavailable. Reload the page to check your access.']);
    }
    exit;
}

// Consume validation feedback on GET; no POST response renders the page.
$feedback = $_SESSION['nms_diagnostic_feedback'] ?? [];
unset($_SESSION['nms_diagnostic_feedback']);
$error = (string) ($feedback['error'] ?? '');
$failed_profile_input = $feedback['profile'] ?? null;
$result = null;
$diagnostic_job = null;
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
			$job_id = nms_diag_run($selected_diagnostic_host_id, $selected_diagnostic_tool);
			header('Location: diagnostics.php?section=run&job_id=' . $job_id . '#diagnostic-run', true, 303);
			exit();
		} else {
			throw new RuntimeException("Unsupported diagnostic action.");
		}
	} catch (Throwable $exception) {
        $feedback = ['error' => $exception->getMessage()];
        $target = ['section' => 'run'];
        if (($_POST['nms_action'] ?? '') === 'save_diagnostic_profile') {
            $target = ['section' => 'profiles'];
            // Retain only bounded form values, never the CSRF token or arbitrary POST fields.
            $feedback['profile'] = [];
            foreach (['diagnostic_profile_id', 'diagnostic_profile_name', 'ping_count', 'trace_hops', 'bandwidth_seconds'] as $field) {
                if (isset($_POST[$field]) && is_scalar($_POST[$field])) {
                    $feedback['profile'][$field] = substr((string) $_POST[$field], 0, 100);
                }
            }
            $tools = is_array($_POST['diagnostic_tools'] ?? null) ? $_POST['diagnostic_tools'] : [];
            $feedback['profile']['diagnostic_tools'] = array_values(array_intersect(array_keys(nms_diag_labels()), array_filter($tools, 'is_string')));
        } else {
            $target['host_id'] = max(0, $selected_diagnostic_host_id);
            $target['tool'] = is_string($selected_diagnostic_tool) && isset(nms_diag_labels()[$selected_diagnostic_tool]) ? $selected_diagnostic_tool : 'ping';
        }
        $_SESSION['nms_diagnostic_feedback'] = $feedback;
        header('Location: diagnostics.php?' . http_build_query($target, '', '&', PHP_QUERY_RFC3986), true, 303);
        exit();
	}
}

$profiles = db_fetch_assoc("SELECT * FROM plugin_nms_diagnostic_profiles ORDER BY name");
$devices = db_fetch_assoc(
	"SELECT h.id, h.description, h.hostname, h.poller_id, p.id AS profile_id, p.name AS profile_name, p.tools
	FROM host AS h
	JOIN plugin_nms_diagnostic_devices AS d ON d.host_id = h.id
	JOIN plugin_nms_diagnostic_profiles AS p ON p.id = d.profile_id
	WHERE h.deleted = '' AND h.disabled = '' AND " . nms_visible_host_sql("h.id") . "
	ORDER BY h.description",
);

// Availability is checked by the execution collector, never guessed from web-host binaries.
$nms_diagnostic_readiness = [
	'ping' => ['purpose' => 'Tests collector-to-device reachability.', 'requirement' => 'Requires the ping executable and ICMP permission in the existing poller runtime.'],
	'traceroute' => ['purpose' => 'Shows the route and responding hops.', 'requirement' => 'Uses traceroute or tracepath on the assigned collector. Partial output is retained on timeout.'],
	'arp' => ['purpose' => 'Reads the collector IPv4 ARP and IPv6 neighbour cache.', 'requirement' => 'Requires ip. This is the collector cache, not the selected device ARP table.'],
	'iperf3' => ['purpose' => 'Measures TCP throughput.', 'requirement' => 'Remote devices need an iperf3 server on TCP 5201. IPs assigned to the collector test the collector itself using a temporary local server.'],
	'netperf' => ['purpose' => 'Measures TCP stream throughput.', 'requirement' => 'Remote devices need netserver on TCP 12865 and its data connection. IPs assigned to the collector use a temporary local server.'],
	'pathchar' => ['purpose' => 'Estimates path capacity by hop.', 'requirement' => 'Requires pathchar or pchar installed on this collector, with raw-socket permission. Offline installs need an approved build for its OS and architecture.'],
];
$job_id = isset_request_var('job_id') ? (int) get_filter_request_var('job_id') : 0;
if ($job_id) {
	try {
		$diagnostic_job = nms_diag_job($job_id);
		$selected_diagnostic_host_id = (int) $diagnostic_job['host_id'];
		$selected_diagnostic_tool = $diagnostic_job['tool'];
		if ($diagnostic_job['result_json'] !== '') {
			$result = json_decode($diagnostic_job['result_json'], true, 512, JSON_THROW_ON_ERROR);
		}
	} catch (Throwable $exception) {
		$error = $exception->getMessage();
	}
}
require_once __DIR__ . '/includes/diagnostic_history.php';
$history = nms_diag_history($_GET);

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
	$open_profile_modal || ($error !== "" && is_array($failed_profile_input));

nms_prepare_page("diagnostics", "NMS · Protocol checks", "css/nms-topology-config.css", "");
require __DIR__ . "/templates/app_header.php";
require __DIR__ . "/templates/diagnostics.php";
require __DIR__ . "/templates/app_footer.php";
