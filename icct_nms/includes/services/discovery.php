<?php
/** ICCT-owned discovery services, derived from the existing ICCT NMS implementation. */

/** Reused Inventory service: nd assignment write. */
function icct_backend_nd_assignment_write($host_id, $assignment)
{
    icct_backend_require_management();
    icct_backend_require_device_access($host_id);
    if (
        !db_fetch_cell_prepared(
            "SELECT id FROM host WHERE id=? AND deleted=''",
            [$host_id],
        )
    ) {
        throw new InvalidArgumentException("Device no longer exists.");
    }
    // Keep explicit unassigned rows so future-assignment rules cannot override a deliberate choice.
    icct_backend_category_execute(
        "INSERT INTO plugin_icct_nms_discovery_devices(host_id,preset_id,methods,collection_enabled,last_attempt) VALUES (?,?,?,?,NULL) ON DUPLICATE KEY UPDATE last_attempt=IF(preset_id<>VALUES(preset_id) OR methods<>VALUES(methods) OR collection_enabled<>VALUES(collection_enabled),NULL,last_attempt),preset_id=VALUES(preset_id),methods=VALUES(methods),collection_enabled=VALUES(collection_enabled)",
        [
            $host_id,
            $assignment["preset_id"],
            $assignment["methods"],
            $assignment["collection_enabled"],
        ],
    );
}

/** Reused Inventory service: nd hash. */
function icct_backend_nd_hash($host)
{
    $fields = [
        "hostname",
        "site_id",
        "poller_id",
        "disabled",
        "deleted",
        "snmp_version",
        "snmp_community",
        "snmp_username",
        "snmp_password",
        "snmp_auth_protocol",
        "snmp_priv_passphrase",
        "snmp_priv_protocol",
        "snmp_context",
        "snmp_engine_id",
        "snmp_port",
        "snmp_timeout",
        "preset_id",
        "protocol",
        "enabled",
        "methods",
        "collection_enabled",
    ];
    $values = [];
    foreach ($fields as $key) {
        $values[$key] = (string) ($host[$key] ?? "");
    }
    return hash("sha256", json_encode($values, JSON_THROW_ON_ERROR));
}

/** Reused Inventory service: nd host methods. */
function icct_backend_nd_host_methods($host, $active_only = true)
{
    $preset = icct_backend_nd_protocols($host["protocol"]);
    $methods = empty($host["methods"])
        ? $preset
        : array_values(
            array_intersect(
                $preset,
                icct_backend_nd_protocols($host["methods"]),
            ),
        );
    if (!$active_only || !function_exists("icct_backend_protocol_enabled")) {
        return $methods;
    }
    if (
        !icct_backend_protocol_enabled(
            $host["host_id"] ?? ($host["id"] ?? 0),
            "snmp",
        )
    ) {
        return [];
    }
    return array_values(
        array_filter($methods, function ($method) use ($host) {
            return !in_array($method, ["cdp", "lldp"], true) ||
                icct_backend_protocol_enabled(
                    $host["host_id"] ?? ($host["id"] ?? 0),
                    $method,
                );
        }),
    );
}

/** Reused Inventory service: nd hosts. */
function icct_backend_nd_hosts($include_disabled = false)
{
    return db_fetch_assoc(
        "SELECT h.*,d.preset_id,d.last_attempt,d.methods,d.collection_enabled,p.name AS preset_name,p.protocol,p.enabled,p.interval_seconds,p.stale_seconds,p.refresh_seconds FROM host h JOIN plugin_icct_nms_discovery_devices d ON d.host_id=h.id JOIN plugin_icct_nms_discovery_presets p ON p.id=d.preset_id WHERE h.deleted=''" .
            ($include_disabled ? "" : " AND h.disabled=''"),
    );
}

/** Reused Inventory service: nd method labels. */
function icct_backend_nd_method_labels()
{
    return [
        "lldp" => "LLDP neighbours",
        "cdp" => "CDP neighbours",
        "arp" => "IP neighbours (IPv4 / IPv6)",
        "fdb" => "MAC/FDB table",
    ];
}

