<?php
/**
 * @file import.php
 * Render SNMP-record upload, prior import details, and explicit simulator-health/probe controls.
 * Record samples describe a simulator; live device readings still require successful SNMP polling.
 */
require_once __DIR__ . "/../../includes/import_object_report.php";
$import_kind =
	($_GET["kind"] ?? "") === "mib" || in_array($action ?? "", ["mib_preview", "mib_create"], true) ? "mib" : "snmprec";
?>
<nav class="nms-page-tabs" aria-label="Import type"><a href="?kind=snmprec" class="<?php print $import_kind ===
"snmprec"
	? "selected"
	: ""; ?>">SNMP recordings</a><a href="?kind=mib" class="<?php print $import_kind === "mib"
	? "selected"
	: ""; ?>">MIB files</a></nav>
<?php
if ($import_kind === "mib") {
	require_once __DIR__ . "/../../includes/import_summary.php";
	if (!empty($_SESSION["nms_mib_success"])) {
		nms_mib_import_summary($_SESSION["nms_mib_success"]);
		unset($_SESSION["nms_mib_success"]);
	}
	require __DIR__ . "/mib.php";
	return;
}
require __DIR__ . "/record_report.php";
$simulator_health = nms_snmpsim_health();
$upload_open =
	(isset($_GET["upload"]) && $_GET["upload"] === "1") ||
	(!empty($page_error) && ($action ?? "") === "import_snmprec");
$simulator_config = $simulator_health["error"] === "" ? $simulator_health["config"] : [];
$simulator_ready =
	$simulator_health["error"] === "" &&
	!empty($simulator_health["data_writable"]) &&
	($simulator_health["service_state"] === "manual" || !empty($simulator_health["executable"]));
$simulator_badge = nms_snmpsim_status_badge($simulator_health);
?>
<section id="nmsSimulatorStatus" class="nms-panel nms-snmpsim-status <?php print $simulator_badge["tone"]; ?>">
	<div class="nms-snmpsim-status-copy">
		<span aria-hidden="true"></span>
		<strong id="nmsSimulatorStatusLabel" role="status"><?php print nms_h($simulator_badge["label"]); ?></strong>
	</div>
	<div class="nms-snmpsim-toolbar">
	<details class="nms-snmpsim-details">
		<summary>Details</summary>
		<div class="nms-snmpsim-details-popup">
			<strong>SNMPSim details</strong>
			<?php if ($simulator_health["error"] !== "") { ?>
				<p><?php print nms_h($simulator_health["error"]); ?></p>
			<?php } else { ?>
				<?php if ($simulator_health["service_state"] === "manual") { ?>
				<p><span>Activation</span>Administrator-managed</p>
				<?php } else { ?>
				<p><span>Executable</span><?php print nms_h($simulator_config["executable"]); ?> — <?php print $simulator_health[
 	"executable"
 ]
 	? "accessible"
 	: "missing or inaccessible"; ?></p>
				<p><span>Service</span><?php print nms_h(
    	$simulator_config["service"] . " — " . $simulator_health["service_state"],
    ); ?></p>
				<p><span>Activation queue</span><?php print $simulator_health["reload_pending"]
    	? "pending"
    	: "no pending marker"; ?></p>
				<?php } ?>
				<p><span>Data directory</span><?php print nms_h($simulator_config["data_dir"]); ?> — <?php print $simulator_health[
 	"data_writable"
 ]
 	? "writable"
 	: "missing or not writable"; ?></p>
				<p><span>Client endpoint</span><?php print nms_h(
    	$simulator_config["client_address"] . ":" . $simulator_config["port"],
    ); ?></p>
				<?php if (
    	$simulator_health["service_state"] === "unavailable"
    ) { ?><small>The web process cannot read service status. Verify individual records with Check live SNMP.</small><?php } ?>
			<?php } ?>
		</div>
	</details>
	<?php $can_control =
 	function_exists("is_realm_allowed") &&
 	is_realm_allowed(3) &&
 	($simulator_config["ui_control"] ?? false) === true &&
 	($simulator_config["activation"] ?? "") === "systemd"; ?>
	<form method="post" action="file_repository.php" class="nms-simulator-controls" onsubmit="return confirm('Apply this SNMPSim service command? Stop pauses automatic upload activation until Start or Restart.');">
		<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
		<input type="hidden" name="nms_action" value="control_snmpsim">
		<?php foreach (
  	["start" => "Start", "stop" => "Stop", "restart" => "Restart"]
  	as $command => $label
  ) { ?><button type="submit" name="simulator_command" value="<?php print $command; ?>" <?php if (!$can_control) {
	print "disabled";
} ?>><?php print $label; ?></button><?php } ?>
	</form>
	</div>
	<?php if (
 	$simulator_message !== "" &&
 	($action ?? "") !== "control_snmpsim"
 ) { ?><p class="nms-snmpsim-message" role="status"><?php print nms_h($simulator_message); ?></p><?php } ?>
