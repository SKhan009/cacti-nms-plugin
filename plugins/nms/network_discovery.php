<?php
/** Supplemental methods extend native Automation, without another inventory. */
require __DIR__ . "/../../include/auth.php";
require_once __DIR__ . "/includes/database.php";
require_once __DIR__ . "/includes/discovery_network.php";
nms_require_database();
nms_require_management(23);
header("Cache-Control: no-store, private");
$error = "";
$notice = $_SESSION["nms_network_notice"] ?? "";
unset($_SESSION["nms_network_notice"]);
if ($_SERVER["REQUEST_METHOD"] === "POST") {
	try {
		if (!csrf_check_tokens($_POST["__csrf_magic"] ?? "")) {
			throw new RuntimeException("Invalid request token.");
		}
		nms_nd_network_queue($_POST);
		$_SESSION["nms_network_notice"] = "Probes queued for the native collector. This page does not start scanning.";
		// Refresh must read current results, never replay the queue submission.
		header("Location: " . nms_plugin_url("network_discovery.php"), true, 303);
		exit();
	} catch (Throwable $e) {
		$error = $e->getMessage();
	}
}
$networks = db_fetch_assoc("SELECT id,name,subnet_range,snmp_id,enabled FROM automation_networks ORDER BY name");
$items = db_fetch_assoc("SELECT id,snmp_id,sequence,snmp_version FROM automation_snmp_items ORDER BY snmp_id,sequence");
nms_prepare_page("devices", "NMS · Network discovery", "css/nms-topology-config.css", "");
require __DIR__ . "/templates/app_header.php";
?>
<main class="nms-shell nms-topology-config"><h1>Network discovery</h1>
<p>Manage ranges, schedules, identification and inventory in Cacti Automation. Use supplemental probes for independently selected methods and multiple ports.</p>
<nav class="nms-form-actions"><?php foreach (
	[
		"automation_networks.php" => "Networks and schedules",
		"automation_snmp.php" => "SNMP options",
		"automation_devices.php" => "Review discovered devices",
		"automation_templates.php" => "Device Rules",
	]
	as $page => $label
) { ?><a href="<?php print nms_h($config["url_path"] . $page); ?>"><?php print nms_h($label); ?></a><?php } ?></nav>
<?php
if ($error) { ?><p class="nms-action-feedback error" role="alert"><?php print nms_h($error); ?></p><?php }
if ($notice) { ?><p class="nms-action-feedback" role="status"><?php print nms_h($notice); ?></p><?php }
?>
<section class="nms-panel"><div class="nms-panel-head"><h2>Supplemental method selection</h2></div>
<form method="post" class="nms-config-form"><input type="hidden" name="__csrf_magic" value="<?php print nms_h(
	$nms_csrf_token,
); ?>">
<label>Native network<select name="network_id" required><option value="">Select network</option><?php foreach (
	$networks
	as $n
) { ?><option value="<?php print (int) $n["id"]; ?>"><?php print nms_h(
	$n["name"] . " · " . $n["subnet_range"] . ($n["enabled"] !== "on" ? " · disabled" : ""),
); ?></option><?php } ?></select></label>
<div class="nms-discovery-methods"><p>Methods (run independently)</p><?php foreach (
	["icmp" => "ICMP reachability", "tcp" => "TCP ports", "udp" => "UDP reachability", "snmp" => "SNMP sysDescr"]
	as $key => $label
) { ?><label><input type="checkbox" name="methods[]" value="<?php print $key; ?>"> <?php print nms_h(
	$label,
); ?></label><?php } ?></div>
<label>TCP/UDP ports<input name="ports" placeholder="22,80,443" maxlength="100"><small>Up to 8 ports. UDP reachability is not proof of an open UDP service.</small></label>
<label>Explicit native SNMP option<select name="snmp_item_id"><option value="0">Select if using SNMP</option><?php foreach (
	$items
	as $i
) { ?><option value="<?php print (int) $i["id"]; ?>"><?php print nms_h(
	"Options set " . $i["snmp_id"] . " / sequence " . $i["sequence"] . " / SNMPv" . $i["snmp_version"],
); ?></option><?php } ?></select><small>Must belong to the selected network’s SNMP options. No alternate credential is attempted.</small></label>
<label>Scheduling<select name="follow_schedule"><option value="0">Run once</option><option value="1">Also run after each native discovery starts</option></select></label>
<p>Maximum 256 IPv4 addresses from native ranges, 8 ports, 500 ms reachability timeout, one retry. Runs in bounded poller batches; unfinished results are marked incomplete. SNMP uses the selected native option’s timeout and retry limits. New devices are added through Cacti’s review/rules workflow.</p>
<button type="submit">Queue selected probes</button></form></section>
<?php foreach (
	db_fetch_assoc(
		"SELECT j.*,n.name,n.subnet_range,n.poller_id,n.enabled,n.snmp_id FROM plugin_nms_discovery_network_jobs j JOIN automation_networks n ON n.id=j.network_id ORDER BY requested_at DESC",
	)
	as $job
) {

	$status = $job["status"];
	try {
		if ($job["config_hash"] !== "" && !hash_equals($job["config_hash"], nms_nd_network_signature($job, $job))) {
			$status = "Configuration changed";
		}
	} catch (Throwable $e) {
		$status = "Configuration unavailable";
	}
	?><section class="nms-panel"><div class="nms-panel-head"><h2><?php print nms_h(
	$job["name"],
); ?></h2><span><?php print nms_h($status); ?></span></div>
<p><?php print nms_h($job["error"]); ?> <?php print $status === "complete"
 	? "All requested probes finished. Results describe the last run."
 	: "Results are incomplete or no longer current."; ?> Refresh this page to read updates. Requested: <?php print nms_h(
 	$job["requested_at"],
 ); ?></p>
<div class="nms-table-wrap"><table class="nms-table"><thead><tr><th>Address</th><th>Method</th><th>Port</th><th>Reachable</th><th>Details</th></tr></thead><tbody><?php foreach (
	json_decode($job["results_json"], true) ?: []
	as $r
) { ?><tr><td><?php print nms_h($r["ip"]); ?></td><td><?php print nms_h(
	strtoupper($r["method"]),
); ?></td><td><?php print nms_h($r["port"] ?? "—"); ?></td><td><?php print $r["reachable"]
	? "Yes"
	: "No / failed"; ?></td><td><?php print nms_h(
	$r["detail"],
); ?></td></tr><?php } ?></tbody></table></div></section><?php
} ?>
</main><?php require __DIR__ . "/templates/app_footer.php"; ?>