/** Reused Inventory service: nd methods validate. */
function icct_backend_nd_methods_validate($methods)
{
    if (!is_array($methods) || !$methods || count($methods) > 4) {
        throw new InvalidArgumentException(
            "Select at least one collection method.",
        );
    }
    foreach ($methods as $method) {
        if (
            !is_string($method) ||
            !isset(icct_backend_nd_method_labels()[$method])
        ) {
            throw new InvalidArgumentException("Unknown collection method.");
        }
    }
    return array_values(
        array_intersect(array_keys(icct_backend_nd_method_labels()), $methods),
    );
}

/** Reused Inventory service: nd poll. */
function icct_backend_nd_poll()
{
    global $config;

    $deadline = PHP_FLOAT_MAX;
    $hosts = icct_backend_nd_hosts();
    usort($hosts, function ($a, $b) {
        return strcmp($a["last_attempt"] ?? "", $b["last_attempt"] ?? "");
    });
    foreach ($hosts as $host) {
        if (
            function_exists("icct_backend_protocol_enabled") &&
            !icct_backend_protocol_enabled($host["id"], "snmp")
        ) {
            continue;
        }
        if (
            !$host["enabled"] ||
            !$host["collection_enabled"] ||
            (int) $host["poller_id"] !== (int) $config["poller_id"]
        ) {
            continue;
        }
        if (
            $host["last_attempt"] &&
            time() - strtotime($host["last_attempt"]) <
                (int) $host["interval_seconds"]
        ) {
            continue;
        }
        $lock = "icct_backend_discovery_" . (int) $host["id"];
        if (
            (int) db_fetch_cell_prepared("SELECT GET_LOCK(?,0)", [$lock]) !== 1
        ) {
            continue;
        }
        try {
            $last = db_fetch_cell_prepared(
                "SELECT last_attempt FROM plugin_icct_nms_discovery_devices WHERE host_id=?",
                [$host["id"]],
            );
            if (
                $last &&
                time() - strtotime($last) < (int) $host["interval_seconds"]
            ) {
                continue;
            }
            $fresh = null;
            foreach (icct_backend_nd_hosts() as $candidate) {
                if ($candidate["id"] == $host["id"]) {
                    $fresh = $candidate;
                }
            }
            if (
                !$fresh ||
                !$fresh["enabled"] ||
                !$fresh["collection_enabled"] ||
                (int) $fresh["poller_id"] !== (int) $config["poller_id"]
            ) {
                continue;
            }
            $host = $fresh;
            icct_backend_category_execute(
                "UPDATE plugin_icct_nms_discovery_devices SET last_attempt=NOW() WHERE host_id=?",
                [$host["id"]],
            );
            $hash = icct_backend_nd_hash($host);
            $previousIdentity = db_fetch_row_prepared(
                "SELECT status,config_hash,data_json FROM plugin_icct_nms_discovery_snapshots WHERE host_id=? AND protocol='identity'",
                [$host["id"]],
            );
            $previousData =
                $previousIdentity &&
                $previousIdentity["status"] === "success" &&
                $previousIdentity["config_hash"] === $hash
                    ? json_decode($previousIdentity["data_json"], true)
                    : [];
            icct_backend_category_execute(
                "INSERT INTO plugin_icct_nms_discovery_snapshots(host_id,protocol,status,attempted_at,config_hash,data_json) VALUES (?,'identity','running',NOW(),?,'{}') ON DUPLICATE KEY UPDATE status='running',attempted_at=NOW(),error=''",
                [$host["id"], $hash],
            );
            try {
                $identity = icct_backend_nd_collect_identity($host, $deadline);
                $identity = icct_backend_interface_rates(
                    $identity,
                    is_array($previousData) ? $previousData : [],
                    (int) ($host["stale_seconds"] ?? 600),
                );
                icct_backend_category_execute(
                    "UPDATE plugin_icct_nms_discovery_snapshots SET status='success',succeeded_at=NOW(),config_hash=?,data_json=?,error='' WHERE host_id=? AND protocol='identity'",
                    [
                        $hash,
                        json_encode($identity, JSON_THROW_ON_ERROR),
                        $host["id"],
                    ],
                );
            } catch (Throwable $e) {
                icct_backend_category_execute(
                    "UPDATE plugin_icct_nms_discovery_snapshots SET status='failed',error=? WHERE host_id=? AND protocol='identity'",
                    [
                        $e instanceof RuntimeException
                            ? substr($e->getMessage(), 0, 255)
                            : "Identity collection failed.",
                        $host["id"],
                    ],
                );
            }
            foreach (icct_backend_nd_host_methods($host) as $protocol) {
                // Recheck between methods: an administrator may pause/delete/change this
                // device while the previous SNMP table walk is in progress.
                $current = null;
                foreach (icct_backend_nd_hosts() as $candidate) {
                    if ($candidate["id"] == $host["id"]) {
                        $current = $candidate;
                    }
                }
                if (
                    !$current ||
                    !in_array(
                        $protocol,
                        icct_backend_nd_host_methods($current),
                        true,
                    ) ||
                    icct_backend_nd_hash($current) !== $hash
                ) {
                    break;
                }
                $old = db_fetch_row_prepared(
                    "SELECT * FROM plugin_icct_nms_discovery_snapshots WHERE host_id=? AND protocol=?",
                    [$host["id"], $protocol],
                );
                icct_backend_category_execute(
                    "INSERT INTO plugin_icct_nms_discovery_snapshots(host_id,protocol,status,attempted_at,config_hash,data_json) VALUES (?,?,'running',NOW(),?,'{}') ON DUPLICATE KEY UPDATE status='running',attempted_at=NOW(),error=''",
                    [$host["id"], $protocol, $hash],
                );
                try {
                    $data = icct_backend_nd_collect_direct(
                        $host,
                        $protocol,
                        $deadline,
                    );
                    $current = null;
                    foreach (icct_backend_nd_hosts() as $candidate) {
                        if ($candidate["id"] == $host["id"]) {
                            $current = $candidate;
                        }
                    }
                    if (!$current || icct_backend_nd_hash($current) !== $hash) {
                        throw new RuntimeException(
                            "Configuration changed during collection.",
                        );
                    }
                    $previous =
                        $old && $old["config_hash"] === $hash
                            ? json_decode($old["data_json"], true)
                            : [];
                    $data["neighbors"] = icct_backend_nd_observation_history(
                        $previous["neighbors"] ?? [],
                        $data["neighbors"],
                        time(),
                    );
                    icct_backend_category_execute(
                        "UPDATE plugin_icct_nms_discovery_snapshots SET status='success',succeeded_at=NOW(),config_hash=?,data_json=?,error='' WHERE host_id=? AND protocol=?",
                        [
                            $hash,
                            json_encode($data, JSON_THROW_ON_ERROR),
                            $host["id"],
                            $protocol,
                        ],
                    );
                } catch (Throwable $e) {
                    icct_backend_category_execute(
                        "UPDATE plugin_icct_nms_discovery_snapshots SET status='failed',error=? WHERE host_id=? AND protocol=?",
                        [
                            $e instanceof RuntimeException
                                ? substr($e->getMessage(), 0, 255)
                                : "Discovery failed.",
                            $host["id"],
                            $protocol,
                        ],
                    );
                }
            }
        } finally {
            db_fetch_cell_prepared("SELECT RELEASE_LOCK(?)", [$lock]);
        }
    }
}

