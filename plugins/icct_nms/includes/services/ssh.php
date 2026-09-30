<?php
/** ICCT-owned ssh services, derived from the existing ICCT NMS implementation. */

/** Reused Inventory service: ssh config. */
function icct_backend_ssh_config()
{
    global $config;
    // One explicit configuration source; never depend on PHP-FPM environment inheritance.
    $path =
        $config["icct_backend_ssh_config"] ??
        ICCT_NMS_ROOT . "/ssh.config.local.json";
    if (
        !is_string($path) ||
        strpos($path, "://") !== false ||
        !is_file($path) ||
        !is_readable($path) ||
        is_link($path)
    ) {
        throw new RuntimeException(
            "SSH backend is not configured. Install ssh.config.local.json in the ICCT NMS plugin, or set the administrator-managed icct_backend_ssh_config path in Cacti configuration.",
        );
    }
    if (
        PHP_OS_FAMILY !== "Windows" &&
        (fileperms($path) & 0022 ||
            fileperms(dirname($path)) & 0022 ||
            fileowner($path) !== 0)
    ) {
        throw new RuntimeException(
            "SSH configuration must be root-owned; the file and its directory must not be group/world writable.",
        );
    }
    if (PHP_SAPI !== "cli" && is_writable($path)) {
        throw new RuntimeException(
            "SSH configuration must not be writable by the web-server account.",
        );
    }
    if (PHP_OS_FAMILY === "Windows") {
        foreach ([$path, dirname($path)] as $checked) {
            $acl = icct_backend_ssh_windows_identity($checked);
            if (
                $acl["reparse"] ||
                !in_array($acl["owner"], ["S-1-5-18", "S-1-5-32-544"], true)
            ) {
                throw new RuntimeException(
                    "SSH configuration requires an Administrators or SYSTEM owner.",
                );
            }
            foreach ($acl["rules"] as $rule) {
                if (
                    $rule["allow"] &&
                    $rule["rights"] &
                        (2 | 4 | 16 | 64 | 256 | 65536 | 262144 | 524288) &&
                    !in_array($rule["sid"], ["S-1-5-18", "S-1-5-32-544"], true)
                ) {
                    throw new RuntimeException(
                        "SSH configuration permits modification by a non-administrator.",
                    );
                }
            }
        }
    }
    $c = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    foreach (["store_dir", "master_key", "origin", "poller_id"] as $key) {
        if (empty($c[$key])) {
            throw new RuntimeException(
                "SSH service configuration missing: " . $key,
            );
        }
    }
    foreach (["store_dir", "master_key"] as $key) {
        if (!icct_backend_ssh_absolute_path($c[$key])) {
            throw new RuntimeException(
                "Invalid SSH configuration path: " . $key,
            );
        }
    }
    if (PHP_OS_FAMILY === "Windows") {
        if (
            !preg_match(
                '/^127\.0\.0\.1:[0-9]+$/D',
                $c["control_listen"] ?? "",
            ) ||
            !icct_backend_ssh_absolute_path($c["rpc_key"] ?? "") ||
            !preg_match('/^S-1-5-[0-9-]+$/D', $c["service_sid"] ?? "")
        ) {
            throw new RuntimeException(
                "Windows local RPC and service identity must be configured.",
            );
        }
        $acl = icct_backend_ssh_windows_identity($c["rpc_key"]);
        foreach ($acl["rules"] as $rule) {
            if (
                $rule["allow"] &&
                !in_array(
                    $rule["sid"],
                    [
                        $c["service_sid"],
                        $c["web_sid"] ?? "",
                        "S-1-5-18",
                        "S-1-5-32-544",
                    ],
                    true,
                )
            ) {
                throw new RuntimeException(
                    "Local RPC key grants access to an unexpected Windows identity.",
                );
            }
        }
    } elseif (!icct_backend_ssh_absolute_path($c["control_socket"] ?? "")) {
        throw new RuntimeException("Configure the SSH Unix control socket.");
    }
    if (!preg_match('~^https://[a-zA-Z0-9.\-]+(?::[0-9]+)?$~D', $c["origin"])) {
        throw new RuntimeException("SSH requires an HTTPS origin.");
    }
    if (
        !preg_match('/^127\.0\.0\.1:[0-9]{1,5}$/D', $c["guacd_listen"] ?? "") ||
        (int) substr(strrchr($c["guacd_listen"], ":"), 1) < 1 ||
        (int) substr(strrchr($c["guacd_listen"], ":"), 1) > 65535
    ) {
        throw new RuntimeException(
            "Configure guacd_listen as a loopback host and port.",
        );
    }
    return $c;
}

