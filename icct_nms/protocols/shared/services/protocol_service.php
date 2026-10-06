<?php
/**
 * Persist protocol assignments through existing NMS validation and credential services.
 */

/**
 * Load a device assignment together with its saved discovery preset.
 */
function icct_nms_discovery_assignment($id)
{
    return db_fetch_row_prepared(
        "SELECT d.*,p.name,p.protocol,p.enabled,p.interval_seconds,p.stale_seconds,p.refresh_seconds FROM plugin_icct_nms_discovery_devices d LEFT JOIN plugin_icct_nms_discovery_presets p ON p.id=d.preset_id WHERE d.host_id=?",
        [$id],
    );
}

/**
 * Create a private discovery preset; never edit a preset shared by other devices.
 */
function icct_nms_save_discovery($id, $input)
{
    // Each protocol form edits its own selection; shared observation controls preserve the other protocols.
    if (isset($input['discovery_protocol']) || !empty($input['discovery_policy'])) {
        $previous=icct_nms_discovery_assignment($id);
        $methods=$previous ? icct_backend_nd_host_methods($previous,false) : [];
        $hadMethods=(bool)$methods;
        if(isset($input['discovery_protocol'])) {
            if(!in_array($input['discovery_protocol'],['cdp','lldp'],true)) throw new InvalidArgumentException('Unsupported discovery protocol.');
            $methods[]=$input['discovery_protocol'];
        }
        if(!empty($input['discovery_methods_present'])) {
            $observations=$input['methods'] ?? [];
            if(!is_array($observations) || array_diff($observations,['arp','fdb'])) throw new InvalidArgumentException('Choose supported SNMP observation methods.');
            $methods=array_merge(array_diff($methods,['arp','fdb']),$observations);
        } else $input['collection_enabled']=$hadMethods ? (int)$previous['collection_enabled'] : 1;
        $input['methods']=array_values(array_unique($methods));
    }
    $methods = $input["methods"] ?? [];
    if (!$methods) {
        icct_backend_nd_assignment_write($id, [
            "preset_id" => 0,
            "methods" => "",
            "collection_enabled" => 0,
        ]);
        return;
    }
    $preset = icct_backend_nd_preset_validate([
        "name" => "ICCT device " . $id . " " . bin2hex(random_bytes(6)),
        "methods" => $methods,
        "enabled" => 1,
        "interval_seconds" => $input["interval_seconds"] ?? "",
        "stale_seconds" => $input["stale_seconds"] ?? "",
        "refresh_seconds" => $input["refresh_seconds"] ?? "",
    ]);
    $cadence = (int) read_config_option("poller_interval");
    if ($cadence < 1 || $preset["interval_seconds"] % $cadence !== 0) {
        throw new InvalidArgumentException(
            "Collection interval must be a multiple of the poller interval.",
        );
    }
    // Create a device-specific snapshot, never mutate a preset used by other devices.
    icct_backend_category_execute(
        "INSERT INTO plugin_icct_nms_discovery_presets(name,protocol,enabled,interval_seconds,stale_seconds,refresh_seconds,updated_by,updated_at) VALUES(?,?,?,?,?,?,?,NOW())",
        array_merge(array_values($preset), [icct_backend_current_user_id()]),
    );
    $presetId = (int) db_fetch_cell("SELECT LAST_INSERT_ID()");
    icct_backend_nd_assignment_write($id, [
        "preset_id" => $presetId,
        "methods" => "",
        "collection_enabled" => !empty($input["collection_enabled"]) ? 1 : 0,
    ]);
}

/**
 * Validate native tools and assign a device-specific diagnostic profile.
 */