/** Reused Inventory service: nd preset validate. */
function icct_backend_nd_preset_validate($input)
{
    if (
        !is_array($input) ||
        !is_string($input["name"] ?? null) ||
        (!isset($input["methods"]) && !is_string($input["protocol"] ?? null)) ||
        !in_array($input["enabled"] ?? 0, [0, 1, "0", "1"], true)
    ) {
        throw new InvalidArgumentException("Invalid discovery preset fields.");
    }
    $name = trim($input["name"]);
    if (
        $name === "" ||
        strlen($name) > 100 ||
        preg_match('/[\x00-\x1f\x7f]/', $name)
    ) {
        throw new InvalidArgumentException(
            "Enter a preset name of 1–100 bytes without control characters.",
        );
    }
    $protocol = implode(
        ",",
        icct_backend_nd_methods_validate(
            $input["methods"] ??
                icct_backend_nd_protocols($input["protocol"] ?? ""),
        ),
    );
    $out = [
        "name" => $name,
        "protocol" => $protocol,
        "enabled" => !empty($input["enabled"]) ? 1 : 0,
    ];
    foreach (
        [
            "interval_seconds" => [300, 86400],
            "stale_seconds" => [600, 604800],
            "refresh_seconds" => [10, 300],
        ]
        as $key => $range
    ) {
        $v = $input[$key] ?? "";
        if (
            !is_scalar($v) ||
            !preg_match('/^[0-9]+$/D', (string) $v) ||
            $v < $range[0] ||
            $v > $range[1]
        ) {
            throw new InvalidArgumentException(
                $key .
                    " must be an integer from " .
                    $range[0] .
                    " to " .
                    $range[1] .
                    ".",
            );
        }
        $out[$key] = (int) $v;
    }
    if ($out["stale_seconds"] < 2 * $out["interval_seconds"]) {
        throw new InvalidArgumentException(
            "Stale threshold must be at least twice the collection interval.",
        );
    }
    return $out;
}

