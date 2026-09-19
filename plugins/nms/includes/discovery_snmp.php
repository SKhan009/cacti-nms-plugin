<?php
require_once __DIR__ . "/discovery_neighbors.php";
require_once __DIR__ . "/discovery_endpoints.php";
/**
 * Handles nd snmp value.
 */
function nms_nd_snmp_value($response, $oid)
{
	if (
		!is_object($response) ||
		!isset($response->type, $response->value) ||
		in_array((int) $response->type, [128, 129, 130], true)
	) {
		throw new RuntimeException("SNMP object is unavailable or not readable: " . $oid);
	}
	return ["type" => (int) $response->type, "value" => (string) $response->value];
}
/** Read one mandatory scalar with explicit native-session error handling. */
function nms_nd_snmp_scalar($session, $oid, $deadline)
{
	if (microtime(true) > $deadline) {
		throw new RuntimeException("Discovery time limit reached.");
	}
	$response = @$session->get($oid);
	if ($response === false || $session->getErrno()) {
		throw new RuntimeException("SNMP request failed or the required MIB object is not readable: " . $oid);
	}
	return nms_nd_snmp_value($response, $oid);
}
/** Return whether a GETNEXT error denotes the normal end of an SNMPv1 or SNMPv2+ subtree. */
function nms_nd_snmp_end_of_subtree($session)
{
	return in_array((int) $session->getErrno(), [2, 8], true) &&
		preg_match(
			"/No more variables left|End of MIB|endOfMibView|No Such (?:Object|Name)/i",
			(string) $session->getError(),
		);
}
/** Walk GETNEXT, retaining typed values; never accept a partial walk after a transport failure. */
function nms_nd_snmp_subtree($session, $root, $deadline, &$budget)
{
	$values = [];
	$cursor = $root;
	while (true) {
		if (microtime(true) > $deadline) {
			throw new RuntimeException("Discovery time limit reached; partial result discarded.");
		}
		$budget--;
		$result = @$session->getnext([$cursor]);
		if ($result === false || $session->getErrno()) {
			// PHP reports normal SNMPv1 and SNMPv2+ table endings as errors.
			if (nms_nd_snmp_end_of_subtree($session)) {
				break;
			}
			throw new RuntimeException("SNMP table read failed: " . $root . ". Partial result discarded.");
		}
		if (!is_array($result) || count($result) !== 1) {
			throw new RuntimeException("Unexpected SNMP GETNEXT response.");
		}
		$oid = ltrim((string) array_key_first($result), ".");
		$v = current($result);
		if (!preg_match('/^[0-9]+(?:\.[0-9]+)+$/D', $oid) || nms_nd_oid_compare($oid, $cursor) <= 0) {
			throw new RuntimeException("Non-increasing or malformed SNMP OID.");
		}
		if (strpos($oid, $root . ".") !== 0) {
			break;
		}
		$values[$oid] = nms_nd_snmp_value($v, $oid);
		$cursor = $oid;
	}
	return $values;
}
/** Return usable interfaces when IF-MIB is exposed, without making optional topology evidence fail collection. */
function nms_nd_snmp_interfaces($values)
{
	try {
		return nms_nd_interfaces($values);
	} catch (RuntimeException $e) {
		return [];
	}
}
/** Return a successful empty observation when a requested table is unavailable or nonconforming. */
function nms_nd_snmp_empty_protocol($protocol, $interfaces, $error)
{
	$data = ["interfaces" => $interfaces, "neighbors" => [], "warning" => $error];
	if ($protocol === "arp") {
		$data["endpoints"] = [];
	}
	if (in_array($protocol, ["lldp", "cdp"], true)) {
		$data += ["identity" => "", "name" => "", "ports" => []];
	}
	return $data;
}
/** Read an optional MIB subtree without making an absent vendor or newer MIB fail discovery. */
function nms_nd_snmp_optional_subtree($session, $root, $deadline, &$budget)
{
	try {
		return nms_nd_snmp_subtree($session, $root, $deadline, $budget);
	} catch (RuntimeException $e) {
		return [];
	}
}
/** Compare numeric OID arcs, not lexicographic strings. */
function nms_nd_oid_compare($a, $b)
{
	$a = explode(".", $a);
	$b = explode(".", $b);
	for ($i = 0; $i < min(count($a), count($b)); $i++) {
		if ((float) $a[$i] != (float) $b[$i]) {
			return (float) $a[$i] < (float) $b[$i] ? -1 : 1;
		}
	}
	return count($a) <=> count($b);
}
/** Validate the explicitly configured SNMPv3 level before the native helper can downgrade missing keys. */
function nms_nd_snmp_security($host)
{
	if ((string) $host["snmp_version"] === "1") {
		return "SNMPv1";
	}
	if ((string) $host["snmp_version"] === "2") {
		return "SNMPv2c";
	}
	if ($host["snmp_username"] === "" || strlen($host["snmp_username"]) > 32) {
		throw new RuntimeException("SNMPv3 requires a security name of 1–32 bytes.");
	}
	$auth = $host["snmp_auth_protocol"];
	$priv = $host["snmp_priv_protocol"];
	if (
		!in_array($auth, ["[None]", "MD5", "SHA", "SHA224", "SHA256", "SHA384", "SHA512"], true) ||
		!in_array($priv, ["[None]", "DES", "AES", "AES128", "AES192", "AES192C", "AES256", "AES256C"], true)
	) {
		throw new RuntimeException("SNMPv3 algorithm is not a recognized native Cacti selection.");
	}
	$hasAuth = $auth !== "[None]";
	$hasPriv = $priv !== "[None]";
	if (($hasAuth && strlen($host["snmp_password"]) < 8) || (!$hasAuth && $host["snmp_password"] !== "")) {
		throw new RuntimeException("SNMPv3 authentication settings are inconsistent; security was not downgraded.");
	}
	if (
		($hasPriv && strlen($host["snmp_priv_passphrase"]) < 8) ||
		(!$hasPriv && $host["snmp_priv_passphrase"] !== "")
	) {
		throw new RuntimeException("SNMPv3 privacy settings are inconsistent; encryption was not disabled.");
	}
	if ($hasPriv && !$hasAuth) {
		throw new RuntimeException("SNMPv3 privacy requires authentication.");
	}
	if (strlen($host["snmp_context"]) > 255) {
		throw new RuntimeException("SNMPv3 context exceeds the protocol maximum of 255 bytes.");
	}
	return $hasPriv ? "authPriv" : ($hasAuth ? "authNoPriv" : "noAuthNoPriv");
}
/** Open one native Cacti session with the selected endpoint and security settings, without alternate transport. */
function nms_nd_discovery_session($host)
{
	global $config;
	require_once $config["base_path"] . "/lib/snmp.php";
	if (!$config["php_snmp_support"] || !extension_loaded("snmp")) {
		throw new RuntimeException(
			"PHP SNMP is unavailable on the assigned Cacti collector. Install and enable php-snmp, then restart Apache/PHP. lldpcli and ip neigh show the local RHEL neighbour cache; they do not provide SNMP discovery evidence for this device.",
		);
	}
	if ($host["disabled"] !== "" || (int) $host["poller_id"] !== (int) $config["poller_id"]) {
		throw new RuntimeException("Device is disabled or assigned to another collector.");
	}
	if (!in_array((string) $host["snmp_version"], ["1", "2", "3"], true)) {
		throw new RuntimeException("Discovery requires an explicitly configured SNMPv1, SNMPv2c, or SNMPv3 profile.");
	}
	$security = nms_nd_snmp_security($host);
	$timeout = (int) $host["snmp_timeout"];
	$retries = $host["nms_snmp_retries"] ?? read_config_option("snmp_retries");
	if ($timeout < 1 || !is_scalar($retries) || !preg_match('/^\d+$/D', (string) $retries)) {
		throw new RuntimeException("Discovery requires a positive timeout and a non-negative retry count.");
	}
	$session = false;
	try {
		$session = @cacti_snmp_session(
			$host["hostname"],
			$host["snmp_community"],
			$host["snmp_version"],
			$host["snmp_username"],
			$host["snmp_password"],
			$host["snmp_auth_protocol"],
			$host["snmp_priv_passphrase"],
			$host["snmp_priv_protocol"],
			$host["snmp_context"],
			$host["snmp_engine_id"],
			$host["snmp_port"],
			$timeout,
			(int) $retries,
			1,
			1,
		);
		// Cacti 1.2.31 does not check setSecurity's boolean return. Confirm the exact profile.
		if ($session && (string) $host["snmp_version"] === "3") {
			$ok = @$session->setSecurity(
				$security,
				$host["snmp_auth_protocol"] === "[None]" ? "" : $host["snmp_auth_protocol"],
				$host["snmp_password"],
				$host["snmp_priv_protocol"] === "[None]" ? "" : $host["snmp_priv_protocol"],
				$host["snmp_priv_passphrase"],
				$host["snmp_context"],
			);
			if (!$ok) {
				$session->close();
				$session = false;
			}
		}
	} catch (Throwable $e) {
		if ($session) {
			$session->close();
		}
		throw new RuntimeException("Native Cacti SNMP session rejected the selected settings or algorithms.");
	}
	if (!$session) {
		throw new RuntimeException("Native Cacti SNMP session rejected the selected settings or algorithms.");
	}
	$session->valueretrieval = SNMP_VALUE_OBJECT | SNMP_VALUE_PLAIN;
	$session->enum_print = true;
	// Retain our own numeric OID guard while accepting devices whose agent does not advertise ordered OIDs.
	$session->oid_increasing_check = false;
	return $session;
}
/** Read standard UPS-MIB battery values when the device exposes them. */
function nms_nd_collect_battery($session, $deadline)
{
	$oids = [
		"status" => "1.3.6.1.2.1.33.1.2.1.0",
		"minutes_remaining" => "1.3.6.1.2.1.33.1.2.3.0",
		"charge_percent" => "1.3.6.1.2.1.33.1.2.4.0",
		"voltage" => "1.3.6.1.2.1.33.1.2.5.0",
		"current" => "1.3.6.1.2.1.33.1.2.6.0",
	];
	$out = ["supported" => false];
	foreach ($oids as $name => $oid) {
		try {
			$value = nms_nd_snmp_scalar($session, $oid, $deadline);
			if (is_numeric($value["value"])) {
				$out[$name] = (float) $value["value"];
				$out["supported"] = true;
			}
		} catch (RuntimeException $error) {
			// UPS-MIB is optional; a normal server, laptop, or phone may not expose it.
		}
	}
	return $out;
}

