<?php
/**
 * @file snmpsim.php
 * Read administrator-managed simulator configuration, expose health, and resolve per-record defaults.
 * Explicit live probes use Cacti SNMP; missing configuration or failed SNMP never returns a record-file value as a reading.
 */

/** Only administrator-selected configuration is accepted; OS detection never selects a hidden path. */
function nms_snmpsim_config()
{
	global $config;
	if (isset($config) && array_key_exists("nms_snmpsim", $config)) {
		// No request parameter or database setting may select executable/configuration paths.
		$settings = $config["nms_snmpsim"];
	} else {
		$path = $config["nms_snmpsim_config"] ?? getenv("NMS_SNMPSIM_CONFIG");
		$local = false;
		if (!$path && is_file(dirname(__DIR__) . "/config.local.php")) {
			$path = dirname(__DIR__) . "/config.local.php";
			$local = true;
		}
		if (!$path) {
			throw new RuntimeException(
				"Optional SNMPSim is not configured. Copy config.example.php to config.local.php in the NMS plugin and set your storage, endpoint and collector. Alternatively set nms_snmpsim_config in Cacti configuration to an external JSON path. Normal monitoring does not require SNMPSim.",
			);
		}
		if (!nms_snmpsim_local_path($path) || !is_file($path) || !is_readable($path) || is_link($path)) {
			throw new RuntimeException(
				"SNMPSim configuration is not a readable regular local file. Check the selected path, service-account read access and SELinux policy where enabled.",
			);
		}
		if (PHP_OS_FAMILY !== "Windows") {
			foreach ([$path, dirname($path)] as $protected_path) {
				clearstatcache(true, $protected_path);
				$mode = @fileperms($protected_path);
				if ($mode === false) {
					throw new RuntimeException(
						"Cannot inspect SNMPSim configuration permissions: " .
							$protected_path .
							". Check service-account traversal access and SELinux policy.",
					);
				}
				if ($mode & 0022) {
					throw new RuntimeException(
						"SNMPSim configuration path " .
							$protected_path .
							" has permissions " .
							sprintf("%04o", $mode & 0777) .
							". Remove group/world write access from this configuration path and keep it administrator-owned. This check does not apply to the writable record-data directory.",
					);
				}
			}
		}
		if (PHP_SAPI !== "cli" && is_writable($path)) {
			throw new RuntimeException(
				"SNMPSim administrator configuration must not be writable by the web-server account.",
			);
		}
		$settings = $local ? require $path : json_decode(file_get_contents($path), true);
	}
	if (!is_array($settings)) {
		throw new RuntimeException("Invalid explicit SNMPSim configuration. No alternate configuration was used.");
	}
	if (($settings["activation"] ?? "") === "systemd") {
		if (PHP_OS_FAMILY !== "Linux") {
			throw new RuntimeException(
				"The configured systemd adapter requires Linux. Use manual activation on other supported platforms.",
			);
		}
		require_once __DIR__ . "/platform/systemd.php";
		return nms_snmpsim_validate_config($settings);
	}
	return nms_snmpsim_validate_portable_config($settings);
}

/** Local absolute filesystem paths only; reject URI wrappers, roots, traversal and UNC shares. */
function nms_snmpsim_local_path($path, $os_family = PHP_OS_FAMILY)
{
	if (!is_string($path) || $path === "" || preg_match('/[\x00-\x1f\x7f<>|?*]/', $path)) {
		return false;
	}
	$normalized = str_replace("\\", "/", $path);
	if ($os_family === "Windows") {
		if (!preg_match("~^[A-Za-z]:/[^/]+~D", $normalized) || strpos(substr($normalized, 2), ":") !== false) {
			return false;
		}
	} elseif ($normalized[0] !== "/" || substr($normalized, 0, 2) === "//" || strpos($normalized, ":") !== false) {
		return false;
	}
	if (preg_match('~(^|/)\.{1,2}(/|$)~', $normalized) || trim($normalized, "/") === "") {
		return false;
	}
	return true;
}

