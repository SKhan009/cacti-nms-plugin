<?php

/** Verify the one-time merge with connection-local legacy fixtures. */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require '/var/www/html/cacti/include/cli_check.php';
require_once __DIR__ . '/../services/netflow_alarm_migration.php';

foreach (['netflow_events', 'alarms', 'alarm_history'] as $suffix) {
    $table = 'plugin_icct_nms_' . $suffix;
    if (!db_execute("CREATE TEMPORARY TABLE qa_netflow_copy LIKE $table") ||
        !db_execute("CREATE TEMPORARY TABLE $table LIKE qa_netflow_copy") ||
        !db_execute('DROP TEMPORARY TABLE qa_netflow_copy')) {
        throw new RuntimeException('Unable to isolate NetFlow migration tables.');
    }
}
$hostId = (int) db_fetch_cell('SELECT id FROM host ORDER BY id LIMIT 1');
$eventKey = str_repeat('d', 32);
for ($episode = 1; $episode <= 3; $episode++) {
    if (!db_execute_prepared(
        'INSERT INTO plugin_icct_nms_netflow_events ' .
            '(host_id,event_key,severity,state,message,opened_at,updated_at) ' .
            "VALUES(?,?,'Major',?,'Unusually high bandwidth',NOW(),NOW())",
        [$hostId, $eventKey, $episode === 3 ? 'Active' : 'Resolved'],
    )) {
        throw new RuntimeException('Unable to create isolated NetFlow event.');
    }
    $id = (int) db_fetch_cell('SELECT LAST_INSERT_ID()');
    $oldKey = hash('sha256', 'netflow:' . $id);
    if (!db_execute_prepared(
        'INSERT INTO plugin_icct_nms_alarms ' .
            '(alarm_key,host_id,source,graph_id,name,description,fix,severity,state,first_seen,last_seen,sample_time,occurrences,acknowledged) ' .
            "VALUES(?,?,'NetFlow',0,'NetFlow bandwidth overuse','Unusually high bandwidth','Review flows','Major',?,NOW(),NOW(),?,1,?)",
        [$oldKey, $hostId, $episode === 3 ? 'Active' : 'Resolved', time() + $episode, $episode === 2 ? 1 : 0],
    ) || !db_execute_prepared(
        'INSERT INTO plugin_icct_nms_alarm_history ' .
            '(alarm_key,event,actor_id,note,snapshot_json,created_at) VALUES(?,\'Raised\',0,\'Major\',?,NOW())',
        [$oldKey, json_encode(['occurrences' => 1, 'acknowledged' => $episode === 2 ? 1 : 0])],
    )) {
        throw new RuntimeException('Unable to create isolated legacy alarm.');
    }
}
$key = hash('sha256', 'netflow:' . $hostId . ':' . $eventKey);
icct_nms_schema_netflow_alarm_migration();
$alarm = db_fetch_row_prepared('SELECT * FROM plugin_icct_nms_alarms WHERE alarm_key=?', [$key]);
if (!$alarm || (int) $alarm['occurrences'] !== 3 || !(int) $alarm['acknowledged'] ||
    (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_icct_nms_alarms') !== 1 ||
    (int) db_fetch_cell_prepared(
        'SELECT COUNT(*) FROM plugin_icct_nms_alarm_history WHERE alarm_key=?', [$key],
    ) !== 3) {
    throw new RuntimeException('Legacy count, acknowledgement, or history was not retained.');
}
icct_nms_schema_netflow_alarm_migration();
if ((int) db_fetch_cell_prepared(
    'SELECT occurrences FROM plugin_icct_nms_alarms WHERE alarm_key=?', [$key],
) !== 3) {
    throw new RuntimeException('NetFlow migration was not idempotent.');
}
echo "PASS: legacy episodes merge with count, acknowledgement, and history.\n";
