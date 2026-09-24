<?php
/** Saved diagnostic history, bounded in SQL and scoped like result access. */
function nms_diag_history(array $input)
{
    $value = static function ($key) use ($input) { return is_scalar($input[$key] ?? null) ? (string) $input[$key] : ''; };
    $filters = [
        'node_id' => max(0, (int) $value('node_id')),
        'history_device' => max(0, (int) $value('history_device')),
        'history_tool' => $value('history_tool'),
        'history_status' => $value('history_status'),
        'history_from' => $value('history_from'),
        'history_to' => $value('history_to'),
        'history_size' => (int) $value('history_size'),
    ];
    if (!isset(nms_diag_labels()[$filters['history_tool']])) $filters['history_tool'] = '';
    if (!in_array($filters['history_status'], ['queued', 'running', 'complete', 'failed'], true)) $filters['history_status'] = '';
    foreach (['history_from', 'history_to'] as $key) {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $filters[$key]) ? DateTimeImmutable::createFromFormat('!Y-m-d', $filters[$key]) : false;
        if (!$date || $date->format('Y-m-d') !== $filters[$key]) $filters[$key] = '';
    }
    if (!in_array($filters['history_size'], [5, 10, 25, 50, 100], true)) $filters['history_size'] = 10;
    $base = " FROM plugin_nms_diagnostic_jobs j JOIN host h ON h.id=j.host_id WHERE j.user_id=? AND h.deleted='' AND " . nms_visible_host_sql('h.id');
    $params = [nms_current_user_id()];
    if ($filters['node_id']) {
        $base .= ' AND EXISTS (SELECT 1 FROM plugin_nms_node_devices nm WHERE nm.host_id=h.id AND nm.node_id=?)';
        $params[]=$filters['node_id'];
    }
    $devices = db_fetch_assoc_prepared('SELECT DISTINCT h.id,h.description,h.hostname' . $base . ' ORDER BY h.description,h.id', $params);
    foreach (['history_device' => 'j.host_id', 'history_tool' => 'j.tool', 'history_status' => 'j.status'] as $key => $column) {
        if ($filters[$key] !== '' && $filters[$key] !== 0) { $base .= " AND $column=?"; $params[] = $filters[$key]; }
    }
    if ($filters['history_from'] !== '') { $base .= ' AND j.requested_at>=?'; $params[] = $filters['history_from'] . ' 00:00:00'; }
    if ($filters['history_to'] !== '') { $base .= ' AND j.requested_at<DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $filters['history_to'] . ' 00:00:00'; }
    $total = (int) db_fetch_cell_prepared('SELECT COUNT(*)' . $base, $params);
    $pages = max(1, (int) ceil($total / $filters['history_size']));
    $page = min($pages, max(1, (int) $value('history_page')));
    $offset = ($page - 1) * $filters['history_size'];
    // Do not fetch potentially large outputs until a result is opened.
    $rows = db_fetch_assoc_prepared('SELECT j.id,j.host_id,j.tool,j.status,j.requested_at,j.finished_at,h.description,h.hostname' . $base . ' ORDER BY j.id DESC LIMIT ' . $filters['history_size'] . ' OFFSET ' . $offset, $params);
    return compact('filters', 'devices', 'total', 'pages', 'page', 'offset', 'rows');
}

function nms_diag_history_url(array $filters, array $extra = [])
{
    return 'diagnostics.php?' . http_build_query(array_merge(['section' => 'run'], $filters, $extra), '', '&', PHP_QUERY_RFC3986);
}
