<?php
/** Prepare and render the create/edit diagnostic-profile dialog. */
$profile_values = $editing_profile ?: [
	"id" => 0,
	"name" => "",
	"tools" => "ping,traceroute,arp",
	"ping_count" => 4,
	"trace_hops" => 20,
	"bandwidth_seconds" => 10,
];

if ($error && ($_POST["nms_action"] ?? "") === "save_diagnostic_profile") {
	$profile_values = array_merge($profile_values, [
		"id" => (int) ($_POST["diagnostic_profile_id"] ?? 0),
		"name" => (string) ($_POST["diagnostic_profile_name"] ?? ""),
		"tools" => implode(",", nms_diag_tools($_POST["diagnostic_tools"] ?? [])),
		"ping_count" => (int) ($_POST["ping_count"] ?? 4),
		"trace_hops" => (int) ($_POST["trace_hops"] ?? 20),
		"bandwidth_seconds" => (int) ($_POST["bandwidth_seconds"] ?? 10),
	]);
}

$enabled_tools = nms_diag_tools($profile_values["tools"]);
?>
<dialog id="nmsConfigDialog" class="nms-config-dialog nms-diagnostic-dialog" aria-labelledby="nmsConfigTitle" data-auto-open="<?php print $open_profile_modal ? "true" : "false"; ?>">
	<div class="nms-panel-head">
		<div>
			<p class="nms-dialog-eyebrow">NMS / PRESETS</p>
			<h2 id="nmsConfigTitle"><?php print (int) $profile_values["id"] ? "Edit diagnostic profile" : "Add diagnostic profile"; ?></h2>
		</div>
		<button type="button" data-nms-config-close aria-label="Close diagnostic profile">×</button>
	</div>

	<?php if ($error) { ?>
		<p role="alert" class="nms-config-error"><?php print nms_h($error); ?></p>
	<?php } ?>

	<form method="post" class="nms-config-form nms-diagnostic-profile-form">
		<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
		<input type="hidden" name="nms_action" value="save_diagnostic_profile">
		<input type="hidden" name="diagnostic_profile_id" value="<?php print (int) $profile_values["id"]; ?>">

		<section class="nms-diagnostic-profile-section">
			<label class="nms-diagnostic-profile-name">
				<span>Profile name</span>
				<input required maxlength="100" name="diagnostic_profile_name" value="<?php print nms_h($profile_values["name"]); ?>" placeholder="Standard site diagnostics">
			</label>
		</section>

		<section class="nms-diagnostic-profile-section">
			<fieldset class="nms-discovery-methods nms-diagnostic-tools">
				<legend>Enabled tools</legend>
				<p class="nms-diagnostic-section-help">Choose the checks this profile can run for an assigned device.</p>
				<div class="nms-diagnostic-tool-grid">
					<?php foreach (nms_diag_labels() as $key => $label) { ?>
						<label>
							<input type="checkbox" name="diagnostic_tools[]" value="<?php print nms_h($key); ?>" <?php if (in_array($key, $enabled_tools, true)) { print "checked"; } ?>>
							<span><?php print nms_h($label); ?></span>
						</label>
					<?php } ?>
				</div>
			</fieldset>
		</section>

		<section class="nms-diagnostic-profile-section">
			<h3>Test limits</h3>
			<div class="nms-diagnostic-limits">
				<label>
					<span>Ping packets</span>
					<input type="number" name="ping_count" min="1" max="10" value="<?php print (int) $profile_values["ping_count"]; ?>">
				</label>
				<label>
					<span>Traceroute maximum hops</span>
					<input type="number" name="trace_hops" min="1" max="30" value="<?php print (int) $profile_values["trace_hops"]; ?>">
				</label>
				<label>
					<span>Bandwidth test seconds</span>
					<input type="number" name="bandwidth_seconds" min="1" max="30" value="<?php print (int) $profile_values["bandwidth_seconds"]; ?>">
				</label>
			</div>
		</section>

		<p class="nms-config-note">Enable bandwidth tests only for devices that you are authorised to test. Assign the saved profile in Add/Edit device → On-demand diagnostics.</p>
		<div class="nms-discovery-preset-actions">
			<button type="submit"><?php print (int) $profile_values["id"] ? "Save changes" : "Save diagnostic profile"; ?></button>
			<button type="button" class="nms-cancel-button" data-nms-config-close>Cancel</button>
		</div>
	</form>
</dialog>
