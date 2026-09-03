<?php
/** Operational groups are many-to-many labels, not equipment classes, sites or display trees. */
require_once(__DIR__ . '/categories.php');

/** Cacti has no equivalent independent many-to-many operational device grouping. */
function nms_group_schema() {
	nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_groups (
		id INT UNSIGNED NOT NULL AUTO_INCREMENT,
		name VARCHAR(150) NOT NULL,
		description VARCHAR(512) NOT NULL DEFAULT '',
		updated_by INT UNSIGNED NOT NULL DEFAULT 0,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (id), UNIQUE KEY name (name)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");
	nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_group_members (
		group_id INT UNSIGNED NOT NULL,
		host_id MEDIUMINT UNSIGNED NOT NULL,
		updated_by INT UNSIGNED NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (group_id, host_id), KEY host_id (host_id)
	) ENGINE=InnoDB ROW_FORMAT=Dynamic");
}

/** List labels independently of device visibility; membership is permission-filtered by callers. */
function nms_groups() {
	return db_fetch_assoc('SELECT * FROM plugin_nms_groups ORDER BY name, id');
}

/** Create/rename an operational label without altering core devices or fault categories. */
function nms_group_save($id, $name, $description) {
	$id = (int) $id;
	$name = nms_classification_text($name, 150);
	$description = nms_classification_text($description, 512);
	if ($name === '') throw new InvalidArgumentException('An operational group needs a name.');
	if ($id > 0 && !(int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_groups WHERE id = ?', array($id))) {
		throw new InvalidArgumentException('Select an existing operational group.');
	}
	if ((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_groups WHERE name = ? AND id != ?', array($name, $id))) {
		throw new InvalidArgumentException('An operational group with this name already exists.');
	}
	if ($id > 0) {
		nms_category_execute('UPDATE plugin_nms_groups SET name = ?, description = ?, updated_by = ?, updated_at = NOW() WHERE id = ?',
			array($name, $description, nms_current_user_id(), $id));
	} else {
		nms_category_execute('INSERT INTO plugin_nms_groups (name, description, updated_by, updated_at) VALUES (?, ?, ?, NOW())',
			array($name, $description, nms_current_user_id()));
		$id = (int) db_fetch_cell('SELECT LAST_INSERT_ID()');
	}
	return $id;
}

/** Atomically replace one device's explicit group memberships after validating every selected group. */
function nms_group_membership_save($host_id, $group_ids) {
	$host_id = (int) $host_id;
	if (!is_array($group_ids)) throw new InvalidArgumentException('Submit a list of operational groups.');
	if (!(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM host WHERE id = ? AND deleted = ''", array($host_id))) {
		throw new InvalidArgumentException('Select an existing Cacti device.');
	}
	$ids = array();
	foreach ($group_ids as $id) {
		if (!is_scalar($id) || !preg_match('/^[1-9][0-9]*$/D', (string) $id) ||
			!(int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_groups WHERE id = ?', array((int) $id))) {
			throw new InvalidArgumentException('An operational group is invalid or no longer exists.');
		}
		$ids[(int) $id] = (int) $id;
	}
	nms_category_execute('START TRANSACTION');
	try {
		nms_category_execute('DELETE FROM plugin_nms_group_members WHERE host_id = ?', array($host_id));
		foreach ($ids as $id) nms_category_execute('INSERT INTO plugin_nms_group_members (group_id, host_id, updated_by, updated_at) VALUES (?, ?, ?, NOW())',
			array($id, $host_id, nms_current_user_id()));
		nms_category_execute('COMMIT');
	} catch (Throwable $error) {
		db_execute('ROLLBACK');
		throw $error;
	}
}
