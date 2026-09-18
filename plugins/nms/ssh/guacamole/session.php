<?php
require_once __DIR__ . "/protocol.php";
/** Only the backend can decrypt credentials or select a guacd destination. */
function nms_guac_console($session, $request)
{
	global $nms_worker_channel, $nms_worker_wire;
	$c = nms_ssh_config();
	$d = nms_ssh_device($session["host_id"]);
	if ((int) $d["poller_id"] !== (int) $c["poller_id"]) {
		throw new RuntimeException("Device belongs to another collector.");
	}
	if (!$d["host_key"] || $d["verified_endpoint"] !== $d["endpoint"]) {
		throw new RuntimeException("Verify this device host key before connecting.");
	}
	$cred = (new NmsSshStore($c))->get($d["credential_ref"]);
	if ($cred["method"] !== $d["auth_method"]) {
		throw new RuntimeException("Credential method mismatch.");
	}
	$params = [
		"hostname" => $d["hostname"],
		"port" => (string) $d["port"],
		"username" => $d["username"],
		"host-key" => "[" . $d["hostname"] . "]:" . $d["port"] . " " . $d["host_key"],
		"timeout" => (string) $d["connect_timeout"],
		"server-alive-interval" => (string) max(2, (int) $d["keepalive"]),
		"font-name" => "monospace",
		"font-size" => "14",
		"color-scheme" => "gray-black",
		"enable-sftp" => "false",
		"disable-copy" => "true",
		"disable-paste" => "true",
		"backspace" => "127",
		"scrollback" => "1000",
	];
	if ($cred["method"] === "key") {
		$key = phpseclib3\Crypt\PublicKeyLoader::loadPrivateKey($cred["secret"], $cred["passphrase"]);
		$params["private-key"] = $key->withPassword(false)->toString("OpenSSH");
	} else {
		$params["password"] = $cred["secret"];
	}
	unset($cred, $key);
	$guac = @stream_socket_client("tcp://" . $c["guacd_listen"], $errno, $error, 5);
	if (!$guac) {
		throw new RuntimeException("Apache guacd is unavailable. Check the nms-guacd service.");
	}
	stream_set_timeout($guac, min(30, (int) $d["connect_timeout"] + 5));
	$send = function ($data) use ($guac) {
		while ($data !== "") {
			$n = fwrite($guac, $data);
			if (!$n) {
				throw new RuntimeException("Guacamole connection closed.");
			}
			$data = substr($data, $n);
		}
	};
	try {
		$send(nms_guac_instruction(["select", "ssh"]));
		$buffer = "";
		$args = null;
		while (!$args) {
			$chunk = fread($guac, 8192);
			if (!$chunk) {
				throw new RuntimeException("Guacamole handshake timed out.");
			}
			$buffer .= $chunk;
			$args = nms_guac_parse($buffer);
		}
		$names = $args["parts"];
		if (array_shift($names) !== "args" || !in_array("host-key", $names, true)) {
			throw new RuntimeException("Guacd SSH host verification support is required.");
		}
		$width = max(100, min(4096, (int) ($request["width"] ?? 1000)));
		$height = max(100, min(2160, (int) ($request["height"] ?? 550)));
		$send(
			nms_guac_instruction(["size", $width, $height, 96]) .
				nms_guac_instruction(["audio"]) .
				nms_guac_instruction(["video"]) .
				nms_guac_instruction(["image", "image/png", "image/jpeg"]),
		);
		$values = ["connect"];
		foreach ($names as $name) {
			$values[] = strpos($name, "VERSION_") === 0 ? "VERSION_1_5_0" : $params[$name] ?? "";
		}
		$send(nms_guac_instruction($values));
		unset($params, $values);
		$ready = false;
		$inputBuffer = "";
		$lastInput = time();
		$lastCheck = 0;
		$started = time();
		stream_set_blocking($nms_worker_channel, false);
		stream_set_blocking($guac, false);
		while (!feof($guac) && !feof($nms_worker_channel)) {
			if (time() - $lastInput >= 600) {
				throw new RuntimeException("Console idle timeout.");
			}
			if (!$ready && time() - $started > 30) {
				throw new RuntimeException("Guacamole SSH connection timed out.");
			}
			if (time() > $lastCheck) {
				if (!nms_ssh_session_live($session["id"])) {
					throw new RuntimeException("Console authorization expired or settings changed.");
				}
				$lastCheck = time();
			}
			while ($m = nms_guac_parse($buffer)) {
				$op = $m["parts"][0];
				if ($op === "require") {
					throw new RuntimeException(
						"Guacamole requested another credential; authentication changes are not permitted.",
					);
				}
				if ($op === "ready") {
					$ready = true;
					nms_ssh_emit(["type" => "connected"]);
					continue;
				}
				if ($op === "error") {
					throw new RuntimeException(
						"Guacamole rejected the SSH connection. Check credentials, host key and guacd service diagnostics.",
					);
				}
				if ($ready) {
					nms_ssh_emit(["type" => "guac", "data" => base64_encode($m["raw"])]);
				}
			}
			$read = [$guac, $nms_worker_channel];
			$write = $except = [];
			if (!stream_select($read, $write, $except, 0, 200000)) {
				continue;
			}
			foreach ($read as $source) {
				$chunk = fread($source, 16384);
				if ($chunk === false) {
					throw new RuntimeException("Guacamole stream failed.");
				}
				if ($source === $guac) {
					$buffer .= $chunk;
					continue;
				}
				$inputBuffer .= $chunk;
				if (strlen($inputBuffer) > 196608) {
					throw new RuntimeException("Worker input exceeded limit.");
				}
				while (($p = strpos($inputBuffer, "\n")) !== false) {
					$m = json_decode(
						$nms_worker_wire->decode(substr($inputBuffer, 0, $p)),
						true,
						8,
						JSON_THROW_ON_ERROR,
					);
					$inputBuffer = substr($inputBuffer, $p + 1);
					if (($m["type"] ?? "") === "disconnect") {
						return;
					}
					if (($m["type"] ?? "") !== "guac") {
						throw new RuntimeException("Unsupported terminal operation.");
					}
					$raw = base64_decode($m["data"] ?? "", true);
					if ($raw === false) {
						throw new RuntimeException("Invalid terminal input.");
					}
					if (nms_guac_validate_input($raw)) {
						$lastInput = time();
					}
					$send($raw);
				}
			}
		}
	} finally {
		fclose($guac);
	}
}
