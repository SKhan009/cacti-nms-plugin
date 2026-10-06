<?php
/** Authenticated, ordered messages on the local worker channel; never expose credentials on loopback. */
final class NmsSshWire
{
	private $key;
	private $send = 0;
	private $receive = 0;
	private $out;
	private $in;
	public function __construct($token, $worker = false)
	{
		if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
			throw new RuntimeException("Invalid worker channel key.");
		}
		$this->key = hex2bin($token);
		$this->out = $worker ? "worker" : "gateway";
		$this->in = $worker ? "gateway" : "worker";
	}
	public function encode($plain)
	{
		$iv = random_bytes(12);
		$tag = "";
		$aad = "nms-worker-v1:" . $this->out . ":" . $this->send++;
		$cipher = openssl_encrypt($plain, "aes-256-gcm", $this->key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
		if ($cipher === false) {
			throw new RuntimeException("Worker encryption failed.");
		}
		return base64_encode($iv . $tag . $cipher);
	}
	public function decode($frame)
	{
		$data = base64_decode($frame, true);
		if ($data === false || strlen($data) < 28) {
			throw new RuntimeException("Invalid worker frame.");
		}
		$plain = openssl_decrypt(
			substr($data, 28),
			"aes-256-gcm",
			$this->key,
			OPENSSL_RAW_DATA,
			substr($data, 0, 12),
			substr($data, 12, 16),
			"nms-worker-v1:" . $this->in . ":" . $this->receive,
		);
		if ($plain === false) {
			throw new RuntimeException("Worker authentication or sequence failed.");
		}
		$this->receive++;
		return $plain;
	}
}
