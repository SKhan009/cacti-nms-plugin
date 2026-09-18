<?php
/** Render factual creation results; templates are not called active data sources. */
function nms_record_import_summary($result)
{
	global $config;
	$id = (int) (is_array($result) ? $result["import_id"] ?? 0 : $result);
	$r = db_fetch_row_prepared(
		"SELECT i.*,h.name AS core_name FROM plugin_nms_snmprec_imports i LEFT JOIN host_template h ON h.id=i.host_template_id WHERE i.id=?",
		[$id],
	);
	if (!$r) {
		return;
	}
	$pairs = db_fetch_assoc_prepared(
		"SELECT o.oid,o.data_template_id,o.graph_template_id,d.name AS data_name,g.name AS graph_name FROM plugin_nms_snmprec_oids o JOIN data_template d ON d.id=o.data_template_id JOIN graph_templates g ON g.id=o.graph_template_id WHERE o.import_id=? AND o.graphable='on' ORDER BY o.id",
		[$id],
	);
	print '<section class="nms-form-message success nms-import-success" role="status"><h2>SNMP record imported</h2><p><strong>' .
		nms_h($r["original_name"]) .
		"</strong>: " .
		(int) $r["record_count"] .
		" OIDs validated; " .
		count($pairs) .
		" numeric readings configured; " .
		((int) $r["record_count"] - count($pairs)) .
		" non-graphable records retained in the simulator.</p>";
	print "<ul><li>Simulator record saved as <strong>" .
		nms_h($r["community"]) .
		".snmprec</strong>. Activation is queued or administrator-managed; use Check live SNMP to verify responses.</li><li>Cacti device template " .
		(!empty($result["host_template_created"]) ? "created" : "created or reused") .
		': <a href="' .
		nms_h($config["url_path"] . "host_templates.php?action=template_edit&id=" . (int) $r["host_template_id"]) .
		'">' .
		nms_h($r["core_name"]) .
		" (#" .
		(int) $r["host_template_id"] .
		")</a>.</li><li>" .
		count($pairs) .
		" data templates and " .
		count($pairs) .
		" graph templates created and linked to that device template.</li><li>This upload does not create a device or active data sources. Add a simulator device using this import to instantiate the data sources, graphs and native poller items.</li></ul>";
	print '<details><summary>Show every OID and its created Cacti templates</summary><div class="nms-table-wrap"><table class="nms-table"><thead><tr><th>OID</th><th>Data template created</th><th>Graph template created</th></tr></thead><tbody>';
	foreach ($pairs as $pair) {
		print "<tr><td>" . nms_h($pair["oid"]) . "</td>";
		foreach (
			[
				"data" => ["data_templates.php", $pair["data_template_id"]],
				"graph" => ["graph_templates.php", $pair["graph_template_id"]],
			]
			as $kind => $route
		) {
			print '<td><a href="' .
				nms_h($config["url_path"] . $route[0] . "?action=template_edit&id=" . (int) $route[1]) .
				'">' .
				nms_h($pair[$kind . "_name"]) .
				" (#" .
				(int) $route[1] .
				")</a></td>";
		}
		print "</tr>";
	}
	print '</tbody></table></div></details><p><a href="file_repository.php?imported=' .
		$id .
		'">View full OID / backend object report</a> · <a href="devices.php?tab=add&amp;snmpsim_import_id=' .
		$id .
		'">Add simulator device</a></p></section>';
}

/**
 * Handles mib import summary.
 */
function nms_mib_import_summary($result)
{
	global $config;
	if (!is_array($result) || empty($result["preview"])) {
		return;
	}
	$p = $result["preview"];
	$host = (int) $p["host_id"];
	if (!is_device_allowed($host)) {
		return;
	}
	$rows = $result["rows"];
	$count = count($rows);
	$ds = [];
	foreach ($rows as $r) {
		$ds = array_merge($ds, $r["data_ids"]);
	}
	print '<section class="nms-form-message success nms-import-success" role="status"><h2>MIB imported</h2><p><strong>' .
		nms_h(implode(", ", $p["files"] ?? $p["modules"])) .
		"</strong>: " .
		count($p["modules"]) .
		" modules validated; " .
		count($p["records"]) .
		" readable numeric instances found; " .
		$count .
		" selected; " .
		count($p["skipped"]) .
		" definitions skipped.</p>";
	print '<ul><li>Existing Cacti device: <a href="' .
		nms_h($config["url_path"] . "host.php?action=edit&id=" . $host) .
		'">' .
		nms_h(db_fetch_cell_prepared("SELECT description FROM host WHERE id=?", [$host])) .
		" (#" .
		$host .
		")</a>.</li><li>" .
		$count .
		" data templates, " .
		$count .
		" graph templates, " .
		count(array_unique($ds)) .
		" active data sources and " .
		$count .
		" graphs created or reused. Reimporting an existing device/OID reuses its objects.</li><li>Native SNMP poller items use the device’s Cacti connection settings. Future polling supplies readings and RRD history.</li><li>No simulator file, duplicate device or device template was created. The MIB supplies definitions; measurements come from the selected device.</li></ul><details><summary>Show every OID and its Cacti objects</summary>";
	nms_import_object_report($rows);
	print "</details></section>";
}
