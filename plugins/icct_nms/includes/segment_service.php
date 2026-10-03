<?php
/** Segment presets share the classifications already used by device forms. */
function icct_nms_save_segment($input)
{
    $id = icct_nms_id($input['segment_id'] ?? 0);
    if ($id && !icct_backend_category_exists($id)) throw new InvalidArgumentException('This segment no longer exists. Reload the page.');
    if (($input['action'] ?? '') === 'delete_segment') {
        $references = (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_icct_nms_device_classification WHERE category_id=?', [$id]);
        $references += (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_icct_nms_snmprec_imports WHERE category_id=?', [$id]);
        if (function_exists('icct_nms_device_types')) foreach (icct_nms_device_types() as $type) if ((int)$type['category_id'] === $id) $references++;
        if (!$id) throw new InvalidArgumentException('Choose a saved segment.');
        if ($references) throw new InvalidArgumentException('This segment is in use. Reassign its devices, device types and imported templates before deleting it.');
        icct_backend_category_execute('DELETE FROM plugin_icct_nms_categories WHERE id=?', [$id]);
        return 'Segment deleted.';
    }
    $name = icct_backend_classification_text($input['segment_name'] ?? '', 150);
    if ($name === '') throw new InvalidArgumentException('Enter a segment name.');
    if (db_fetch_cell_prepared('SELECT id FROM plugin_icct_nms_categories WHERE LOWER(name)=LOWER(?) AND id<>? LIMIT 1', [$name,$id])) throw new InvalidArgumentException('A segment with this name already exists. Choose a different name.');
    if ($id) {
        icct_backend_category_execute('UPDATE plugin_icct_nms_categories SET name=?,updated_by=?,updated_at=NOW() WHERE id=?', [$name,icct_backend_current_user_id(),$id]);
        return 'Segment updated.';
    }
    icct_backend_category_execute("INSERT INTO plugin_icct_nms_categories(name,description,sort_order,updated_by,updated_at) VALUES(?,'',0,?,NOW())", [$name,icct_backend_current_user_id()]);
    return 'Segment added.';
}
