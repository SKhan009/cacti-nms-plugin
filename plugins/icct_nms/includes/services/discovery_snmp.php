<?php
/** ICCT-owned discovery snmp services, derived from the existing ICCT NMS implementation. */

/** Reused Inventory service: nd collect battery. */
function icct_backend_nd_collect_battery($session, $deadline)
{
    $oids = [
        "status" => "1.3.6.1.2.1.33.1.2.1.0",
        "minutes_remaining" => "1.3.6.1.2.1.33.1.2.3.0",
        "charge_percent" => "1.3.6.1.2.1.33.1.2.4.0",
        "voltage" => "1.3.6.1.2.1.33.1.2.5.0",
        "current" => "1.3.6.1.2.1.33.1.2.6.0",
    ];
    $out = ["supported" => false];
    foreach ($oids as $name => $oid) {
        try {
            $value = icct_backend_nd_snmp_scalar($session, $oid, $deadline);
            if (is_numeric($value["value"])) {
                $out[$name] = (float) $value["value"];
                $out["supported"] = true;
            }
        } catch (RuntimeException $error) {
            // UPS-MIB is optional; a normal server, laptop, or phone may not expose it.
        }
    }
    return $out;
}

/** Reused Inventory service: nd collect direct. */
function icct_backend_nd_collect_direct($host, $protocol, $jobDeadline)
{
    if (
        function_exists("icct_backend_protocol_enabled") &&
        (!icct_backend_protocol_enabled($host["id"], "snmp") ||
            (in_array($protocol, ["cdp", "lldp"], true) &&
                !icct_backend_protocol_enabled($host["id"], $protocol)))
    ) {
        throw new RuntimeException("Protocol is disabled for this device.");
    }
    $session = icct_backend_nd_discovery_session($host);
    $deadline = $jobDeadline;
    $budget = PHP_INT_MAX;
    $values = [];
    try {
        $uptime = "1.3.6.1.2.1.1.3.0";
        try {
            $before = icct_backend_nd_snmp_scalar($session, $uptime, $deadline);
        } catch (RuntimeException $e) {
            $before = null;
        }
        // IF-MIB and IFX-MIB are both optional: LLDP port labels can still be collected without them.
        $values += icct_backend_nd_snmp_optional_subtree(
            $session,
            "1.3.6.1.2.1.2.2.1",
            $deadline,
            $budget,
        );
        foreach (
            [
                "1.3.6.1.2.1.2.2.1.5",
                "1.3.6.1.2.1.31.1.1.1.1",
                "1.3.6.1.2.1.31.1.1.1.15",
                "1.3.6.1.2.1.31.1.1.1.18",
            ]
            as $root
        ) {
            $values += icct_backend_nd_snmp_optional_subtree(
                $session,
                $root,
                $deadline,
                $budget,
            );
        }
        $interfaces = icct_backend_nd_snmp_interfaces($values);
        if ($protocol === "lldp") {
            $values += icct_backend_nd_snmp_optional_subtree(
                $session,
                "1.0.8802.1.1.2.1.3",
                $deadline,
                $budget,
            );
            $values += icct_backend_nd_snmp_optional_subtree(
                $session,
                "1.0.8802.1.1.2.1.4.1.1",
                $deadline,
                $budget,
            );
            $values += icct_backend_nd_snmp_optional_subtree(
                $session,
                "1.0.8802.1.1.2.1.4.2.1",
                $deadline,
                $budget,
            );
            try {
                $data = icct_backend_nd_parse_lldp($values, $interfaces);
            } catch (RuntimeException $e) {
                $data = icct_backend_nd_snmp_empty_protocol(
                    $protocol,
                    $interfaces,
                    $e->getMessage(),
                );
            }
        } elseif ($protocol === "cdp") {
            $values += icct_backend_nd_snmp_optional_subtree(
                $session,
                "1.3.6.1.4.1.9.9.23.1",
                $deadline,
                $budget,
            );
            try {
                $data = icct_backend_nd_parse_cdp($values, $interfaces);
            } catch (RuntimeException $e) {
                $data = icct_backend_nd_snmp_empty_protocol(
                    $protocol,
                    $interfaces,
                    $e->getMessage(),
                );
            }
        } elseif ($protocol === "arp") {
            // Retain legacy IPv4 support and add the version-neutral IPv4/IPv6 table.
            foreach (["1.3.6.1.2.1.4.22.1", "1.3.6.1.2.1.4.35.1"] as $root) {
                $values += icct_backend_nd_snmp_optional_subtree(
                    $session,
                    $root,
                    $deadline,
                    $budget,
                );
            }
            try {
                $data = icct_backend_nd_parse_arp($values, $interfaces);
            } catch (RuntimeException $e) {
                $data = icct_backend_nd_snmp_empty_protocol(
                    $protocol,
                    $interfaces,
                    $e->getMessage(),
                );
            }
        } elseif ($protocol === "fdb") {
            foreach (
                [
                    "1.3.6.1.2.1.17.1.4.1",
                    "1.3.6.1.2.1.17.4.3.1",
                    "1.3.6.1.2.1.17.7.1.2.2.1",
                    "1.3.6.1.2.1.17.7.1.4.2.1.3",
                ]
                as $root
            ) {
                $values += icct_backend_nd_snmp_optional_subtree(
                    $session,
                    $root,
                    $deadline,
                    $budget,
                );
            }
            try {
                $data = icct_backend_nd_parse_fdb($values, $interfaces);
            } catch (RuntimeException $e) {
                $data = icct_backend_nd_snmp_empty_protocol(
                    $protocol,
                    $interfaces,
                    $e->getMessage(),
                );
            }
        } else {
            throw new RuntimeException("Unknown discovery protocol.");
        }
        try {
            $after = icct_backend_nd_snmp_scalar($session, $uptime, $deadline);
        } catch (RuntimeException $e) {
            $after = null;
        }
        if (
            $before !== null &&
            $after !== null &&
            (float) $after["value"] < (float) $before["value"]
        ) {
            throw new RuntimeException(
                "Device restarted or uptime wrapped during collection; result discarded.",
            );
        }
        // Optional inventory uses the same authenticated session.
        // Its failure must not turn successful neighbour evidence into an empty success.
        try {
            $entity = icct_backend_nd_snmp_subtree(
                $session,
                "1.3.6.1.2.1.47.1.1.1.1",
                $deadline,
                $budget,
            );
            $data["hardware"] = icct_backend_nd_hardware($entity);
        } catch (RuntimeException $e) {
            $data["hardware"] = ["error" => $e->getMessage()];
        }
        $data["collected"] = time();
        return $data;
    } finally {
        $session->close();
    }
}