/** Reused Inventory service: nd protocols. */
function icct_backend_nd_protocols($protocol)
{
    if ($protocol === "both") {
        return ["lldp", "cdp"];
    }
    if (!is_string($protocol)) {
        return [];
    }
    $parts = explode(",", $protocol);
    return count(
        array_diff($parts, array_keys(icct_backend_nd_method_labels())),
    )
        ? []
        : array_values(array_unique($parts));
}

/** Reused Inventory service: nd test queue. */
function icct_backend_nd_test_queue($host_id, $site_id)
{
    icct_backend_require_management();
    icct_backend_require_device_access($host_id);
    if (
        !db_fetch_cell_prepared(
            "SELECT id FROM host WHERE id=? AND site_id=? AND deleted='' AND disabled=''",
            [$host_id, $site_id],
        )
    ) {
        throw new InvalidArgumentException(
            "Select an enabled device in this site.",
        );
    }
    $preset = db_fetch_row_prepared(
        "SELECT p.enabled,d.collection_enabled FROM plugin_icct_nms_discovery_devices d JOIN plugin_icct_nms_discovery_presets p ON p.id=d.preset_id WHERE d.host_id=?",
        [$host_id],
    );
    if (!$preset || !$preset["enabled"] || !$preset["collection_enabled"]) {
        throw new InvalidArgumentException(
            "Save an enabled discovery preset for this device before testing.",
        );
    }
    $host = null;
    foreach (icct_backend_nd_hosts() as $candidate) {
        if ((int) $candidate["id"] === (int) $host_id) {
            $host = $candidate;
        }
    }
    if (!$host) {
        throw new RuntimeException(
            "The enabled device could not be read from the discovery assignment. Save its discovery settings and retry.",
        );
    }
    icct_backend_category_execute(
        "UPDATE plugin_icct_nms_discovery_devices SET last_attempt=NULL WHERE host_id=?",
        [$host_id],
    );
    $hash = icct_backend_nd_hash($host);
    icct_backend_category_execute(
        "INSERT INTO plugin_icct_nms_discovery_snapshots(host_id,protocol,status,attempted_at,config_hash,data_json,error)
  VALUES (?,'identity','queued',NOW(),?,'{}',?) ON DUPLICATE KEY UPDATE status='queued',attempted_at=NOW(),config_hash=VALUES(config_hash),data_json='{}',succeeded_at=NULL,error=VALUES(error)",
        [
            $host_id,
            $hash,
            "Queued for the assigned Cacti collector. The next poll cycle will report identity values or the exact collection error.",
        ],
    );
    foreach (icct_backend_nd_host_methods($host) as $method) {
        icct_backend_category_execute(
            "INSERT INTO plugin_icct_nms_discovery_snapshots(host_id,protocol,status,attempted_at,config_hash,data_json,error)
   VALUES (?,?,'queued',NOW(),?,'{}',?) ON DUPLICATE KEY UPDATE status='queued',attempted_at=NOW(),config_hash=VALUES(config_hash),data_json='{}',succeeded_at=NULL,error=VALUES(error)",
            [
                $host_id,
                $method,
                $hash,
                "Queued for the assigned Cacti collector. The next poll cycle will report success or the exact collection error.",
            ],
        );
    }
}
