<?php
/**
 * @file snmprec.php
 * Parse bounded SNMP simulator record uploads, validate community filenames, and deploy new records to the configured data directory.
 * Activation is queued for the managed service; PHP does not launch a responder.
 */

require_once __DIR__ . "/snmpsim.php";

/** Validate a bounded SNMP record upload and return typed OID records with section and graphability metadata. */
function nms_snmprec_parse($content)
{
	if (!is_string($content) || trim($content) === "") {
		throw new InvalidArgumentException("The SNMP record file is empty.");
	}
	if (strlen($content) > 2097152) {
		throw new InvalidArgumentException("The SNMP record file must be 2 MB or smaller.");
	}

	$records = [];
	$seen_oids = [];
	$section = "General readings";
	$lines = preg_split('/\r\n|\r|\n/', $content);
	if (count($lines) > 5000) {
		throw new InvalidArgumentException("The SNMP record file contains too many lines.");
	}

	foreach ($lines as $line_number => $line) {
		$line = trim($line);
		if ($line === "") {
			continue;
		}
		if ($line[0] === "#") {
			$heading = trim(preg_replace('/^[#\s-]+|[\s-]+$/', "", $line));
			$heading = trim(preg_replace("/\s*\([^)]*Template[^)]*\)\s*/i", "", $heading));
			if ($heading !== "") {
				$section = substr($heading, 0, 255);
			}
			continue;
		}

		$parts = explode("|", $line, 3);
		if (count($parts) !== 3) {
			throw new InvalidArgumentException("Invalid SNMP record syntax on line " . ($line_number + 1) . ".");
		}
		$oid = ltrim(trim($parts[0]), ".");
		$tag = trim($parts[1]);
		$value = trim($parts[2]);
		if (!preg_match('/^(?:[0-9]+\.)*[0-9]+$/', $oid)) {
			throw new InvalidArgumentException("Invalid OID on line " . ($line_number + 1) . ".");
		}
		$arcs = explode(".", $oid);
		if (
			count($arcs) < 2 ||
			count($arcs) > 128 ||
			!in_array($arcs[0], ["0", "1", "2"], true) ||
			($arcs[0] !== "2" && (int) $arcs[1] > 39)
		) {
			throw new InvalidArgumentException("Invalid OID structure on line " . ($line_number + 1) . ".");
		}
		foreach ($arcs as $arc) {
			if ((strlen($arc) > 1 && $arc[0] === "0") || strlen($arc) > 10 || (float) $arc > 4294967295) {
				throw new InvalidArgumentException("Invalid OID arc on line " . ($line_number + 1) . ".");
			}
		}
		if (isset($seen_oids[$oid])) {
			throw new InvalidArgumentException("Duplicate OID on line " . ($line_number + 1) . ".");
		}
		$seen_oids[$oid] = true;
		if (!preg_match('/^(2|4|4x|6|64|65|66|67|70)$/D', $tag)) {
			throw new InvalidArgumentException(
				"Unsupported SNMP tag on line " .
					($line_number + 1) .
					". Upload static records; variation modules are not supported by this importer.",
			);
		}
		$tag_match = ["", rtrim($tag, "x")];
		if ($tag === "4x" && (!ctype_xdigit($value) || strlen($value) % 2)) {
			throw new InvalidArgumentException("Invalid hexadecimal octets on line " . ($line_number + 1) . ".");
		}
		if (
			in_array($tag, ["2", "65", "66", "67", "70"], true) &&
			!preg_match($tag === "2" ? '/^-?[0-9]+$/D' : '/^[0-9]+$/D', $value)
		) {
			throw new InvalidArgumentException("Invalid integer SNMP value on line " . ($line_number + 1) . ".");
		}
		if (strlen($value) > 1024) {
			throw new InvalidArgumentException("The value on line " . ($line_number + 1) . " is too long.");
		}

		$type = (int) $tag_match[1];
		$graphable =
			in_array($type, [2, 65, 66, 67, 70], true) && preg_match('/^-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)$/', $value);
		$records[] = [
			"oid" => $oid,
			"tag" => $tag,
			"type" => $type,
			"value" => $value,
			"section" => $section,
			"graphable" => (bool) $graphable,
		];
	}

	if (!count($records)) {
		throw new InvalidArgumentException("No SNMP records were found in the uploaded file.");
	}
	return $records;
}

