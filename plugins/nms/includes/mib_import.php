<?php
/** MIB definitions are parsed by Net-SNMP; reads and core writes use Cacti. */
require_once __DIR__ . "/discovery_snmp.php";
require_once __DIR__ . "/template_manager.php";
function nms_mib_command($args)
{
	$pipes = [];
	$p = proc_open($args, [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes, null, null, [
		"bypass_shell" => true,
	]);
	if (!is_resource($p)) {
		throw new RuntimeException("Net-SNMP snmptranslate is required on this Cacti server.");
	}
	fclose($pipes[0]);
	stream_set_blocking($pipes[1], false);
	stream_set_blocking($pipes[2], false);
	$out = "";
	$err = "";
	$deadline = microtime(true) + 10;
	$exit = -1;
	try {
		while (true) {
			$out .= stream_get_contents($pipes[1]);
			$err .= stream_get_contents($pipes[2]);
			$state = proc_get_status($p);
			if (strlen($out) + strlen($err) > 2097152 || microtime(true) > $deadline) {
				proc_terminate($p);
				throw new RuntimeException("MIB parsing exceeded its time or output limit.");
			}
			if (!$state["running"]) {
				$exit = $state["exitcode"];
				break;
			}
			usleep(10000);
		}
		$out .= stream_get_contents($pipes[1]);
		$err .= stream_get_contents($pipes[2]);
	} finally {
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($p);
	}
	if (
		$exit !== 0 ||
		preg_match(
			"/Cannot find module|Unlinked OID|Undefined identifier|Bad operator|Cannot adopt OID|Error in parsing/i",
			$err,
		)
	) {
		throw new RuntimeException(
			"MIB parsing failed. Install snmptranslate and supply valid MIBs with all imported dependencies. " .
				substr($err, 0, 500),
		);
	}
	return $out;
}
/**
 * Handles mib preview.
 */
function nms_mib_preview($upload, $host_id)
{
	global $config;
	nms_require_device_access($host_id);
	$host = db_fetch_row_prepared("SELECT * FROM host WHERE id=? AND deleted='' AND disabled=''", [$host_id]);
	if (!$host) {
		throw new InvalidArgumentException("Select an enabled Cacti device.");
	}
	$names = $upload["name"] ?? [];
	if (!is_array($names) || !count($names) || count($names) > 8) {
		throw new InvalidArgumentException("Upload 1–8 MIB files, including dependencies.");
	}
	$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "nms-mib-" . bin2hex(random_bytes(16));
	if (!mkdir($dir, 0700)) {
		throw new RuntimeException("Cannot create private MIB parsing directory.");
	}
	$modules = [];
	$symbols = [];
	$total = 0;
	try {
		foreach ($names as $i => $name) {
			if (
				($upload["error"][$i] ?? -1) !== UPLOAD_ERR_OK ||
				!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ["mib", "txt"], true)
			) {
				throw new InvalidArgumentException("Select .mib or .txt MIB definition files.");
			}
			$size = filesize($upload["tmp_name"][$i]);
			$total += $size;
			if ($size < 1 || $size > 1048576 || $total > 4194304) {
				throw new InvalidArgumentException("Limit: 1 MB per MIB and 4 MB total.");
			}
			$text = file_get_contents($upload["tmp_name"][$i]);
			if (
				strpos($text, "\0") !== false ||
				!preg_match("/\b([A-Za-z][A-Za-z0-9-]*)\s+DEFINITIONS\s*::=\s*BEGIN\b/", $text, $m)
			) {
				throw new InvalidArgumentException("The file does not contain a valid MIB module header.");
			}
			$module = $m[1];
			if (isset($modules[$module])) {
				throw new InvalidArgumentException("Duplicate MIB module.");
			}
			$modules[$module] = true;
			file_put_contents($dir . DIRECTORY_SEPARATOR . $module . ".txt", $text);
			preg_match_all(
				"/^\s*([A-Za-z][A-Za-z0-9-]*)\s+OBJECT-TYPE\b/m",
				preg_replace("/\bIMPORTS\b.*?;/s", "", $text),
				$matches,
			);
			foreach ($matches[1] as $symbol) {
				$symbols[$module . "::" . $symbol] = true;
			}
		}
		if (count($symbols) > 128) {
			throw new InvalidArgumentException(
				"Select MIB modules containing at most 128 OBJECT-TYPE definitions per preview.",
			);
		}
		$bin = read_config_option("path_snmptranslate") ?: "snmptranslate";
		$args = [$bin, "-M", "+" . $dir, "-m", implode(":", array_keys($modules))];
		nms_mib_command(array_merge($args, ["-Tz"]));
		$session = nms_nd_discovery_session($host);
		$records = [];
		$skipped = [];
		$deadline = microtime(true) + 25;
		$budget = 256;
		try {
			foreach (array_keys($symbols) as $symbol) {
				if (microtime(true) > $deadline) {
					throw new RuntimeException("Preview time limit reached; use smaller MIB modules.");
				}
				$definition = nms_mib_command(array_merge($args, ["-Td", "-On", $symbol]));
				if (
					!preg_match('/^\.?([0-9]+(?:\.[0-9]+)+)\s*$/m', $definition, $oid) ||
					!preg_match("/MAX-ACCESS\s+(read-only|read-write|read-create)/", $definition) ||
					!preg_match(
						"/SYNTAX\s+(Integer32|INTEGER|Unsigned32|Gauge32|Counter32|Counter64|TimeTicks)\b/",
						$definition,
						$type,
					)
				) {
					$skipped[] = $symbol . ": not a readable numeric object";
					continue;
				}
				if (preg_match('/SYNTAX[^\n]*\{/', $definition)) {
					$skipped[] = $symbol . ": enumerated state needs a deliberate graph mapping";
					continue;
				}
				$units = "";
				if (preg_match('/UNITS\s+"([^"]*)"/', $definition, $u)) {
					$units = $u[1];
				}
				try {
					$values = nms_nd_snmp_subtree($session, $oid[1], $deadline, $budget);
				} catch (RuntimeException $e) {
					throw new RuntimeException($symbol . ": " . $e->getMessage());
				}
				if (!$values) {
					$skipped[] = $symbol . ": no readable instances on selected device";
					continue;
				}
				foreach ($values as $instance => $v) {
					if (!in_array($v["type"], [2, 65, 66, 67, 70], true) || !is_numeric($v["value"])) {
						$skipped[] = $symbol . ": nonnumeric instance";
						continue;
					}
					$suffix = substr($instance, strlen($oid[1]));
					$records[] = [
						"oid" => $instance,
						"type" => $v["type"],
						"tag" => (string) $v["type"],
						"value" => "",
						"section" => $symbol . $suffix,
						"graphable" => true,
						"units" => $units,
						"reading_index" => 1,
						"reading_total" => 1,
					];
					if (count($records) > 64) {
						throw new InvalidArgumentException(
							"At most 64 numeric instances can be configured per import.",
						);
					}
				}
			}
		} finally {
			$session->close();
		}
		if (!$records) {
			throw new InvalidArgumentException(
				"No graphable instances found. " . implode("; ", array_slice($skipped, 0, 8)),
			);
		}
		return [
			"files" => array_map("basename", $names),
			"host_id" => $host_id,
			"modules" => array_keys($modules),
			"records" => $records,
			"skipped" => $skipped,
			"created" => time(),
			"token" => bin2hex(random_bytes(16)),
		];
	} finally {
		foreach (glob($dir . DIRECTORY_SEPARATOR . "*") as $file) {
			unlink($file);
		}
		rmdir($dir);
	}
}
/**
 * Handles mib create.
 */