/**
 * Collect asset identity separately from neighbour protocols.  IF-MIB and
 * ENTITY-MIB are useful on ordinary servers and switches even when LLDP/CDP
 * is disabled or excluded from the device SNMP view.
 */
function nms_nd_collect_identity($host, $jobDeadline)
{
	$session = nms_nd_discovery_session($host);
	$deadline = $jobDeadline;
	$budget = PHP_INT_MAX;
	$values = [];
	try {
		$uptime = "1.3.6.1.2.1.1.3.0";
		try {
			$before = nms_nd_snmp_scalar($session, $uptime, $deadline);
		} catch (RuntimeException $e) {
			$before = null;
		}
		foreach (
			["1.3.6.1.2.1.2.2.1", "1.3.6.1.2.1.31.1.1.1.1", "1.3.6.1.2.1.31.1.1.1.15", "1.3.6.1.2.1.31.1.1.1.18"]
			as $root
		) {
			try {
				$values += nms_nd_snmp_subtree($session, $root, $deadline, $budget);
			} catch (RuntimeException $e) {
			}
		}
		try {
			$entity = nms_nd_snmp_subtree($session, "1.3.6.1.2.1.47.1.1.1.1", $deadline, $budget);
		} catch (RuntimeException $e) {
			$entity = [];
			$hardware_error = $e->getMessage();
		}
		$battery = nms_nd_collect_battery($session, $deadline);
		$interfaces = [];
		$interface_error = "";
		try {
			$interfaces = nms_nd_interfaces($values);
		} catch (RuntimeException $e) {
			$interface_error = $e->getMessage();
		}
		$hardware = nms_nd_hardware($entity);
		if (isset($hardware_error)) {
			$hardware["error"] = $hardware_error;
		}
		try {
			$after = nms_nd_snmp_scalar($session, $uptime, $deadline);
		} catch (RuntimeException $e) {
			$after = null;
		}
		if ($before !== null && $after !== null && (float) $after["value"] < (float) $before["value"]) {
			throw new RuntimeException(
				"Device restarted or uptime wrapped during identity collection; result discarded.",
			);
		}
		return [
			"interfaces" => $interfaces,
			"hardware" => $hardware,
			"battery" => $battery,
			"interface_error" => $interface_error,
			"neighbors" => [],
			"collected" => time(),
		];
	} finally {
		$session->close();
	}
}
/**
 * Handles nd collect direct.
 */
