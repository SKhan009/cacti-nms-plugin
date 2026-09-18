<?php
/** Explicit topology edges. Cacti tree membership and layout parents are never network evidence. */
require_once __DIR__ . "/functions.php";

/** Store only connectivity metadata missing from native Cacti, separately from map coordinates. */
function nms_relationship_schema()
{
	nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_relationships (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		fingerprint CHAR(64) NOT NULL,
		source_host_id MEDIUMINT UNSIGNED NOT NULL,
		source_query_id INT UNSIGNED NOT NULL DEFAULT 0,
		source_index VARCHAR(191) NOT NULL DEFAULT '',
		source_identity VARCHAR(512) NOT NULL DEFAULT '',
		target_host_id MEDIUMINT UNSIGNED NOT NULL,
		target_query_id INT UNSIGNED NOT NULL DEFAULT 0,
		target_index VARCHAR(191) NOT NULL DEFAULT '',
		target_identity VARCHAR(512) NOT NULL DEFAULT '',
		relation_type VARCHAR(24) NOT NULL,
		provenance VARCHAR(24) NOT NULL,
		discovery_source VARCHAR(150) NOT NULL DEFAULT '',
		last_seen DATETIME NULL DEFAULT NULL,
		updated_by INT UNSIGNED NOT NULL,
		updated_at DATETIME NOT NULL,
		archived_at DATETIME NULL DEFAULT NULL,
		PRIMARY KEY (id), UNIQUE KEY fingerprint (fingerprint),
		KEY source_host_id (source_host_id), KEY target_host_id (target_host_id)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");
}

/** Validate an endpoint against the current core device/query cache; transport ports are not accepted. */
function nms_relationship_endpoint($encoded)
{
	if (!is_string($encoded) || !preg_match('/^([1-9][0-9]*):([0-9]+):(.*)$/D', $encoded, $parts)) {
		throw new InvalidArgumentException("Select a device or a cached interface endpoint.");
	}
	$host_id = (int) $parts[1];
	$query_id = (int) $parts[2];
	$index = nms_classification_text($parts[3], 191);
	nms_require_device_access($host_id);
	if (
		!(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM host WHERE id = ? AND deleted = '' AND disabled = ''", [
			$host_id,
		])
	) {
		throw new InvalidArgumentException("The endpoint device is absent or disabled.");
	}
	$identity = "";
	if (($query_id === 0 && $index !== "") || ($query_id > 0 && $index === "")) {
		throw new InvalidArgumentException("An interface requires both its Cacti data-query ID and SNMP index.");
	}
	if ($query_id > 0) {
		$identity = nms_relationship_interface_identity($host_id, $query_id, $index);
		if ($identity === "") {
			throw new InvalidArgumentException(
				"The interface no longer exists in Cacti cache. Re-index and select it again.",
			);
		}
	}
	return ["host_id" => $host_id, "query_id" => $query_id, "index" => $index, "identity" => $identity];
}

/** Preserve a named interface identity as well as its index to detect index reuse after re-discovery. */
function nms_relationship_interface_identity($host_id, $query_id, $index)
{
	$rows = db_fetch_assoc_prepared(
		"SELECT field_name, field_value FROM host_snmp_cache
		WHERE host_id = ? AND snmp_query_id = ? AND snmp_index = ? AND field_name IN ('ifName', 'ifDescr')
		ORDER BY CASE field_name WHEN 'ifName' THEN 0 ELSE 1 END",
		[(int) $host_id, (int) $query_id, (string) $index],
	);
	foreach ($rows as $row) {
		$value = trim((string) $row["field_value"]);
		if ($value !== "") {
			return nms_classification_text($row["field_name"] . ":" . $value, 512);
		}
	}
	return "";
}

