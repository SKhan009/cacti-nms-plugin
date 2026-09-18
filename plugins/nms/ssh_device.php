<?php
require __DIR__ . "/../../include/auth.php";
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/database.php";
require_once __DIR__ . "/includes/ssh.php";
$id = (int) get_filter_request_var("id");
$error = "";
$notice = "";
$device = [];
$assignment = [];
$state = [];
$presets = [];
try {
	nms_ssh_require_schema();
	nms_ssh_manage();
	nms_require_device_access($id);
	$device = db_fetch_row_prepared("SELECT id,description,hostname FROM host WHERE id=? AND deleted=''", [$id]);
	if (!$device) {
		throw new RuntimeException("Select an existing Cacti device.");
	}
	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		nms_ssh_https();
		if (!csrf_check_tokens($_POST["__csrf_magic"] ?? "")) {
			throw new RuntimeException("Invalid request token.");
		}
		$action = $_POST["action"] ?? "";
		if ($action === "assign") {
			$preset = (int) ($_POST["preset_id"] ?? 0);
			if (!db_fetch_cell_prepared("SELECT id FROM plugin_nms_ssh_presets WHERE id=?", [$preset])) {
				throw new RuntimeException("Select an existing SSH preset.");
			}
			$saved = db_execute_prepared(
				"INSERT INTO plugin_nms_ssh_devices (host_id,preset_id,monitoring,updated_by,updated_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE preset_id=VALUES(preset_id),monitoring=VALUES(monitoring),revision=revision+1,updated_by=VALUES(updated_by),updated_at=NOW()",
				[$id, $preset, !empty($_POST["monitoring"]) ? 1 : 0, nms_current_user_id()],
			);
			if (!$saved) {
				throw new RuntimeException("SSH assignment could not be saved.");
			}
			$notice = "SSH assignment saved.";
		} elseif ($action === "unassign") {
			$saved = db_execute_prepared("DELETE FROM plugin_nms_ssh_devices WHERE host_id=?", [$id]);
			if (!$saved) {
				throw new RuntimeException("SSH assignment could not be removed.");
			}
			$notice = "SSH assignment removed. Existing graphs remain and return unknown readings.";
		} elseif ($action === "observe" || $action === "test") {
			$data = nms_ssh_rpc(nms_ssh_ticket($action, $id));
			$notice =
				$action === "observe"
					? "Observed fingerprint: " . $data["fingerprint"] . ". Verify it independently before trusting it."
					: $data["message"];
		} elseif ($action === "trust") {
			$d = nms_ssh_device($id);
			$observed = db_fetch_row_prepared(
				"SELECT observed_key,observed_endpoint,observed_at FROM plugin_nms_ssh_devices WHERE host_id=? AND observed_at>DATE_SUB(NOW(),INTERVAL 10 MINUTE)",
				[$id],
			);
			$confirmation = trim((string) ($_POST["fingerprint"] ?? ""));
			if (
				!$observed ||
				$observed["observed_endpoint"] !== $d["endpoint"] ||
				!hash_equals(nms_ssh_fingerprint($observed["observed_key"]), $confirmation)
			) {
				throw new RuntimeException("Enter the independently verified fingerprint from a recent observation.");
			}
			$saved = db_execute_prepared(
				"UPDATE plugin_nms_ssh_devices SET host_key=?,verified_endpoint=?,verified_by=?,verified_at=NOW(),revision=revision+1 WHERE host_id=?",
				[$observed["observed_key"], $d["endpoint"], nms_current_user_id(), $id],
			);
			if (!$saved) {
				throw new RuntimeException("Host key could not be saved.");
			}
			$notice = "Device host key explicitly trusted.";
		} elseif ($action === "graphs") {
			nms_require_management(10);
			if (!is_file(__DIR__ . "/includes/ssh_graphs.php")) {
				throw new RuntimeException("SSH graph integration is not installed yet. No graph was created.");
			}
			require_once __DIR__ . "/includes/ssh_graphs.php";
			nms_ssh_graphs_create($id);
			$notice = "Native Cacti SSH graphs created or existing graphs retained.";
		} else {
			throw new RuntimeException("Unsupported device SSH action.");
		}
	}
	$assignment = db_fetch_row_prepared("SELECT * FROM plugin_nms_ssh_devices WHERE host_id=?", [$id]);
	$state = db_fetch_row_prepared("SELECT * FROM plugin_nms_ssh_state WHERE host_id=?", [$id]);
	$presets = db_fetch_assoc("SELECT id,name,enabled FROM plugin_nms_ssh_presets ORDER BY name");
} catch (Throwable $e) {
	$error = $e->getMessage();
}
$readiness = nms_ssh_web_readiness();
nms_prepare_page("devices", "Device SSH settings", "css/nms-ssh.css");
require __DIR__ . "/templates/app_header.php";
?>
<main class="nms-shell nms-ssh"><div class="nms-heading"><div><p class="nms-eyebrow">Devices / SSH settings</p><h1><?php print nms_h(
	$device["description"] ?? "SSH settings",
); ?></h1><p><?php print nms_h($device["hostname"] ?? ""); ?></p></div></div>
<nav class="ssh-presets-list"><a href="devices.php?tab=edit&amp;id=<?php print $id; ?>">Overview</a><a aria-current="page" href="ssh_device.php?id=<?php print $id; ?>">SSH settings</a><?php /* Console UI temporarily hidden. */ if (
	false
) { ?><a href="ssh_console.php?id=<?php print $id; ?>">SSH console</a><?php } ?></nav>
<?php
if ($error) { ?><div class="ssh-message error" role="alert"><?php print nms_h($error); ?></div><?php }
if ($notice) { ?><div class="ssh-message" role="status"><?php print nms_h($notice); ?></div><?php }
?>
<?php if ($readiness) { ?><div class="ssh-message" role="status"><strong>SSH setup required</strong><ul><?php foreach (
	$readiness
	as $issue
) { ?><li><?php print nms_h($issue); ?></li><?php } ?></ul></div><?php } ?>
<?php if ($device) { ?><form method="post" class="nms-panel ssh-form"><fieldset <?php if ($readiness) {
	print "disabled";
} ?> style="border:0;margin:0;padding:0;min-width:0"><input type="hidden" name="__csrf_magic" value="<?php print nms_h(
 	$nms_csrf_token,
 ); ?>">
