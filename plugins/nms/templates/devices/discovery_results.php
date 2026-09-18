<?php
$nd_host = null;
foreach (nms_nd_hosts() as $h) {
	if ((int) $h["id"] === (int) $device_values["id"]) {
		$nd_host = $h;
	}
}
$nd_samples = array_column(
	db_fetch_assoc_prepared("SELECT * FROM plugin_nms_discovery_snapshots WHERE host_id=?", [$device_values["id"]]),
	null,
	"protocol",
);
?>
<section class="nms-panel" id="connection-discovery-results"><div class="nms-panel-head"><h2>Connection discovery results</h2>
<form method="post" action="devices.php?tab=edit&amp;id=<?php print (int) $device_values["id"]; ?>">
<input type="hidden" name="__csrf_magic" value="<?php print nms_h(
	$nms_csrf_token,
); ?>"><input type="hidden" name="id" value="<?php print (int) $device_values[
	"id"
]; ?>"><input type="hidden" name="nms_action" value="discovery_test">
<button type="submit" <?php if (!$nd_host || !$nd_host["enabled"] || !$nd_host["collection_enabled"]) {
	print "disabled";
} ?>>Test selected methods</button></form></div>
<?php if (isset_request_var("discovery_queued")) { ?><p role="status"><?php print count(
	array_filter($nd_samples, function ($s) {
		return in_array($s["status"], ["queued", "running"], true);
	}),
)
	? "Test queued on the assigned Cacti collector. Refresh after its next poll cycle."
	: "Test completed. Results below show the latest collection outcome."; ?></p><?php } ?>
<?php if ($nd_host) { ?><p>SNMP endpoint: <?php print nms_h(
	$nd_host["hostname"] . " · UDP port " . $nd_host["snmp_port"] . " · SNMP v" . $nd_host["snmp_version"],
); ?>.
<?php if (
	in_array(strtolower($nd_host["hostname"]), ["localhost", "127.0.0.1", "::1"], true)
) { ?> This is the assigned collector's loopback address. For SNMPSim, verify its listening port and dataset/community; port 161 may reach the collector's own SNMP agent.<?php } ?></p><?php } ?>
<div class="nms-table-wrap"><table class="nms-table"><thead><tr><th>Method</th><th>Result</th><th>Last attempt</th><th>Last success</th><th>Details</th></tr></thead><tbody>
<?php foreach ($nd_host ? nms_nd_host_methods($nd_host) : [] as $method) {

	$s = $nd_samples[$method] ?? null;
	$data = $s ? json_decode($s["data_json"], true) : [];
	$status = $s ? $s["status"] : "Not collected";
	if (
		$s &&
		(!hash_equals($s["config_hash"], nms_nd_hash($nd_host)) ||
			!$nd_host["enabled"] ||
			!$nd_host["collection_enabled"])
	) {
		$status = "Paused / configuration changed";
	} elseif (
		$s &&
		$status === "success" &&
		!nms_nd_evidence_fresh($data["collected"] ?? 0, time(), $nd_host["stale_seconds"])
	) {
		$status = "Stale";
	}
	$detail = $s ? $s["error"] : "";
	if (!$detail && $data) {
		$detail =
			count($data["neighbors"] ?? []) .
			" neighbour observations; " .
			count($data["endpoints"] ?? []) .
			" endpoint observations. " .
			($data["scope"] ?? "");
	}
	?><tr><td><?php print nms_h(nms_nd_method_labels()[$method]); ?></td><td><?php print nms_h(
	$status,
); ?></td><td><?php print nms_h($s["attempted_at"] ?? "—"); ?></td><td><?php print nms_h(
	$s["succeeded_at"] ?? "—",
); ?></td><td><?php print nms_h($detail); ?></td></tr><?php
} ?>
<?php if (
	!$nd_host
) { ?><tr><td colspan="5">No enabled core device with a discovery preset. Assign a preset and save above.</td></tr><?php } ?>
</tbody></table></div></section>