function nms_nd_collect_direct($host, $protocol, $jobDeadline)
{
	$session = nms_nd_discovery_session($host);
	$deadline = $jobDeadline;
	$budget = PHP_INT_MAX;
	$values = [];
	try {
		$uptime = "1.3.6.1.2.1.1.3.0";
		try {
			$before = nms_nd_snmp_scalar($session, $uptime, $deadline);
		} catch (RuntimeException $e) {
			$before = null;
		}
		// IF-MIB and IFX-MIB are both optional: LLDP port labels can still be collected without them.
		$values += nms_nd_snmp_optional_subtree($session, "1.3.6.1.2.1.2.2.1", $deadline, $budget);
		foreach (["1.3.6.1.2.1.2.2.1.5", "1.3.6.1.2.1.31.1.1.1.1", "1.3.6.1.2.1.31.1.1.1.15", "1.3.6.1.2.1.31.1.1.1.18"] as $root) {
			$values += nms_nd_snmp_optional_subtree($session, $root, $deadline, $budget);
		}
		$interfaces = nms_nd_snmp_interfaces($values);
		if ($protocol === "lldp") {
			$values += nms_nd_snmp_optional_subtree($session, "1.0.8802.1.1.2.1.3", $deadline, $budget);
			$values += nms_nd_snmp_optional_subtree($session, "1.0.8802.1.1.2.1.4.1.1", $deadline, $budget);
			try {
				$data = nms_nd_parse_lldp($values, $interfaces);
			} catch (RuntimeException $e) {
				$data = nms_nd_snmp_empty_protocol($protocol, $interfaces, $e->getMessage());
			}
		} elseif ($protocol === "cdp") {
			$values += nms_nd_snmp_optional_subtree($session, "1.3.6.1.4.1.9.9.23.1", $deadline, $budget);
			try {
				$data = nms_nd_parse_cdp($values, $interfaces);
			} catch (RuntimeException $e) {
				$data = nms_nd_snmp_empty_protocol($protocol, $interfaces, $e->getMessage());
			}
		} elseif ($protocol === "arp") {
			// Retain legacy IPv4 support and add the version-neutral IPv4/IPv6 table.
			foreach (["1.3.6.1.2.1.4.22.1", "1.3.6.1.2.1.4.35.1"] as $root) {
				$values += nms_nd_snmp_optional_subtree($session, $root, $deadline, $budget);
			}
			try {
				$data = nms_nd_parse_arp($values, $interfaces);
			} catch (RuntimeException $e) {
				$data = nms_nd_snmp_empty_protocol($protocol, $interfaces, $e->getMessage());
			}
		} elseif ($protocol === "fdb") {
			foreach (
				[
					"1.3.6.1.2.1.17.1.4.1",
					"1.3.6.1.2.1.17.4.3.1",
					"1.3.6.1.2.1.17.7.1.2.2.1",
					"1.3.6.1.2.1.17.7.1.4.2.1.3",
				]
				as $root
			) {
				$values += nms_nd_snmp_optional_subtree($session, $root, $deadline, $budget);
			}
			try {
				$data = nms_nd_parse_fdb($values, $interfaces);
			} catch (RuntimeException $e) {
				$data = nms_nd_snmp_empty_protocol($protocol, $interfaces, $e->getMessage());
			}
		} else {
			throw new RuntimeException("Unknown discovery protocol.");
		}
		try {
			$after = nms_nd_snmp_scalar($session, $uptime, $deadline);
		} catch (RuntimeException $e) {
			$after = null;
		}
		if ($before !== null && $after !== null && (float) $after["value"] < (float) $before["value"]) {
			throw new RuntimeException("Device restarted or uptime wrapped during collection; result discarded.");
		}
		// Optional inventory uses the same authenticated session.
		// Its failure must not turn successful neighbour evidence into an empty success.
		try {
			$entity = nms_nd_snmp_subtree($session, "1.3.6.1.2.1.47.1.1.1.1", $deadline, $budget);
			$data["hardware"] = nms_nd_hardware($entity);
		} catch (RuntimeException $e) {
			$data["hardware"] = ["error" => $e->getMessage()];
		}
		$data["collected"] = time();
		return $data;
	} finally {
		$session->close();
	}
}

/** ENTITY-MIB inventory: chassis serials and explicitly classified physical ports. */
function nms_nd_hardware($values)
{
	$root = "1.3.6.1.2.1.47.1.1.1.1";
	$chassis = [];
	$ports = [];
	foreach (nms_nd_column($values, $root, 5, 1, 2) as $index => $class) {
		if (!in_array((int) $class, [3, 10], true)) {
			continue;
		}
		$row = [
			"entity_index" => (int) $index,
			"name" => nms_nd_octets(nms_nd_value($values, $root . ".7." . $index, 4, false)),
			"position" => nms_nd_value($values, $root . ".6." . $index, 2, false),
			"parent" => nms_nd_value($values, $root . ".4." . $index, 2, false),
			"serial" => nms_nd_octets(nms_nd_value($values, $root . ".11." . $index, 4, false)),
			"model" => nms_nd_octets(nms_nd_value($values, $root . ".13." . $index, 4, false)),
		];
		if ((int) $class === 3) {
			$chassis[] = $row;
		} else {
			$ports[] = $row;
		}
	}
	return ["chassis" => $chassis, "physical_ports" => $ports, "error" => ""];
}