<section><h2>Connection assignment</h2><div class="ssh-fields"><label>SSH preset<select name="preset_id"><option value="">Select preset</option><?php foreach (
	$presets
	as $p
) { ?><option value="<?php print (int) $p["id"]; ?>" <?php if (
	(int) ($assignment["preset_id"] ?? 0) === (int) $p["id"]
) {
	print "selected";
} ?>><?php print nms_h(
	$p["name"] . (!$p["enabled"] ? " (disabled)" : ""),
); ?></option><?php } ?></select></label><label>Command profile<input value="Linux health v1" readonly></label></div><label class="ssh-check"><input type="checkbox" name="monitoring" value="1" <?php if (
	!empty($assignment["monitoring"])
) {
	print "checked";
} ?>>Enable monitoring every 5 minutes</label><p>The first supported target is this RHEL VM. Assignments use the existing Cacti device address and collector.</p><button class="ssh-button primary" name="action" value="assign">Save assignment</button> <button class="ssh-button" name="action" value="unassign">Remove assignment</button></section>
<?php if ($assignment) { ?><section><h2>Device host key</h2><p>Stored fingerprint: <?php print nms_h(
	!empty($assignment["host_key"]) ? nms_ssh_fingerprint($assignment["host_key"]) : "Not verified",
); ?></p><p>Verified endpoint: <?php print nms_h(
	$assignment["verified_endpoint"] ?? "None",
); ?></p><button class="ssh-button" name="action" value="observe">Observe host key</button><label class="ssh-trust">Independently verified SHA256 fingerprint<input name="fingerprint" placeholder="SHA256:…" autocomplete="off"></label><button class="ssh-button" name="action" value="trust">Trust verified key</button> <button class="ssh-button primary" name="action" value="test">Test connection</button><p>On the RHEL target, compare with <code>ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub -E sha256</code>; use the public key file matching the observed key type.</p></section>
<section><h2>Monitoring &amp; graphs</h2><p>Last attempt: <?php print nms_h(
	$state["last_attempt"] ?? "Never",
); ?> · Last success: <?php print nms_h($state["last_success"] ?? "Never"); ?></p><p>Collection result: <?php
print nms_h($state["status"] ?? "unknown");
if (!empty($state["last_error"])) {
	print " — " . nms_h($state["last_error"]);
}
?></p><p>Current reading: <?php try {
	$sample = nms_ssh_current_sample($id);
	print nms_h(
		"CPU " .
			$sample["cpu_percent"] .
			"% · Memory " .
			$sample["memory_percent"] .
			"% · Uptime " .
			$sample["uptime_seconds"] .
			" s",
	);
} catch (Throwable $e) {
	print nms_h("Unknown / stale: " . $e->getMessage());
} ?></p><button class="ssh-button" name="action" value="graphs">Create Cacti graphs</button></section><?php } ?></fieldset></form><?php } ?></main>
<?php require __DIR__ . "/templates/app_footer.php"; ?>