function nms_mib_create($preview, $selected)
{
	$host_id = (int) $preview["host_id"];
	nms_require_device_access($host_id);
	$host = db_fetch_row_prepared("SELECT * FROM host WHERE id=? AND deleted='' AND disabled=''", [$host_id]);
	if (!$host) {
		throw new RuntimeException("Device is unavailable.");
	}
	$records = [];
	foreach ($preview["records"] as $i => $r) {
		if (in_array((string) $i, $selected, true)) {
			$records[] = $r;
		}
	}
	if (!$records) {
		throw new InvalidArgumentException("Select at least one metric.");
	}
	// Revalidate actual readings immediately before creating objects.
	$deadline = microtime(true) + 25;
	$session = nms_nd_discovery_session($host);
	try {
		foreach ($records as $r) {
			$v = nms_nd_snmp_scalar($session, $r["oid"], $deadline);
			if ($v["type"] !== $r["type"] || !is_numeric($v["value"])) {
				throw new RuntimeException("An OID changed type; run preview again.");
			}
		}
	} finally {
		$session->close();
	}
	$lock = "nms_mib_host_" . $host_id;
	if ((int) db_fetch_cell_prepared("SELECT GET_LOCK(?,10)", [$lock]) !== 1) {
		throw new RuntimeException("Import busy.");
	}
	$report = [];
	db_execute("START TRANSACTION");
	try {
		foreach ($records as $r) {
			$old = db_fetch_row_prepared("SELECT * FROM plugin_nms_mib_objects WHERE host_id=? AND oid=?", [
				$host_id,
				$r["oid"],
			]);
			if ($old) {
				$saved = json_decode($old["report_json"], true);
				if (
					!(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM graph_local WHERE id=? AND host_id=?", [
						$saved["graph_id"],
						$host_id,
					])
				) {
					throw new RuntimeException(
						"An existing imported graph was removed. Review its core report before importing again.",
					);
				}
				$report[] = $saved;
				continue;
			}
			$pair = nms_template_pair("MIB " . $host["description"], $r);
			if ($r["type"] === 2) {
				db_execute_prepared(
					"UPDATE data_template_rrd SET rrd_minimum='U',t_rrd_minimum='' WHERE data_template_id=? AND local_data_id=0",
					[$pair["data_template_id"]],
				);
			}
			foreach (
				["data_template_id" => "data_template", "graph_template_id" => "graph_template"]
				as $key => $kind
			) {
				nms_managed_object_record($kind, $pair[$key]);
			}
			if ($r["units"] !== "") {
				db_execute_prepared(
					"UPDATE graph_templates_graph SET vertical_label=? WHERE graph_template_id=? AND local_graph_id=0",
					[
						substr($r["units"] . (in_array($r["type"], [65, 70], true) ? "/s" : ""), 0, 20),
						$pair["graph_template_id"],
					],
				);
			}
			$suggested = [];
			$result = create_complete_graph_from_template($pair["graph_template_id"], $host_id, null, $suggested);
			if (empty($result["local_graph_id"])) {
				throw new RuntimeException("Cacti could not instantiate graph.");
			}
			nms_managed_object_record("graph", (int) $result["local_graph_id"]);
			foreach ((array) $result["local_data_id"] as $ds) {
				nms_managed_object_record("data_source", (int) $ds);
			}
			$row = array_merge($pair, [
				"oid" => $r["oid"],
				"name" => nms_template_record_label($r),
				"graph_id" => (int) $result["local_graph_id"],
				"data_ids" => array_values(array_map("intval", (array) $result["local_data_id"])),
			]);
			db_execute_prepared("REPLACE INTO host_graph(host_id,graph_template_id) VALUES (?,?)", [
				$host_id,
				$pair["graph_template_id"],
			]);
			db_execute_prepared(
				"INSERT INTO plugin_nms_mib_objects(host_id,oid,report_json,created_at) VALUES (?,?,?,NOW())",
				[$host_id, $r["oid"], json_encode($row)],
			);
			$report[] = $row;
		}
		nms_storage_execute(
			"INSERT INTO plugin_nms_mib_uploads (host_id,user_id,files_json,modules_json,metric_count,created_at) VALUES (?,?,?,?,?,NOW())",
			[
				$host_id,
				nms_current_user_id(),
				json_encode(array_values($preview["files"] ?? [])),
				json_encode(array_values($preview["modules"] ?? [])),
				count($report),
			],
		);
		db_execute("COMMIT");
	} catch (Throwable $e) {
		db_execute("ROLLBACK");
		throw $e;
	} finally {
		db_fetch_cell_prepared("SELECT RELEASE_LOCK(?)", [$lock]);
	}
	foreach ($report as $row) {
		foreach ($row["data_ids"] as $id) {
			push_out_host($host_id, $id);
		}
	}
	set_config_option("time_last_change_graph", time());
	set_config_option("time_last_change_data_source", time());
	return $report;
}
