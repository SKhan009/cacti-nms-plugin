<?php
/** Shared SSH application contracts; native Cacti authentication and DB only. */
require_once __DIR__ . "/ssh_schema.php";
require_once __DIR__ . "/ssh_linux.php";
require_once __DIR__ . "/ssh_platform.php";

function nms_ssh_config()
{
	global $config;
	// One explicit configuration source; never depend on PHP-FPM environment inheritance.
	$path = $config["nms_ssh_config"] ?? dirname(__DIR__) . "/ssh.config.local.json";
	if (
		!is_string($path) ||
		strpos($path, "://") !== false ||
		!is_file($path) ||
		!is_readable($path) ||
		is_link($path)
	) {
		throw new RuntimeException(
			"SSH backend is not configured. Install ssh.config.local.json in the NMS plugin, or set the administrator-managed nms_ssh_config path in Cacti configuration.",
		);
	}
	if (
		PHP_OS_FAMILY !== "Windows" &&
		(fileperms($path) & 0022 || fileperms(dirname($path)) & 0022 || fileowner($path) !== 0)
	) {
		throw new RuntimeException(
			"SSH configuration must be root-owned; the file and its directory must not be group/world writable.",
		);
	}
	if (PHP_SAPI !== "cli" && is_writable($path)) {
		throw new RuntimeException("SSH configuration must not be writable by the web-server account.");
	}
	if (PHP_OS_FAMILY === "Windows") {
		foreach ([$path, dirname($path)] as $checked) {
			$acl = nms_ssh_windows_identity($checked);
			if ($acl["reparse"] || !in_array($acl["owner"], ["S-1-5-18", "S-1-5-32-544"], true)) {
				throw new RuntimeException("SSH configuration requires an Administrators or SYSTEM owner.");
			}
			foreach ($acl["rules"] as $rule) {
				if (
					$rule["allow"] &&
					$rule["rights"] & (2 | 4 | 16 | 64 | 256 | 65536 | 262144 | 524288) &&
					!in_array($rule["sid"], ["S-1-5-18", "S-1-5-32-544"], true)
				) {
					throw new RuntimeException("SSH configuration permits modification by a non-administrator.");
				}
			}
		}
	}
	$c = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
	foreach (["store_dir", "master_key", "origin", "poller_id"] as $key) {
		if (empty($c[$key])) {
			throw new RuntimeException("SSH service configuration missing: " . $key);
		}
	}
	foreach (["store_dir", "master_key"] as $key) {
		if (!nms_ssh_absolute_path($c[$key])) {
			throw new RuntimeException("Invalid SSH configuration path: " . $key);
		}
	}
	if (PHP_OS_FAMILY === "Windows") {
		if (
			!preg_match('/^127\.0\.0\.1:[0-9]+$/D', $c["control_listen"] ?? "") ||
			!nms_ssh_absolute_path($c["rpc_key"] ?? "") ||
			!preg_match('/^S-1-5-[0-9-]+$/D', $c["service_sid"] ?? "")
		) {
			throw new RuntimeException("Windows local RPC and service identity must be configured.");
		}
		$acl = nms_ssh_windows_identity($c["rpc_key"]);
		foreach ($acl["rules"] as $rule) {
			if (
				$rule["allow"] &&
				!in_array($rule["sid"], [$c["service_sid"], $c["web_sid"] ?? "", "S-1-5-18", "S-1-5-32-544"], true)
			) {
				throw new RuntimeException("Local RPC key grants access to an unexpected Windows identity.");
			}
		}
	} elseif (!nms_ssh_absolute_path($c["control_socket"] ?? "")) {
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
		throw new RuntimeException("Configure guacd_listen as a loopback host and port.");
	}
	return $c;
}

/**
 * Handles ssh manage.
 */
function nms_ssh_manage()
{
	nms_require_management(3);
	if (!api_user_realm_auth("ssh_presets.php")) {
		throw new RuntimeException("SSH management permission required.");
	}
}

/**
 * Handles ssh device.
 */