</section>

<?php if (count($imports)) { ?>
<section class="nms-panel nms-fcaps-lab" id="fcaps-lab-scenarios">
	<div class="nms-panel-head"><div><h2>FCAPS lab scenarios</h2><p>Changes only the selected imported SNMPSim record. Real devices, Cacti credentials, and production monitoring are never changed.</p></div></div>
	<div class="nms-fcaps-lab-grid">
	<?php foreach ($imports as $import) {
		$targets = $import["fcaps_targets"];
		$available = (bool) ($targets["interfaces"] || $targets["traffic"] || $targets["battery"] || $targets["sys_name"]);
	?>
	<article class="nms-fcaps-record">
		<header><div><strong><?php print nms_h($import["community"]); ?></strong><small><?php print nms_h($import["original_name"]); ?></small></div><span>Simulator only</span></header>
		<?php if (!$available) { ?><p class="nms-empty">This record has no supported FCAPS OIDs. Import interface, UPS, or system OIDs to create lab scenarios.</p><?php } ?>
		<?php if ($targets["interfaces"]) { ?><form method="post" action="file_repository.php" class="nms-fcaps-form">
			<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="apply_snmpsim_fcaps"><input type="hidden" name="import_id" value="<?php print (int) $import["id"]; ?>">
			<label><span>Fault — interface link</span><select name="interface_index"><?php foreach ($targets["interfaces"] as $index => $interface) { ?><option value="<?php print (int) $index; ?>">IF-MIB interface <?php print (int) $index; ?> · currently <?php print $interface["value"] === "1" ? "up" : "down"; ?></option><?php } ?></select></label>
			<div><button type="submit" name="fcaps_scenario" value="interface_up">Set link up</button><button type="submit" name="fcaps_scenario" value="interface_down" class="nms-danger-button">Set link down</button></div>
			<small>Changes <code>ifOperStatus</code>. The device can remain reachable while one Ethernet port is down.</small>
		</form><?php } ?>
		<?php if ($targets["traffic"]) { ?><form method="post" action="file_repository.php" class="nms-fcaps-form nms-fcaps-inline">
			<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="apply_snmpsim_fcaps"><input type="hidden" name="import_id" value="<?php print (int) $import["id"]; ?>"><div><strong>Performance and accounting — traffic</strong><small>Increases the available interface byte counters by 1,250,000. Cacti calculates a rate after two polls.</small></div><button type="submit" name="fcaps_scenario" value="traffic_pulse">Add traffic pulse</button>
		</form><?php } ?>
		<?php if ($targets["battery"]) { ?><form method="post" action="file_repository.php" class="nms-fcaps-form nms-fcaps-inline">
			<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="apply_snmpsim_fcaps"><input type="hidden" name="import_id" value="<?php print (int) $import["id"]; ?>"><div><strong>Fault — UPS battery</strong><small>Uses standard UPS-MIB values. Low means 15% charge and 8 minutes remaining.</small></div><div><button type="submit" name="fcaps_scenario" value="battery_normal">Set normal</button><button type="submit" name="fcaps_scenario" value="battery_low" class="nms-warning-button">Set low</button></div>
		</form><?php } ?>
		<?php if ($targets["sys_name"]) { ?><form method="post" action="file_repository.php" class="nms-fcaps-form nms-fcaps-inline">
			<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="apply_snmpsim_fcaps"><input type="hidden" name="import_id" value="<?php print (int) $import["id"]; ?>"><label><span>Configuration — system name</span><input required name="system_name" maxlength="255" placeholder="Lab device name"></label><button type="submit" name="fcaps_scenario" value="system_name">Update name</button>
		</form><?php } ?>
		<footer><strong>Security testing</strong><span>Use a separate Cacti test device with intentionally incorrect SNMP credentials. A record file cannot safely simulate SNMPv3 authentication or access control.</span></footer>
	</article>
	<?php } ?>
	</div>
</section>
<?php } ?>
<div class="nms-upload-dialog<?php print $upload_open ? " nms-upload-inline" : ""; ?>" id="nmsUploadDialog" <?php if (
	!$upload_open
) {
	print "hidden";
} ?> data-auto-open="<?php print $upload_open
 	? "true"
 	: "false"; ?>" role="dialog" aria-modal="true" aria-labelledby="nmsUploadDialogTitle" tabindex="-1">
	<div class="nms-panel-head nms-upload-dialog-head"><div><h2 id="nmsUploadDialogTitle">Upload an SNMP record</h2><p>Deploy a simulator community and generate native Cacti templates for numeric OIDs.</p></div><button class="nms-upload-dialog-close" type="button" aria-label="Close upload dialog">×</button></div>
	<?php if (
 	!empty($page_error) &&
 	($action ?? "") === "import_snmprec"
 ) { ?><div class="nms-form-message error" role="alert"><?php print nms_h($page_error); ?></div><?php } ?>
	<div class="nms-upload-dialog-body">
	<div class="nms-import-explainer nms-import-workflow"><div><strong>1. Validate</strong><span>NMS checks every OID, type, value, filename, size, and community.</span></div><div><strong>2. Build data sources</strong><span>Numeric readings receive native Cacti data-source and starter graph templates automatically.</span></div><div><strong>3. Configure templates</strong><span>Review graph and device-template settings before creating a device.</span></div><div><strong>4. Verify and add</strong><span>Check live SNMP, then add the device as the final step.</span></div></div>
	<form method="post" action="file_repository.php" enctype="multipart/form-data" class="nms-device-form nms-import-form">
		<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
		<input type="hidden" name="nms_action" value="import_snmprec">
		<div class="nms-form-grid">
			<label class="nms-file-field"><span>SNMP record file</span><input required type="file" name="snmprec_file" accept=".snmprec,text/plain"><small>Upload a valid .snmprec file. Maximum 2 MB and 5,000 lines.</small></label>
			<label><span>Simulator community</span><input required name="community" placeholder="serial-device-server"><small>The community becomes the SNMPSim record name.</small></label>
			<label><span>New Cacti host template</span><input required name="template_name" placeholder="Serial Device Server"><small>If this exact template exists, NMS safely adds the new graphs to it.</small></label>
			<label><span>Device segment</span><select required name="category_id"><option value="">Select a device segment</option><?php foreach (
   	$categories
   	as $category
   ) { ?><option value="<?php print (int) $category["id"]; ?>"><?php print nms_h(
	$category["name"],
); ?></option><?php } ?></select></label>
		</div>
		<div class="nms-form-actions"><button type="submit">Upload and create templates</button></div>
	</form>
	</div>