<?php $identity = nms_identity_observation((int) $device_values["id"]); ?>
<section class="nms-panel" id="discovered-hardware">
<div class="nms-panel-head"><div><h2>Discovered identity and ports</h2><p>Automatic SNMP observations. Manual entries above are preserved. Test selected methods refreshes these details on the collector.</p></div></div>
<div class="nms-identity-summary">
<?php if (
	!$identity
) { ?><p>No current discovery snapshot. Save an enabled preset and run its test. Failed, stale or changed connections do not supply current identity.</p><?php } else { ?>
<p>Last successful observation: <?php print nms_h($identity["time"]); ?></p>
<dl><dt>LLDP chassis ID</dt><dd><?php print nms_h(
	$identity["chassis_id"] ?: "Not reported by selected methods",
); ?></dd>
<dt>Chassis serial / model (ENTITY-MIB)</dt><dd><?php
foreach ($identity["hardware"]["chassis"] ?? [] as $chassis) { ?><div><?php print nms_h(
	($chassis["name"] ?: "Chassis") .
		" — " .
		($chassis["serial"] ?: "Serial not reported") .
		" — " .
		($chassis["model"] ?: "Model not reported"),
); ?></div><?php }
if (empty($identity["hardware"]["chassis"])) {
	print "Not reported";
}
?></dd>
<dt>Reported physical ports (ENTITY-MIB)</dt><dd><?php print !empty($identity["hardware"]["physical_ports"])
	? count($identity["hardware"]["physical_ports"])
	: "Not reported"; ?></dd>
<dt>Interfaces (IF-MIB, including logical interfaces)</dt><dd><?php print count($identity["interfaces"]); ?></dd></dl>
<?php if (!empty($identity["hardware"]["error"])) { ?><p>Hardware inventory unavailable: <?php print nms_h(
	$identity["hardware"]["error"],
); ?></p><?php } ?>
<p>Each interface can have its own MAC address. An ifIndex is an SNMP identifier, not the number printed on the chassis.</p>
<?php } ?></div>
<?php if (
	$identity
) { ?><div class="nms-table-wrap"><table class="nms-table"><thead><tr><th>Interface / ifIndex</th><th>MAC address</th><th>Admin / operational state</th><th>Connected neighbour / remote port</th></tr></thead><tbody>
<?php
$states = [
	1 => "Up",
	2 => "Down",
	3 => "Testing",
	4 => "Unknown",
	5 => "Dormant",
	6 => "Not present",
	7 => "Lower layer down",
];
foreach ($identity["interfaces"] as $port) { ?>
<tr><td><?php print nms_h(
	($port["name"] ?: $port["description"]) . " / " . $port["index"],
); ?></td><td><?php print nms_h(
	!empty($port["mac_hex"]) ? implode(":", str_split($port["mac_hex"], 2)) : "Not reported",
); ?></td><td><?php print nms_h(
	($states[$port["admin"]] ?? "Unknown") . " / " . ($states[$port["oper"]] ?? "Unknown"),
); ?></td><td><?php
$connections = [];
foreach ($identity["neighbors"] as $neighbor) {
	if ((int) $neighbor["local_ifindex"] === (int) $port["index"]) {
		$connections[] = ($neighbor["remote_name"] ?: $neighbor["peer_label"]) . " → " . $neighbor["remote_port"];
	}
}
print nms_h($connections ? implode("; ", $connections) : "No current direct neighbour reported");
?></td></tr>
<?php }
?></tbody></table></div>
<?php if (
	!empty($identity["hardware"]["physical_ports"])
) { ?><div class="nms-table-wrap"><table class="nms-table"><thead><tr><th>Physical port name</th><th>Entity index</th><th>Parent entity</th><th>Position within parent</th></tr></thead><tbody><?php foreach (
	$identity["hardware"]["physical_ports"]
	as $port
) { ?><tr><td><?php print nms_h($port["name"] ?: "Not reported"); ?></td><td><?php print (int) $port[
	"entity_index"
]; ?></td><td><?php print nms_h($port["parent"] ?? "Unknown"); ?></td><td><?php print isset($port["position"]) &&
$port["position"] >= 0
	? (int) $port["position"]
	: "Unknown"; ?></td></tr><?php } ?></tbody></table></div><?php } ?>
<?php } ?></section>

<?php
// Show observations for this permitted device even when local/remote port resolution fails.
require_once __DIR__ . "/../../includes/topology/discovery.php";
require_once __DIR__ . "/../../includes/discovery_display.php";
require_once __DIR__ . "/../../includes/discovery_endpoints.php";
$nd_view = nms_topology_discovery((int) ($device_values["site_id"] ?? 0));
$nd_warnings = nms_nd_identity_warnings($nd_view["snapshots"]);
$nd_endpoint_view = nms_nd_correlate_endpoints($nd_view["snapshots"], array_values($nd_view["hosts"]));
$nd_observations = array_filter(array_merge($nd_view["unresolved"] ?? [], $nd_endpoint_view["observations"]), function (
	$o,
) use ($device_values) {
	return (int) $o["host_id"] === (int) $device_values["id"];
});
?>
<section class="nms-panel"><div class="nms-panel-head"><h2>Neighbour and endpoint observations</h2></div>
<?php foreach ($nd_warnings[(int) $device_values["id"]] ?? [] as $warning) { ?><p role="status"><?php print nms_h(
	$warning,
); ?></p><?php } ?>
<?php foreach (array_slice($nd_observations, 0, 100) as $o) { ?><p><?php print nms_h(
	nms_nd_observation_text($o),
); ?></p><?php } ?>
<?php if (count($nd_observations) > 100) { ?><p>Showing 100 of <?php print count(
	$nd_observations,
); ?> observations. Inspect the topology map for more.</p><?php } ?>
<p>IP-to-MAC mappings describe observed addresses. LLDP/CDP observations identify advertised neighbours; unresolved observations remain visible until a unique device and port can be matched.</p>
</section>
