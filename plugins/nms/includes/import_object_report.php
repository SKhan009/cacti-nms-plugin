<?php
/**
 * Handles import object report.
 */
function nms_import_object_report($rows)
{
	global $config;
	print '<div class="nms-core-report-wrap"><table class="nms-table nms-core-report"><thead><tr><th>OID / metric</th><th>Data template</th><th>Graph template</th><th>Active data sources</th><th>Graphs</th></tr></thead><tbody>';
	foreach ($rows as $r) {
		print "<tr><td>" . nms_h($r["oid"]) . "<br>" . nms_h($r["name"]) . "</td>";
		foreach (
			[
				"data_template_id" => ["data_templates.php?action=template_edit&id=", "data_template"],
				"graph_template_id" => ["graph_templates.php?action=template_edit&id=", "graph_templates"],
			]
			as $key => $route
		) {
			$name = db_fetch_cell_prepared("SELECT name FROM " . $route[1] . " WHERE id=?", [$r[$key]]);
			print '<td><a href="' .
				nms_h($config["url_path"] . $route[0] . (int) $r[$key]) .
				'">Edit ' .
				nms_h($name ?: "deleted object") .
				" (#" .
				(int) $r[$key] .
				")</a></td>";
		}
		print "<td>";
		if (!$r["data_ids"]) {
			print "Not instantiated — assign to a device first.";
		}
		foreach ($r["data_ids"] as $id) {
			$name = db_fetch_cell_prepared("SELECT name_cache FROM data_template_data WHERE local_data_id=?", [$id]);
			print '<a href="' .
				nms_h($config["url_path"] . "data_sources.php?action=ds_edit&id=" . (int) $id) .
				'">Edit ' .
				nms_h($name ?: "Data source") .
				" (#" .
				(int) $id .
				")</a><br>";
		}
		print "</td><td>";
		foreach ($r["graph_ids"] ?? array_filter([$r["graph_id"] ?? 0]) as $id) {
			print '<a href="' .
				nms_h($config["url_path"] . "graphs.php?action=graph_edit&id=" . (int) $id) .
				'">Edit graph #' .
				(int) $id .
				"</a><br>";
		}
		print "</td></tr>";
	}
	print "</tbody></table></div>";
}
