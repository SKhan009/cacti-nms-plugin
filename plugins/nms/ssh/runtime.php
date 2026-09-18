<?php
require_once __DIR__ . "/store.php";
require_once __DIR__ . "/strict_ssh.php";

/**
 * Handles ssh consume.
 */
function nms_ssh_consume($request, $expected_kind = null)
{
	$id = $request["id"] ?? "";
	$token = $request["token"] ?? "";
	if (
		!is_string($id) ||
		!is_string($token) ||
		!preg_match('/^[a-f0-9]{64}$/D', $id) ||
		!preg_match('/^[a-f0-9]{64}$/D', $token)
	) {
		throw new RuntimeException("Invalid SSH ticket.");
	}
	db_execute("START TRANSACTION");
	try {
		$s = db_fetch_row_prepared(
			"SELECT * FROM plugin_nms_ssh_sessions WHERE id=? AND status='issued' AND expires_at>NOW() FOR UPDATE",
			[$id],
		);
		if (
			!$s ||
			!hash_equals($s["ticket_hash"], hash("sha256", $token)) ||
			($expected_kind && $expected_kind !== "rpc" && $s["kind"] !== $expected_kind) ||
			($expected_kind === "rpc" && $s["kind"] === "console")
		) {
			throw new RuntimeException("SSH ticket expired, consumed or invalid.");
		}
		if ($s["kind"] === "console") {
			$cookies = [];
			foreach (explode(";", $request["cookie"] ?? "") as $part) {
				$pair = explode("=", trim($part), 2);
				if (count($pair) === 2) {
					$cookies[$pair[0]] = rawurldecode($pair[1]);
				}
			}
			if (
				!isset($cookies[$s["session_cookie"]]) ||
				!hash_equals($s["session_hash"], hash("sha256", $cookies[$s["session_cookie"]]))
			) {
				throw new RuntimeException("Console ticket belongs to another browser session.");
			}
		}
		if (db_fetch_cell_prepared("SELECT enabled FROM user_auth WHERE id=?", [$s["user_id"]]) !== "on") {
			throw new RuntimeException("Cacti account disabled.");
		}
		$_SESSION = ["sess_user_id" => (int) $s["user_id"]];
		if ($s["kind"] === "console") {
			if (!api_user_realm_auth("ssh_console.php")) {
				throw new RuntimeException("Console permission denied.");
			}
		} else {
			nms_ssh_manage();
		}
		if ($s["host_id"]) {
			if (!is_device_allowed((int) $s["host_id"], (int) $s["user_id"])) {
				throw new RuntimeException("Device access denied.");
			}
			$d = nms_ssh_device($s["host_id"]);
			if (
				(int) $s["preset_revision"] !== (int) $d["preset_revision"] ||
				(int) $s["device_revision"] !== (int) $d["device_revision"]
			) {
				throw new RuntimeException("SSH assignment changed.");
			}
		}
		if (
			!db_execute_prepared(
				"UPDATE plugin_nms_ssh_sessions SET status='active',started_at=NOW(),ticket_hash='' WHERE id=?",
				[$id],
			)
		) {
			throw new RuntimeException("SSH ticket consumption failed.");
		}
		db_execute("COMMIT");
		return $s;
	} catch (Throwable $e) {
		db_execute("ROLLBACK");
		throw $e;
	}
}

/**
 * Handles ssh session live.
 */
function nms_ssh_session_live($id)
{
	$s = db_fetch_row_prepared(
		"SELECT s.* FROM plugin_nms_ssh_sessions s INNER JOIN user_auth u ON u.id=s.user_id WHERE s.id=? AND s.status='active' AND s.lease_until>NOW() AND u.enabled='on'",
		[$id],
	);
	if (!$s || !nms_ssh_enabled()) {
		return false;
	}
	try {
		$d = nms_ssh_device($s["host_id"]);
		return (int) $d["preset_revision"] === (int) $s["preset_revision"] &&
			(int) $d["device_revision"] === (int) $s["device_revision"] &&
			$d["verified_endpoint"] === $d["endpoint"];
	} catch (Throwable $e) {
		return false;
	}
}

/**
 * Handles ssh close session.
 */