function nms_ssh_device($id, $require_enabled = true)
{
	$row = db_fetch_row_prepared(
		"SELECT h.id AS host_id,h.hostname,h.description,h.poller_id,h.disabled,h.deleted,
        d.profile_id,d.monitoring,d.interval_seconds,d.host_key,d.verified_endpoint,d.revision AS device_revision,
        d.preset_id,p.name AS preset_name,p.enabled,p.username,p.auth_method,p.credential_ref,p.port,p.connect_timeout,
        p.command_timeout,p.retries,p.keepalive,p.revision AS preset_revision
        FROM host h INNER JOIN plugin_nms_ssh_devices d ON d.host_id=h.id
        INNER JOIN plugin_nms_ssh_presets p ON p.id=d.preset_id WHERE h.id=?",
		[(int) $id],
	);
	if (!$row || $row["deleted"] !== "") {
		throw new RuntimeException("Existing Cacti device and SSH assignment required.");
	}
	if ($require_enabled && ($row["disabled"] !== "" || !(int) $row["enabled"])) {
		throw new RuntimeException("Device or SSH preset is disabled.");
	}
	if (!is_string($row["hostname"]) || !preg_match('/^[A-Za-z0-9_.:\-]+$/D', $row["hostname"])) {
		throw new RuntimeException("Unsupported Cacti device address.");
	}
	$row["endpoint"] = strtolower($row["hostname"]) . ":" . (int) $row["port"];
	return $row;
}

/**
 * Handles ssh fingerprint.
 */
function nms_ssh_fingerprint($key)
{
	$parts = explode(" ", trim($key));
	if (count($parts) < 2 || !($raw = base64_decode($parts[1], true))) {
		throw new RuntimeException("Invalid SSH host public key.");
	}
	return "SHA256:" . rtrim(base64_encode(hash("sha256", $raw, true)), "=");
}

/**
 * Handles ssh https.
 */
function nms_ssh_https()
{
	$c = nms_ssh_config();
	if (empty($_SERVER["HTTPS"]) || $_SERVER["HTTPS"] === "off") {
		throw new RuntimeException("SSH credentials and consoles require HTTPS.");
	}
	$origin = $_SERVER["HTTP_ORIGIN"] ?? "";
	if ($origin !== "" && !hash_equals($c["origin"], $origin)) {
		throw new RuntimeException("SSH request origin rejected.");
	}
}

