<?php
/** Bounded UTF-8 Guacamole framing. Protocol lengths count Unicode characters, not bytes. */
function nms_guac_instruction(array $parts): string
{
	return implode(
		",",
		array_map(function ($v) {
			$v = (string) $v;
			if (!preg_match("//u", $v)) {
				throw new RuntimeException("Invalid Guacamole text.");
			}
			return preg_match_all("/./us", $v) . "." . $v;
		}, $parts),
	) . ";";
}
function nms_guac_parse(string &$buffer): ?array
{
	$offset = 0;
	$parts = [];
	$length = strlen($buffer);
	if ($length > 1048576) {
		throw new RuntimeException("Guacamole buffer limit exceeded.");
	}
	while (true) {
		$dot = strpos($buffer, ".", $offset);
		if ($dot === false) {
			if ($length - $offset > 6) {
				throw new RuntimeException("Invalid instruction length.");
			}
			return null;
		}
		$digits = substr($buffer, $offset, $dot - $offset);
		if (!preg_match('/^[0-9]{1,6}$/D', $digits) || (int) $digits > 262144) {
			throw new RuntimeException("Invalid instruction length.");
		}
		$start = $offset = $dot + 1;
		for ($i = 0; $i < (int) $digits; $i++) {
			if ($offset >= $length) {
				return null;
			}
			$b = ord($buffer[$offset]);
			$bytes =
				$b < 128
					? 1
					: ($b >= 194 && $b <= 223
						? 2
						: ($b >= 224 && $b <= 239
							? 3
							: ($b >= 240 && $b <= 244
								? 4
								: 0)));
			if (!$bytes) {
				throw new RuntimeException("Invalid UTF-8 instruction.");
			}
			if ($offset + $bytes > $length) {
				return null;
			}
			if (!preg_match("//u", substr($buffer, $offset, $bytes))) {
				throw new RuntimeException("Invalid UTF-8 instruction.");
			}
			$offset += $bytes;
		}
		if ($offset >= $length) {
			return null;
		}
		$parts[] = substr($buffer, $start, $offset - $start);
		$end = $buffer[$offset++];
		if (count($parts) > 128) {
			throw new RuntimeException("Too many instruction parameters.");
		}
		if ($end === ";") {
			$raw = substr($buffer, 0, $offset);
			$buffer = substr($buffer, $offset);
			return ["parts" => $parts, "raw" => $raw];
		}
		if ($end !== ",") {
			throw new RuntimeException("Invalid instruction separator.");
		}
	}
}
/**
 * Handles guac validate input.
 */
function nms_guac_validate_input(string $data): bool
{
	$input = false;
	$buffer = $data;
	if (strlen($data) > 32768) {
		throw new RuntimeException("Terminal input too large.");
	}
	while ($buffer !== "") {
		$m = nms_guac_parse($buffer);
		if (!$m) {
			throw new RuntimeException("Incomplete terminal input.");
		}
		$p = $m["parts"];
		$op = array_shift($p);
		$counts = ["key" => 2, "mouse" => 3, "size" => 2, "sync" => 1, "ack" => 3, "nop" => 0, "disconnect" => 0];
		if (!isset($counts[$op]) || count($p) !== $counts[$op]) {
			throw new RuntimeException("Terminal instruction is not permitted.");
		}
		if ($op !== "ack") {
			foreach ($p as $value) {
				if (!preg_match('/^[0-9]{1,16}$/D', $value)) {
					throw new RuntimeException("Invalid terminal parameter.");
				}
			}
		}
		if ($op === "size" && ((int) $p[0] < 100 || (int) $p[0] > 4096 || (int) $p[1] < 100 || (int) $p[1] > 2160)) {
			throw new RuntimeException("Invalid terminal size.");
		}
		if ($op === "key" && ((int) $p[0] > 0x1fffffff || !in_array($p[1], ["0", "1"], true))) {
			throw new RuntimeException("Invalid key event.");
		}
		if ($op === "mouse" && ((int) $p[0] > 4096 || (int) $p[1] > 2160 || (int) $p[2] > 31)) {
			throw new RuntimeException("Invalid mouse event.");
		}
		if ($op === "key" || ($op === "mouse" && (int) $p[2] > 0)) {
			$input = true;
		}
	}
	return $input;
}
