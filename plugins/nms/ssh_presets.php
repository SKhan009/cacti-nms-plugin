<?php
/** Native Cacti-authenticated SSH preset management; secrets are backend-only. */
require __DIR__ . "/../../include/auth.php";
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/database.php";
require_once __DIR__ . "/includes/ssh.php";
$storedAuth = "";
$error = "";
$notice = $_SESSION["nms_ssh_notice"] ?? "";
unset($_SESSION["nms_ssh_notice"]);
$selected = (int) get_filter_request_var("id");
$values = [
	"name" => "",
	"description" => "",
	"enabled" => 1,
	"username" => "admin",
	"auth_method" => "password",
	"port" => 22,
	"connect_timeout" => 10,
	"command_timeout" => 30,
	"retries" => 1,
	"keepalive" => 30,
];
try {
	nms_ssh_require_schema();
	nms_ssh_manage();
	if ($selected) {
		$values = db_fetch_row_prepared("SELECT * FROM plugin_nms_ssh_presets WHERE id=?", [$selected]);
		if (!$values) {
			throw new RuntimeException("SSH preset not found.");
		}
		$storedAuth = $values["auth_method"];
	}
	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		nms_ssh_https();
		if (empty($_POST) && (int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 0) {
			throw new RuntimeException(
				"Submitted form exceeds the server upload limit. Private keys must be at most 64 KB.",
			);
		}
		if (!csrf_check_tokens($_POST["__csrf_magic"] ?? "")) {
			throw new RuntimeException("Invalid request token.");
		}
		if (($_POST["action"] ?? "") === "delete") {
			if (
				!$selected ||
				(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_nms_ssh_devices WHERE preset_id=?", [
					$selected,
				])
			) {
				throw new RuntimeException("Remove device assignments before deleting this preset.");
			}
			if (!db_execute_prepared("DELETE FROM plugin_nms_ssh_presets WHERE id=?", [$selected])) {
				throw new RuntimeException("Preset could not be deleted.");
			}
			$_SESSION["nms_ssh_notice"] = "SSH preset deleted.";
			header("Location: ssh_presets.php", true, 303);
			exit();
		}
		if (!in_array($_POST["action"] ?? "", ["save", "delete"], true)) {
			throw new RuntimeException("Invalid preset action.");
		}
		$input = nms_ssh_preset_values($_POST);
		if (
			db_fetch_cell_prepared("SELECT id FROM plugin_nms_ssh_presets WHERE name=? AND id<>?", [
				$input["name"],
				$selected,
			])
		) {
			throw new RuntimeException("A preset with this name already exists. Choose another name.");
		}
		$ref = $values["credential_ref"] ?? "";
		$secret = $input["auth_method"] === "key" ? $_POST["private_key"] ?? "" : $_POST["secret"] ?? "";
		if (
			$input["auth_method"] === "key" &&
			isset($_FILES["key_file"]) &&
			($_FILES["key_file"]["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
		) {
			$upload = $_FILES["key_file"];
			if (
				!is_scalar($upload["error"] ?? null) ||
				(int) $upload["error"] !== UPLOAD_ERR_OK ||
				!is_string($upload["tmp_name"] ?? null) ||
				!is_uploaded_file($upload["tmp_name"])
			) {
				throw new RuntimeException("Private key upload failed. Choose the file again.");
			}
			if ($secret !== "") {
				throw new RuntimeException("Choose a key file or paste a private key, not both.");
			}
			if ((int) $upload["size"] < 1 || (int) $upload["size"] > 65536) {
				throw new RuntimeException("Private key file must be between 1 byte and 64 KB.");
			}
			$secret = file_get_contents($upload["tmp_name"], false, null, 0, 65537);
			if ($secret === false || strlen($secret) > 65536 || strpos($secret, "\0") !== false) {
				throw new RuntimeException("Invalid private key file.");
			}
		}
		$phrase = $_POST["passphrase"] ?? "";
		if (
			!is_string($secret) ||
			!is_string($phrase) ||
			strlen($secret) > 65536 ||
			strlen($phrase) > 1024 ||
			strpos($secret, "\0") !== false ||
			strpos($phrase, "\0") !== false
		) {
			throw new RuntimeException(
				"Invalid credential: maximum 64 KB for the credential and 1024 bytes for its passphrase; NUL bytes are not allowed.",
			);
		}
		if ($input["auth_method"] === "key" && $secret !== "") {
			nms_ssh_key_envelope($secret);
		}
		if ($secret === "" && $phrase !== "") {
			throw new RuntimeException("Provide the private key again when changing its passphrase.");
		}
		if (is_string($secret) && $secret !== "") {
			$ticket = nms_ssh_ticket("credential");
			$credential = nms_ssh_rpc(
				$ticket + [
					"auth_method" => $input["auth_method"],
					"secret" => $secret,
					"passphrase" => $_POST["passphrase"] ?? "",
				],
			);
			$ref = $credential["credential_ref"];
		} elseif (!$ref || $values["auth_method"] !== $input["auth_method"]) {
			throw new RuntimeException("Enter a credential for the selected authentication method.");
		}
		$input += [
			"id" => $selected,
			"credential_ref" => $ref,
			"revision" => ($values["revision"] ?? 0) + 1,
			"updated_by" => nms_current_user_id(),
			"updated_at" => nms_now(),
		];
		$saved = (int) sql_save($input, "plugin_nms_ssh_presets");
		if (!$saved) {
			throw new RuntimeException("Preset save failed; the name may already exist.");
		}
		header("Location: ssh_presets.php?id=" . $saved . "&saved=1");
		exit();
	}
} catch (Throwable $e) {
	$error = $e->getMessage();
	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		foreach (
			[
				"name",
				"description",
				"username",
				"auth_method",
				"port",
				"connect_timeout",
				"command_timeout",
				"retries",
				"keepalive",
			]
			as $f
		) {
			if (isset($_POST[$f]) && is_scalar($_POST[$f])) {
				$values[$f] = (string) $_POST[$f];
			}
		}
	}
}
if (isset($_GET["saved"])) {
	$notice = "SSH preset saved. Assign it to an existing Cacti device to verify the host key and test the connection.";
}
$presets = [];
try {
	nms_ssh_require_schema();
	nms_ssh_manage();
	$presets = db_fetch_assoc("SELECT id,name,enabled FROM plugin_nms_ssh_presets ORDER BY name");
} catch (Throwable $e) {
}
$readiness = nms_ssh_web_readiness();
nms_prepare_page("presets", "SSH presets", "css/nms-ssh.css", "js/nms-ssh.js");
require __DIR__ . "/templates/app_header.php";
?>
<main class="nms-shell nms-ssh" id="ssh-presets">
<div class="nms-heading"><div><p class="nms-eyebrow">Presets / SSH</p><h1><?php print $selected
	? "Edit SSH preset"
	: "New SSH preset"; ?></h1><p>Reusable SSH connection settings for existing Cacti devices.</p></div></div>