function icct_nms_save_diagnostics($id, $input)
{
    $tools = $input["diagnostic_tools"] ?? [];
    if (
        !is_array($tools) ||
        array_diff($tools, array_keys(icct_backend_diag_available_labels()))
    ) {
        throw new InvalidArgumentException("Unsupported diagnostic method.");
    }
    if (!$tools) {
        icct_backend_category_execute(
            "DELETE FROM plugin_icct_nms_diagnostic_devices WHERE host_id=?",
            [$id],
        );
        return;
    }
    icct_backend_require_management(3);
    $profile = icct_backend_diag_profile_validate(
        $input + [
            "diagnostic_profile_name" =>
                "ICCT device " . $id . " " . bin2hex(random_bytes(6)),
        ],
    );
    icct_backend_category_execute(
        "INSERT INTO plugin_icct_nms_diagnostic_profiles(name,tools,ping_count,trace_hops,bandwidth_seconds,mtr_cycles,mtr_background,mtr_interval,arp_interface,pathchar_hops,pathchar_timeout,updated_by,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,NOW())",
        array_merge(array_values($profile), [icct_backend_current_user_id()]),
    );
    icct_backend_category_execute(
        "INSERT INTO plugin_icct_nms_diagnostic_devices(host_id,profile_id) VALUES(?,?) ON DUPLICATE KEY UPDATE profile_id=VALUES(profile_id)",
        [$id, (int) db_fetch_cell("SELECT LAST_INSERT_ID()")],
    );
}

/**
 * Store credentials through the encrypted service and assign a private SSH preset.
 */