/** Reused Inventory service: ssh device. */
function icct_backend_ssh_device($id, $require_enabled = true)
{
    $row = db_fetch_row_prepared(
        "SELECT h.id AS host_id,h.hostname,h.description,h.poller_id,h.disabled,h.deleted,
        d.profile_id,d.monitoring,d.interval_seconds,d.host_key,d.verified_endpoint,d.revision AS device_revision,
        d.preset_id,p.name AS preset_name,p.enabled,p.username,p.auth_method,p.credential_ref,p.port,p.connect_timeout,
        p.command_timeout,p.retries,p.keepalive,p.revision AS preset_revision
        FROM host h INNER JOIN plugin_icct_nms_ssh_devices d ON d.host_id=h.id
        INNER JOIN plugin_icct_nms_ssh_presets p ON p.id=d.preset_id WHERE h.id=?",
        [(int) $id],
    );
    if (!$row || $row["deleted"] !== "") {
        throw new RuntimeException(
            "Existing Cacti device and SSH assignment required.",
        );
    }
    if (
        $require_enabled &&
        (!icct_backend_protocol_enabled($id, "ssh") ||
            $row["disabled"] !== "" ||
            !(int) $row["enabled"])
    ) {
        throw new RuntimeException("Device or SSH preset is disabled.");
    }
    if (
        !is_string($row["hostname"]) ||
        !preg_match('/^[A-Za-z0-9_.:\-]+$/D', $row["hostname"])
    ) {
        throw new RuntimeException("Unsupported Cacti device address.");
    }
    $row["endpoint"] = strtolower($row["hostname"]) . ":" . (int) $row["port"];
    return $row;
}

/** Reused Inventory service: ssh https. */
function icct_backend_ssh_https()
{
    $c = icct_backend_ssh_config();
    if (empty($_SERVER["HTTPS"]) || $_SERVER["HTTPS"] === "off") {
        throw new RuntimeException(
            "SSH credentials and consoles require HTTPS.",
        );
    }
    $origin = $_SERVER["HTTP_ORIGIN"] ?? "";
    if ($origin !== "" && !hash_equals($c["origin"], $origin)) {
        throw new RuntimeException("SSH request origin rejected.");
    }
}

/** Reused Inventory service: ssh manage. */
function icct_backend_ssh_manage()
{
    icct_backend_require_management(3);
    if (!api_user_realm_auth("protocol.php")) {
        throw new RuntimeException("SSH management permission required.");
    }
}

