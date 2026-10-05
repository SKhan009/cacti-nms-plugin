<?php
/** One explicit credential; do not probe none authentication or smart MFA. */
final class NmsStrictSsh extends phpseclib3\Net\SSH2
{
	public function login($username, ...$args)
	{
		if (count($args) !== 1 || (!is_string($args[0]) && !($args[0] instanceof phpseclib3\Crypt\Common\PrivateKey))) {
			throw new RuntimeException("Exactly one password or private key is required.");
		}
		$this->disableSmartMFA();
		return parent::sublogin($username, ...$args);
	}

	/** phpseclib's setter only updates local dimensions; notify an already-open PTY explicitly. */
	public function setWindowSize($columns = 80, $rows = 24)
	{
		if (!is_int($columns) || !is_int($rows) || $columns < 2 || $columns > 500 || $rows < 2 || $rows > 200) {
			throw new RuntimeException("Invalid terminal dimensions.");
		}
		parent::setWindowSize($columns, $rows);
		if ($this->isShellOpen()) {
			$this->send_binary_packet(
				phpseclib3\Common\Functions\Strings::packSSH2(
					"CNsbN4",
					NET_SSH2_MSG_CHANNEL_REQUEST,
					$this->server_channels[self::CHANNEL_SHELL],
					"window-change",
					false,
					$columns,
					$rows,
					0,
					0,
				),
			);
		}
	}
}