<?php if ($error) { ?><div class="ssh-message nms-action-feedback error" role="alert"><?php print nms_h($error); ?></div><?php } ?>
<?php if ($notice) { ?><div class="ssh-message nms-action-feedback" role="status"><?php print nms_h($notice); ?></div><?php } ?>
<nav class="ssh-presets-list" aria-label="Saved SSH presets"><a href="ssh_presets.php">+ New preset</a><?php foreach (
	$presets
	as $p
) { ?><a href="ssh_presets.php?id=<?php print (int) $p["id"]; ?>" <?php if ($selected === (int) $p["id"]) {
	print 'aria-current="page"';
} ?>><?php
print nms_h($p["name"]);
if (!$p["enabled"]) {
	print " (disabled)";
}
?></a><?php } ?></nav>
<?php if ($readiness) { ?><div class="ssh-message" role="status"><strong>SSH setup required</strong><ul><?php foreach (
	$readiness
	as $issue
) { ?><li><?php print nms_h(
	$issue,
); ?></li><?php } ?></ul><p>Presets, monitoring and console connections are not active until setup and acceptance testing are complete.</p></div><?php } ?>
<form class="nms-panel ssh-form" method="post" enctype="multipart/form-data" autocomplete="off" data-stored-auth="<?php print nms_h(
	$storedAuth,
); ?>">
<fieldset <?php if ($readiness) {
	print "disabled";
} ?> style="border:0;margin:0;padding:0;min-width:0">
<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
<section><div class="ssh-section-heading"><h2>01 · Preset details</h2><label class="ssh-check"><input type="checkbox" name="enabled" value="1" <?php if (
	$values["enabled"]
) {
	print "checked";
} ?>>Enabled</label></div>
<div class="ssh-fields"><label class="full">Preset name *<input name="name" required maxlength="80" value="<?php print nms_h(
	$values["name"],
); ?>"></label><label class="full">Description<input name="description" maxlength="500" value="<?php print nms_h(
	$values["description"],
); ?>"></label></div></section>
<section><h2>02 · Connection &amp; authentication</h2><p>The destination address comes from the assigned Cacti device.</p>
<div class="ssh-auth"><label><input type="radio" name="auth_method" value="key" <?php if (
	$values["auth_method"] === "key"
) {
	print "checked";
} ?>>SSH private key</label><label><input type="radio" name="auth_method" value="password" <?php if (
	$values["auth_method"] === "password"
) {
	print "checked";
} ?>>Password</label></div>
<div class="ssh-fields"><label>Username *<input name="username" required maxlength="128" value="<?php print nms_h(
	$values["username"],
); ?>" autocomplete="off"></label><label>SSH port *<input type="number" name="port" required min="1" max="65535" value="<?php print (int) $values[
	"port"
]; ?>"></label>
<label class="full" data-ssh-credential="password">Password <?php print $selected
	? "(leave blank to retain)"
	: "*"; ?><input type="password" name="secret" maxlength="65536" autocomplete="new-password" data-new="<?php print $selected
	? "0"
	: "1"; ?>"><small>Stored encrypted outside the web root. Stored passwords are never displayed.</small></label>