function nms_ssh_close_session($id, $outcome)
{
	db_execute_prepared(
		"UPDATE plugin_nms_ssh_sessions SET status='closed',ended_at=NOW(),outcome=? WHERE id=? AND status IN ('issued','active')",
		[substr($outcome, 0, 250), $id],
	);
}

/** Enforce a wall-clock budget even while an SSH peer stalls inside a blocking read. */
function nms_ssh_deadline($seconds, $operation)
{
	if (PHP_OS_FAMILY === "Windows") {
		global $nms_worker_channel;
		if (!is_resource($nms_worker_channel)) {
			throw new RuntimeException("Gateway-supervised deadline required.");
		}
		nms_ssh_emit(["type" => "worker_deadline", "seconds" => max(1, (int) $seconds)]);
		$ack = json_decode(nms_ssh_worker_line(), true);
		if (($ack["type"] ?? "") !== "deadline_ready") {
			throw new RuntimeException("Gateway deadline was not confirmed.");
		}
		try {
			return $operation();
		} finally {
			nms_ssh_emit(["type" => "worker_deadline", "seconds" => 0]);
			$ack = json_decode(nms_ssh_worker_line(), true);
			if (($ack["type"] ?? "") !== "deadline_ready") {
				throw new RuntimeException("Gateway deadline cancellation failed.");
			}
		}
	}
	$previous = pcntl_signal_get_handler(SIGALRM);
	$async = pcntl_async_signals(true);
	pcntl_signal(SIGALRM, function () {
		throw new RuntimeException("SSH operation timed out.");
	});
	pcntl_alarm(max(1, (int) $seconds));
	try {
		return $operation();
	} finally {
		pcntl_alarm(0);
		pcntl_signal(SIGALRM, $previous);
		pcntl_async_signals($async);
	}
}

/**
 * Handles ssh connect.
 */
function nms_ssh_connect($device, $observe = false)
{
	$c = nms_ssh_config();
	if ((int) $device["poller_id"] !== (int) $c["poller_id"]) {
		throw new RuntimeException("Device belongs to a different Cacti collector; SSH is not configured there.");
	}
	if (!$observe && (!$device["host_key"] || $device["verified_endpoint"] !== $device["endpoint"])) {
		throw new RuntimeException("Verify the host key for this device address and port before connecting.");
	}
	$ssh = null;
	$key = null;
	// Retry only transport establishment, using identical credentials and algorithms.
	for ($attempt = 0; $attempt <= (int) $device["retries"]; $attempt++) {
		try {
			$ssh = new NmsStrictSsh($device["hostname"], (int) $device["port"], (int) $device["connect_timeout"]);
			$ssh->setPreferredAlgorithms([
				"kex" => ["curve25519-sha256", "ecdh-sha2-nistp256", "diffie-hellman-group14-sha256"],
				"hostkey" => ["ssh-ed25519", "ecdsa-sha2-nistp256", "rsa-sha2-512", "rsa-sha2-256"],
				"client_to_server" => [
					"crypt" => ["aes256-gcm@openssh.com", "aes128-gcm@openssh.com", "aes256-ctr", "aes128-ctr"],
					"mac" => ["hmac-sha2-256-etm@openssh.com", "hmac-sha2-512-etm@openssh.com", "hmac-sha2-256"],
				],
				"server_to_client" => [
					"crypt" => ["aes256-gcm@openssh.com", "aes128-gcm@openssh.com", "aes256-ctr", "aes128-ctr"],
					"mac" => ["hmac-sha2-256-etm@openssh.com", "hmac-sha2-512-etm@openssh.com", "hmac-sha2-256"],
				],
			]);
			$key = nms_ssh_deadline($device["connect_timeout"], function () use ($ssh) {
				return $ssh->getServerPublicHostKey();
			});
			if (!is_string($key) || $key === "") {
				throw new RuntimeException("No SSH host key.");
			}
			break;
		} catch (Throwable $e) {
			if ($ssh) {
				$ssh->disconnect();
			}
			if ($attempt === (int) $device["retries"]) {
				throw new RuntimeException("SSH handshake failed or timed out.");
			}
		}
	}
	if ($observe) {
		$ssh->disconnect();
		return $key;
	}
	if (!hash_equals($device["host_key"], $key)) {
		$ssh->disconnect();
		throw new RuntimeException("SSH host key changed. Connection blocked.");
	}
	$credential = (new NmsSshStore($c))->get($device["credential_ref"]);
	if ($credential["method"] !== $device["auth_method"]) {
		throw new RuntimeException("Credential type does not match the selected authentication method.");
	}
	try {
		$auth =
			$device["auth_method"] === "key"
				? phpseclib3\Crypt\PublicKeyLoader::loadPrivateKey($credential["secret"], $credential["passphrase"])
				: $credential["secret"];
		if (
			!nms_ssh_deadline($device["connect_timeout"], function () use ($ssh, $device, $auth) {
				return $ssh->login($device["username"], $auth);
			})
		) {
			throw new RuntimeException("Authentication rejected.");
		}
	} catch (Throwable $e) {
		$ssh->disconnect();
		throw new RuntimeException("SSH authentication failed using the selected credential.");
	}
	unset($credential, $auth);
	$ssh->setTimeout((int) $device["command_timeout"]);
	$ssh->setKeepAlive((int) $device["keepalive"]);
	return $ssh;
}

