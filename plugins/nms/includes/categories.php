<?php
/**
 * Equipment classification owned by NMS. Cacti owns hosts, templates, native
 * template classes, sites and trees; none of those objects are changed here.
 */

/**
 * Execute one plugin-owned storage statement and fail closed on a database error.
 *
 * Cacti's prepared helper returns false after it has recorded the native database
 * error.  Callers must not continue an incident lifecycle, migration, or audit
 * operation after that point because doing so would make the UI claim state that
 * was never stored.  The exception deliberately excludes SQL text and values so
 * credentials and device data are not copied into web or poller diagnostics.
 */
/**
 * Handles storage execute.
 */
function nms_storage_execute($sql, $params = [])
{
	if (db_execute_prepared($sql, $params) === false) {
		throw new RuntimeException("NMS storage operation failed. Check the Cacti database log.");
	}
}

/** Backward-compatible checked-write name retained for existing migration callers. */
function nms_category_execute($sql, $params = [])
{
	nms_storage_execute($sql, $params);
}

/** Create only the relationships missing from Cacti; DDL precedes the data transaction. */
function nms_category_schema()
{
	nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_categories (
		id INT UNSIGNED NOT NULL AUTO_INCREMENT,
		name VARCHAR(150) NOT NULL,
		description VARCHAR(512) NOT NULL DEFAULT '',
		seed_key VARCHAR(64) NULL DEFAULT NULL,
		sort_order INT UNSIGNED NOT NULL DEFAULT 0,
		updated_by INT UNSIGNED NOT NULL DEFAULT 0,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (id), UNIQUE KEY seed_key (seed_key)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");
	nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_category_migration (
		legacy_tree_id INT UNSIGNED NOT NULL,
		category_id INT UNSIGNED NOT NULL,
		legacy_name VARCHAR(150) NOT NULL,
		migrated_at DATETIME NOT NULL,
		PRIMARY KEY (legacy_tree_id), UNIQUE KEY category_id (category_id)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");
	nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_device_classification (
		host_id MEDIUMINT UNSIGNED NOT NULL,
		category_id INT UNSIGNED NOT NULL DEFAULT 0,
		device_type VARCHAR(150) NOT NULL DEFAULT '',
		device_role VARCHAR(150) NOT NULL DEFAULT '',
		assignment_source VARCHAR(24) NOT NULL,
		updated_by INT UNSIGNED NOT NULL DEFAULT 0,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (host_id), KEY category_id (category_id)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");
}

/**
 * Translate the old tree namespace once, in a transaction under a DB-wide lock.
 * Preserve rule IDs, incident fingerprints and core trees. Snapshot each existing
 * host's inherited assignment so subsequent template edits cannot move it.
 * Unknown/deleted tree references retain an explicit migration label, not a guess.
 */
function nms_category_migrate()
{
	if (
		db_fetch_cell_prepared("SELECT meta_value FROM plugin_nms_meta WHERE meta_key = ?", [
			"equipment_categories_v1",
		]) === "complete"
	) {
		return;
	}
	$lock = "nms_categories_" . substr(hash("sha256", (string) db_fetch_cell("SELECT DATABASE()")), 0, 32);
	if ((int) db_fetch_cell_prepared("SELECT GET_LOCK(?, 10)", [$lock]) !== 1) {
		throw new RuntimeException("NMS category upgrade is busy. Retry after the current upgrade completes.");
	}
	try {
		if (
			db_fetch_cell_prepared("SELECT meta_value FROM plugin_nms_meta WHERE meta_key = ?", [
				"equipment_categories_v1",
			]) === "complete"
		) {
			return;
		}
		if (
			(int) db_fetch_cell("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
			AND TABLE_NAME = 'plugin_nms_device_categories'") &&
			(int) db_fetch_cell("SELECT COUNT(*) FROM plugin_nms_device_categories")
		) {
			throw new RuntimeException(
				"Legacy category storage still exists. Confirm whether references are legacy category IDs or tree IDs before migration; no records were translated.",
			);
		}
		nms_category_execute("START TRANSACTION");
		$references = db_fetch_assoc("SELECT refs.category_id AS legacy_tree_id, t.name
			FROM (SELECT category_id FROM plugin_nms_category_templates
				UNION SELECT category_id FROM plugin_nms_fault_rules
				UNION SELECT category_id FROM plugin_nms_snmprec_imports) AS refs
			LEFT JOIN graph_tree AS t ON t.id = refs.category_id
			WHERE refs.category_id > 0 ORDER BY refs.category_id");
		if (!is_array($references)) {
			throw new RuntimeException("Could not read legacy category references.");
		}
		foreach ($references as $reference) {
			$name =
				$reference["name"] === null
					? "Legacy tree #" . (int) $reference["legacy_tree_id"] . " (missing)"
					: $reference["name"];
			nms_category_execute(
				'INSERT INTO plugin_nms_categories (name, description, updated_at)
				VALUES (?, ?, NOW())',
				[
					$name,
					"Preserved from Cacti tree ID " .
					(int) $reference["legacy_tree_id"] .
					"; independent device segment.",
				],
			);
			$id = (int) db_fetch_cell("SELECT LAST_INSERT_ID()");
			if ($id < 1) {
				throw new RuntimeException("Could not allocate an device segment.");
			}
			nms_category_execute(
				'INSERT INTO plugin_nms_category_migration
				(legacy_tree_id, category_id, legacy_name, migrated_at) VALUES (?, ?, ?, NOW())',
				[$reference["legacy_tree_id"], $id, $name],
			);
		}
		// A joined update reads the old ID once; overlapping old/new IDs cannot cascade.
		foreach (["plugin_nms_category_templates", "plugin_nms_fault_rules", "plugin_nms_snmprec_imports"] as $table) {
			nms_category_execute(
				"UPDATE " .
					$table .
					' AS r INNER JOIN plugin_nms_category_migration AS m
				ON m.legacy_tree_id = r.category_id SET r.category_id = m.category_id',
			);
		}
		nms_category_execute("INSERT INTO plugin_nms_device_classification
			(host_id, category_id, assignment_source, updated_at)
			SELECT h.id, ct.category_id, 'legacy_template', NOW() FROM host AS h
			INNER JOIN plugin_nms_category_templates AS ct ON ct.host_template_id = h.host_template_id
			WHERE h.deleted = ''");
		// Initial catalogue data is editable in the UI; it is never a runtime enum.
		$catalogue = [
			"network" => "Network",
			"voice-video" => "Voice/Video",
			"security" => "Security",
			"vsat" => "Satellite/VSAT",
			"los" => "LOS Communications",
			"computers" => "Computers",
			"power" => "Power",
			"timing" => "Timing",
			"sensors" => "Sensors/Instrumentation",
			"fire" => "Fire Prevention",
		];
		$order = 0;
		foreach ($catalogue as $key => $name) {
			// Do not merge similarly named legacy categories: that would merge rule scopes.
			if (!(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_nms_categories WHERE name = ?", [$name])) {
				nms_category_execute(
					'INSERT INTO plugin_nms_categories (name, seed_key, sort_order, updated_at)
					VALUES (?, ?, ?, NOW())',
					[$name, $key, ++$order * 10],
				);
			}
		}
		nms_category_execute("INSERT INTO plugin_nms_meta (meta_key, meta_value, updated_at)
			VALUES ('equipment_categories_v1', 'complete', NOW())
			ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = NOW()");
		nms_category_execute("COMMIT");
	} catch (Throwable $error) {
		db_execute("ROLLBACK");
		throw $error;
	} finally {
		db_fetch_cell_prepared("SELECT RELEASE_LOCK(?)", [$lock]);
	}
}

/** Normalize the old default label without merging classification scopes. */
function nms_category_normalize_legacy_default()
{
	// Rename only the untouched migrated label. Preserve IDs, assignments and audit history.
	nms_category_execute("UPDATE plugin_nms_categories AS c
		INNER JOIN plugin_nms_category_migration AS m ON m.category_id = c.id
		SET c.name = 'General', c.updated_at = NOW()
		WHERE c.name = 'Default Tree' AND m.legacy_name = 'Default Tree'
		AND c.updated_by = 0");
}

/** Device segment IDs are independent of graph_tree IDs. Zero means explicitly unclassified. */
function nms_category_exists($category_id)
{
	return (int) $category_id > 0 &&
		(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_nms_categories WHERE id = ?", [
			(int) $category_id,
		]) === 1;
}

/** Read editable device segments in presentation order, not Cacti tree order. */
function nms_categories()
{
	return db_fetch_assoc("SELECT * FROM plugin_nms_categories ORDER BY sort_order, name, id");
}

/** Validate text without silently truncating asset classification submitted by operators. */
function nms_classification_text($text, $max_length)
{
	if (!is_string($text)) {
		throw new InvalidArgumentException("Classification text must be a string.");
	}
	$text = trim((string) $text);
	if (
		!preg_match("//u", $text) ||
		preg_match('/[\x00-\x1f\x7f]/', $text) ||
		preg_match_all("/./us", $text) > $max_length
	) {
		throw new InvalidArgumentException(
			"Classification text contains invalid characters or exceeds its allowed length.",
		);
	}
	return $text;
}

/** Create or edit catalogue metadata without changing assignments, rules or core trees. */
function nms_category_save($id, $name, $description)
{
	$id = (int) $id;
	$name = nms_classification_text($name, 150);
	$description = nms_classification_text($description, 512);
	if ($name === "" || ($id > 0 && !nms_category_exists($id))) {
		throw new InvalidArgumentException("Select a valid category and enter its name.");
	}
	if ($id > 0) {
		nms_category_execute(
			"UPDATE plugin_nms_categories SET name = ?, description = ?, updated_by = ?, updated_at = NOW() WHERE id = ?",
			[$name, $description, nms_current_user_id(), $id],
		);
	} else {
		nms_category_execute(
			"INSERT INTO plugin_nms_categories (name, description, updated_by, updated_at) VALUES (?, ?, ?, NOW())",
			[$name, $description, nms_current_user_id()],
		);
		$id = (int) db_fetch_cell("SELECT LAST_INSERT_ID()");
	}
	return $id;
}

/** Save a default for future reviewed device creation, never reclassify existing devices. */
function nms_assign_template_category($template_id, $category_id)
{
	if (!(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM host_template WHERE id = ?", [(int) $template_id])) {
		throw new InvalidArgumentException("Select a valid Cacti device template.");
	}
	if (!nms_category_exists($category_id)) {
		throw new InvalidArgumentException("Select a valid device segment.");
	}
	nms_category_execute(
		'INSERT INTO plugin_nms_category_templates (host_template_id, category_id, assigned_at)
		VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), assigned_at = NOW()',
		[(int) $template_id, (int) $category_id],
	);
}

/** Resolve only an explicitly requested template suggestion; never guess by a device's name. */
function nms_new_device_category($choice, $template_id)
{
	if ($choice === "template") {
		$choice = db_fetch_cell_prepared(
			"SELECT category_id FROM plugin_nms_category_templates WHERE host_template_id = ?",
			[(int) $template_id],
		);
		if (!$choice) {
			throw new InvalidArgumentException(
				"This template has no device segment suggestion. Select a category or Unclassified.",
			);
		}
	}
	if (!is_scalar($choice) || !preg_match('/^[0-9]+$/D', (string) $choice)) {
		throw new InvalidArgumentException("Select an device segment or Unclassified.");
	}
	$category_id = (int) $choice;
	if ($category_id > 0 && !nms_category_exists($category_id)) {
		throw new InvalidArgumentException("The device segment no longer exists.");
	}
	return $category_id;
}

/** Persist one explicit device assignment; no template or tree updates are performed. */
function nms_device_classification_save($host_id, $category_id, $type, $role)
{
	$host_id = (int) $host_id;
	$category_id = (int) $category_id;
	if (!(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM host WHERE id = ? AND deleted = ''", [$host_id])) {
		throw new InvalidArgumentException("Select an existing Cacti device.");
	}
	if ($category_id < 0 || ($category_id > 0 && !nms_category_exists($category_id))) {
		throw new InvalidArgumentException("Select a valid device segment or Unclassified.");
	}
	$type = nms_classification_text($type, 150);
	$role = nms_classification_text($role, 150);
	nms_category_execute(
		"INSERT INTO plugin_nms_device_classification
		(host_id, category_id, device_type, device_role, assignment_source, updated_by, updated_at)
		VALUES (?, ?, ?, ?, 'manual', ?, NOW()) ON DUPLICATE KEY UPDATE category_id = VALUES(category_id),
		device_type = VALUES(device_type), device_role = VALUES(device_role), assignment_source = 'manual',
		updated_by = VALUES(updated_by), updated_at = NOW()",
		[$host_id, $category_id, $type, $role, nms_current_user_id()],
	);
}