<div class="full" data-ssh-credential="key"><label class="ssh-upload-label">Upload private key file<input type="file" name="key_file" data-key-file accept=".pem,.key,.pk,.ppk,.openssh"><small>PEM, OpenSSH or PuTTY (PPK) private key, up to 64 KB. Extensionless keys are also supported using All files. Contents and passphrase are verified on Save; renaming another file does not make it a key.</small></label><label class="ssh-upload-label">Or paste private key <?php print $selected
	? "(leave blank to retain)"
	: "*"; ?><textarea name="private_key" maxlength="65536" spellcheck="false" autocomplete="off" data-new="<?php print $selected
	? "0"
	: "1"; ?>"></textarea></label><p data-key-file-status aria-live="polite">Choose a file or paste its complete contents. Stored keys are never returned to this form.</p></div>
<label class="full" data-ssh-passphrase>Private key passphrase (if encrypted)<input type="password" name="passphrase" maxlength="1024" autocomplete="new-password"></label></div>
<div class="ssh-policy">Host key verification is mandatory for each assigned device. New or changed keys block authentication until explicitly verified.</div></section>
<details><summary>Advanced connection settings</summary><div class="ssh-fields">
<?php foreach (
	[
		"connect_timeout" => ["Connection timeout (seconds)", 1, 120],
		"command_timeout" => ["Command timeout (seconds)", 1, 300],
		"retries" => ["Connection retries", 0, 2],
		"keepalive" => ["Keepalive interval (seconds)", 0, 300],
	]
	as $key => $field
) { ?><label><?php print $field[0]; ?><input name="<?php print $key; ?>" type="number" required min="<?php print $field[1]; ?>" max="<?php print $field[2]; ?>" value="<?php print (int) $values[
	$key
]; ?>"></label><?php } ?></div></details>
<section><h2>03 · Device assignment</h2><p>Save this preset, then open an existing device’s SSH Settings. Command profiles and graph templates are configured separately.</p><a class="ssh-link" href="devices.php">Open devices →</a></section>
<footer><a class="ssh-button" href="ssh_presets.php">New preset</a><div><?php if (
	$selected
) { ?><button class="ssh-button" name="action" value="delete" formnovalidate onclick="return confirm('Delete this unassigned preset? Stored credential files are retained for controlled cleanup.');">Delete preset</button><?php } ?><button class="ssh-button primary" name="action" value="save">Save preset</button></div></footer>
</fieldset></form></main>
<?php require __DIR__ . "/templates/app_footer.php"; ?>