/** Portable imports use explicitly configured local storage and manual responder activation. */
function nms_snmpsim_validate_portable_config($settings, $os_family = PHP_OS_FAMILY)
{
	if (!is_array($settings) || ($settings["activation"] ?? "") !== "manual") {
		throw new RuntimeException("Portable SNMPSim configuration requires activation = manual.");
	}
	if (!nms_snmpsim_local_path($settings["data_dir"] ?? null, $os_family)) {
		throw new RuntimeException(
			"SNMPSim data_dir must be a non-root absolute local directory on this operating system.",
		);
	}
	if (
		!isset($settings["client_address"]) ||
		!is_string($settings["client_address"]) ||
		!filter_var($settings["client_address"], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ||
		$settings["client_address"] === "0.0.0.0"
	) {
		throw new RuntimeException("SNMPSim requires an explicit reachable IPv4 client_address.");
	}
	if (!isset($settings["port"]) || !is_int($settings["port"]) || $settings["port"] < 1 || $settings["port"] > 65535) {
		throw new RuntimeException("SNMPSim port must be an integer from 1 to 65535.");
	}
	$validated = [
		"data_dir" => $settings["data_dir"],
		"client_address" => $settings["client_address"],
		"port" => $settings["port"],
		"activation" => "manual",
		"poller_id" => nms_snmpsim_configured_poller($settings),
	];
	if (isset($settings["executable"])) {
		if (!nms_snmpsim_local_path($settings["executable"], $os_family)) {
			throw new RuntimeException("SNMPSim executable must be an absolute local path.");
		}
		$validated["executable"] = $settings["executable"];
	}
	return $validated;
}

/** Validate explicit simulator paths, endpoint, and service settings before the configuration is used. */
function nms_snmpsim_validate_config($settings)
{
	if (!is_array($settings)) {
		throw new RuntimeException("Invalid SNMPSim configuration JSON.");
	}
	nms_snmpsim_configured_poller($settings);
	if (($settings["activation"] ?? "") !== "systemd") {
		throw new RuntimeException("Managed SNMPSim configuration requires activation = systemd.");
	}
	if (isset($settings["auto_start"]) && !is_bool($settings["auto_start"])) {
		throw new RuntimeException("SNMPSim auto_start must be a boolean.");
	}
	foreach (["executable", "data_dir", "systemctl", "lock_path"] as $key) {
		if (!nms_snmpsim_local_path($settings[$key] ?? null, "Linux")) {
			throw new RuntimeException("SNMPSim requires an absolute local " . $key . " path.");
		}
	}
	if (isset($settings["status_path"]) && !nms_snmpsim_local_path($settings["status_path"], "Linux")) {
		throw new RuntimeException("SNMPSim status_path must be an absolute local path.");
	}
	foreach (["listen_address", "client_address"] as $key) {
		if (
			!isset($settings[$key]) ||
			!is_string($settings[$key]) ||
			!filter_var($settings[$key], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
		) {
			throw new RuntimeException("SNMPSim requires an explicit IPv4 " . $key . ".");
		}
	}
	if ($settings["client_address"] === "0.0.0.0") {
		throw new RuntimeException("Use a reachable client address, not 0.0.0.0.");
	}
	if (!isset($settings["port"]) || !is_int($settings["port"]) || $settings["port"] < 1 || $settings["port"] > 65535) {
		throw new RuntimeException("SNMPSim port must be an integer from 1 to 65535.");
	}
	if (
		!isset($settings["service"]) ||
		!is_string($settings["service"]) ||
		!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}\.service$/D', $settings["service"])
	) {
		throw new RuntimeException("Invalid SNMPSim systemd service name.");
	}
	return $settings;
}

/** Require an explicit native collector ID; validation of its live record occurs before use. */
function nms_snmpsim_configured_poller($settings)
{
	$id = $settings["poller_id"] ?? null;
	if (!is_int($id) || $id < 1 || $id > 4294967295) {
		throw new RuntimeException(
			"SNMPSim poller_id must be the positive integer ID of the Cacti collector that can reach this endpoint. Select it explicitly; no collector is assumed.",
		);
	}
	return $id;
}

/** Reject deleted/disabled collectors without redirecting imported devices to another collector. */
function nms_snmpsim_require_poller($settings)
{
	$id = nms_snmpsim_configured_poller($settings);
	if (!(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM poller WHERE id = ? AND disabled = ''", [$id])) {
		throw new RuntimeException(
			"The configured SNMPSim collector is missing or disabled in Cacti. Review poller_id before adding or probing a simulated device.",
		);
	}
	return $id;
}

/** A local PHP probe cannot establish reachability from a different native collector. */
function nms_snmpsim_require_probe_locality($device)
{
	global $config;
	$local_id = $config["poller_id"] ?? null;
	if (
		(!is_int($local_id) && !is_string($local_id)) ||
		!preg_match('/^[1-9][0-9]*$/D', (string) $local_id) ||
		(string) $local_id !== (string) $device["poller_id"]
	) {
		throw new RuntimeException(
			"This web process does not run on the configured simulator collector. Verify the device through that collector’s native Cacti poll cycle. No local probe or sample fallback was used.",
		);
	}
}

/** Configuration checks are not proof of a live SNMP response. */
function nms_snmpsim_status_badge($health)
{
	$control = $health["control_pending"] ?? "";
	if (in_array($control, ["start", "restart"], true)) {
		return ["label" => "SNMPSim — Starting", "tone" => "warning"];
	}
	if ($control === "stop") {
		return ["label" => "SNMPSim — Stopping", "tone" => "warning"];
	}
	$state = $health["service_state"] ?? "unavailable";
	if ($state === "manual") {
		return ["label" => "SNMPSim — Manual activation", "tone" => "warning"];
	}
	$labels = [
		"active" => "Active",
		"inactive" => "Inactive",
		"failed" => "Failed",
		"activating" => "Starting",
		"deactivating" => "Stopping",
	];
	return [
		"label" => "SNMPSim — " . ($labels[$state] ?? "Status unavailable"),
		"tone" =>
			$state === "active" ? "ready" : (in_array($state, ["inactive", "failed"], true) ? "offline" : "warning"),
	];
}

/**
 * Handles snmpsim queue control.
 */
function nms_snmpsim_queue_control($command)
{
	if (!in_array($command, ["start", "stop", "restart"], true)) {
		throw new InvalidArgumentException("Invalid simulator command.");
	}
	$settings = nms_snmpsim_config();
	if (($settings["activation"] ?? "") !== "systemd" || ($settings["ui_control"] ?? false) !== true) {
		throw new RuntimeException(
			"Install the updated managed responder worker and enable ui_control before using service controls.",
		);
	}
	$target = $settings["data_dir"] . "/.control.pending";
	$handle = @fopen($target, "x");
	if (!$handle) {
		throw new RuntimeException("A command is already queued, or the simulator data directory is not writable.");
	}
	try {
		if (fwrite($handle, $command . "\n") !== strlen($command) + 1) {
			throw new RuntimeException("Could not queue simulator command.");
		}
	} finally {
		fclose($handle);
	}
	return ucfirst($command) .
		" requested. The managed worker normally processes requests within 10 seconds. Refresh to check service status.";
}

/**
 * Handles snmpsim health.
 */
function nms_snmpsim_health()
{
	try {
		$settings = nms_snmpsim_config();
		$manual = ($settings["activation"] ?? "") === "manual";
		$control_pending = "";
		$control_path = $settings["data_dir"] . "/.control.pending";
		if (($settings["ui_control"] ?? false) === true && is_file($control_path) && !is_link($control_path)) {
			$candidate = trim((string) @file_get_contents($control_path, false, null, 0, 32));
			if (in_array($candidate, ["start", "stop", "restart"], true)) {
				$control_pending = $candidate;
			}
		}
		return [
			"config" => $settings,
			"error" => "",
			"control_pending" => $control_pending,
			"executable" => !$manual && is_file($settings["executable"]) && is_executable($settings["executable"]),
			"data_writable" => is_dir($settings["data_dir"]) && is_writable($settings["data_dir"]),
			"reload_pending" =>
				file_exists($settings["data_dir"] . "/.reload.pending") ||
				file_exists($settings["data_dir"] . "/.reload.processing"),
			"service_state" => $manual
				? "manual"
				: nms_snmpsim_service_state(
					$settings["service"],
					$settings["systemctl"] ?? "",
					$settings["status_path"] ?? "",
				),
		];
	} catch (Throwable $exception) {
		return ["config" => null, "error" => $exception->getMessage()];
	}
}

/** Read only: PHP never starts/stops services or executes the configured responder. */
function nms_snmpsim_service_state($service, $executable = "", $status_path = "")
{
	if (PHP_OS_FAMILY !== "Linux") {
		return "unavailable";
	}
	require_once __DIR__ . "/platform/systemd.php";
	return nms_snmpsim_systemd_state($service, $executable, $status_path);
}

/** Resolve each record independently; templates alone do not imply a simulated device. */
function nms_snmpsim_import_defaults($import_id)
{
	$settings = nms_snmpsim_config();
	$poller_id = nms_snmpsim_require_poller($settings);
	$record = db_fetch_row_prepared(
		"SELECT id, community, host_template_id FROM plugin_nms_snmprec_imports WHERE id = ?",
		[(int) $import_id],
	);
	if (!$record) {
		throw new InvalidArgumentException("Select an existing imported simulator record.");
	}
	if (
		!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/D', $record["community"]) ||
		!is_readable($settings["data_dir"] . "/" . $record["community"] . ".snmprec")
	) {
		throw new RuntimeException(
			"This record is not readable in the configured simulator data directory. Check the directory configuration or migrate the record before adding its device.",
		);
	}
	return [
		"hostname" => $settings["client_address"],
		"snmp_port" => $settings["port"],
		"snmp_community" => $record["community"],
		"host_template_id" => (int) $record["host_template_id"],
		"snmp_version" => 2,
		"availability_method" => AVAIL_SNMP,
		"proxy" => true,
		"poller_id" => $poller_id,
	];
}

/** On-demand protocol check using Cacti SNMP, never the sample value in the record file. */
function nms_snmpsim_probe_import($import_id)
{
	global $config, $snmp_error;
	require_once $config["base_path"] . "/lib/snmp.php";
	require_once __DIR__ . "/inventory.php";
	$device = nms_snmpsim_import_defaults($import_id);
	nms_snmpsim_require_probe_locality($device);
	// Reuse configured native retry/timeout policy, not a private simulator policy.
	$timeout = read_config_option("snmp_timeout");
	$retries = read_config_option("snmp_retries");
	if (
		(!is_int($timeout) && !is_string($timeout)) ||
		!preg_match('/^[1-9][0-9]*$/D', (string) $timeout) ||
		(!is_int($retries) && !is_string($retries)) ||
		!preg_match('/^(0|[1-9][0-9]*)$/D', (string) $retries)
	) {
		throw new RuntimeException("Configure valid native Cacti SNMP timeout and retry settings before probing.");
	}
	$oid = db_fetch_cell_prepared("SELECT oid FROM plugin_nms_snmprec_oids WHERE import_id = ? ORDER BY id LIMIT 1", [
		(int) $import_id,
	]);
	if (!$oid) {
		throw new RuntimeException("This imported record has no OID to test.");
	}
	$snmp_error = "";
	$value = cacti_snmp_get(
		$device["hostname"],
		$device["snmp_community"],
		$oid,
		2,
		"",
		"",
		"",
		"",
		"",
		"",
		$device["snmp_port"],
		(int) $timeout,
		(int) $retries,
		"NMS simulator check",
		"",
		SNMP_STRING_OUTPUT_ASCII,
	);
	if (nms_inventory_snmp_failed($value)) {
		throw new RuntimeException(
			"Live SNMP check failed. Check the responder service, endpoint, community and activation queue. No fallback value was used.",
		);
	}
	return "Live SNMP response received for OID " . $oid . ": " . (string) $value;
}