/** Reused Inventory service: nd collect identity. */
function icct_backend_nd_collect_identity($host, $jobDeadline)
{
    $session = icct_backend_nd_discovery_session($host);
    $deadline = $jobDeadline;
    $budget = PHP_INT_MAX;
    $values = [];
    try {
        $uptime = "1.3.6.1.2.1.1.3.0";
        try {
            $before = icct_backend_nd_snmp_scalar($session, $uptime, $deadline);
        } catch (RuntimeException $e) {
            $before = null;
        }
        foreach (
            [
                "1.3.6.1.2.1.31.1.1.1.6",
                "1.3.6.1.2.1.31.1.1.1.10",
                "1.3.6.1.2.1.31.1.1.1.19",
                "1.3.6.1.2.1.2.2.1",
                "1.3.6.1.2.1.31.1.1.1.1",
                "1.3.6.1.2.1.31.1.1.1.15",
                "1.3.6.1.2.1.31.1.1.1.18",
            ]
            as $root
        ) {
            try {
                $values += icct_backend_nd_snmp_subtree(
                    $session,
                    $root,
                    $deadline,
                    $budget,
                );
            } catch (RuntimeException $e) {
            }
        }
        $counterCollected = time();

        try {
            $entity = icct_backend_nd_snmp_subtree(
                $session,
                "1.3.6.1.2.1.47.1.1.1.1",
                $deadline,
                $budget,
            );
        } catch (RuntimeException $e) {
            $entity = [];
            $hardware_error = $e->getMessage();
        }
        $battery = icct_backend_nd_collect_battery($session, $deadline);
        $addressValues = [];
        $addressErrors = [];
        foreach (["1.3.6.1.2.1.4.20.1.2", "1.3.6.1.2.1.4.34.1"] as $root) {
            try {
                $addressValues += icct_backend_nd_snmp_subtree(
                    $session,
                    $root,
                    $deadline,
                    $budget,
                );
            } catch (RuntimeException $e) {
                $addressErrors[] = $e->getMessage();
            }
        }
        $interfaces = [];
        $interface_error = "";
        try {
            $interfaces = icct_backend_nd_interfaces($values);
        } catch (RuntimeException $e) {
            $interface_error = $e->getMessage();
        }
        $hardware = icct_backend_nd_hardware($entity);
        if (isset($hardware_error)) {
            $hardware["error"] = $hardware_error;
        }
        try {
            $after = icct_backend_nd_snmp_scalar($session, $uptime, $deadline);
        } catch (RuntimeException $e) {
            $after = null;
        }
        if (
            $before !== null &&
            $after !== null &&
            (float) $after["value"] < (float) $before["value"]
        ) {
            throw new RuntimeException(
                "Device restarted or uptime wrapped during identity collection; result discarded.",
            );
        }
        return [
            "interfaces" => $interfaces,
            "hardware" => $hardware,
            "own_addresses" => icct_backend_nd_own_addresses($addressValues),
            "own_address_errors" => $addressErrors,
            "battery" => $battery,
            "uptime" => $after["value"] ?? null,
            "interface_error" => $interface_error,
            "neighbors" => [],
            "collected" => $counterCollected,
        ];
    } finally {
        $session->close();
    }
}

