<?php
/** Render discovery presets and device assignments. */
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

foreach ($presets as $preset) {
	if ((int) $preset["id"] === $edit_id) {
		$edit = $preset;
	}
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["nms_action"] ?? "") === "discovery_preset" && $error) {
	foreach (["name", "enabled", "interval_seconds", "stale_seconds", "refresh_seconds"] as $field) {
		if (isset($_POST[$field]) && is_scalar($_POST[$field])) {
			$edit[$field] = $_POST[$field];
		}
	}
	$edit["id"] = (int) ($_POST["preset_id"] ?? 0);

	try {
		$edit["protocol"] = implode(",", nms_nd_methods_validate($_POST["methods"] ?? []));
	} catch (Throwable $exception) {
		$edit["protocol"] = "";
	}
}

$section = isset_request_var("section") ? get_nfilter_request_var("section") : "presets";
if (!in_array($section, ["presets", "assignments"], true)) {
	$section = "presets";
}
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["nms_action"] ?? "") === "discovery_bulk") {
	$section = "assignments";
}

$labels = [
	"presets" => "Discovery presets",
	"assignments" => "Device assignments",
];
$descriptions = [
	"presets" => "Shared collection methods and timing.",
	"assignments" => "Existing devices and their shared discovery presets.",
];
$open_modal = isset_request_var("discovery_preset") || ($error && ($_POST["nms_action"] ?? "") === "discovery_bulk");
?>
<nav class="nms-preset-tabs" aria-label="Connection discovery and protocol preset views">
	<?php foreach ($labels as $key => $label) { ?>
		<a class="<?php print $section === $key ? "active" : ""; ?>" <?php print $section === $key ? 'aria-current="page"' : ""; ?> href="discovery_presets.php?section=<?php print nms_h($key); ?>">
			<?php print nms_h($label); ?>
		</a>
	<?php } ?>
</nav>

<section class="nms-panel">
	<div class="nms-panel-head">
		<div>
			<h2><?php print nms_h($labels[$section]); ?></h2>
			<p><?php print nms_h($descriptions[$section]); ?></p>
		</div>
		<?php if ($section === "presets") { ?>
			<a class="nms-panel-action" href="discovery_presets.php?section=presets&amp;discovery_preset=0">+ Add preset</a>
		<?php } else { ?>
			<button type="button" class="nms-panel-action" data-nms-config-open>Configure assignments</button>
		<?php } ?>
	</div>

	<?php if ($section === "presets") { ?>
		<div class="nms-table-wrap">
			<table class="nms-table">
				<thead><tr><th>Preset</th><th>Protocol</th><th>Timing</th><th>Action</th></tr></thead>
				<tbody>
					<?php foreach ($presets as $preset) { ?>
						<tr>
							<td><?php print nms_h($preset["name"]); ?></td>
							<td><?php print nms_h(strtoupper($preset["protocol"])); ?></td>
							<td><?php print ($preset["enabled"] ? "Scheduled" : "Disabled") . " · interval " . (int) $preset["interval_seconds"] . " seconds · stale after " . (int) $preset["stale_seconds"] . " seconds · refresh " . (int) $preset["refresh_seconds"] . " seconds"; ?></td>
							<td><a href="<?php print nms_h($base_url . "?discovery_preset=" . (int) $preset["id"]); ?>">Edit</a></td>
						</tr>
					<?php } ?>
				</tbody>
			</table>
		</div>
	<?php } else { ?>
		<div class="nms-table-wrap">
			<table class="nms-table">
				<thead><tr><th>Device</th><th>Preset</th><th>Collection</th><th>Action</th></tr></thead>
				<tbody>
					<?php
					$assignments = db_fetch_assoc(
						"SELECT h.id, h.description, p.name, d.collection_enabled FROM host AS h JOIN plugin_nms_discovery_devices AS d ON d.host_id = h.id JOIN plugin_nms_discovery_presets AS p ON p.id = d.preset_id WHERE h.deleted = '' AND " . nms_visible_host_sql() . " ORDER BY h.description",
					);
					foreach ($assignments as $assignment) { ?>
						<tr>
							<td><?php print nms_h($assignment["description"]); ?></td>
							<td><?php print nms_h($assignment["name"]); ?></td>
							<td><?php print $assignment["collection_enabled"] ? "Enabled" : "Paused"; ?></td>
							<td><a href="devices.php?tab=edit&amp;id=<?php print (int) $assignment["id"]; ?>#discovery-connections">Manage device</a></td>
						</tr>
					<?php }
					if (!$assignments) { ?>
						<tr><td colspan="4">No assigned devices.</td></tr>
					<?php } ?>
				</tbody>
			</table>
		</div>
	<?php } ?>
</section>

