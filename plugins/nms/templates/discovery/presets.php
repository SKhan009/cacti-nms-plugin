<?php
$presets = db_fetch_assoc("SELECT * FROM plugin_nms_discovery_presets ORDER BY name");
$edit_id = isset_request_var("discovery_preset") ? get_filter_request_var("discovery_preset") : 0;
$edit = [
	"id" => 0,
	"name" => "",
	"protocol" => "lldp",
	"enabled" => 1,
	"interval_seconds" => 300,
	"stale_seconds" => 900,
	"refresh_seconds" => 30,
];
foreach ($presets as $p) {
	if ((int) $p["id"] === $edit_id) {
		$edit = $p;
	}
}
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["nms_action"] ?? "") === "discovery_preset" && !empty($error)) {
	foreach (["name", "enabled", "interval_seconds", "stale_seconds", "refresh_seconds"] as $field) {
		if (isset($_POST[$field]) && is_scalar($_POST[$field])) {
			$edit[$field] = $_POST[$field];
		}
	}
	$edit["id"] = (int) ($_POST["preset_id"] ?? 0);
	try {
		$edit["protocol"] = implode(",", nms_nd_methods_validate($_POST["methods"] ?? []));
	} catch (Throwable $e) {
		$edit["protocol"] = "";
	}
}
?>

<?php
$section = isset_request_var("section") ? get_nfilter_request_var("section") : "presets";
if (!in_array($section, ["presets", "assignments", "rules"], true)) {
	$section = "presets";
}
if ($_SERVER["REQUEST_METHOD"] === "POST") {
	$section =
		["discovery_bulk" => "assignments", "discovery_rule" => "rules", "discovery_rule_toggle" => "rules"][
			$_POST["nms_action"] ?? ""
		] ?? "presets";
}
$labels = ["presets" => "Discovery presets", "assignments" => "Device assignments", "rules" => "Future-device rules"];
$open_modal =
	isset_request_var("discovery_preset") ||
	(!empty($error) && ($_POST["nms_action"] ?? "") !== "discovery_rule_toggle");
?>
<nav class="nms-preset-tabs" aria-label="Connection discovery and protocol preset views"><?php foreach (
	$labels
	as $key => $label
) { ?><a class="<?php print $section === $key ? "active" : ""; ?>" <?php if ($section === $key) {
	print 'aria-current="page"';
} ?> href="discovery_presets.php?section=<?php print $key; ?>"><?php print nms_h(
	$label,
); ?></a><?php } ?><a href="diagnostics.php#diagnostic-profiles" data-nms-tip="Create profiles for Ping, Traceroute, ARP, iPerf3, Netperf, and Pathchar. Assign a profile to a device in Add/Edit device before running a test.">Protocol checks</a></nav>
<section class="nms-panel">
<div class="nms-panel-head"><div><h2><?php print nms_h($labels[$section]); ?></h2><p><?php print [
	"presets" => "Shared collection methods and timing.",
	"assignments" => "Existing devices and their shared discovery presets.",
	"rules" => "Optional automatic assignment for newly created devices.",
][$section]; ?></p></div>
<?php if (
	$section === "presets"
) { ?><a class="nms-panel-action" href="discovery_presets.php?section=presets&amp;discovery_preset=0">+ Add preset</a><?php } else { ?><button type="button" class="nms-panel-action" data-nms-config-open><?php print $section ===
"assignments"
	? "Configure assignments"
	: "+ Add rule"; ?></button><?php } ?></div>