/** Validate a community string that can also safely identify its simulator record filename. */
function nms_snmprec_community($value)
{
	$value = trim((string) $value);
	if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $value)) {
		throw new InvalidArgumentException("Community must use only letters, numbers, dots, underscores, or hyphens.");
	}
	return $value;
}

/** Resolve the configured simulator directory and require writable access; never substitute another path. */
function nms_snmprec_runtime_dir()
{
	$settings = nms_snmpsim_config();
	$directory = $settings["data_dir"];
	// Called only by an explicit upload, never by read-only health checks.
	// Create only the administrator-selected path. Never chmod existing parents.
	if (!file_exists($directory) && !is_link($directory)) {
		$missing = [];
		$parent = rtrim($directory, "/\\");
		while (!file_exists($parent) && !is_link($parent)) {
			$missing[] = basename($parent);
			$next = dirname($parent);
			if ($next === $parent) {
				throw new RuntimeException("No existing parent for the configured SNMPSim data directory.");
			}
			$parent = $next;
		}
		$parent = realpath($parent);
		if ($parent === false || !is_dir($parent) || !is_writable($parent)) {
			throw new RuntimeException(
				"Cannot create the configured SNMPSim data directory: its existing parent is not writable by PHP. An administrator must grant access to the dedicated data parent (including SELinux policy where enabled). No system permissions were changed.",
			);
		}
		foreach (array_reverse($missing) as $part) {
			$parent .= DIRECTORY_SEPARATOR . $part;
			if (is_link($parent) || (!@mkdir($parent, 0750) && !is_dir($parent))) {
				throw new RuntimeException(
					"Could not create the configured SNMPSim data directory. Check service-account permissions and SELinux policy.",
				);
			}
		}
		clearstatcache();
	}
	if (is_link($directory)) {
		throw new RuntimeException(
			"SNMPSim data directory must not be a symbolic link. Configure its real local path.",
		);
	}
	$real = realpath($directory);
	if ($real === false || !is_dir($real) || !is_writable($real)) {
		throw new RuntimeException(
			"The configured SNMPSim data directory must exist and be writable by the PHP service account. Check directory ownership, traversal permissions and SELinux policy; do not make the plugin code or configuration world-writable.",
		);
	}
	return $real;
}

/** Write a new community record and queue activation; reject existing records and report filesystem failures. */
function nms_snmprec_deploy($community, $content)
{
	$community = nms_snmprec_community($community);
	$directory = nms_snmprec_runtime_dir();
	$target = $directory . DIRECTORY_SEPARATOR . $community . ".snmprec";
	if (file_exists($target)) {
		throw new RuntimeException("A simulator record with this community already exists.");
	}
	$temporary = tempnam($directory, ".nms-upload-");
	if ($temporary === false || file_put_contents($temporary, $content, LOCK_EX) === false) {
		throw new RuntimeException("NMS could not write the uploaded simulator record.");
	}
	@chmod($temporary, 0640);
	if (!rename($temporary, $target)) {
		@unlink($temporary);
		throw new RuntimeException("NMS could not activate the uploaded simulator record.");
	}
	if ((nms_snmpsim_config()["activation"] ?? "") === "manual") {
		return $target;
	}
	$pending = $directory . DIRECTORY_SEPARATOR . ".reload.pending";
	if (file_put_contents($pending, (string) time(), LOCK_EX) === false) {
		@unlink($target);
		throw new RuntimeException("The simulator record was saved, but NMS could not queue its activation.");
	}
	return $target;
}