</div>

<section class="nms-panel">
	<div class="nms-panel-head"><div><h2>Imported SNMP records</h2><p>Generated objects remain editable in the Cacti backend.</p></div><a class="nms-upload-trigger" id="nmsUploadTrigger" href="?upload=1#nmsUploadDialog" aria-controls="nmsUploadDialog" aria-expanded="false">Upload SNMP record</a></div>
	<div class="nms-table-wrap nms-import-table-wrap"><table class="nms-table nms-import-table"><thead><tr><th>Community and file</th><th>Cacti host template</th><th>Category</th><th>Records</th><th>Generated templates</th><th>Imported</th><th>Next step</th></tr></thead><tbody>
	<?php if (
 	!count($imports)
 ) { ?><tr><td colspan="7" class="nms-empty">No SNMP record files have been imported.</td></tr><?php } ?>
	<?php foreach ($imports as $import) { ?><tr>
		<td data-label="Community and file"><strong><?php print nms_h(
  	$import["community"],
  ); ?></strong><small><?php print nms_h($import["original_name"]); ?></small></td>
		<td data-label="Cacti host template"><strong><?php print nms_h(
  	$import["host_template_name"],
  ); ?></strong><small>ID <?php print (int) $import["host_template_id"]; ?></small></td>
		<td data-label="Category"><?php print nms_h($import["category_name"]); ?></td>
		<td data-label="Records"><strong><?php print (int) $import[
  	"record_count"
  ]; ?> OIDs</strong><small><?php print (int) $import["graphable_count"]; ?> numeric readings</small></td>
		<td data-label="Generated templates"><strong><?php print (int) $import[
  	"graphable_count"
  ]; ?> data templates</strong><small><?php print (int) $import["graphable_count"]; ?> graph templates</small></td>
		<td data-label="Imported"><?php print nms_h(nms_time_ago($import["created_at"])); ?><small>by <?php print nms_h(
	$import["uploaded_by_name"] ?: "system",
); ?></small></td>
		<td data-label="Next step"><div class="nms-import-actions"><a class="nms-row-link" href="?imported=<?php print (int) $import[
  	"id"
  ]; ?>">View objects</a><form method="post" action="file_repository.php">
			<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
			<input type="hidden" name="nms_action" value="check_snmpsim">
			<input type="hidden" name="import_id" value="<?php print (int) $import["id"]; ?>">
			<button class="nms-row-link nms-import-primary" type="submit">Check live SNMP</button>
		</form>
		<a class="nms-row-link" href="devices.php?tab=add&amp;snmpsim_import_id=<?php print (int) $import[
  	"id"
  ]; ?>">Add device</a></div></td>
	</tr><?php } ?>
	</tbody></table></div>
</section>