<dialog id="nmsConfigDialog" class="nms-config-dialog" aria-labelledby="nmsConfigTitle" data-auto-open="<?php print $open_modal ? "true" : "false"; ?>">
	<div class="nms-panel-head">
		<h2 id="nmsConfigTitle"><?php print $section === "presets" ? ((int) $edit["id"] ? "Edit discovery preset" : "Add discovery preset") : "Configure device assignments"; ?></h2>
		<button type="button" data-nms-config-close aria-label="Close configuration">×</button>
	</div>
	<?php if ($error) { ?>
		<p class="nms-config-error" role="alert"><?php print nms_h($error); ?></p>
	<?php } ?>

	<?php if ($section === "presets") { ?>
		<form method="post" action="<?php print nms_h($base_url); ?>" class="nms-config-form nms-discovery-preset-form">
			<?php nms_topology_form_fields("discovery_preset"); ?>
			<input type="hidden" name="preset_id" value="<?php print (int) $edit["id"]; ?>">
			<div class="nms-discovery-preset-intro">
				<label>Preset name<input name="name" required maxlength="100" value="<?php print nms_h($edit["name"]); ?>"></label>
				<fieldset class="nms-discovery-methods">
					<legend>Collection methods</legend>
					<?php foreach (nms_nd_method_labels() as $key => $label) { ?>
						<label><input type="checkbox" name="methods[]" value="<?php print nms_h($key); ?>" <?php print in_array($key, nms_nd_protocols($edit["protocol"]), true) ? "checked" : ""; ?>><span><?php print nms_h($label); ?></span></label>
					<?php } ?>
				</fieldset>
			</div>
			<div class="nms-discovery-preset-settings">
				<label>Collection mode<select name="enabled"><option value="1" <?php print $edit["enabled"] ? "selected" : ""; ?>>Scheduled collection</option><option value="0" <?php print !$edit["enabled"] ? "selected" : ""; ?>>Disabled</option></select></label>
				<?php foreach (["interval_seconds" => ["Collection interval (seconds)", 300, 86400], "stale_seconds" => ["Stale after (seconds)", 600, 604800], "refresh_seconds" => ["Display refresh (seconds)", 10, 300]] as $key => $spec) { ?>
					<label><?php print nms_h($spec[0]); ?><input type="number" name="<?php print nms_h($key); ?>" required min="<?php print (int) $spec[1]; ?>" max="<?php print (int) $spec[2]; ?>" value="<?php print (int) $edit[$key]; ?>"></label>
				<?php } ?>
			</div>
			<div class="nms-discovery-preset-help">
				<p><strong><?php print (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_nms_discovery_devices WHERE preset_id = ?", [$edit["id"]]); ?></strong> devices use this preset.</p>
				<p>Stale threshold must be at least twice the interval. Collection runs on the assigned Cacti collector after its poll cycle. Saving a shared preset affects all its assigned devices.</p>
			</div>
			<div class="nms-discovery-preset-actions"><button type="submit">Save discovery preset</button><button type="button" class="nms-cancel-button" data-nms-config-close>Cancel</button></div>
		</form>
	<?php } else { ?>
		<form method="post" class="nms-config-form">
			<?php nms_topology_form_fields("discovery_bulk"); ?>
			<label>Preset<select name="discovery_preset_id"><option value="0">Unassign</option><?php foreach ($presets as $preset) { ?><option value="<?php print (int) $preset["id"]; ?>" <?php print (string) $preset["id"] === ($_POST["discovery_preset_id"] ?? null) ? "selected" : ""; ?>><?php print nms_h($preset["name"]); ?></option><?php } ?></select></label>
			<label>Collection<select name="discovery_enabled"><option value="1">Enabled</option><option value="0" <?php print ($_POST["discovery_enabled"] ?? "1") === "0" ? "selected" : ""; ?>>Paused</option></select></label>
			<input type="hidden" name="discovery_method_mode" value="preset">
			<label>Devices (multiple selection)<select name="host_ids[]" multiple size="10" required><?php foreach (db_fetch_assoc("SELECT h.id, h.description FROM host AS h WHERE h.deleted = '' AND " . nms_visible_host_sql() . " ORDER BY h.description") as $host) { ?><option value="<?php print (int) $host["id"]; ?>" <?php print is_array($_POST["host_ids"] ?? null) && in_array((string) $host["id"], $_POST["host_ids"], true) ? "selected" : ""; ?>><?php print nms_h($host["description"]); ?></option><?php } ?></select></label>
			<p>Assigns shared discovery methods only. SNMP credentials and graph trees are unchanged. Disabled or inaccessible devices are excluded.</p>
			<button type="submit">Apply to selected devices</button>
			<button type="button" class="nms-cancel-button" data-nms-config-close>Cancel</button>
		</form>
	<?php } ?>
</dialog>
