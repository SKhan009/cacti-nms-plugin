<?php
$nd_presets = db_fetch_assoc("SELECT * FROM plugin_nms_discovery_presets ORDER BY name");
$nd_assignment = $device_form_is_edit
	? db_fetch_row_prepared("SELECT * FROM plugin_nms_discovery_devices WHERE host_id=?", [$device_values["id"]])
	: [];
$nd_assignment = $nd_assignment ?: ["preset_id" => 0, "methods" => "", "collection_enabled" => 1];
$nd_post = $_SERVER["REQUEST_METHOD"] === "POST";
$nd_selected = $nd_post ? (int) ($_POST["discovery_preset_id"] ?? 0) : (int) $nd_assignment["preset_id"];
$nd_mode = $nd_post
	? $_POST["discovery_method_mode"] ?? "preset"
	: ($nd_assignment["methods"] === ""
		? "preset"
		: "selected");
$nd_methods = $nd_post ? $_POST["discovery_methods"] ?? [] : nms_nd_protocols($nd_assignment["methods"]);
if (!is_array($nd_methods)) {
	$nd_methods = [];
}
if ($nd_mode === "preset") {
	$nd_methods = [];
	foreach ($nd_presets as $nd_preset) {
		if ((int) $nd_preset["id"] === $nd_selected) {
			$nd_methods = nms_nd_protocols($nd_preset["protocol"]);
		}
	}
}
?>
<fieldset id="discovery-connections"><legend>Discovery and connections</legend>
<p>Collect selected tables using this device’s Cacti SNMP settings. This does not enable protocols on the device.</p>
<div class="nms-form-grid">
<label>Shared preset<select name="discovery_preset_id"><option value="0">Not assigned</option><?php foreach (
	$nd_presets
	as $p
) { ?><option data-methods="<?php print nms_h(
	implode(",", nms_nd_protocols($p["protocol"])),
); ?>" value="<?php print (int) $p["id"]; ?>" <?php if ($nd_selected === (int) $p["id"]) {
	print "selected";
} ?>><?php print nms_h(
	$p["name"] .
		" · " .
		strtoupper($p["protocol"]) .
		" · " .
		$p["interval_seconds"] .
		"s / stale " .
		$p["stale_seconds"] .
		"s" .
		(!$p["enabled"] ? " · paused" : ""),
); ?></option><?php } ?></select><small><a href="discovery_presets.php">Manage shared presets</a></small></label>
<label>Collection<select name="discovery_enabled"><option value="1">Enabled</option><option value="0" <?php if (
	!($nd_post ? $_POST["discovery_enabled"] ?? 0 : $nd_assignment["collection_enabled"])
) {
	print "selected";
} ?>>Paused</option></select></label>
<label>Methods<select name="discovery_method_mode"><option value="preset">Follow all shared-preset methods</option><option value="selected" <?php if (
	$nd_mode === "selected"
) {
	print "selected";
} ?>>Use selected subset below</option></select></label>
<div class="nms-discovery-methods"><p>Preset methods (choose a subset to edit)</p><?php foreach (
	nms_nd_method_labels()
	as $key => $label
) { ?><label><input type="checkbox" name="discovery_methods[]" value="<?php print $key; ?>" <?php if (
	in_array($key, $nd_methods, true)
) {
	print "checked";
} ?>> <?php print nms_h($label); ?></label><?php } ?></div>
</div>
<p>Requires SNMPv2c/v3 and readable tables on the assigned collector. Save to apply collection settings. Interval and stale threshold come from the shared preset; disabled devices are not collected.</p>
</fieldset>
<?php
$diag_profiles = db_fetch_assoc("SELECT id,name,tools FROM plugin_nms_diagnostic_profiles ORDER BY name");
$diag_assignment = $device_form_is_edit
	? (int) db_fetch_cell_prepared("SELECT profile_id FROM plugin_nms_diagnostic_devices WHERE host_id=?", [
		$device_values["id"],
	])
	: 0;
$diag_selected = $nd_post ? (int) ($_POST["diagnostic_profile_id"] ?? 0) : $diag_assignment;
?>
<fieldset id="diagnostic-profile"><legend>On-demand diagnostics</legend><p>These are manual tests from the collector. They are separate from scheduled SNMP discovery so bandwidth tests never create traffic during polling.</p><div class="nms-form-grid"><label>Diagnostic profile<select name="diagnostic_profile_id"><option value="0">Not assigned</option><?php foreach (
	$diag_profiles
	as $profile
) { ?><option value="<?php print (int) $profile["id"]; ?>" <?php if ($diag_selected === (int) $profile["id"]) {
	print "selected";
} ?>><?php print nms_h(
	$profile["name"] .
		" · " .
		implode(", ", array_map(fn($tool) => nms_diag_labels()[$tool], nms_diag_tools($profile["tools"]))),
); ?></option><?php } ?></select><small><a href="diagnostics.php">Manage diagnostic profiles and run tests</a></small></label></div><p>Ping and traceroute check reachability. ARP reads the collector cache. iPerf3 and Netperf require their server service at the selected device.</p></fieldset>