/** Reused Inventory service: ssh rpc. */
function icct_backend_ssh_rpc($request)
{
    $c = icct_backend_ssh_config();
    $s = @stream_socket_client(
        PHP_OS_FAMILY === "Windows"
            ? "tcp://" . $c["control_listen"]
            : "unix://" . $c["control_socket"],
        $errno,
        $errstr,
        3,
    );
    if (!$s) {
        throw new RuntimeException(
            "SSH backend unavailable. Check the nms-ssh service.",
        );
    }
    stream_set_timeout($s, 45);
    try {
        $data = json_encode($request, JSON_THROW_ON_ERROR);
        if (PHP_OS_FAMILY === "Windows") {
            $data = icct_backend_ssh_rpc_cipher($data, $c);
        }
        $responseContext = hash("sha256", $data);
        $data .= "\n";
        while ($data !== "") {
            $n = fwrite($s, $data);
            if (!$n) {
                throw new RuntimeException("SSH backend write failed.");
            }
            $data = substr($data, $n);
        }
        $line = fgets($s, 196609);
        if (!$line || strlen($line) > 196608) {
            throw new RuntimeException(
                "SSH backend response timed out or exceeded limit.",
            );
        }
        if (PHP_OS_FAMILY === "Windows") {
            $line = icct_backend_ssh_rpc_cipher(
                trim($line),
                $c,
                true,
                "response:" . $responseContext,
            );
        }
        $r = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
        if (!($r["ok"] ?? false)) {
            throw new RuntimeException(
                $r["error"] ?? "SSH backend operation failed.",
            );
        }
        return $r["data"];
    } finally {
        fclose($s);
    }
}

/** Reused Inventory service: ssh ticket. */
function icct_backend_ssh_ticket($kind, $host_id = 0)
{
    icct_backend_ssh_https();
    if (!in_array($kind, ["console", "observe", "test", "credential"], true)) {
        throw new InvalidArgumentException("Unsupported SSH operation.");
    }
    if ($kind === "console") {
        if (!api_user_realm_auth("protocol.php")) {
            throw new RuntimeException("SSH console permission required.");
        }
    } else {
        icct_backend_ssh_manage();
    }
    $device = null;
    if ($host_id) {
        icct_backend_require_device_access($host_id);
        $device = icct_backend_ssh_device($host_id);
    }
    if ($kind !== "credential" && !$device) {
        throw new RuntimeException("Select an assigned Cacti device.");
    }
    $uid = icct_backend_current_user_id();
    if ($uid < 1 || session_id() === "") {
        throw new RuntimeException("Authenticated Cacti session required.");
    }
    $id = bin2hex(random_bytes(32));
    $token = bin2hex(random_bytes(32));
    $lock = "icct_backend_ssh_sessions";
    if ((int) db_fetch_cell_prepared("SELECT GET_LOCK(?,5)", [$lock]) !== 1) {
        throw new RuntimeException("SSH session allocation busy.");
    }
    try {
        if ($kind === "console") {
            $active =
                "kind='console' AND ((status='issued' AND expires_at>NOW()) OR (status='active' AND lease_until>NOW()))";
            if (
                (int) db_fetch_cell(
                    "SELECT COUNT(*) FROM plugin_icct_nms_ssh_sessions WHERE " .
                        $active,
                ) >= 10 ||
                (int) db_fetch_cell_prepared(
                    "SELECT COUNT(*) FROM plugin_icct_nms_ssh_sessions WHERE " .
                        $active .
                        " AND user_id=?",
                    [$uid],
                ) >= 2
            ) {
                throw new RuntimeException(
                    "SSH console session limit reached.",
                );
            }
        }
        if (
            !db_execute_prepared(
                "INSERT INTO plugin_icct_nms_ssh_sessions (id,ticket_hash,session_hash,session_cookie,user_id,host_id,kind,preset_revision,device_revision,created_at,expires_at,lease_until) VALUES (?,?,?,?,?,?,?,?,?,NOW(),DATE_ADD(NOW(),INTERVAL 30 SECOND),DATE_ADD(NOW(),INTERVAL 12 SECOND))",
                [
                    $id,
                    hash("sha256", $token),
                    hash("sha256", session_id()),
                    session_name(),
                    $uid,
                    (int) $host_id,
                    $kind,
                    $device["preset_revision"] ?? null,
                    $device["device_revision"] ?? null,
                ],
            )
        ) {
            throw new RuntimeException("SSH ticket storage failed.");
        }
    } finally {
        db_fetch_cell_prepared("SELECT RELEASE_LOCK(?)", [$lock]);
    }
    return ["id" => $id, "token" => $token];
}
