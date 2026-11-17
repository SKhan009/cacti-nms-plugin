<?php

require_once __DIR__ . '/../../protocols/syslog/services/syslog_history_report.php';
require_once __DIR__ . '/../../protocols/shared/services/protocol_preset_service.php';

function icct_nms_alarm_syslog_url($console, $overrides = []): string
{
    return 'alarms/controllers/alarms.php?' . http_build_query(array_replace(
        ['tab' => 'syslog', 'size' => $console['size']], $console['params'], $overrides,
    ));
}

function icct_nms_alarm_syslog_console(array $input)
{
    $devices = array_column(icct_nms_inventory(), null, 'id');
    $params = array_intersect_key($input, array_flip([
        'syslog_search', 'syslog_facility', 'syslog_severity', 'syslog_since',
    ]));
    foreach ($params as $key => $value) {
        if ($value === '' || $value === ['']) {
            unset($params[$key]);
        }
    }
    [$filters] = icct_nms_syslog_history_request(0, $params);
    unset($filters['host_id']);
    $filters['origin'] = 'syslog';
    $ids = array_keys($devices);
    if (($input['device'] ?? '') !== '') {
        $device = icct_nms_id($input['device']);
        $ids = isset($devices[$device]) ? [$device] : [];
        $params['device'] = $device;
    }
    if (($input['sort'] ?? '') === 'oldest') {
        $params['sort'] = 'oldest';
        $filters['oldest_first'] = true;
    }
    $size = is_scalar($input['size'] ?? '') ? (int) ($input['size'] ?? 10) : 10;
    $size = in_array($size, [10, 25, 50, 100], true) ? $size : 10;
    $page = is_scalar($input['page'] ?? '') ? max(1, min(1000000, (int) ($input['page'] ?? 1))) : 1;
    $total = 0;
    $read = static function ($page) use ($ids, $filters, $size, &$total) {
        $events = icct_backend_syslog_events($ids, $filters, $size, ($page - 1) * $size, $total, false, true);
        if ($events === false) {
            throw new RuntimeException('Unable to load Syslog readings.');
        }
        return $events;
    };
    $events = $read($page);
    $pages = max(1, (int) ceil($total / $size));
    if ($page > $pages) {
        $page = $pages;
        $events = $read($page);
    }
    return compact('devices', 'events', 'params', 'size', 'page', 'pages', 'total');
}