<?php if ($section === "presets") { ?>
<div class="nms-table-wrap"><table class="nms-table"><thead><tr><th>Preset</th><th>Protocol</th><th>Timing</th><th>Action</th></tr></thead><tbody><?php foreach (
	$presets
	as $p
) { ?><tr><td><?php print nms_h($p["name"]); ?></td><td><?php print nms_h(
	strtoupper($p["protocol"]),
); ?></td><td><?php print ($p["enabled"] ? "Scheduled" : "Disabled") .
	" · interval " .
	(int) $p["interval_seconds"] .
	" seconds · stale after " .
	(int) $p["stale_seconds"] .
	" seconds · refresh " .
	(int) $p["refresh_seconds"] .
	" seconds"; ?></td><td><a href="<?php print nms_h(
	$base_url . (strpos($base_url, "?") === false ? "?" : "&") . "discovery_preset=" . (int) $p["id"],
); ?>">Edit</a></td></tr><?php } ?></tbody></table></div>

<?php } elseif ($section === "assignments") { ?>
<div class="nms-table-wrap"><table class="nms-table"><thead><tr><th>Device</th><th>Preset</th><th>Collection</th><th>Action</th></tr></thead><tbody>
<?php
$assignments = db_fetch_assoc(
	"SELECT h.id,h.description,p.name,d.collection_enabled FROM host h JOIN plugin_nms_discovery_devices d ON d.host_id=h.id JOIN plugin_nms_discovery_presets p ON p.id=d.preset_id WHERE h.deleted='' AND " .
		nms_visible_host_sql() .
		" ORDER BY h.description",
);
foreach ($assignments as $a) { ?><tr><td><?php print nms_h($a["description"]); ?></td><td><?php print nms_h(
	$a["name"],
); ?></td><td><?php print $a["collection_enabled"]
	? "Enabled"
	: "Paused"; ?></td><td><a href="devices.php?tab=edit&amp;id=<?php print (int) $a[
	"id"
]; ?>#discovery-connections">Manage device</a></td></tr><?php }
if (!$assignments) { ?><tr><td colspan="4">No assigned devices.</td></tr><?php }
?></tbody></table></div>
<?php } else { ?>
<div class="nms-table-wrap"><table class="nms-table"><thead><tr><th>Rule</th><th>State</th><th>Applies after device ID</th><th>Action</th></tr></thead><tbody><?php foreach (
	db_fetch_assoc("SELECT * FROM plugin_nms_discovery_rules ORDER BY id")
	as $rule
) { ?><tr><td><?php print nms_h($rule["name"]); ?></td><td><?php print $rule["enabled"]
	? "Enabled"
	: "Paused"; ?></td><td><?php print (int) $rule[
	"after_host_id"
]; ?></td><td><form method="post"><?php nms_topology_form_fields(
	"discovery_rule_toggle",
); ?><input type="hidden" name="rule_id" value="<?php print (int) $rule[
	"id"
]; ?>"><button type="submit"><?php print $rule["enabled"]
	? "Pause"
	: "Enable"; ?></button></form></td></tr><?php } ?></tbody></table></div>

<?php } ?></section>
<dialog id="nmsConfigDialog" class="nms-config-dialog" aria-labelledby="nmsConfigTitle" data-auto-open="<?php print $open_modal
	? "true"
	: "false"; ?>">
<div class="nms-panel-head"><h2 id="nmsConfigTitle"><?php print $section === "presets"
	? ($edit["id"]
		? "Edit discovery preset"
		: "Add discovery preset")
	: ($section === "assignments"
		? "Configure device assignments"
		: "Add future-device rule"); ?></h2><button type="button" data-nms-config-close aria-label="Close configuration">×</button></div>