/** Issue a one-time ticket only after native web authorization; never from the gateway. */
function nms_ssh_ticket($kind, $host_id = 0)
{
	nms_ssh_https();
	if (!in_array($kind, ["console", "observe", "test", "credential"], true)) {
		throw new InvalidArgumentException("Unsupported SSH operation.");
	}
	if ($kind === "console") {
		if (!api_user_realm_auth("ssh_console.php")) {
			throw new RuntimeException("SSH console permission required.");
		}
	} else {
		nms_ssh_manage();
	}
	$device = null;
	if ($host_id) {
		nms_require_device_access($host_id);
		$device = nms_ssh_device($host_id);
	}
	if ($kind !== "credential" && !$device) {
		throw new RuntimeException("Select an assigned Cacti device.");
	}
	$uid = nms_current_user_id();
	if ($uid < 1 || session_id() === "") {
		throw new RuntimeException("Authenticated Cacti session required.");
	}
	$id = bin2hex(random_bytes(32));
	$token = bin2hex(random_bytes(32));
	$lock = "nms_ssh_sessions";
	if ((int) db_fetch_cell_prepared("SELECT GET_LOCK(?,5)", [$lock]) !== 1) {
		throw new RuntimeException("SSH session allocation busy.");
	}
	try {
		if ($kind === "console") {
			$active =
				"kind='console' AND ((status='issued' AND expires_at>NOW()) OR (status='active' AND lease_until>NOW()))";
			if (
				(int) db_fetch_cell("SELECT COUNT(*) FROM plugin_nms_ssh_sessions WHERE " . $active) >= 10 ||
				(int) db_fetch_cell_prepared(
					"SELECT COUNT(*) FROM plugin_nms_ssh_sessions WHERE " . $active . " AND user_id=?",
					[$uid],
				) >= 2
			) {
				throw new RuntimeException("SSH console session limit reached.");
			}
		}
		if (
			!db_execute_prepared(
				"INSERT INTO plugin_nms_ssh_sessions (id,ticket_hash,session_hash,session_cookie,user_id,host_id,kind,preset_revision,device_revision,created_at,expires_at,lease_until) VALUES (?,?,?,?,?,?,?,?,?,NOW(),DATE_ADD(NOW(),INTERVAL 30 SECOND),DATE_ADD(NOW(),INTERVAL 12 SECOND))",
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

/** A live web session renews a console lease through Cacti's normal auth entry point. */
function nms_ssh_renew($id, $close = false)
{
	nms_ssh_https();
	if (!api_user_realm_auth("ssh_console.php")) {
		throw new RuntimeException("SSH console permission revoked.");
	}
	$s = db_fetch_row_prepared(
		"SELECT * FROM plugin_nms_ssh_sessions WHERE id=? AND kind='console' AND status IN ('issued','active') AND lease_until>NOW()",
		[$id],
	);
	if (
		!$s ||
		(int) $s["user_id"] !== nms_current_user_id() ||
		!hash_equals($s["session_hash"], hash("sha256", session_id()))
	) {
		throw new RuntimeException("SSH session expired or belongs to another browser session.");
	}
	nms_require_device_access((int) $s["host_id"]);
	$d = nms_ssh_device($s["host_id"]);
	if (
		(int) $d["preset_revision"] !== (int) $s["preset_revision"] ||
		(int) $d["device_revision"] !== (int) $s["device_revision"]
	) {
		throw new RuntimeException("SSH settings changed; reconnect explicitly.");
	}
	if ($close) {
		$saved = db_execute_prepared(
			"UPDATE plugin_nms_ssh_sessions SET status='closed',ended_at=NOW(),outcome='Operator disconnected' WHERE id=?",
			[$id],
		);
	} else {
		$saved = db_execute_prepared(
			"UPDATE plugin_nms_ssh_sessions SET lease_until=DATE_ADD(NOW(),INTERVAL 12 SECOND) WHERE id=?",
			[$id],
		);
	}
	if (!$saved) {
		throw new RuntimeException("Console authorization update failed.");
	}
}

/** Local RPC contains a one-time authorization ticket; secrets never go on command lines. */
function nms_ssh_rpc($request)
{
	$c = nms_ssh_config();
	$s = @stream_socket_client(
		PHP_OS_FAMILY === "Windows" ? "tcp://" . $c["control_listen"] : "unix://" . $c["control_socket"],
		$errno,
		$errstr,
		3,
	);
	if (!$s) {
		throw new RuntimeException("SSH backend unavailable. Check the nms-ssh service.");
	}
	stream_set_timeout($s, 45);
	try {
		$data = json_encode($request, JSON_THROW_ON_ERROR);
		if (PHP_OS_FAMILY === "Windows") {
			$data = nms_ssh_rpc_cipher($data, $c);
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
			throw new RuntimeException("SSH backend response timed out or exceeded limit.");
		}
		if (PHP_OS_FAMILY === "Windows") {
			$line = nms_ssh_rpc_cipher(trim($line), $c, true, "response:" . $responseContext);
		}
		$r = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
		if (!($r["ok"] ?? false)) {
			throw new RuntimeException($r["error"] ?? "SSH backend operation failed.");
		}
		return $r["data"];
	} finally {
		fclose($s);
	}
}

/**
 * Handles ssh current sample.
 */
function nms_ssh_current_sample($host_id)
{
	$d = nms_ssh_device($host_id);
	$s = db_fetch_row_prepared(
		"SELECT *, UNIX_TIMESTAMP(last_success) AS sample_time FROM plugin_nms_ssh_state WHERE host_id=?",
		[(int) $host_id],
	);
	if (
		!(int) $d["monitoring"] ||
		!$s ||
		$s["status"] !== "ok" ||
		!$s["sample_time"] ||
		time() - (int) $s["sample_time"] > 2 * (int) $d["interval_seconds"] ||
		time() < (int) $s["sample_time"] ||
		(int) $s["preset_revision"] !== (int) $d["preset_revision"] ||
		(int) $s["device_revision"] !== (int) $d["device_revision"] ||
		$s["endpoint"] !== $d["endpoint"]
	) {
		throw new RuntimeException("SSH reading is unknown or stale.");
	}
	return json_decode($s["sample_json"], true, 16, JSON_THROW_ON_ERROR);
}

/** Readiness is visible on all platforms; missing services never break other NMS pages. */
function nms_ssh_web_readiness()
{
	$issues = [];
	if (!nms_ssh_platform_supported()) {
		$issues[] = "SSH has service adapters for Linux, Windows and macOS only.";
	}
	if (empty($_SERVER["HTTPS"]) || $_SERVER["HTTPS"] === "off") {
		$issues[] = "HTTPS is required before entering credentials or opening consoles. This page currently uses HTTP.";
	}
	try {
		$c = nms_ssh_config();
		if (PHP_OS_FAMILY !== "Windows" && !file_exists($c["control_socket"])) {
			$issues[] = "SSH backend socket is absent. Configure and start the nms-ssh service.";
		}
	} catch (Throwable $e) {
		$issues[] = $e->getMessage();
	}
	return $issues;
}

/** Long-running workers must observe plugin revocation rather than an API's process cache. */
function nms_ssh_enabled()
{
	return (int) db_fetch_cell_prepared("SELECT status FROM plugin_config WHERE directory=?", ["nms"], "", false) === 1;
}
