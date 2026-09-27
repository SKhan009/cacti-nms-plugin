<?php
/** MIB definitions are parsed by Net-SNMP; reads and core writes use Cacti. */
require_once __DIR__ . "/discovery_snmp.php";
require_once __DIR__ . "/template_manager.php";
/** Validate PHP upload failures before trying to inspect the temporary file. */
function nms_mib_upload_path($upload, $index)
{
    $error = $upload['error'][$index] ?? UPLOAD_ERR_NO_FILE;
    $errors = [
        UPLOAD_ERR_INI_SIZE => 'The MIB exceeds the RHEL PHP upload_max_filesize limit (' . ini_get('upload_max_filesize') . ').',
        UPLOAD_ERR_FORM_SIZE => 'The MIB exceeds the form upload size limit.',
        UPLOAD_ERR_PARTIAL => 'The MIB upload was interrupted. Select the file and retry.',
        UPLOAD_ERR_NO_FILE => 'Select a MIB file to upload.',
        UPLOAD_ERR_NO_TMP_DIR => 'PHP has no upload temporary directory. Ask the RHEL administrator to configure upload_tmp_dir.',
        UPLOAD_ERR_CANT_WRITE => 'PHP cannot write the uploaded MIB. Check temporary-directory permissions, free space and SELinux labels on RHEL.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the MIB upload. Check the RHEL PHP-FPM log.',
    ];
    if ($error !== UPLOAD_ERR_OK) throw new InvalidArgumentException($errors[$error] ?? 'The MIB upload failed. Select the file and retry.');
    $name = $upload['name'][$index] ?? '';
    if (!is_string($name) || !in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['mib', 'my', 'txt'], true)) {
        throw new InvalidArgumentException('Select .mib, .my or .txt MIB definition files.');
    }
    $path = $upload['tmp_name'][$index] ?? '';
    if (!is_string($path) || !is_file($path) || !is_readable($path)) {
        throw new RuntimeException('The uploaded MIB temporary file is unavailable. Select the file and retry.');
    }
    return $path;
}
function nms_mib_command($args)
{
	$pipes = [];
	$p = proc_open($args, [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes, null, null, [
		"bypass_shell" => true,
	]);
	if (!is_resource($p)) {
		throw new RuntimeException("Net-SNMP snmptranslate is required locally. On offline RHEL, install net-snmp-utils and its dependencies from matching RHEL installation media or an offline RPM repository.");
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
    if ($exit === 127) {
        throw new RuntimeException('Cannot run snmptranslate. Install net-snmp-utils locally on RHEL and check the configured executable path. Offline installation can use matching RHEL media or an offline RPM repository.');
    }
    if (preg_match_all('/Cannot find module \(([^)]+)\)/', $err, $missing)) {
        throw new RuntimeException('Missing MIB dependencies: ' . implode(', ', array_unique($missing[1])) . '. Upload these modules together with the main MIB, or have the administrator install them in /usr/share/snmp/mibs. Internet access is not required.');
    }
	if (
		$exit !== 0 ||
		preg_match(
			"/Cannot find module|Unlinked OID|Undefined identifier|Bad operator|Cannot adopt OID|Error in parsing/i",
			$err,
		)
	) {
		throw new RuntimeException(
			"MIB parsing failed locally. Check the MIB syntax and its imported dependencies. " .
				substr($err, 0, 500),
		);
	}
	return $out;
}
/**
 * Handles mib preview.
 */
function nms_mib_preview($upload, $host_id, $template_name = "", $category_id = 0)
{
	global $config;
	if ($host_id) nms_require_device_access($host_id);
	$host = db_fetch_row_prepared("SELECT * FROM host WHERE id=? AND deleted='' AND disabled=''", [$host_id]);
	if ($host_id && !$host) {
		throw new InvalidArgumentException("Select an enabled Cacti device.");
	}
    if (!$host_id) {
        $template_name=nms_template_clean_name($template_name);
        if ($template_name==='' || !nms_category_exists($category_id)) throw new InvalidArgumentException('Enter a template name and select a device type.');
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
            $path = nms_mib_upload_path($upload, $i);
            $size = filesize($path);
			$total += $size;
			if ($size < 1 || $size > 1048576 || $total > 4194304) {
				throw new InvalidArgumentException("Limit: 1 MB per MIB and 4 MB total.");
			}
			$text = file_get_contents($path);
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
			if (file_put_contents($dir . DIRECTORY_SEPARATOR . $module . ".txt", $text) !== strlen($text)) {
                throw new RuntimeException('Cannot write the private MIB parsing file. Check free space and temporary-directory permissions on RHEL.');
            }
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
		$session = $host_id ? nms_nd_discovery_session($host) : null;
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
                if (!$host_id) {
                    $parent=preg_replace('/\.[0-9]+$/','',$oid[1]);
                    $parent_definition=nms_mib_command(array_merge($args,['-Td','-On',$parent]));
                    if (preg_match('/\b(?:INDEX|AUGMENTS)\s*\{|SYNTAX\s+SEQUENCE\b/',$parent_definition)) {
                        $skipped[]=$symbol.': table column requires device-specific indexes';continue;
                    }
                    $numeric_type=['Integer32'=>2,'INTEGER'=>2,'Unsigned32'=>66,'Gauge32'=>66,'Counter32'=>65,'Counter64'=>70,'TimeTicks'=>67][$type[1]];
                    $records[]=['oid'=>$oid[1].'.0','type'=>$numeric_type,'tag'=>(string)$numeric_type,'value'=>'','section'=>$symbol,'graphable'=>true,'units'=>$units,'reading_index'=>1,'reading_total'=>1];
                    if(count($records)>64)throw new InvalidArgumentException('At most 64 numeric scalar objects can be prepared per upload.');
                    continue;
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
			if ($session) $session->close();
		}
		if (!$records) {
			throw new InvalidArgumentException(
				"No supported numeric scalar objects or readable instances found. " . implode("; ", array_slice($skipped, 0, 8)),
			);
		}
		return [
			"files" => array_map("basename", $names),
			"host_id" => $host_id,
            "template_name" => $template_name,
            "category_id" => (int)$category_id,
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