/** Return simulator OIDs that can be changed by the lab-only FCAPS controls. */
function nms_snmprec_fcaps_targets($import_id)
{
	$rows = db_fetch_assoc_prepared(
		"SELECT oid, tag, raw_value FROM plugin_nms_snmprec_oids WHERE import_id = ? ORDER BY oid",
		[(int) $import_id],
	);
	$targets = ["interfaces" => [], "traffic" => [], "battery" => [], "sys_name" => false];
	foreach ($rows as $row) {
		$oid = (string) $row["oid"];
		if (preg_match('/^1\\.3\\.6\\.1\\.2\\.1\\.2\\.2\\.1\\.8\\.([0-9]+)$/D', $oid, $match) && $row["tag"] === "2") {
			$targets["interfaces"][(int) $match[1]] = ["oid" => $oid, "value" => $row["raw_value"]];
		}
		if (preg_match('/^1\\.3\\.6\\.1\\.2\\.1\\.(?:2\\.2\\.1\\.(?:10|16)|31\\.1\\.1\\.1\\.(?:6|10))\\.[0-9]+$/D', $oid) && in_array($row["tag"], ["65", "70"], true)) {
			$targets["traffic"][] = $oid;
		}
		if (in_array($oid, ["1.3.6.1.2.1.33.1.2.1.0", "1.3.6.1.2.1.33.1.2.3.0", "1.3.6.1.2.1.33.1.2.4.0"], true) && $row["tag"] === "2") {
			$targets["battery"][] = $oid;
		}
		if ($oid === "1.3.6.1.2.1.1.5.0" && $row["tag"] === "4") {
			$targets["sys_name"] = true;
		}
	}
	return $targets;
}

/** Add two non-negative decimal strings without depending on platform integer size. */
function nms_snmprec_decimal_add($left, $right)
{
	$left = ltrim((string) $left, "0");
	$right = ltrim((string) $right, "0");
	$left = $left === "" ? "0" : $left;
	$right = $right === "" ? "0" : $right;
	$carry = 0;
	$out = "";
	for ($i = 0, $length = max(strlen($left), strlen($right)); $i < $length; $i++) {
		$a = $i < strlen($left) ? (int) $left[strlen($left) - 1 - $i] : 0;
		$b = $i < strlen($right) ? (int) $right[strlen($right) - 1 - $i] : 0;
		$sum = $a + $b + $carry;
		$out = ($sum % 10) . $out;
		$carry = intdiv($sum, 10);
	}
	return ($carry ? (string) $carry : "") . $out;
}

/** Atomically replace an imported record and ask the managed worker to reload it. */
function nms_snmprec_replace_import($import_id, $changes)
{
	$import = db_fetch_row_prepared(
		"SELECT community, deployed_path FROM plugin_nms_snmprec_imports WHERE id = ?",
		[(int) $import_id],
	);
	if (!$import || !$changes) {
		throw new InvalidArgumentException("Select an imported simulator record and a supported lab scenario.");
	}
	$community = nms_snmprec_community($import["community"]);
	$directory = nms_snmprec_runtime_dir();
	$target = $directory . DIRECTORY_SEPARATOR . $community . ".snmprec";
	if (is_link($target) || !is_file($target) || !is_readable($target)) {
		throw new RuntimeException("The imported simulator record is not a readable regular file in the configured data directory.");
	}
	$content = file_get_contents($target);
	if ($content === false) {
		throw new RuntimeException("NMS could not read the imported simulator record.");
	}
	$records = nms_snmprec_parse($content);
	$known = [];
	foreach ($records as $record) {
		$known[$record["oid"]] = $record;
	}
	foreach ($changes as $oid => $value) {
		if (!isset($known[$oid])) {
			throw new RuntimeException("The requested OID is no longer present in this simulator record.");
		}
		$test = $known[$oid];
		$test["value"] = (string) $value;
		// Reuse the record parser to validate type and value rules before writing anything.
		nms_snmprec_parse($oid . "|" . $test["tag"] . "|" . $test["value"]);
	}
	$rewritten = [];
	foreach (preg_split('/\\r\\n|\\r|\\n/', $content) as $line) {
		$parts = explode("|", trim($line), 3);
		$oid = count($parts) === 3 ? ltrim(trim($parts[0]), ".") : "";
		if ($oid !== "" && array_key_exists($oid, $changes)) {
			$line = trim($parts[0]) . "|" . trim($parts[1]) . "|" . $changes[$oid];
		}
		$rewritten[] = $line;
	}
	$new_content = rtrim(implode("\n", $rewritten)) . "\n";
	// Validate the full record so duplicate or malformed data can never be deployed.
	nms_snmprec_parse($new_content);
	$temporary = tempnam($directory, ".nms-fcaps-");
	if ($temporary === false || file_put_contents($temporary, $new_content, LOCK_EX) === false) {
		throw new RuntimeException("NMS could not write the updated simulator record.");
	}
	@chmod($temporary, 0640);
	if (!rename($temporary, $target)) {
		@unlink($temporary);
		throw new RuntimeException("NMS could not activate the updated simulator record.");
	}
	foreach ($changes as $oid => $value) {
		db_execute_prepared("UPDATE plugin_nms_snmprec_oids SET raw_value = ? WHERE import_id = ? AND oid = ?", [
			(string) $value,
			(int) $import_id,
			$oid,
		]);
	}
	if ((nms_snmpsim_config()["activation"] ?? "") !== "manual") {
		if (file_put_contents($directory . DIRECTORY_SEPARATOR . ".reload.pending", (string) time(), LOCK_EX) === false) {
			throw new RuntimeException("The record was updated, but NMS could not queue the SNMPSim reload.");
		}
	}
}

