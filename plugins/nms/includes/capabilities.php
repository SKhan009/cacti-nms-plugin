<?php
/** Read-only FCAPS evidence from Cacti. No duplicated credentials, collector settings or time series. */
require_once __DIR__ . "/functions.php";

/** Separate configuration, actual evidence and unsupported integrations in a device capability view. */
function nms_device_capabilities($host)
{
	$id = (int) $host["id"];
	$methods = db_fetch_assoc_prepared(
		'SELECT DISTINCT di.id, di.name, di.type_id
		FROM data_local AS dl INNER JOIN data_template_data AS dtd ON dtd.local_data_id = dl.id
		INNER JOIN data_input AS di ON di.id = dtd.data_input_id WHERE dl.host_id = ? ORDER BY di.name',
		[$id],
	);
	$parameters = db_fetch_assoc_prepared(
		"SELECT raw_value, last_seen FROM plugin_nms_device_parameters WHERE host_id = ?",
		[$id],
	);
	$current = 0;
	foreach ($parameters as $parameter) {
		$parameter["host_status"] = $host["status"];
		$parameter["host_last_updated"] = $host["last_updated"];
		if (nms_parameter_has_current_value($parameter)) {
			$current++;
		}
	}
	$poller_items = (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM poller_item WHERE host_id = ?", [$id]);
	$disabled = ($host["disabled"] ?? "") !== "";
	$fresh_host = nms_parameter_is_fresh($host["last_updated"]);
	$availability = $disabled
		? "disabled"
		: (!$fresh_host
			? "stale"
			: ((int) $host["status"] === HOST_UP
				? "healthy"
				: "failed"));
	$performance = $disabled
		? "disabled"
		: ($poller_items === 0
			? "unconfigured"
			: (!$fresh_host
				? "stale"
				: ((int) $host["status"] !== HOST_UP
					? "failed"
					: ($current > 0
						? "collecting"
						: "no current samples"))));
	$result = [
		[
			"area" => "Fault",
			"capability" => "Device availability",
			"provider" => "Cacti native availability method",
			"state" => $availability,
			"detail" => "Last Cacti update: " . $host["last_updated"] . ". Category rules evaluate this native state.",
		],
		[
			"area" => "Performance",
			"capability" => "Configured device/interface/component measurements",
			"provider" => "Cacti poller, data queries and RRDs",
			"state" => $performance,
			"detail" =>
				$current .
				" current values across " .
				$poller_items .
				" poller items. Raw counter samples are not calculated traffic rates. Use native graphs for rates.",
		],
		[
			"area" => "Configuration",
			"capability" => "Monitoring configuration",
			"provider" => "Cacti device and template APIs",
			"state" => "available",
			"detail" =>
				"Manage collection settings here or in Cacti. This is not remote device configuration backup/restore.",
		],
		[
			"area" => "Security",
			"capability" => "Dashboard access control",
			"provider" => "Cacti authentication, realms and device permissions",
			"state" => "available",
			"detail" => "Dashboard authorization is not a measurement of the managed device’s security posture.",
		],
	];
	$plugins = db_fetch_assoc("SELECT directory, status FROM plugin_config");
	$installed = [];
	foreach ($plugins as $plugin) {
		$installed[$plugin["directory"]] = (int) $plugin["status"];
	}
	foreach (
		[
			["Fault", "Threshold integration", "thold"],
			["Fault", "Syslog ingestion", "syslog"],
			["Configuration", "Remote configuration backup/restore", "routerconfigs"],
		]
		as $optional
	) {
		$found = array_key_exists($optional[2], $installed);
		$result[] = [
			"area" => $optional[0],
			"capability" => $optional[1],
			"provider" => $optional[2],
			"state" => !$found
				? "not installed"
				: ($installed[$optional[2]] !== 1
					? "disabled"
					: "integration pending"),
			"detail" =>
				"No NMS adapter is connected. An installed plugin alone does not establish model/protocol support or successful collection.",
		];
	}
	foreach (
		[
			["Fault", "SNMP trap reception"],
			["Accounting", "Usage accounting / NetFlow / IPFIX"],
			["Configuration", "LLDP/CDP connection discovery"],
			["Security", "Managed-device security events"],
		]
		as $future
	) {
		$result[] = [
			"area" => $future[0],
			"capability" => $future[1],
			"provider" => "No configured NMS adapter",
			"state" => "unavailable",
			"detail" =>
				"Requires a supported collector/integration and verified device protocol capability. No traffic, connections or events are fabricated.",
		];
	}
	return ["methods" => $methods, "capabilities" => $result];
}