<?php if (!empty($error)) { ?><p class="nms-config-error" role="alert"><?php print nms_h($error); ?></p><?php } ?>
<?php if ($section === "presets") { ?>
<form method="post" action="<?php print nms_h($base_url); ?>" class="nms-config-form nms-discovery-preset-form">
<?php nms_topology_form_fields(
	"discovery_preset",
); ?><input type="hidden" name="preset_id" value="<?php print (int) $edit["id"]; ?>">
<div class="nms-discovery-preset-intro">
<label>Preset name<input name="name" required maxlength="100" value="<?php print nms_h($edit["name"]); ?>"></label>
<fieldset class="nms-discovery-methods"><legend>Collection methods</legend><?php foreach (
	nms_nd_method_labels()
	as $key => $label
) { ?><label><input type="checkbox" name="methods[]" value="<?php print $key; ?>" <?php if (
	in_array($key, nms_nd_protocols($edit["protocol"]), true)
) {
	print "checked";
} ?>> <span><?php print nms_h($label); ?></span></label><?php } ?></fieldset>
</div>
<div class="nms-discovery-preset-settings">
<label>Collection mode<select name="enabled"><option value="1" <?php if ($edit["enabled"]) {
	print "selected";
} ?>>Scheduled collection</option><option value="0" <?php if (!$edit["enabled"]) {
	print "selected";
} ?>>Disabled</option></select></label>
<?php foreach (
	[
		"interval_seconds" => ["Collection interval (seconds)", 300, 86400],
		"stale_seconds" => ["Stale after (seconds)", 600, 604800],
		"refresh_seconds" => ["Display refresh (seconds)", 10, 300],
	]
	as $key => $spec
) { ?><label><?php print $spec[0]; ?><input type="number" name="<?php print $key; ?>" required min="<?php print $spec[1]; ?>" max="<?php print $spec[2]; ?>" value="<?php print (int) $edit[
	$key
]; ?>"></label><?php } ?>
</div>
<div class="nms-discovery-preset-help"><p><strong><?php print (int) db_fetch_cell_prepared(
	"SELECT COUNT(*) FROM plugin_nms_discovery_devices WHERE preset_id=?",
	[$edit["id"]],
); ?></strong> devices use this preset.</p><p>Stale threshold must be at least twice the interval. Collection runs on the assigned Cacti collector after its poll cycle. Saving a shared preset affects all its assigned devices.</p></div>
<div class="nms-discovery-preset-actions"><button type="submit">Save discovery preset</button><button type="button" class="nms-cancel-button" data-nms-config-close>Cancel</button></div>
</form>
<?php } elseif ($section === "assignments") { ?>
<form method="post" class="nms-config-form"><?php nms_topology_form_fields("discovery_bulk"); ?>
<label>Preset<select name="discovery_preset_id"><option value="0">Unassign</option><?php foreach (
	$presets
	as $p
) { ?><option value="<?php print (int) $p["id"]; ?>" <?php if (
	(string) $p["id"] ===
	($_POST["discovery_preset_id"] ?? null)
) {
	print "selected";
} ?>><?php print nms_h($p["name"]); ?></option><?php } ?></select></label>
<label>Collection<select name="discovery_enabled"><option value="1">Enabled</option><option value="0" <?php if (
	($_POST["discovery_enabled"] ?? "1") ===
	"0"
) {
	print "selected";
} ?>>Paused</option></select></label>
<input type="hidden" name="discovery_method_mode" value="preset">
<label>Devices (multiple selection)<select name="host_ids[]" multiple size="10" required><?php foreach (
	db_fetch_assoc(
		"SELECT h.id,h.description FROM host h WHERE h.deleted='' AND " .
			nms_visible_host_sql() .
			" ORDER BY h.description",
	)
	as $h
) { ?><option value="<?php print (int) $h["id"]; ?>" <?php if (
	is_array($_POST["host_ids"] ?? null) &&
	in_array((string) $h["id"], $_POST["host_ids"], true)
) {
	print "selected";
} ?>><?php print nms_h($h["description"]); ?></option><?php } ?></select></label>
<p>Assigns shared discovery methods only. SNMP credentials and graph trees are unchanged. Disabled or inaccessible devices are excluded.</p><button type="submit">Apply to selected devices</button>
<button type="button" class="nms-cancel-button" data-nms-config-close>Cancel</button></form>
<?php } else { ?><p class="nms-config-note">All three core fields must match. Existing assignments are preserved; the first matching enabled rule wins. Applies only to devices created after the rule.</p>
<form method="post" class="nms-config-form"><?php nms_topology_form_fields("discovery_rule"); ?>
<label>Rule name<input name="rule_name" required maxlength="100" value="<?php print nms_h(
	is_string($_POST["rule_name"] ?? null) ? $_POST["rule_name"] : "",
); ?>"></label>
<?php foreach (
	[
		"preset_id" => ["Preset", "plugin_nms_discovery_presets"],
		"site_id" => ["Cacti site", "sites"],
		"host_template_id" => ["Device template", "host_template"],
		"poller_id" => ["Collector", "poller"],
	]
	as $key => $spec
) { ?><label><?php print $spec[0]; ?><select name="<?php print $key; ?>" required><option value="">Select</option><?php foreach (
	db_fetch_assoc("SELECT id,name FROM " . $spec[1] . " ORDER BY name")
	as $row
) { ?><option value="<?php print (int) $row["id"]; ?>" <?php if ((string) $row["id"] === ($_POST[$key] ?? null)) {
	print "selected";
} ?>><?php print nms_h($row["name"]); ?></option><?php } ?></select></label><?php } ?>
<button type="submit">Create future-device rule</button><button type="button" class="nms-cancel-button" data-nms-config-close>Cancel</button></form>
<?php } ?></dialog>
