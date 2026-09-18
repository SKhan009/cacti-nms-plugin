<?php
/** Backend-only authenticated encryption. HTTP handlers cannot read the master key. */
final class NmsSshStore
{
	private $dir;
	private $key;
	private $config;
	public function __construct($config)
	{
		$this->config = $config;
		$this->dir = $config["store_dir"];
		foreach ([$this->dir, $config["master_key"]] as $p) {
			nms_ssh_private_path($p, $config);
		}
		$this->key = file_get_contents($config["master_key"]);
		if (strlen($this->key) !== 32) {
			throw new RuntimeException("Invalid SSH credential encryption key.");
		}
	}
	/**
	 * Handles put.
	 */
	public function put($method, $secret, $passphrase = "")
	{
		if (
			!in_array($method, ["key", "password"], true) ||
			!is_string($secret) ||
			$secret === "" ||
			strlen($secret) > 65536 ||
			!is_string($passphrase) ||
			strlen($passphrase) > 1024
		) {
			throw new RuntimeException("Invalid SSH credential.");
		}
		if ($method === "key") {
			try {
				phpseclib3\Crypt\PublicKeyLoader::loadPrivateKey($secret, $passphrase);
			} catch (Throwable $e) {
				throw new RuntimeException("Private key could not be loaded with the supplied passphrase.");
			}
		}
		$ref = bin2hex(random_bytes(32));
		$iv = random_bytes(12);
		$tag = "";
		$cipher = openssl_encrypt(
			json_encode(["method" => $method, "secret" => $secret, "passphrase" => $passphrase], JSON_THROW_ON_ERROR),
			"aes-256-gcm",
			$this->key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			$ref,
		);
		if ($cipher === false) {
			throw new RuntimeException("Credential encryption failed.");
		}
		$path = $this->dir . "/" . $ref;
		$old = umask(0077);
		try {
			$f = fopen($path, "x");
			if (!$f) {
				throw new RuntimeException("Credential store write failed.");
			}
			try {
				$data = $iv . $tag . $cipher;
				if (fwrite($f, $data) !== strlen($data)) {
					unlink($path);
					throw new RuntimeException("Credential store write incomplete.");
				}
			} finally {
				fclose($f);
			}
		} finally {
			umask($old);
		}
		return $ref;
	}
	/**
	 * Handles get.
	 */
	public function get($ref)
	{
		if (!preg_match('/^[a-f0-9]{64}$/D', $ref)) {
			throw new RuntimeException("Invalid credential reference.");
		}
		$p = $this->dir . "/" . $ref;
		nms_ssh_private_path($p, $this->config);
		if (!is_file($p)) {
			throw new RuntimeException("SSH credential is not a file.");
		}
		$data = file_get_contents($p);
		$plain = openssl_decrypt(
			substr($data, 28),
			"aes-256-gcm",
			$this->key,
			OPENSSL_RAW_DATA,
			substr($data, 0, 12),
			substr($data, 12, 16),
			$ref,
		);
		if ($plain === false) {
			throw new RuntimeException("SSH credential integrity check failed.");
		}
		return json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
	}
}