/** Save an operator-declared edge; never mark a manual statement as a discovery observation. */
function nms_relationship_save($source, $target, $type)
{
	if (!in_array($type, ["network", "power", "containment"], true)) {
		throw new InvalidArgumentException("Select a supported relationship type.");
	}
	$source = nms_relationship_endpoint($source);
	$target = nms_relationship_endpoint($target);
	if ($source["host_id"] === $target["host_id"]) {
		throw new InvalidArgumentException("Select two different devices.");
	}
	$fingerprint = hash(
		"sha256",
		json_encode([
			$source["host_id"],
			$source["query_id"],
			$source["index"],
			$target["host_id"],
			$target["query_id"],
			$target["index"],
			$type,
			"manual",
		]),
	);
	nms_category_execute(
		"INSERT INTO plugin_nms_relationships
		(fingerprint, source_host_id, source_query_id, source_index, source_identity,
		target_host_id, target_query_id, target_index, target_identity, relation_type,
		provenance, updated_by, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'manual', ?, NOW())
		ON DUPLICATE KEY UPDATE source_identity = VALUES(source_identity), target_identity = VALUES(target_identity),
		updated_by = VALUES(updated_by), updated_at = NOW(), archived_at = NULL",
		[
			$fingerprint,
			$source["host_id"],
			$source["query_id"],
			$source["index"],
			$source["identity"],
			$target["host_id"],
			$target["query_id"],
			$target["index"],
			$target["identity"],
			$type,
			nms_current_user_id(),
		],
	);
}

/** Archive a manual edge recoverably; an absent collector must not let the UI forge discovered edges. */
function nms_relationship_archive($id)
{
	$row = db_fetch_row_prepared("SELECT * FROM plugin_nms_relationships WHERE id = ?", [(int) $id]);
	if (!$row || $row["provenance"] !== "manual") {
		throw new InvalidArgumentException("Select a manual connection.");
	}
	nms_require_device_access($row["source_host_id"]);
	nms_require_device_access($row["target_host_id"]);
	nms_category_execute(
		"UPDATE plugin_nms_relationships SET archived_at = NOW(), updated_at = NOW(), updated_by = ? WHERE id = ?",
		[nms_current_user_id(), (int) $id],
	);
}

/** Read explicit visible site edges, including cross-site links, and flag cached interface identity changes. */
function nms_relationships($site_id)
{
	$source_visible = nms_visible_host_sql("s.id");
	$target_visible = nms_visible_host_sql("t.id");
	$rows = db_fetch_assoc_prepared(
		"SELECT r.*, s.description AS source_name, t.description AS target_name
		FROM plugin_nms_relationships AS r INNER JOIN host AS s ON s.id = r.source_host_id AND s.deleted = ''
		INNER JOIN host AS t ON t.id = r.target_host_id AND t.deleted = ''
		WHERE r.archived_at IS NULL AND (s.site_id = ? OR t.site_id = ?) AND $source_visible AND $target_visible
		ORDER BY r.id",
		[(int) $site_id, (int) $site_id],
	);
	foreach ($rows as &$row) {
		$row["identity_state"] = "recorded";
		foreach (["source", "target"] as $end) {
			if (
				(int) $row[$end . "_query_id"] > 0 &&
				nms_relationship_interface_identity(
					$row[$end . "_host_id"],
					$row[$end . "_query_id"],
					$row[$end . "_index"],
				) !== $row[$end . "_identity"]
			) {
				$row["identity_state"] = "needs revalidation";
			}
		}
	}
	unset($row);
	return $rows;
}

/** Offer native devices and actual interface cache rows, keeping query identity distinct from port numbers. */
function nms_relationship_options()
{
	$visible = nms_visible_host_sql();
	$devices = db_fetch_assoc(
		"SELECT h.id, h.description FROM host AS h WHERE h.deleted = '' AND h.disabled = '' AND $visible ORDER BY h.description",
	);
	$options = [];
	foreach ($devices as $device) {
		$options[$device["id"] . ":0:"] = $device["description"] . " (device)";
	}
	$interfaces = db_fetch_assoc("SELECT h.id, h.description, c.snmp_query_id, c.snmp_index,
		MAX(CASE c.field_name WHEN 'ifName' THEN c.field_value ELSE '' END) AS if_name,
		MAX(CASE c.field_name WHEN 'ifDescr' THEN c.field_value ELSE '' END) AS if_description
		FROM host AS h INNER JOIN host_snmp_cache AS c ON c.host_id = h.id
		WHERE h.deleted = '' AND h.disabled = '' AND $visible AND c.field_name IN ('ifName', 'ifDescr')
		GROUP BY h.id, h.description, c.snmp_query_id, c.snmp_index ORDER BY h.description, c.snmp_index");
	foreach ($interfaces as $interface) {
		$options[$interface["id"] . ":" . $interface["snmp_query_id"] . ":" . $interface["snmp_index"]] =
			$interface["description"] .
			" · " .
			($interface["if_name"] !== "" ? $interface["if_name"] : $interface["if_description"]) .
			" (query " .
			$interface["snmp_query_id"] .
			", index " .
			$interface["snmp_index"] .
			")";
	}
	return $options;
}
