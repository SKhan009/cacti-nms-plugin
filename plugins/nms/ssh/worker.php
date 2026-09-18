<?php
/** One process per operation. Console traffic and credential payloads use stdin, never argv. */
require_once __DIR__ . "/wire.php";
function nms_ssh_worker_line()
{
	global $nms_worker_channel, $nms_worker_wire;
	$line = fgets($nms_worker_channel, 196609);
	if (!$line) {
		throw new RuntimeException("Worker channel closed.");
	}
	return $nms_worker_wire->decode(trim($line));
}
function nms_ssh_emit($message)
{
	global $nms_worker_channel, $nms_worker_wire;
	if (!is_resource($nms_worker_channel)) {
		return;
	}
	@fwrite(
		$nms_worker_channel,
		$nms_worker_wire->encode(json_encode($message, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)) . "\n",
	);
	@fflush($nms_worker_channel);
}
$session = null;
$ssh = null;
$nms_worker_channel = null;
try {
	require_once __DIR__ . "/bootstrap.php";
	require_once __DIR__ . "/runtime.php";
	$endpoint = getenv("NMS_SSH_WORKER_ENDPOINT");
	$token = getenv("NMS_SSH_WORKER_TOKEN");
	if (!preg_match('~^tcp://127\.0\.0\.1:[0-9]+$~D', $endpoint) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
		throw new RuntimeException("Worker must be launched by the local gateway.");
	}
	$nms_worker_channel = stream_socket_client($endpoint, $errno, $error, 5);
	if (!$nms_worker_channel) {
		throw new RuntimeException("Worker gateway unavailable.");
	}
	stream_set_timeout($nms_worker_channel, 10);
	$nms_worker_wire = new NmsSshWire($token, true);
	fwrite($nms_worker_channel, hash_hmac("sha256", "nms-worker-hello", $token) . "\n");
	putenv("NMS_SSH_WORKER_TOKEN");
	unset($token);
	if (PHP_OS_FAMILY !== "Windows") {
		pcntl_async_signals(true);
		pcntl_signal(SIGTERM, function () {
			pcntl_signal(SIGTERM, SIG_IGN);
			throw new RuntimeException("Connection closed.");
		});
	}
	$line = nms_ssh_worker_line();
	if (!$line || strlen($line) > 131072) {
		throw new RuntimeException("Invalid SSH worker request.");
	}
	$r = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
	if (($r["kind"] ?? "") === "collect") {
		if (getenv("NMS_SSH_COLLECTOR") !== "1") {
			throw new RuntimeException("Collection requires the service scheduler.");
		}
		nms_ssh_collect((int) $r["host_id"]);
		exit();
	}
	$session = nms_ssh_consume($r, $r["expected_kind"] ?? null);
	$kind = $session["kind"];
	if ($kind === "console") {
		nms_ssh_emit(["type" => "authorized"]);
	}
	if ($kind === "credential") {
		$ref = (new NmsSshStore(nms_ssh_config()))->put(
			$r["auth_method"] ?? "",
			$r["secret"] ?? "",
			$r["passphrase"] ?? "",
		);
		nms_ssh_emit(["ok" => true, "data" => ["credential_ref" => $ref]]);
	} else {
		$d = nms_ssh_device($session["host_id"]);
		if ($kind === "observe") {
			$key = nms_ssh_connect($d, true);
			db_execute_prepared(
				"UPDATE plugin_nms_ssh_devices SET observed_key=?,observed_endpoint=?,observed_at=NOW() WHERE host_id=? AND revision=?",
				[$key, $d["endpoint"], $d["host_id"], $d["device_revision"]],
			);
			nms_ssh_emit([
				"ok" => true,
				"data" => ["fingerprint" => nms_ssh_fingerprint($key), "endpoint" => $d["endpoint"]],
			]);
		} else {
			if ($kind === "console") {
				require_once __DIR__ . "/guacamole/session.php";
				nms_guac_console($session, $r);
			} elseif ($kind === "test") {
				$ssh = nms_ssh_connect($d);
				nms_ssh_emit([
					"ok" => true,
					"data" => ["message" => "SSH host key verified and authentication succeeded."],
				]);
			} else {
				throw new RuntimeException("Unsupported SSH operation.");
			}
		}
	}
	if ($session) {
		nms_ssh_close_session($session["id"], "Completed");
	}
} catch (Throwable $e) {
	if (function_exists("pcntl_signal")) {
		pcntl_signal(SIGTERM, SIG_IGN);
	}
	if ($session) {
		try {
			nms_ssh_close_session($session["id"], "Operation failed: " . substr($e->getMessage(), 0, 200));
		} catch (Throwable $auditError) {
			@fwrite(STDERR, "SSH session audit update failed.\n");
		}
	}
	nms_ssh_emit([
		"ok" => false,
		"type" => "error",
		"error" =>
			$e instanceof RuntimeException
				? $e->getMessage()
				: "SSH operation failed. Check service dependencies and configuration.",
	]);
} finally {
	if (function_exists("pcntl_signal")) {
		pcntl_signal(SIGTERM, SIG_IGN);
	}
	if ($ssh) {
		try {
			$ssh->disconnect();
		} catch (Throwable $cleanupError) {
			/* The process exit closes remaining transport resources. */
		}
	}
}
