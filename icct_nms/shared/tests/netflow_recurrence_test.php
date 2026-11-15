<?php

/** Run against connection-local tables so the real alarm journal is untouched. */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require '/var/www/html/cacti/include/cli_check.php';
api_plugin_load_realms();
require_once $config['base_path'] . '/lib/auth.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION = ['sess_user_id' => 1];
require_once __DIR__ . '/../services/bootstrap.php';
require_once __DIR__ . '/../../protocols/netflow/services/netflow_faults.php';
icct_nms_backend();
foreach (['netflow_events', 'alarms', 'alarm_history'] as $suffix) {
    $table = 'plugin_icct_nms_' . $suffix;
    if (!db_execute("CREATE TEMPORARY TABLE qa_recurrence_copy LIKE $table") ||
        !db_execute("CREATE TEMPORARY TABLE $table LIKE qa_recurrence_copy") ||
        !db_execute('DROP TEMPORARY TABLE qa_recurrence_copy')) {
        throw new RuntimeException('Temporary alarm isolation failed.');
    }
}
$host = db_fetch_row_prepared(
    "SELECT h.* FROM host h JOIN plugin_icct_nms_netflow_devices d ON d.host_id=h.id " .
        "WHERE h.deleted='' AND h.disabled='' AND d.enabled=1 AND h.poller_id=? LIMIT 1",
    [icct_backend_inventory_collector_id()],
);
if (!$host) {
    throw new RuntimeException('An assigned NetFlow device is needed.');
}
$hostId = (int) $host['id'];
$eventKey = str_repeat('e', 32);
$addEvent = static function ($state) use ($hostId, $eventKey) {
    if (!db_execute_prepared(
        'INSERT INTO plugin_icct_nms_netflow_events(host_id,event_key,severity,state,message,opened_at,updated_at) ' .
            'VALUES(?,?,?, ?,?,NOW(),NOW())',
        [$hostId, $eventKey, 'Major', $state, 'Unusually high bandwidth in isolated QA'],
    )) {
        throw new RuntimeException('Unable to add isolated NetFlow event.');
    }
};
$addEvent('Active');
icct_nms_netflow_sync_faults();
$key = hash('sha256', 'netflow:' . $hostId . ':' . $eventKey);
$get = static fn() => db_fetch_row_prepared(
    'SELECT * FROM plugin_icct_nms_alarms WHERE alarm_key=?', [$key],
);
$first = $get();
if (!$first || (int) $first['occurrences'] !== 1) {
    throw new RuntimeException('First NetFlow occurrence was not opened.');
}
icct_nms_alarm_action([
    'alarm_key' => $key, 'alarm_action' => 'ack',
    'revision' => $first['revision'], 'note' => 'Carry this acknowledgement forward',
]);
db_execute_prepared(
    "UPDATE plugin_icct_nms_netflow_events SET state='Resolved',updated_at=NOW() WHERE host_id=? AND event_key=?",
    [$hostId, $eventKey],
);
icct_nms_netflow_sync_faults();
$addEvent('Active');
icct_nms_netflow_sync_faults();
icct_nms_netflow_sync_faults();
$latest = $get();
$raised = json_decode((string) db_fetch_cell_prepared(
    "SELECT snapshot_json FROM plugin_icct_nms_alarm_history WHERE alarm_key=? AND event='Raised' ORDER BY id DESC LIMIT 1",
    [$key],
), true);
if ((int) $latest['occurrences'] !== 2 || !(int) $latest['acknowledged'] ||
    ($raised['ack_note'] ?? '') !== 'Carry this acknowledgement forward' ||
    (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_icct_nms_alarms') !== 1 ||
    (int) db_fetch_cell_prepared(
        "SELECT COUNT(*) FROM plugin_icct_nms_alarm_history WHERE alarm_key=? AND event='Raised'",
        [$key],
    ) !== 2) {
    throw new RuntimeException('A recurrence duplicated the alarm, count, or acknowledgement.');
}
echo "PASS: new NetFlow occurrence increments one alarm and carries acknowledgement.\n";
