<?php
if (isset_request_var("imported")) {
	$import_id = (int) get_filter_request_var("imported");
	$rows = [];
	foreach (
		db_fetch_assoc_prepared(
			"SELECT o.*,d.name FROM plugin_nms_snmprec_oids o LEFT JOIN data_template d ON d.id=o.data_template_id WHERE o.import_id=? AND o.graphable='on'",
			[$import_id],
		)
		as $r
	) {
		$r["data_ids"] = [];
		$r["graph_ids"] = [];
		foreach (
			db_fetch_assoc_prepared("SELECT id,host_id FROM data_local WHERE data_template_id=?", [
				$r["data_template_id"],
			])
			as $local
		) {
			if (is_device_allowed((int) $local["host_id"])) {
				$r["data_ids"][] = (int) $local["id"];
			}
		}
		foreach (
			db_fetch_assoc_prepared("SELECT id,host_id FROM graph_local WHERE graph_template_id=?", [
				$r["graph_template_id"],
			])
			as $local
		) {
			if (is_device_allowed((int) $local["host_id"])) {
				$r["graph_ids"][] = (int) $local["id"];
			}
		}
		$rows[] = $r;
	}
	if ($rows) {
		print '<section class="nms-panel"><div class="nms-panel-head"><h2>SNMP recording — Cacti object report</h2></div><p>Templates are created at import. Active data sources and graphs appear after device activation.</p>';
		nms_import_object_report($rows);
		print "</section>";
	}
}