/** Reused Inventory service: nd discovery session. */
function icct_backend_nd_discovery_session($host)
{
    global $config;
    require_once $config["base_path"] . "/lib/snmp.php";
    if (!$config["php_snmp_support"] || !extension_loaded("snmp")) {
        throw new RuntimeException(
            "PHP SNMP is unavailable on the assigned Cacti collector. Install and enable php-snmp, then restart Apache/PHP. lldpcli and ip neigh show the local RHEL neighbour cache; they do not provide SNMP discovery evidence for this device.",
        );
    }
    if (
        $host["disabled"] !== "" ||
        (int) $host["poller_id"] !== (int) $config["poller_id"]
    ) {
        throw new RuntimeException(
            "Device is disabled or assigned to another collector.",
        );
    }
    if (!in_array((string) $host["snmp_version"], ["1", "2", "3"], true)) {
        throw new RuntimeException(
            "Discovery requires an explicitly configured SNMPv1, SNMPv2c, or SNMPv3 profile.",
        );
    }
    $security = icct_backend_nd_snmp_security($host);
    $timeout = (int) $host["snmp_timeout"];
    $retries =
        $host["icct_backend_snmp_retries"] ??
        read_config_option("snmp_retries");
    if (
        $timeout < 1 ||
        !is_scalar($retries) ||
        !preg_match('/^\d+$/D', (string) $retries)
    ) {
        throw new RuntimeException(
            "Discovery requires a positive timeout and a non-negative retry count.",
        );
    }
    $session = false;
    try {
        $session = @cacti_snmp_session(
            $host["hostname"],
            $host["snmp_community"],
            $host["snmp_version"],
            $host["snmp_username"],
            $host["snmp_password"],
            $host["snmp_auth_protocol"],
            $host["snmp_priv_passphrase"],
            $host["snmp_priv_protocol"],
            $host["snmp_context"],
            $host["snmp_engine_id"],
            $host["snmp_port"],
            $timeout,
            (int) $retries,
            1,
            1,
        );
        // Cacti 1.2.31 does not check setSecurity's boolean return. Confirm the exact profile.
        if ($session && (string) $host["snmp_version"] === "3") {
            $ok = @$session->setSecurity(
                $security,
                $host["snmp_auth_protocol"] === "[None]"
                    ? ""
                    : $host["snmp_auth_protocol"],
                $host["snmp_password"],
                $host["snmp_priv_protocol"] === "[None]"
                    ? ""
                    : $host["snmp_priv_protocol"],
                $host["snmp_priv_passphrase"],
                $host["snmp_context"],
            );
            if (!$ok) {
                $session->close();
                $session = false;
            }
        }
    } catch (Throwable $e) {
        if ($session) {
            $session->close();
        }
        throw new RuntimeException(
            "Native Cacti SNMP session rejected the selected settings or algorithms.",
        );
    }
    if (!$session) {
        throw new RuntimeException(
            "Native Cacti SNMP session rejected the selected settings or algorithms.",
        );
    }
    $session->valueretrieval = SNMP_VALUE_OBJECT | SNMP_VALUE_PLAIN;
    $session->enum_print = true;
    // Retain our own numeric OID guard while accepting devices whose agent does not advertise ordered OIDs.
    $session->oid_increasing_check = false;
    return $session;
}

