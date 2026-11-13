<?php
require __DIR__ . '/../../protocols/netflow/services/netflow_service.php';
require_once __DIR__ . '/../../protocols/netflow/services/netflow_rules.php';
$latest = time() - 86400;
function db_fetch_cell_prepared($sql, $args) {
    if ($args !== [2]) throw new Exception('Device scope missing');
    return $GLOBALS['latest'];
}
function db_fetch_row_prepared($sql, $args) {
    if ($args[0] !== 2 || $args[2] - $args[1] !== 300) throw new Exception('Invalid measurement window');
    if (str_contains($sql, 'MAX(rate)')) return ['rate'=>0];
    $historical = $args[2] === $GLOBALS['latest'];
    return ['flows'=>$historical ? 3 : 0,'latest_epoch'=>$historical ? $GLOBALS['latest'] : null,
        'bandwidth'=>0.25,'destinations'=>1,'ports'=>1,'smb_destinations'=>0,'syn_rate'=>0];
}
$live = icct_nms_netflow_live_readings(2);
if ($live['flows'] !== 0 || $live['values'] !== []) throw new Exception('Stale values treated as live');
$past = icct_nms_netflow_last_readings(2);
if ($past['flows'] !== 3 || $past['values']['bandwidth'] !== 0.25) throw new Exception('Historical readings missing');
$latest = 0;
if (icct_nms_netflow_last_readings(2) !== null) throw new Exception('Invented readings');
$latest = time();
if (icct_nms_netflow_last_readings(2) !== null) throw new Exception('Fresh data marked historical');
echo "NetFlow: device scope, no-rule measurements, historical separation, and empty data passed.\n";
