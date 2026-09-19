<?php
/**
 * @file inventory.php
 * Collect live text inventory, including serial identity, using Cacti SNMP connection settings.
 * Retain observed identity and its baseline separately from numeric RRD data; imported values are never live substitutes.
 */

require_once __DIR__ . "/functions.php";

/**
 * Return true only for a failed live SNMP result.  Imported .snmprec values are
 * definitions for the simulator/template and are intentionally never returned
 * from this path as fallback readings.
 */
function nms_inventory_snmp_failed($value)
{
	$value = trim((string) $value);
	return $value === "" ||
		in_array(strtolower($value), ["u", "unknown", "null", "nan"], true) ||
		stripos($value, "no such") !== false ||
		stripos($value, "timeout") !== false;
}

/** Use Cacti's process identity; never turn a missing collector into the primary collector. */
function nms_inventory_collector_id()
{
	global $config, $poller_id;
	$id = $config["poller_id"] ?? null;
	if (
		(!is_int($id) && !is_string($id)) ||
		!preg_match('/^[1-9][0-9]*$/D', (string) $id) ||
		(float) $id > 4294967295
	) {
		throw new RuntimeException("NMS inventory requires the native Cacti collector identity. No device was probed.");
	}
	// A CLI --poller override must not pretend that this process moved to another host.
	if (isset($poller_id) && (string) $poller_id !== (string) $id) {
		throw new RuntimeException(
			"NMS inventory collector override differs from the configured process identity. Run collection on the assigned collector.",
		);
	}
	if (!(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM poller WHERE id = ? AND disabled = ''", [(int) $id])) {
		throw new RuntimeException("NMS inventory collector is missing or disabled in Cacti. No device was probed.");
	}
	return (int) $id;
}

/**
 * Poll the non-RRD inventory OIDs assigned to imported Cacti host templates.
 * Host connection details come from Cacti core and cacti_snmp_get() performs
 * the request on the owning collector only. Database failures propagate to the
 * isolated plugin hook; a failed write is never counted as a successful run.
 * Only the latest text identity and its baseline are retained.
 */
/**
 * Collects collect inventory values.
 */
function nms_collect_inventory_values($only_host_id = 0)
{
	global $config, $snmp_error;
	include_once $config["base_path"] . "/lib/snmp.php";

	$params = [nms_inventory_collector_id()];
	$host_filter = "";
	if ((int) $only_host_id > 0) {
		$host_filter = " AND h.id = ?";
		$params[] = (int) $only_host_id;
	}
	$definitions = db_fetch_assoc_prepared(
		"SELECT h.id AS host_id, h.hostname, h.status,
		h.snmp_community, h.snmp_version, h.snmp_username, h.snmp_password,
		h.snmp_auth_protocol, h.snmp_priv_passphrase, h.snmp_priv_protocol,
		h.snmp_context, h.snmp_engine_id, h.snmp_port, h.snmp_timeout,
		o.inventory_key, o.oid, i.id AS import_id
		FROM host AS h
		INNER JOIN plugin_nms_snmprec_imports AS i ON i.host_template_id = h.host_template_id
		INNER JOIN plugin_nms_snmprec_oids AS o ON o.import_id = i.id AND o.inventory_key != ''
		WHERE h.poller_id = ? AND h.deleted = '' AND h.disabled = ''$host_filter
		ORDER BY h.id, o.inventory_key, i.id DESC, o.id",
		$params,
	);
	$seen = [];
	$attempted = 0;
	$snmp_retries = read_config_option("snmp_retries");
	if (
		(!is_int($snmp_retries) && !is_string($snmp_retries)) ||
		!preg_match('/^(0|[1-9][0-9]*)$/D', (string) $snmp_retries) ||
		(float) $snmp_retries > PHP_INT_MAX
	) {
		throw new RuntimeException(
			"NMS inventory requires valid native Cacti SNMP retries; no private retry default was used.",
		);
	}
	$snmp_retries = (int) $snmp_retries;

	foreach ($definitions as $definition) {
		$key = (int) $definition["host_id"] . ":" . $definition["inventory_key"];
		if (isset($seen[$key])) {
			continue;
		}
		$seen[$key] = true;
		$attempted++;
		$display_name = nms_inventory_display_name($definition["inventory_key"]);

		if ((int) $definition["snmp_version"] === 0) {
			$state = "unconfigured";
			$error = "SNMP is disabled on this Cacti device; live inventory polling was not attempted.";
			nms_category_execute(
				"INSERT INTO plugin_nms_device_inventory
				(host_id, inventory_key, oid, display_name, status, last_attempt, last_error)
				VALUES (?, ?, ?, ?, ?, NOW(), ?)
				ON DUPLICATE KEY UPDATE oid = VALUES(oid), display_name = VALUES(display_name),
					status = VALUES(status), last_attempt = NOW(), last_error = VALUES(last_error)",
				[
					$definition["host_id"],
					$definition["inventory_key"],
					$definition["oid"],
					$display_name,
					$state,
					$error,
				],
			);
			continue;
		}

		$snmp_error = "";
		$value = cacti_snmp_get(
			$definition["hostname"],
			$definition["snmp_community"],
			$definition["oid"],
			$definition["snmp_version"],
			$definition["snmp_username"],
			$definition["snmp_password"],
			$definition["snmp_auth_protocol"],
			$definition["snmp_priv_passphrase"],
			$definition["snmp_priv_protocol"],
			$definition["snmp_context"],
			$definition["snmp_port"],
			$definition["snmp_timeout"],
			$snmp_retries,
			"NMS Inventory",
			$definition["snmp_engine_id"],
			SNMP_STRING_OUTPUT_ASCII,
		);

		if (nms_inventory_snmp_failed($value)) {
			// Backend diagnostics can include command arguments and credentials. Store
			// an actionable description, never the unfiltered global SNMP error string.
			$error =
				"SNMP returned no valid current value for " .
				$definition["oid"] .
				". Check the endpoint, credentials and protocol configured in Cacti.";
			nms_category_execute(
				"INSERT INTO plugin_nms_device_inventory
				(host_id, inventory_key, oid, display_name, status, last_attempt, last_error)
				VALUES (?, ?, ?, ?, 'failed', NOW(), ?)
				ON DUPLICATE KEY UPDATE oid = VALUES(oid), display_name = VALUES(display_name),
					status = 'failed', last_attempt = NOW(), last_error = VALUES(last_error)",
				[
					$definition["host_id"],
					$definition["inventory_key"],
					$definition["oid"],
					$display_name,
					substr($error, 0, 255),
				],
			);
			continue;
		}

		$value = substr(trim((string) $value, " \t\n\r\0\x0B\""), 0, 512);
		$current = db_fetch_row_prepared(
			'SELECT baseline_value, observed_value
			FROM plugin_nms_device_inventory WHERE host_id = ? AND inventory_key = ?',
			[$definition["host_id"], $definition["inventory_key"]],
		);
		$baseline = cacti_sizeof($current) ? trim((string) $current["baseline_value"]) : "";
		if ($baseline === "") {
			$baseline = $value;
		}
		$status = strcasecmp($baseline, $value) === 0 ? "ok" : "changed";
		nms_category_execute(
			"INSERT INTO plugin_nms_device_inventory
			(host_id, inventory_key, oid, display_name, baseline_value, observed_value,
			 status, last_attempt, last_success, last_error)
			VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), '')
			ON DUPLICATE KEY UPDATE oid = VALUES(oid), display_name = VALUES(display_name),
				baseline_value = VALUES(baseline_value), observed_value = VALUES(observed_value),
				status = VALUES(status), last_attempt = NOW(), last_success = NOW(), last_error = ''",
			[
				$definition["host_id"],
				$definition["inventory_key"],
				$definition["oid"],
				$display_name,
				$baseline,
				$value,
				$status,
			],
		);
	}

	// A collector can have a partial/offline host cache. Absence from that cache is
	// not authorization to erase stored inventory for other collectors or devices.
	// Device removal/retention belongs to a separate reviewed management workflow.
	return $attempted;
}