/** Reused Inventory service: nd hardware. */
function icct_backend_nd_hardware($values)
{
    $root = "1.3.6.1.2.1.47.1.1.1.1";
    $chassis = [];
    $ports = [];
    foreach (
        icct_backend_nd_column($values, $root, 5, 1, 2)
        as $index => $class
    ) {
        if (!in_array((int) $class, [3, 10], true)) {
            continue;
        }
        $row = [
            "entity_index" => (int) $index,
            "name" => icct_backend_nd_octets(
                icct_backend_nd_value(
                    $values,
                    $root . ".7." . $index,
                    4,
                    false,
                ),
            ),
            "position" => icct_backend_nd_value(
                $values,
                $root . ".6." . $index,
                2,
                false,
            ),
            "parent" => icct_backend_nd_value(
                $values,
                $root . ".4." . $index,
                2,
                false,
            ),
            "serial" => icct_backend_nd_octets(
                icct_backend_nd_value(
                    $values,
                    $root . ".11." . $index,
                    4,
                    false,
                ),
            ),
            "model" => icct_backend_nd_octets(
                icct_backend_nd_value(
                    $values,
                    $root . ".13." . $index,
                    4,
                    false,
                ),
            ),
        ];
        if ((int) $class === 3) {
            $chassis[] = $row;
        } else {
            $ports[] = $row;
        }
    }
    return ["chassis" => $chassis, "physical_ports" => $ports, "error" => ""];
}

/** Reused Inventory service: nd oid compare. */
function icct_backend_nd_oid_compare($a, $b)
{
    $a = explode(".", $a);
    $b = explode(".", $b);
    for ($i = 0; $i < min(count($a), count($b)); $i++) {
        if ((float) $a[$i] != (float) $b[$i]) {
            return (float) $a[$i] < (float) $b[$i] ? -1 : 1;
        }
    }
    return count($a) <=> count($b);
}

/** Reused Inventory service: nd snmp empty protocol. */
function icct_backend_nd_snmp_empty_protocol($protocol, $interfaces, $error)
{
    $data = [
        "interfaces" => $interfaces,
        "neighbors" => [],
        "warning" => $error,
    ];
    if ($protocol === "arp") {
        $data["endpoints"] = [];
    }
    if (in_array($protocol, ["lldp", "cdp"], true)) {
        $data += ["identity" => "", "name" => "", "ports" => []];
    }
    return $data;
}

/** Reused Inventory service: nd snmp end of subtree. */
function icct_backend_nd_snmp_end_of_subtree($session)
{
    return in_array((int) $session->getErrno(), [2, 8], true) &&
        preg_match(
            "/No more variables left|End of MIB|endOfMibView|No Such (?:Object|Name)/i",
            (string) $session->getError(),
        );
}

/** Reused Inventory service: nd snmp interfaces. */
function icct_backend_nd_snmp_interfaces($values)
{
    try {
        return icct_backend_nd_interfaces($values);
    } catch (RuntimeException $e) {
        return [];
    }
}

/** Reused Inventory service: nd snmp optional subtree. */
function icct_backend_nd_snmp_optional_subtree(
    $session,
    $root,
    $deadline,
    &$budget,
) {
    try {
        return icct_backend_nd_snmp_subtree(
            $session,
            $root,
            $deadline,
            $budget,
        );
    } catch (RuntimeException $e) {
        return [];
    }
}

/** Reused Inventory service: nd snmp scalar. */
function icct_backend_nd_snmp_scalar($session, $oid, $deadline)
{
    if (microtime(true) > $deadline) {
        throw new RuntimeException("Discovery time limit reached.");
    }
    $response = @$session->get($oid);
    if ($response === false || $session->getErrno()) {
        throw new RuntimeException(
            "SNMP request failed or the required MIB object is not readable: " .
                $oid,
        );
    }
    return icct_backend_nd_snmp_value($response, $oid);
}

