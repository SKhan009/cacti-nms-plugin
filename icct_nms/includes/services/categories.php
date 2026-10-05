<?php
/** ICCT-owned categories services, derived from the existing ICCT NMS implementation. */

/** Reused Inventory service: category execute. */
function icct_backend_category_execute($sql, $params = [])
{
    icct_backend_storage_execute($sql, $params);
}

/** Reused Inventory service: category exists. */
function icct_backend_category_exists($category_id)
{
    return (int) $category_id > 0 &&
        (int) db_fetch_cell_prepared(
            'SELECT COUNT(*) FROM plugin_icct_nms_categories WHERE id = ?',
            [(int) $category_id]
        ) === 1;
}

/** Reused Inventory service: classification text. */
function icct_backend_classification_text($text, $max_length)
{
    if (!is_string($text)) {
        throw new InvalidArgumentException('Classification text must be a string.');
    }
    $text = trim((string) $text);
    if (
        !preg_match('//u', $text) ||
        preg_match('/[\x00-\x1f\x7f]/', $text) ||
        preg_match_all('/./us', $text) > $max_length
    ) {
        throw new InvalidArgumentException(
            'Classification text contains invalid characters or exceeds its allowed length.'
        );
    }
    return $text;
}

/** Reused Inventory service: device classification save. */
function icct_backend_device_classification_save($host_id, $category_id, $type, $role)
{
    $host_id = (int) $host_id;
    $category_id = (int) $category_id;
    if (
        !(int) db_fetch_cell_prepared("SELECT COUNT(*) FROM host WHERE id = ? AND deleted = ''", [
            $host_id
        ])
    ) {
        throw new InvalidArgumentException('Select an existing Cacti device.');
    }
    if ($category_id < 0 || ($category_id > 0 && !icct_backend_category_exists($category_id))) {
        throw new InvalidArgumentException('Select a valid device segment or Unclassified.');
    }
    $type = icct_backend_classification_text($type, 150);
    $role = icct_backend_classification_text($role, 150);
    icct_backend_category_execute(
        "INSERT INTO plugin_icct_nms_device_classification
		(host_id, category_id, device_type, device_role, assignment_source, updated_by, updated_at)
		VALUES (?, ?, ?, ?, 'manual', ?, NOW()) ON DUPLICATE KEY UPDATE category_id = VALUES(category_id),
		device_type = VALUES(device_type), device_role = VALUES(device_role), assignment_source = 'manual',
		updated_by = VALUES(updated_by), updated_at = NOW()",
        [$host_id, $category_id, $type, $role, icct_backend_current_user_id()]
    );
}

/** Reused Inventory service: storage execute. */
function icct_backend_storage_execute($sql, $params = [])
{
    if (db_execute_prepared($sql, $params) === false) {
        throw new RuntimeException('ICCT NMS storage operation failed. Check the Cacti database log.');
    }
}

/** Use an existing, unambiguous segment/type mapping; never invent a classification. */
function icct_backend_category_device_type($category_id, $host_id = 0)
{
    if ((int) $category_id === 0) return '';
    $types = db_fetch_assoc_prepared(
        "SELECT DISTINCT TRIM(device_type) AS device_type FROM plugin_icct_nms_device_classification WHERE category_id=? AND TRIM(device_type)<>'' LIMIT 2",
        [(int) $category_id]
    );
    if (count($types) === 1) return $types[0]['device_type'];
    if (count($types) > 1 && $host_id) {
        return (string) db_fetch_cell_prepared('SELECT device_type FROM plugin_icct_nms_device_classification WHERE host_id=? AND category_id=?', [(int) $host_id, (int) $category_id]);
    }
    return '';
}
