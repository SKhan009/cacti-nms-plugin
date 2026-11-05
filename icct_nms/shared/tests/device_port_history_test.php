<?php

/** Moving history must retain device scope, permissions and the selected tab during paging. */
$id = 2;
$allowed = true;
$queries = [];
$_GET = ['port_events_page' => 2];
function is_realm_allowed($realm)
{
    return $GLOBALS['allowed'];
}
function icct_nms_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function db_fetch_cell_prepared($sql, $args)
{
    $GLOBALS['queries'][] = [$sql, $args];
    return 30;
}
function db_fetch_assoc_prepared($sql, $args)
{
    $GLOBALS['queries'][] = [$sql, $args];
    return [
        [
            'created_at' => '2026-11-04 21:00:00',
            'port_name' => '<unsafe>',
            'if_index' => 2,
            'event' => 'Raised',
            'state_before' => 'Normal',
            'severity_before' => '',
            'severity_after' => 'Warning',
            'state_after' => 'DOWN',
            'admin_status' => 1,
            'oper_status' => 2,
            'collected_at' => '2026-11-04 20:59:59',
        ],
    ];
}
function renderPortHistory()
{
    global $id;
    ob_start();
    require __DIR__ . '/../../ports/templates/device_port_history.php';
    return ob_get_clean();
}
$html = renderPortHistory();
if (
    !str_contains($html, 'Port alarm history') ||
    !str_contains($html, '&lt;unsafe&gt;') ||
    !str_contains($html, 'ports_tab=history#view-ports') ||
    !str_contains($html, 'Page 2 of 2')
) {
    throw new RuntimeException('Port history must escape real events and preserve its tab/page.');
}
foreach ($queries as [$sql, $args]) {
    if (str_contains($sql, 'plugin_icct_nms_meta')) {
        if ($args !== ['port_alarm_state_2']) {
            throw new RuntimeException('Snapshot must use this device.');
        }
        continue;
    }
    if ($args !== [2] || !str_contains($sql, 'host_id=?')) {
        throw new RuntimeException('History must remain scoped to the authorized device.');
    }
}
$allowed = false;
$queries = [];
if (!str_contains(renderPortHistory(), 'permission') || $queries) {
    throw new RuntimeException('Unauthorized history must not query or expose events.');
}
$logs = file_get_contents(__DIR__ . '/../../inventory/templates/device_records.php');
if (str_contains($logs, 'port_alarm_events') || str_contains($logs, 'Port alarm history')) {
    throw new RuntimeException('Port history must be moved, not duplicated in Logs.');
}
echo "Port history tab preserves permissions, device scope, escaping and pagination without duplication.\n";
