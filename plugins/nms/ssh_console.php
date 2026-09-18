<?php
require __DIR__ . "/../../include/auth.php";
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/database.php";
require_once __DIR__ . "/includes/ssh.php";
$id = (int) get_filter_request_var("id");
$error = "";
$devices = [];
try {
	nms_ssh_require_schema();
	nms_ssh_https();
	if (!api_user_realm_auth("ssh_console.php")) {
		throw new RuntimeException("SSH console permission required.");
	}
	if ($id) {
		nms_require_device_access($id);
		nms_ssh_device($id);
	}
	$visible = nms_visible_host_sql("h.id");
	$rows = db_fetch_assoc(
		"SELECT h.id FROM host h INNER JOIN plugin_nms_ssh_devices d ON d.host_id=h.id INNER JOIN plugin_nms_ssh_presets p ON p.id=d.preset_id WHERE h.disabled='' AND h.deleted='' AND p.enabled=1 AND $visible ORDER BY h.description,h.id",
	);
	foreach ($rows as $row) {
		$d = nms_ssh_device((int) $row["id"]);
		$devices[] = [
			"id" => (int) $d["host_id"],
			"name" => $d["description"],
			"endpoint" => $d["endpoint"],
			"preset" => $d["preset_name"],
			"username" => $d["username"],
			"trusted" => (bool) $d["host_key"] && $d["verified_endpoint"] === $d["endpoint"],
		];
	}
	foreach (["guacamole-1.6.0.js"] as $asset) {
		if (!is_file(__DIR__ . "/ssh/assets/" . $asset)) {
			throw new RuntimeException("Offline terminal assets missing. Install the complete NMS SSH package.");
		}
	}
} catch (Throwable $e) {
	$error = $e->getMessage();
}
nms_prepare_page("ssh", "SSH console", "css/nms-ssh.css", "js/nms-ssh-console.js");
require __DIR__ . "/templates/app_header.php";
?>
<main class="nms-shell nms-ssh ssh-workspace"><div class="nms-heading"><div><p class="nms-eyebrow">NMS / SSH Console</p><h1>SSH terminal workspace with Apache Guacamole</h1><p>One workspace for your permitted devices. Each terminal connects to one device using its assigned preset.</p></div></div>
<?php if ($error) { ?><div class="ssh-message error" role="alert"><?php print nms_h($error); ?></div><?php } else { ?>
<section class="nms-panel ssh-console" id="nms-ssh-console" data-host="<?php print $id; ?>" data-csrf="<?php print nms_h(
	$nms_csrf_token,
); ?>" data-api="<?php print nms_h(nms_plugin_url("ssh_api.php")); ?>" data-settings="<?php print nms_h(
	nms_plugin_url("ssh_device.php"),
); ?>">
<script type="application/json" data-devices><?php print json_encode(
	$devices,
	JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE,
); ?></script>
<div class="ssh-workspace-toolbar"><label for="ssh-target">Device for new terminal</label><select id="ssh-target" data-target><?php foreach (
	$devices
	as $d
) { ?><option value="<?php print $d["id"]; ?>" <?php if ($id === $d["id"]) {
	print "selected";
} ?>><?php print nms_h(
	$d["name"] . " — " . $d["endpoint"] . (!$d["trusted"] ? " — Verify host key" : ""),
); ?></option><?php } ?></select><button class="ssh-button primary" type="button" data-new-terminal <?php if (
	!$devices
) {
	print "disabled";
} ?>>+ New terminal</button></div>
<div class="ssh-workspace-tabs" role="tablist" aria-label="SSH terminal sessions" data-tabs></div><div data-panels></div>
<p class="ssh-workspace-hint" data-workspace-status role="status"><?php print $devices
	? "Two terminal tabs per workspace; two live sessions per user across all windows."
	: "No permitted devices have an enabled SSH preset. Open Device management and configure SSH Settings first."; ?></p>
<template data-session-template><section class="ssh-session" role="tabpanel"><header><div><strong data-identity></strong><p data-connection-info></p><a class="ssh-link" data-settings-link>SSH settings</a></div><div class="ssh-session-actions"><button type="button" class="ssh-button primary" data-connect>Connect</button><button type="button" class="ssh-button" data-disconnect disabled>Disconnect</button></div></header><div class="ssh-terminal" data-terminal aria-label="Interactive SSH terminal"></div><footer><span data-status role="status" aria-live="polite">Disconnected — select Connect to start</span><span>Idle timeout 10 minutes</span></footer></section></template>
</section><p>Configure each device’s preset and verify its host key once in SSH Settings. Reuse this workspace thereafter. Commands use the device account’s permissions. Terminal contents are not recorded. Leaving or reloading this page closes its sessions.</p>
<script src="<?php print nms_h(nms_asset_url("ssh/assets/guacamole-1.6.0.js")); ?>"></script>
<?php } ?></main><?php require __DIR__ . "/templates/app_footer.php"; ?>
