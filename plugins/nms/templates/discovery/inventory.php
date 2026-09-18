<?php
/** Discovery inventory shows collected observations even when no managed-device link resolves. */
$canvas = nms_canvas_data(null);
$discovery = nms_topology_discovery(null);
?>
<p>Collected device, neighbour and endpoint evidence. Viewing this list does not start discovery.</p>
<?php if (!$canvas["ready"]) { ?><p role="status"><?php print nms_h($canvas["message"]); ?></p><?php } ?>
<section class="nms-panel"><div class="nms-catalog-scroll"><table class="nms-table">
<thead><tr><th>Device / address</th><th>Cacti status</th><th>Methods / last collection</th><th>Ports / interfaces</th><th>Connection endpoints</th><th>Configure</th></tr></thead><tbody>
<?php foreach ($canvas["nodes"] as $node) { ?>
<tr><td><?php print nms_h($node["name"]); ?><br><?php print nms_h($node["address"]); ?>
<?php foreach (["chassis_id" => "Chassis", "serial" => "Serial", "mac" => "MAC"] as $key => $label) {
	if (!empty($node["identity"][$key])) {
		print "<small>" . nms_h($label . ": " . $node["identity"][$key]) . "</small>";
	}
} ?>
<?php foreach ($node["discovery_warnings"] ?? [] as $warning) { ?><p role="status"><?php print nms_h(
	$warning,
); ?></p><?php } ?></td>
<td><?php print [0 => "Unknown", 1 => "Down", 2 => "Recovering", 3 => "Up"][$node["status"]] ?? "Unknown"; ?></td>
<td><?php
$found = false;
foreach ($discovery["snapshots"] as $snapshot) {
	if ((int) $snapshot["host_id"] === $node["id"]) {
		$found = true;
		$state = $snapshot["valid"] ? $snapshot["status"] : "Configuration changed";
		print nms_h(
			strtoupper($snapshot["protocol"]) .
				" · " .
				$state .
				" · " .
				($snapshot["succeeded_at"] ?: $snapshot["attempted_at"] ?: "No attempt"),
		) . "<br>";
		if (!empty($snapshot["error"])) {
			print "<small>" . nms_h($snapshot["error"]) . "</small>";
		}
	}
}
if (!$found) {
	print "Not collected";
}
?></td>
<td><?php print $node["physical_port_count"] ?: "Unknown"; ?> physical / <?php print count($node["interfaces"]) ?:
 	"Unknown"; ?> interfaces<small><?php print nms_h($node["physical_port_source"]); ?></small></td>
<td><details open><summary><?php
$links = array_values(
	array_filter($canvas["links"], function ($l) use ($node) {
		return $l["a"] === $node["id"] || $l["b"] === $node["id"];
	}),
);
print count($links);
?> resolved connections</summary>
<?php
foreach ($links as $link) {
	$other = $link["a"] === $node["id"] ? $link["b"] : $link["a"];
	$remote = "";
	$remote_addr = "";
	foreach ($canvas["nodes"] as $n) {
		if ($n["id"] === $other) {
			$remote = $n["name"];
			$remote_addr = $n["address"];
		}
	}
	$local_port = $link["a"] === $node["id"] ? $link["a_port"] : $link["b_port"];
	$remote_port = $link["a"] === $node["id"] ? $link["b_port"] : $link["a_port"];
	$speed = (int) ($link["speed"] ?? 0);
	$bandwidth =
		$speed >= 1000000000
			? round($speed / 1000000000, 1) . " Gbps"
			: ($speed >= 1000000
				? round($speed / 1000000, 1) . " Mbps"
				: "Speed not reported");
	print "<div>" .
		nms_h(
			$local_port .
				" ↔ " .
				$remote .
				" " .
				$remote_addr .
				" / " .
				$remote_port .
				" · " .
				$bandwidth .
				" · " .
				$link["state"],
		) .
		"</div>";
}
if (!$links) {
	print "<p>No resolved connection.</p>";
}
?>
</details>
<?php $observations = array_values(
	array_filter($canvas["observations"], function ($o) use ($node) {
		return $o["host_id"] === $node["id"];
	}),
); ?>
<?php if ($observations) { ?><details open><summary><?php print count(
	$observations,
); ?> neighbour / endpoint observations</summary>
<?php foreach (array_slice($observations, 0, 100) as $o) { ?><p><?php print nms_h(
	($o["current"] ? "" : "Historical / stale · ") . $o["text"],
); ?></p><?php } ?>
<?php if (
	count($observations) > 100
) { ?><p>Showing the first 100 observations. Inspect the device on the topology map for more.</p><?php } ?>
</details><?php } elseif (
	!$links
) { ?><p>No neighbour observations collected. Check discovery settings and method results.</p><?php } ?>
</td><td><a href="devices.php?tab=edit&amp;id=<?php print $node["id"]; ?>">Edit device</a></td></tr>
<?php } ?></tbody></table></div></section>