/**
 * Handles ssh collect.
 */
function nms_ssh_collect($host_id)
{
	$lock = "nms_ssh_collect_" . (int) $host_id;
	if ((int) db_fetch_cell_prepared("SELECT GET_LOCK(?,0)", [$lock]) !== 1) {
		return;
	}
	$ssh = null;
	try {
		$d = nms_ssh_device($host_id);
		if (!(int) $d["monitoring"]) {
			return;
		}
		db_execute_prepared(
			"INSERT INTO plugin_nms_ssh_state (host_id,status,last_attempt) VALUES (?,'collecting',NOW()) ON DUPLICATE KEY UPDATE status='collecting',last_attempt=NOW(),last_error=''",
			[$host_id],
		);
		if (
			$d["profile_id"] !== "linux-health-v1" ||
			db_fetch_cell_prepared("SELECT definition_hash FROM plugin_nms_ssh_profiles WHERE id=?", [
				$d["profile_id"],
			]) !== hash("sha256", nms_ssh_linux_command())
		) {
			throw new RuntimeException("Unsupported or mismatched SSH command profile.");
		}
		$ssh = nms_ssh_connect($d);
		$output = "";
		$ssh->exec(nms_ssh_linux_command(), function ($chunk) use (&$output) {
			$output .= $chunk;
			if (strlen($output) > 65536) {
				throw new RuntimeException("Linux health response exceeded limit.");
			}
		});
		if ($ssh->isTimeout() || $ssh->getExitStatus() !== 0) {
			throw new RuntimeException("Linux health command failed or timed out.");
		}
		$sample = nms_ssh_linux_parse($output);
		$now = nms_ssh_device($host_id);
		if (
			$now["preset_revision"] !== $d["preset_revision"] ||
			$now["device_revision"] !== $d["device_revision"] ||
			$now["endpoint"] !== $d["endpoint"]
		) {
			throw new RuntimeException("SSH settings changed during collection.");
		}
		if (
			!db_execute_prepared(
				"UPDATE plugin_nms_ssh_state SET status='ok',last_success=NOW(),last_error='',sample_json=?,preset_revision=?,device_revision=?,endpoint=? WHERE host_id=?",
				[
					json_encode($sample, JSON_THROW_ON_ERROR),
					$d["preset_revision"],
					$d["device_revision"],
					$d["endpoint"],
					$host_id,
				],
			)
		) {
			throw new RuntimeException("SSH reading storage failed.");
		}
	} catch (Throwable $e) {
		db_execute_prepared(
			"INSERT INTO plugin_nms_ssh_state (host_id,status,last_attempt,last_error) VALUES (?,'failed',NOW(),?) ON DUPLICATE KEY UPDATE status='failed',last_error=VALUES(last_error),last_attempt=NOW()",
			[$host_id, substr($e->getMessage(), 0, 250)],
		);
	} finally {
		if ($ssh) {
			$ssh->disconnect();
		}
		db_fetch_cell_prepared("SELECT RELEASE_LOCK(?)", [$lock]);
	}
}
