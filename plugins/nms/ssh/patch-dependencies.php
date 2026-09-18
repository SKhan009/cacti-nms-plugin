<?php
/** Reproducible, narrow policy patch to pinned phpseclib; no Cacti core modifications. */
if (PHP_SAPI !== "cli") {
	http_response_code(404);
	exit();
}
$path = __DIR__ . "/vendor/phpseclib/phpseclib/phpseclib/Net/SSH2.php";
$text = file_get_contents($path);
$old = <<<'CODE'
                if (!$partial_success && in_array('keyboard-interactive', $auth_methods)) {
                    if ($this->keyboard_interactive_login($username, $password)) {
                        $this->bitmap |= self::MASK_LOGIN;
                        return true;
                    }
                    return false;
                }
CODE;
$new = "                // NMS policy: never switch from password to keyboard-interactive authentication.";
if (substr_count($text, $new) === 1) {
	echo "Strict SSH authentication patch already present.\n";
	exit();
}
if (substr_count($text, $old) !== 1) {
	throw new RuntimeException("Pinned SSH dependency changed; review strict-auth patch before packaging.");
}
file_put_contents($path, str_replace($old, $new, $text));
echo "Applied strict SSH authentication patch.\n";
