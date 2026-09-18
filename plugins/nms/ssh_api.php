<?php
/** Cacti session validation stays in HTTP; Guacamole workers receive expiring leases. */
require __DIR__ . "/../../include/auth.php";
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/database.php";
require_once __DIR__ . "/includes/ssh.php";
header("Content-Type: application/json");
header("Cache-Control: no-store");
try {
	nms_ssh_require_schema();
	nms_ssh_https();
	if ($_SERVER["REQUEST_METHOD"] !== "POST" || !csrf_check_tokens($_POST["__csrf_magic"] ?? "")) {
		throw new RuntimeException("Authenticated POST with a valid request token required.");
	}
	if (!api_user_realm_auth("ssh_console.php")) {
		throw new RuntimeException("SSH console permission required.");
	}
	$action = $_POST["action"] ?? "";
	if ($action === "connect") {
		$id = (int) ($_POST["host_id"] ?? 0);
		nms_require_device_access($id);
		$d = nms_ssh_device($id);
		if (!$d["host_key"] || $d["verified_endpoint"] !== $d["endpoint"]) {
			throw new RuntimeException("Verify this device host key before connecting.");
		}
		$result = nms_ssh_ticket("console", $id);
		$request = $result + [
			"console_action" => "open",
			"cookie" => session_name() . "=" . rawurlencode(session_id()),
			"user_id" => nms_current_user_id(),
			"browser_hash" => hash("sha256", session_id()),
			"width" => (int) ($_POST["width"] ?? 1000),
			"height" => (int) ($_POST["height"] ?? 550),
		];
		$nms_guac_csrf_token = csrf_get_tokens();
		session_write_close();
		$result = nms_ssh_rpc($request);
	} elseif ($action === "read" || $action === "write") {
		$sid = (string) ($_POST["session_id"] ?? "");
		nms_ssh_renew($sid);
		$request = [
			"console_action" => $action,
			"id" => $sid,
			"user_id" => nms_current_user_id(),
			"browser_hash" => hash("sha256", session_id()),
		];
		if ($action === "write") {
			$request["data"] = (string) ($_POST["data"] ?? "");
			if (strlen($request["data"]) > 44000) {
				throw new RuntimeException("Terminal input exceeded limit.");
			}
		}
		$nms_guac_csrf_token = csrf_get_tokens();
		session_write_close();
		$result = nms_ssh_rpc($request);
	} elseif ($action === "renew" || $action === "disconnect") {
		nms_ssh_renew((string) ($_POST["session_id"] ?? ""), $action === "disconnect");
		$result = ["renewed" => $action === "renew"];
	} else {
		throw new RuntimeException("Unsupported console action.");
	}
	print json_encode(
		["ok" => true, "data" => $result, "csrf" => $nms_guac_csrf_token ?? csrf_get_tokens()],
		JSON_THROW_ON_ERROR,
	);
} catch (Throwable $e) {
	http_response_code(400);
	print json_encode([
		"ok" => false,
		"error" => $e instanceof RuntimeException ? $e->getMessage() : "SSH request failed.",
	]);
}