/** Apply a named FCAPS scenario to an imported lab record; real Cacti devices are never changed. */
function nms_snmprec_apply_fcaps_scenario($import_id, $scenario, $input)
{
	$targets = nms_snmprec_fcaps_targets($import_id);
	$changes = [];
	if (in_array($scenario, ["interface_up", "interface_down"], true)) {
		$index = filter_var($input["interface_index"] ?? null, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
		if ($index === false || !isset($targets["interfaces"][$index])) {
			throw new InvalidArgumentException("Select an interface provided by this imported record.");
		}
		$changes[$targets["interfaces"][$index]["oid"]] = $scenario === "interface_up" ? "1" : "2";
		$label = "Interface " . $index . ($scenario === "interface_up" ? " set to up" : " set to down");
	} elseif ($scenario === "traffic_pulse") {
		if (!$targets["traffic"]) {
			throw new RuntimeException("This record has no supported interface traffic counters.");
		}
		$rows = db_fetch_assoc_prepared("SELECT oid, raw_value FROM plugin_nms_snmprec_oids WHERE import_id = ?", [(int) $import_id]);
		$values = array_column($rows, "raw_value", "oid");
		foreach ($targets["traffic"] as $oid) {
			$changes[$oid] = nms_snmprec_decimal_add($values[$oid], "1250000");
		}
		$label = "Traffic counters increased for the next Cacti poll";
	} elseif (in_array($scenario, ["battery_normal", "battery_low"], true)) {
		if (!$targets["battery"]) {
			throw new RuntimeException("This record does not include standard UPS battery OIDs.");
		}
		$normal = ["1.3.6.1.2.1.33.1.2.1.0" => "2", "1.3.6.1.2.1.33.1.2.3.0" => "45", "1.3.6.1.2.1.33.1.2.4.0" => "95"];
		$low = ["1.3.6.1.2.1.33.1.2.1.0" => "3", "1.3.6.1.2.1.33.1.2.3.0" => "8", "1.3.6.1.2.1.33.1.2.4.0" => "15"];
		foreach ($targets["battery"] as $oid) {
			$changes[$oid] = ($scenario === "battery_low" ? $low : $normal)[$oid];
		}
		$label = $scenario === "battery_low" ? "UPS battery set to low" : "UPS battery set to normal";
	} elseif ($scenario === "system_name") {
		$name = trim((string) ($input["system_name"] ?? ""));
		if (!$targets["sys_name"] || $name === "" || strlen($name) > 255 || preg_match('/[|\\r\\n]/', $name)) {
			throw new InvalidArgumentException("Enter a valid system name for a record that includes sysName.");
		}
		$changes["1.3.6.1.2.1.1.5.0"] = $name;
		$label = "System name updated";
	} else {
		throw new InvalidArgumentException("Unsupported FCAPS scenario.");
	}
	// The record file is updated before activation.  A manually launched responder
	// keeps its records in memory, so it cannot see this change until its process is
	// restarted.  Managed RHEL installs consume the marker through the reload timer.
	nms_snmprec_replace_import($import_id, $changes);
	$settings = nms_snmpsim_config();
	if (($settings["activation"] ?? "") === "manual") {
		return $label . ". The record file was updated, but manual SNMPSim activation does not reload a running responder. Restart the responder on the configured collector, then run the next Cacti poll.";
	}
	return $label . ". Reload is queued. The managed SNMPSim reload timer must be active and consume the marker; verify the live value before expecting the next Cacti poll to reflect this change.";
}
