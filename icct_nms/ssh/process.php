<?php
/** Portable worker IPC: loopback sockets avoid non-selectable Windows process pipes. */
require_once __DIR__ . "/wire.php";
final class NmsSshProcess
{
	public $stdin;
	public $stdout;
	public $stderr;
	private $loop;
	private $server;
	private $process;
	private $connection;
	private $token;
	private $buffer = "";
	private $queue = "";
	private $listeners = [];
	private $timer;
	private $startup;
	private $deadline;
	private $ended = false;
	private $wire;
	public function __construct($loop, $collector = false, $worker = null)
	{
		$this->loop = $loop;
		$this->token = bin2hex(random_bytes(32));
		$this->wire = new NmsSshWire($this->token);
		$this->stdin = new NmsSshProcessInput($this);
		$this->stdout = new Evenement\EventEmitter();
		$this->stderr = new Evenement\EventEmitter();
		$this->server = new React\Socket\SocketServer("127.0.0.1:0", [], $loop);
		$this->server->on("connection", function ($connection) {
			$buffer = "";
			$accepted = false;
			$timeout = $this->loop->addTimer(2, function () use ($connection) {
				$connection->close();
			});
			$connection->on("data", function ($data) use ($connection, &$buffer, &$accepted, $timeout) {
				if ($accepted) {
					$this->receive($data);
					return;
				}
				$buffer .= $data;
				if (strlen($buffer) > 1024) {
					$connection->close();
					return;
				}
				if (($pos = strpos($buffer, "\n")) === false) {
					return;
				}
				$hello = substr($buffer, 0, $pos);
				if ($this->connection || !hash_equals(hash_hmac("sha256", "nms-worker-hello", $this->token), $hello)) {
					$connection->close();
					return;
				}
				$accepted = true;
				$this->connection = $connection;
				$this->server->close();
				$this->loop->cancelTimer($timeout);
				$this->loop->cancelTimer($this->startup);
				$this->token = "";
				$connection->write($this->queue);
				$this->queue = "";
				$this->receive(substr($buffer, $pos + 1));
				$buffer = "";
			});
			$connection->on("close", function () use ($connection, $timeout) {
				$this->loop->cancelTimer($timeout);
				if ($this->connection === $connection && !$this->ended) {
					$this->terminate();
				}
			});
		});
		$env = getenv();
		$env["NMS_SSH_WORKER_ENDPOINT"] = $this->server->getAddress();
		$env["NMS_SSH_WORKER_TOKEN"] = $this->token;
		$env["NMS_SSH_COLLECTOR"] = $collector ? "1" : "0";
		$null = PHP_OS_FAMILY === "Windows" ? "NUL" : "/dev/null";
		$this->process = proc_open(
			[PHP_BINARY, $worker ?? __DIR__ . "/worker.php"],
			[["file", $null, "r"], ["file", $null, "a"], ["file", $null, "a"]],
			$pipes,
			null,
			$env,
			["bypass_shell" => true],
		);
		if (!is_resource($this->process)) {
			$this->server->close();
			throw new RuntimeException("SSH worker could not start.");
		}
		$this->startup = $loop->addTimer(10, function () {
			$this->terminate();
		});
		$this->timer = $loop->addPeriodicTimer(0.2, function () {
			if (!$this->ended && !proc_get_status($this->process)["running"]) {
				$this->finish();
			}
		});
	}
	/**
	 * Handles on.
	 */
	public function on($event, $callback)
	{
		$this->listeners[$event][] = $callback;
	}
	/**
	 * Handles write.
	 */
	public function write($data)
	{
		if ($this->ended) {
			return;
		}
		$data = $this->wire->encode(rtrim($data, "\n")) . "\n";
		if ($this->connection) {
			$this->connection->write($data);
		} else {
			$this->queue .= $data;
			if (strlen($this->queue) > 131072) {
				$this->terminate();
			}
		}
	}
	/**
	 * Handles receive.
	 */
	private function receive($data)
	{
		$this->buffer .= $data;
		if (strlen($this->buffer) > 262144) {
			$this->terminate();
			return;
		}
		while (($p = strpos($this->buffer, "\n")) !== false) {
			$line = substr($this->buffer, 0, $p);
			$this->buffer = substr($this->buffer, $p + 1);
			try {
				$line = $this->wire->decode($line);
			} catch (Throwable $e) {
				$this->terminate();
				return;
			}
			$message = json_decode($line, true);
			if (is_array($message) && ($message["type"] ?? "") === "worker_deadline") {
				if ($this->deadline) {
					$this->loop->cancelTimer($this->deadline);
				}
				$seconds = $message["seconds"] ?? null;
				if (!is_int($seconds) || $seconds < 0 || $seconds > 300) {
					$this->terminate();
					return;
				}
				$this->deadline = $seconds
					? $this->loop->addTimer($seconds, function () {
						$this->stdout->emit("data", [
							json_encode(["ok" => false, "type" => "error", "error" => "SSH operation timed out."]) .
							"\n",
						]);
						$this->terminate();
					})
					: null;
				$this->write("{\"type\":\"deadline_ready\"}\n");
			} else {
				$this->stdout->emit("data", [$line . "\n"]);
			}
		}
	}
	/**
	 * Handles terminate.
	 */
	public function terminate()
	{
		if ($this->ended) {
			return;
		}
		if (is_resource($this->process)) {
			proc_terminate($this->process);
		}
		$this->finish();
	}
	/**
	 * Handles finish.
	 */
	private function finish()
	{
		if ($this->ended) {
			return;
		}
		$this->ended = true;
		foreach ([$this->timer, $this->startup, $this->deadline] as $timer) {
			if ($timer) {
				$this->loop->cancelTimer($timer);
			}
		}
		$this->server->close();
		if ($this->connection) {
			$this->connection->close();
		}
		// proc_close waits only after the worker has exited; never block the gateway on an SSH read.
		$process = $this->process;
		$loop = $this->loop;
		if (is_resource($process)) {
			$reaper = null;
			$attempts = 0;
			$reaper = $loop->addPeriodicTimer(0.2, function () use ($process, $loop, &$reaper, &$attempts) {
				if (!proc_get_status($process)["running"]) {
					proc_close($process);
					$loop->cancelTimer($reaper);
					return;
				}
				if (++$attempts >= 5) {
					proc_terminate($process, 9);
				}
			});
		}
		foreach ($this->listeners["exit"] ?? [] as $callback) {
			$callback();
		}
	}
}
final class NmsSshProcessInput
{
	private $process;
	public function __construct($p)
	{
		$this->process = $p;
	}
	public function write($data)
	{
		$this->process->write($data);
	}
}
