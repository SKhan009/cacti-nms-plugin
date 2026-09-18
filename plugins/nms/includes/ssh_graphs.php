<?php
/** Native Cacti templates and device graphs; metric scripts read one shared SSH snapshot. */
require_once __DIR__ . "/graph_template_manager.php";
require_once $config["base_path"] . "/lib/api_data_source.php";
require_once $config["base_path"] . "/lib/utility.php";

function nms_ssh_native_save($row, $table)
{
	$id = (int) sql_save($row, $table);
	if (!$id) {
		throw new RuntimeException("Cacti could not save SSH object: " . $table);
	}
	return $id;
}

/**
 * Handles ssh graphs create.
 */
function nms_ssh_graphs_create($host_id)
{
	nms_require_device_access($host_id);
	nms_ssh_device($host_id);
	$lock = "nms_ssh_graphs";
	if ((int) db_fetch_cell_prepared("SELECT GET_LOCK(?,10)", [$lock]) !== 1) {
		throw new RuntimeException("SSH graph provisioning is busy.");
	}
	try {
		$profile = db_fetch_row(
			"SELECT id,step,heartbeat FROM data_source_profiles WHERE step=300 ORDER BY (`default`='on') DESC,id LIMIT 1",
		);
		if (!$profile) {
			throw new RuntimeException("Create a native Cacti data-source profile with a 300-second step first.");
		}
		$result = [];
		foreach (
			[
				"cpu_percent" => ["CPU utilization", "Percent", "100"],
				"memory_percent" => ["Memory utilization", "Percent", "100"],
				"uptime_seconds" => ["Uptime", "Seconds", "U"],
			]
			as $metric => $label
		) {
			$hash = md5("nms:ssh:linux-health-v1:input:" . $metric);
			$di = (int) db_fetch_cell_prepared("SELECT id FROM data_input WHERE hash=?", [$hash]);
			if (!$di) {
				$di = nms_ssh_native_save(
					[
						"id" => 0,
						"hash" => $hash,
						"name" => "NMS SSH Linux " . $label[0],
						"type_id" => 1,
						"input_string" =>
							'"<path_php_binary>" -q "<path_cacti>/plugins/nms/ssh/metrics.php" <host_id> ' . $metric,
					],
					"data_input",
				);
				nms_managed_object_record("data_input", $di);
			} elseif (!nms_managed_object_exists("data_input", $di)) {
				throw new RuntimeException("SSH input hash belongs to an unmanaged core object.");
			}
			$fields = [];
			foreach (["host_id", "value"] as $field) {
				$fh = md5("nms:ssh:linux-health-v1:" . $metric . ":" . $field);
				$fid = (int) db_fetch_cell_prepared("SELECT id FROM data_input_fields WHERE hash=?", [$fh]);
				if (!$fid) {
					$fid = nms_ssh_native_save(
						[
							"id" => 0,
							"hash" => $fh,
							"data_input_id" => $di,
							"name" => $field === "host_id" ? "Cacti device ID" : $label[0],
							"data_name" => $field,
							"input_output" => $field === "host_id" ? "in" : "out",
							"update_rra" => $field === "value" ? "on" : "",
							"sequence" => 1,
							"type_code" => $field === "host_id" ? "host_id" : "",
							"regexp_match" => $field === "host_id" ? '^[0-9]+$' : "",
							"allow_nulls" => "",
						],
						"data_input_fields",
					);
				}
				$fields[$field] = $fid;
			}
			$dh = md5("nms:ssh:linux-health-v1:template:" . $metric);
			$dt = (int) db_fetch_cell_prepared("SELECT id FROM data_template WHERE hash=?", [$dh]);
			if (!$dt) {
				$dt = nms_ssh_native_save(
					["id" => 0, "hash" => $dh, "name" => "NMS SSH Linux " . $label[0]],
					"data_template",
				);
				nms_managed_object_record("data_template", $dt);
			} elseif (!nms_managed_object_exists("data_template", $dt)) {
				throw new RuntimeException("SSH template hash belongs to an unmanaged core object.");
			}
			$dtd = (int) db_fetch_cell_prepared(
				"SELECT id FROM data_template_data WHERE data_template_id=? AND local_data_id=0",
				[$dt],
			);
			if (!$dtd) {
				$dtd = nms_ssh_native_save(
					[
						"id" => 0,
						"data_template_id" => $dt,
						"local_data_id" => 0,
						"local_data_template_data_id" => 0,
						"data_input_id" => $di,
						"name" => "|host_description| - SSH " . $label[0],
						"t_name" => "on",
						"active" => "on",
						"rrd_step" => 300,
						"data_source_profile_id" => $profile["id"],
					],
					"data_template_data",
				);
			}
			if (
				!db_execute_prepared(
					"INSERT IGNORE INTO data_input_data (data_input_field_id,data_template_data_id,t_value,value) VALUES (?,?,?,?)",
					[$fields["host_id"], $dtd, "", ""],
				)
			) {
				throw new RuntimeException("Cacti SSH input mapping failed.");
			}
			$rrd = (int) db_fetch_cell_prepared(
				"SELECT id FROM data_template_rrd WHERE data_template_id=? AND local_data_id=0",
				[$dt],
			);
			if (!$rrd) {
				$rrd = nms_ssh_native_save(
					[
						"id" => 0,
						"hash" => md5("nms:ssh:linux-health-v1:rrd:" . $metric),
						"data_template_id" => $dt,
						"local_data_id" => 0,
						"local_data_template_rrd_id" => 0,
						"data_source_name" => $metric,
						"data_source_type_id" => 1,
						"rrd_minimum" => "0",
						"rrd_maximum" => $label[2],
						"rrd_heartbeat" => (int) $profile["heartbeat"],
						"data_input_field_id" => $fields["value"],
					],
					"data_template_rrd",
				);
			}
			$gt = (int) db_fetch_cell_prepared(
				"SELECT DISTINCT i.graph_template_id FROM graph_templates_item i JOIN plugin_nms_managed_objects m ON m.object_id=i.graph_template_id AND m.object_type='graph_template' WHERE i.task_item_id=? AND i.local_graph_id=0",
				[$rrd],
			);
			if (!$gt) {
				$gt = nms_graph_template_create($rrd, "NMS SSH Linux " . $label[0], $label[1]);
			}
			if (
				!db_execute_prepared("REPLACE INTO host_graph (host_id,graph_template_id) VALUES (?,?)", [
					$host_id,
					$gt,
				])
			) {
				throw new RuntimeException("Cacti graph association failed.");
			}
			$local = (int) db_fetch_cell_prepared(
				"SELECT id FROM graph_local WHERE host_id=? AND graph_template_id=?",
				[$host_id, $gt],
			);
			if (!$local) {
				$suggested = [];
				$created = create_complete_graph_from_template($gt, $host_id, null, $suggested);
				if (empty($created["local_graph_id"])) {
					throw new RuntimeException("Cacti graph instantiation failed.");
				}
				$local = (int) $created["local_graph_id"];
				nms_managed_object_record("graph", $local);
				foreach ($created["local_data_id"] ?? [] as $data_id) {
					nms_managed_object_record("data_source", (int) $data_id);
					push_out_host($host_id, (int) $data_id);
				}
			}
			$result[$metric] = $local;
		}
		set_config_option("time_last_change_graph", time());
		set_config_option("time_last_change_data_source", time());
		return $result;
	} finally {
		db_fetch_cell_prepared("SELECT RELEASE_LOCK(?)", [$lock]);
	}
}
