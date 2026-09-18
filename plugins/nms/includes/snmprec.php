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