function icct_nms_save_ssh($id, $input)
{
    icct_backend_ssh_https();
    icct_backend_ssh_manage();
    icct_backend_ssh_require_schema();
    $old = db_fetch_row_prepared(
        "SELECT p.* FROM plugin_icct_nms_ssh_devices d JOIN plugin_icct_nms_ssh_presets p ON p.id=d.preset_id WHERE d.host_id=?",
        [$id],
    );
    $values = icct_backend_ssh_preset_values(
        $input + [
            "name" => "ICCT " . $id . " " . bin2hex(random_bytes(6)),
            "description" => "Device-specific ICCT Inventory configuration",
            "enabled" => 1,
        ],
    );
    $secret = $input["secret"] ?? "";
    $phrase = $input["passphrase"] ?? "";
    if (
        !is_string($secret) ||
        !is_string($phrase) ||
        strlen($secret) > 65536 ||
        strlen($phrase) > 1024 ||
        strpos($secret, "\0") !== false ||
        strpos($phrase, "\0") !== false
    ) {
        throw new InvalidArgumentException("Invalid SSH credential.");
    }
    $upload = $_FILES["key_file"] ?? null;
    if (
        $upload &&
        ($upload["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
    ) {
        if (
            $values["auth_method"] !== "key" ||
            $secret !== "" ||
            $upload["error"] !== UPLOAD_ERR_OK ||
            !is_uploaded_file($upload["tmp_name"]) ||
            $upload["size"] > 65536
        ) {
            throw new InvalidArgumentException(
                "Choose one private key file no larger than 64 KB.",
            );
        }
        $secret = file_get_contents($upload["tmp_name"]);
    }
    if ($phrase !== "" && $secret === "") {
        throw new InvalidArgumentException(
            "Provide the key when changing its passphrase.",
        );
    }
    if ($secret !== "") {
        if ($values["auth_method"] === "key") {
            icct_backend_ssh_key_envelope($secret);
        }
        $credential = icct_backend_ssh_rpc(
            icct_backend_ssh_ticket("credential") + [
                "auth_method" => $values["auth_method"],
                "secret" => $secret,
                "passphrase" => $phrase,
            ],
        );
        $ref = $credential["credential_ref"];
    } else {
        if (!$old || $old["auth_method"] !== $values["auth_method"]) {
            throw new InvalidArgumentException(
                "Provide a credential for this authentication method.",
            );
        }
        $ref = $old["credential_ref"];
    }
    $preset = (int) sql_save(
        $values + [
            "id" => 0,
            "credential_ref" => $ref,
            "revision" => 1,
            "updated_by" => icct_backend_current_user_id(),
            "updated_at" => icct_backend_now(),
        ],
        "plugin_icct_nms_ssh_presets",
    );
    if (!$preset) {
        throw new RuntimeException("SSH preset was not saved.");
    }
    // Keep verification evidence. Existing NMS compares it against the current endpoint before use.
    icct_backend_category_execute(
        "INSERT INTO plugin_icct_nms_ssh_devices(host_id,preset_id,monitoring,updated_by,updated_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE preset_id=VALUES(preset_id),monitoring=VALUES(monitoring),revision=revision+1,updated_by=VALUES(updated_by),updated_at=NOW()",
        [
            $id,
            $preset,
            isset($input["monitoring"]) ? 1 : 0,
            icct_backend_current_user_id(),
        ],
    );
}

/** Detach only this device's assignment; shared definitions and secrets remain intact. */
function icct_nms_remove_protocol($id, $host, $input)
{
    icct_backend_require_management(3);
    icct_backend_require_device_access($id);
    switch ($input["protocol"] ?? "") {
        case "snmp":
            return icct_nms_save_snmp(
                $id,
                $host,
                array_replace($host, ["snmp_version" => "0"]),
            );
        case "cdp":
        case "lldp":
            $assignment = icct_nms_discovery_assignment($id);
            if (!$assignment) {
                return;
            }
            $methods = array_values(
                array_diff(icct_backend_nd_host_methods($assignment, false), [
                    $input["protocol"],
                ]),
            );
            return icct_nms_save_discovery($id, [
                "methods" => $methods,
                "collection_enabled" => !empty(
                    $assignment["collection_enabled"]
                ),
                "interval_seconds" => $assignment["interval_seconds"],
                "stale_seconds" => $assignment["stale_seconds"],
                "refresh_seconds" => $assignment["refresh_seconds"],
            ]);
        case "discovery":
            return icct_nms_save_discovery($id, ["methods" => []]);
        case "syslog":
            return icct_nms_syslog_remove($id);
        case "serial":
            return icct_backend_serial_assign($id, [
                "connection_id" => 0,
                "assignment_revision" => $input["assignment_revision"] ?? "",
            ]);
        case "ssh":
            return icct_backend_category_execute(
                "DELETE FROM plugin_icct_nms_ssh_devices WHERE host_id=?",
                [$id],
            );
        default:
            throw new InvalidArgumentException(
                "Unsupported saved protocol removal.",
            );
    }
}

/** Toggle only this device, retaining native SNMP credentials and all saved assignments. */
function icct_nms_toggle_protocol($id, $host, $input)
{
    icct_backend_require_management(3);
    icct_backend_require_device_access($id);
    $protocol = $input["protocol"] ?? "";
    if (
        !in_array($protocol, ["cdp", "lldp", "snmp", "ssh", "serial", "syslog"], true) ||
        !in_array($input["enabled"] ?? "", ["0", "1"], true)
    ) {
        throw new InvalidArgumentException("Invalid protocol state.");
    }
    $enabled = $input["enabled"] === "1";
    if (icct_backend_protocol_enabled($id, $protocol) === $enabled) {
        return;
    }
    if ($protocol === "snmp") {
        $key = "protocol_snmp_version_" . (int) $id;
        if (!$enabled && (int) $host["snmp_version"] > 0) {
            icct_backend_category_execute(
                "REPLACE INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())",
                [$key, (string) $host["snmp_version"]],
            );
        }
        $version = $enabled
            ? ((int) $host["snmp_version"] ?:
            (int) db_fetch_cell_prepared(
                "SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?",
                [$key],
            ))
            : 0;
        if ($enabled && !in_array($version, [1, 2, 3], true)) {
            throw new InvalidArgumentException(
                "Configure SNMP before enabling it.",
            );
        }
        // Preserve unused native fields exactly, including blank legacy algorithms.
        $values = array_replace($host, ["snmp_version" => $version]);
        $values["proxy"] = icct_backend_shared_endpoint_get($id);
        icct_backend_device_save($id, $values);
    }
    if ($protocol === "ssh") {
        $key = "protocol_ssh_monitoring_" . (int) $id;
        if (!$enabled) {
            $monitoring = db_fetch_cell_prepared(
                "SELECT monitoring FROM plugin_icct_nms_ssh_devices WHERE host_id=?",
                [$id],
            );
            if (icct_backend_protocol_enabled($id, "ssh")) {
                icct_backend_category_execute(
                    "REPLACE INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())",
                    [$key, (string) $monitoring],
                );
            }
        }
        $monitoring = $enabled
            ? (int) db_fetch_cell_prepared(
                "SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?",
                [$key],
            )
            : 0;
        icct_backend_category_execute(
            "UPDATE plugin_icct_nms_ssh_devices SET monitoring=?,revision=revision+1 WHERE host_id=?",
            [$monitoring, $id],
        );
    }
    if ($protocol === "syslog") icct_nms_syslog_toggle($id, $enabled);
    icct_backend_protocol_state_write($id, $protocol, $enabled);
}

require_once __DIR__ . '/../../syslog/services/syslog_service.php';
