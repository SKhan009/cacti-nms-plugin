<?php
/** Unprivileged local RPC broker and collector. Browser consoles use Guacamole over HTTPS only. */
try {
	require_once __DIR__ . "/bootstrap.php";
	require_once __DIR__ . "/runtime.php";
	require_once __DIR__ . "/process.php";
	require_once __DIR__ . "/guacamole/protocol.php";

	final class NmsSshGateway
	{
		private $loop;
		private $config;
		private $consoles = [];
		private $children = [];
		private $collecting = [];
		/**
		 * Handles construct.
		 */
		public function __construct($loop, $config)
		{
			$this->loop = $loop;
			$this->config = $config;
			$loop->addPeriodicTimer(10, function () {
				$this->schedule();
			});
			$loop->addPeriodicTimer(1, function () {
				foreach ($this->consoles as $id => $state) {
					if (
						$state["process"] &&
						(($state["authorized"] && !nms_ssh_session_live($id)) ||
							(!$state["authorized"] && time() - $state["opened"] > 30))
					) {
						$state["process"]->terminate();
					}
				}
			});
		}
		/**
		 * Handles spawn.
		 */
		public function spawn($request, $onData, $onExit, $collector = false)
		{
			if (count($this->children) >= 18) {
				throw new RuntimeException("SSH backend capacity reached.");
			}
			$process = new NmsSshProcess($this->loop, $collector);
			$key = spl_object_id($process);
			$this->children[$key] = $process;
			$buffer = "";
			$process->stdout->on("data", function ($data) use (&$buffer, $onData, $process) {
				$buffer .= $data;
				if (strlen($buffer) > 262144) {
					$process->terminate();
					return;
				}
				while (($p = strpos($buffer, "\n")) !== false) {
					$line = substr($buffer, 0, $p);
					$buffer = substr($buffer, $p + 1);
					$message = json_decode($line, true);
					if (is_array($message)) {
						$onData($message);
					}
				}
			});
			// Do not forward PHP/transport diagnostics, credentials or terminal transcripts to logs.
			$process->stderr->on("data", function ($data) {});
			$deadline =
				($request["expected_kind"] ?? "") === "console"
					? null
					: $this->loop->addTimer(700, function () use ($process) {
						$process->terminate();
					});
			$process->on("exit", function () use ($key, $onExit, $deadline) {
				if ($deadline) {
					$this->loop->cancelTimer($deadline);
				}
				unset($this->children[$key]);
				$onExit();
			});
			$process->stdin->write(json_encode($request, JSON_THROW_ON_ERROR) . "\n");
			return $process;
		}
		/**
		 * Handles console.
		 */
		public function console($r, $respond)
		{
			$id = $r["id"] ?? "";
			if (!is_string($id) || !preg_match('/^[a-f0-9]{64}$/D', $id)) {
				throw new RuntimeException("Invalid console ID.");
			}
			$s = db_fetch_row_prepared("SELECT * FROM plugin_nms_ssh_sessions WHERE id=? AND kind='console'", [$id]);
			if (
				!$s ||
				(int) $s["user_id"] !== (int) ($r["user_id"] ?? 0) ||
				!hash_equals($s["session_hash"], $r["browser_hash"] ?? "")
			) {
				throw new RuntimeException("Console session binding failed.");
			}
			if ($r["console_action"] === "open") {
				if (isset($this->consoles[$id]) || count($this->consoles) >= 10 || $s["status"] !== "issued") {
					throw new RuntimeException("Console unavailable or capacity reached.");
				}
				$this->consoles[$id] = [
					"process" => null,
					"queue" => [],
					"bytes" => 0,
					"waiting" => null,
					"authorized" => false,
					"opened" => time(),
				];
				$opened = false;
				$process = $this->spawn(
					$r + ["expected_kind" => "console"],
					function ($m) use ($id, $respond, &$opened) {
						if (!isset($this->consoles[$id])) {
							return;
						}
						if (($m["type"] ?? "") === "authorized") {
							$this->consoles[$id]["authorized"] = true;
							return;
						}
						if (!$opened && ($m["type"] ?? "") === "connected") {
							$opened = true;
							$respond(["ok" => true, "data" => ["id" => $id]]);
							return;
						}
						if (!$opened && ($m["type"] ?? "") === "error") {
							$opened = true;
							$respond(["ok" => false, "error" => $m["error"]]);
							return;
						}
						$this->consoles[$id]["queue"][] = $m;
						$this->consoles[$id]["bytes"] += strlen(json_encode($m));
						if ($this->consoles[$id]["bytes"] > 131072) {
							$this->consoles[$id]["process"]->terminate();
							return;
						}
						$this->flush($id);
					},
					function () use ($id, $respond, &$opened) {
						if (!$opened) {
							$respond(["ok" => false, "error" => "Guacamole connection could not be started."]);
						}
						if (isset($this->consoles[$id])) {
							$waiting = $this->consoles[$id]["waiting"];
							unset($this->consoles[$id]);
							if ($waiting) {
								$waiting(["ok" => false, "error" => "Console closed."]);
							}
						}
						nms_ssh_close_session($id, "Guacamole session ended");
					},
				);
				$this->consoles[$id]["process"] = $process;
				return;
			}
			if (!isset($this->consoles[$id]) || !nms_ssh_session_live($id)) {
				throw new RuntimeException("Console expired.");
			}
			if ($r["console_action"] === "read") {
				if ($this->consoles[$id]["waiting"]) {
					throw new RuntimeException("A console read is already pending.");
				}
				$this->consoles[$id]["waiting"] = $respond;
				$this->flush($id);
				$this->loop->addTimer(5, function () use ($id, $respond) {
					if (isset($this->consoles[$id]) && $this->consoles[$id]["waiting"] === $respond) {
						$this->flush($id, true);
					}
				});
			} elseif ($r["console_action"] === "write") {
				$raw = base64_decode($r["data"] ?? "", true);
				if ($raw === false) {
					throw new RuntimeException("Invalid console data.");
				}
				nms_guac_validate_input($raw);
				$this->consoles[$id]["process"]->stdin->write(
					json_encode(["type" => "guac", "data" => $r["data"]]) . "\n",
				);
				$respond(["ok" => true, "data" => []]);
			} else {
				throw new RuntimeException("Unsupported console operation.");
			}
		}
		/**
		 * Handles flush.
		 */
		private function flush($id, $empty = false)
		{
			if (!isset($this->consoles[$id])) {
				return;
			}
			$s = &$this->consoles[$id];
			if (!$s["waiting"] || (!$s["queue"] && !$empty)) {
				return;
			}
			$respond = $s["waiting"];
			$messages = $s["queue"];
			$s["waiting"] = null;
			$s["queue"] = [];
			$s["bytes"] = 0;
			$respond(["ok" => true, "data" => ["messages" => $messages]]);
		}
		/**
		 * Handles schedule.
		 */
		public function schedule()
		{
			db_execute(
				"UPDATE plugin_nms_ssh_sessions SET status='closed',ended_at=NOW(),outcome='Authorization expired' WHERE (status='issued' AND expires_at<=NOW()) OR (status='active' AND lease_until<=NOW() AND kind='console')",
			);
			if (!nms_ssh_enabled()) {
				return;
			}
			$rows = db_fetch_assoc_prepared(
				"SELECT d.host_id FROM plugin_nms_ssh_devices d JOIN host h ON h.id=d.host_id JOIN plugin_nms_ssh_presets p ON p.id=d.preset_id LEFT JOIN plugin_nms_ssh_state s ON s.host_id=d.host_id WHERE h.disabled='' AND h.deleted='' AND h.poller_id=? AND d.monitoring=1 AND p.enabled=1 AND (s.last_attempt IS NULL OR TIMESTAMPDIFF(SECOND,s.last_attempt,NOW())>=d.interval_seconds) ORDER BY s.last_attempt LIMIT 4",
				[(int) $this->config["poller_id"]],
			);
			foreach ($rows as $row) {
				$id = (int) $row["host_id"];
				if (isset($this->collecting[$id]) || count($this->collecting) >= 4) {
					continue;
				}
				$this->collecting[$id] = true;
				try {
					$this->spawn(
						["kind" => "collect", "host_id" => $id],
						function ($m) {},
						function () use ($id) {
							unset($this->collecting[$id]);
						},
						true,
					);
				} catch (Throwable $e) {
					unset($this->collecting[$id]);
				}
			}
		}
		/**
		 * Handles stop.
		 */
		public function stop()
		{
			foreach ($this->children as $child) {
				$child->terminate();
			}
			$this->loop->stop();
		}
	}

	$c = nms_ssh_config();
	$loop = React\EventLoop\Loop::get();
	$app = new NmsSshGateway($loop, $c);
	// POSIX uses a permission-restricted socket; Windows uses encrypted, authenticated loopback RPC.
	if (PHP_OS_FAMILY === "Windows") {
		$rpc = new React\Socket\SocketServer($c["control_listen"], [], $loop);
	} else {
		$dir = dirname($c["control_socket"]);
		if (!is_dir($dir) || is_link($dir) || fileowner($dir) !== posix_geteuid() || fileperms($dir) & 0022) {
			throw new RuntimeException("Invalid SSH runtime directory ownership or permissions.");
		}
		if (file_exists($c["control_socket"])) {
			unlink($c["control_socket"]);
		}
		$rpc = new React\Socket\SocketServer("unix://" . $c["control_socket"], [], $loop);
		chmod($c["control_socket"], 0660);
	}
	/**
	 * Handles ssh rpc response.
	 */
	function nms_ssh_rpc_response($value, $context = "")
	{
		global $c;
		$json = json_encode($value);
		return (PHP_OS_FAMILY === "Windows" ? nms_ssh_rpc_cipher($json, $c, false, "response:" . $context) : $json) .
			"\n";
	}
	$rpc->on("connection", function ($connection) use ($app, $loop) {
		$buffer = "";
		$started = false;
		$process = null;
		$sent = false;
		$responseContext = "";
		$timer = $loop->addTimer(45, function () use ($connection, &$responseContext) {
			$connection->end(
				nms_ssh_rpc_response(["ok" => false, "error" => "SSH backend request timed out."], $responseContext),
			);
		});
		$connection->on("data", function ($data) use (
			&$buffer,
			&$started,
			&$process,
			&$sent,
			$app,
			$connection,
			&$responseContext,
		) {
			if ($started) {
				$connection->close();
				return;
			}
			$buffer .= $data;
			if (strlen($buffer) > 196608) {
				$connection->close();
				return;
			}
			if (strpos($buffer, "\n") === false) {
				return;
			}
			$started = true;
			$responseContext = hash("sha256", trim($buffer));
			try {
				global $c;
				$plain = trim($buffer);
				if (PHP_OS_FAMILY === "Windows") {
					$plain = nms_ssh_rpc_cipher($plain, $c, true);
				}
				$r = json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
				if (isset($r["console_action"])) {
					$app->console($r, function ($m) use ($connection, &$sent, &$responseContext) {
						if ($sent) {
							return;
						}
						$sent = true;
						$connection->end(nms_ssh_rpc_response($m, $responseContext));
					});
					return;
				}
				unset($r["kind"], $r["cookie"]);
				$r["expected_kind"] = "rpc";
				$process = $app->spawn(
					$r,
					function ($m) use ($connection, &$sent, &$responseContext) {
						$sent = true;
						$connection->end(nms_ssh_rpc_response($m, $responseContext));
					},
					function () use ($connection, &$sent, &$responseContext) {
						if (!$sent) {
							$connection->end(
								nms_ssh_rpc_response(
									["ok" => false, "error" => "SSH worker failed. Check service dependencies."],
									$responseContext,
								),
							);
						}
					},
				);
			} catch (Throwable $e) {
				$connection->end(
					nms_ssh_rpc_response(
						["ok" => false, "error" => "SSH request rejected or backend busy."],
						$responseContext,
					),
				);
			}
		});
		$connection->on("close", function () use ($loop, $timer, &$process, &$sent) {
			$loop->cancelTimer($timer);
			if ($process && !$sent) {
				$process->terminate();
			}
		});
	});
	if (PHP_OS_FAMILY !== "Windows") {
		$loop->addSignal(SIGTERM, function () use ($app) {
			$app->stop();
		});
		$loop->addSignal(SIGINT, function () use ($app) {
			$app->stop();
		});
	}
	$loop->run();
} catch (Throwable $e) {
	fwrite(STDERR, "NMS SSH service stopped: " . $e->getMessage() . "\n");
	exit(1);
}