/** Reused Inventory service: nd snmp security. */
function icct_backend_nd_snmp_security($host)
{
    if ((string) $host["snmp_version"] === "1") {
        return "SNMPv1";
    }
    if ((string) $host["snmp_version"] === "2") {
        return "SNMPv2c";
    }
    if ($host["snmp_username"] === "" || strlen($host["snmp_username"]) > 32) {
        throw new RuntimeException(
            "SNMPv3 requires a security name of 1–32 bytes.",
        );
    }
    $auth = $host["snmp_auth_protocol"];
    $priv = $host["snmp_priv_protocol"];
    if (
        !in_array(
            $auth,
            ["[None]", "MD5", "SHA", "SHA224", "SHA256", "SHA384", "SHA512"],
            true,
        ) ||
        !in_array(
            $priv,
            [
                "[None]",
                "DES",
                "AES",
                "AES128",
                "AES192",
                "AES192C",
                "AES256",
                "AES256C",
            ],
            true,
        )
    ) {
        throw new RuntimeException(
            "SNMPv3 algorithm is not a recognized native Cacti selection.",
        );
    }
    $hasAuth = $auth !== "[None]";
    $hasPriv = $priv !== "[None]";
    if (
        ($hasAuth && strlen($host["snmp_password"]) < 8) ||
        (!$hasAuth && $host["snmp_password"] !== "")
    ) {
        throw new RuntimeException(
            "SNMPv3 authentication settings are inconsistent; security was not downgraded.",
        );
    }
    if (
        ($hasPriv && strlen($host["snmp_priv_passphrase"]) < 8) ||
        (!$hasPriv && $host["snmp_priv_passphrase"] !== "")
    ) {
        throw new RuntimeException(
            "SNMPv3 privacy settings are inconsistent; encryption was not disabled.",
        );
    }
    if ($hasPriv && !$hasAuth) {
        throw new RuntimeException("SNMPv3 privacy requires authentication.");
    }
    if (strlen($host["snmp_context"]) > 255) {
        throw new RuntimeException(
            "SNMPv3 context exceeds the protocol maximum of 255 bytes.",
        );
    }
    return $hasPriv ? "authPriv" : ($hasAuth ? "authNoPriv" : "noAuthNoPriv");
}

/** Reused Inventory service: nd snmp subtree. */
function icct_backend_nd_snmp_subtree($session, $root, $deadline, &$budget)
{
    $values = [];
    $cursor = $root;
    while (true) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException(
                "Discovery time limit reached; partial result discarded.",
            );
        }
        $budget--;
        $result = @$session->getnext([$cursor]);
        if ($result === false || $session->getErrno()) {
            // PHP reports normal SNMPv1 and SNMPv2+ table endings as errors.
            if (icct_backend_nd_snmp_end_of_subtree($session)) {
                break;
            }
            throw new RuntimeException(
                "SNMP table read failed: " .
                    $root .
                    ". Partial result discarded.",
            );
        }
        if (!is_array($result) || count($result) !== 1) {
            throw new RuntimeException("Unexpected SNMP GETNEXT response.");
        }
        $oid = ltrim((string) array_key_first($result), ".");
        $v = current($result);
        if (
            !preg_match('/^[0-9]+(?:\.[0-9]+)+$/D', $oid) ||
            icct_backend_nd_oid_compare($oid, $cursor) <= 0
        ) {
            throw new RuntimeException("Non-increasing or malformed SNMP OID.");
        }
        if (strpos($oid, $root . ".") !== 0) {
            break;
        }
        $values[$oid] = icct_backend_nd_snmp_value($v, $oid);
        $cursor = $oid;
    }
    return $values;
}

/** Reused Inventory service: nd snmp value. */
function icct_backend_nd_snmp_value($response, $oid)
{
    if (
        !is_object($response) ||
        !isset($response->type, $response->value) ||
        in_array((int) $response->type, [128, 129, 130], true)
    ) {
        throw new RuntimeException(
            "SNMP object is unavailable or not readable: " . $oid,
        );
    }
    return [
        "type" => (int) $response->type,
        "value" => (string) $response->value,
    ];
}
