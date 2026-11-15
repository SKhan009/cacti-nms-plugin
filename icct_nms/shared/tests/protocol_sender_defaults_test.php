<?php
/** Sender defaults must use native device addresses without DNS or invented loopbacks. */
$writes = [];
function icct_backend_require_management($id) {}
function icct_backend_require_device_access($id) {}
function icct_backend_protocol_enabled($id, $protocol) { return true; }
function icct_backend_current_user_id() { return 1; }
function db_fetch_cell_prepared($sql, $args) {
    if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK')) return 1;
    if (str_contains($sql, 'SELECT hostname')) return '192.0.2.91';
    return null;
}
function db_fetch_row_prepared($sql, $args) { return []; }
function icct_backend_category_execute($sql, $args) { $GLOBALS['writes'][] = [$sql, $args]; }
require __DIR__ . '/../../protocols/syslog/services/backend/syslog.php';
require __DIR__ . '/../../protocols/syslog/services/syslog_service.php';
require __DIR__ . '/../../protocols/netflow/services/netflow_service.php';
require __DIR__ . '/../../protocols/snmp/traps/services/trap_service.php';
foreach (['', '   ', '192.0.2.92', '2001:db8::92'] as $input) {
    $expected = trim($input) ?: '192.0.2.91';
    $writes = [];
    icct_nms_save_syslog(91, ['hostname'=>'192.0.2.91'], ['source_address'=>$input]);
    if ($writes[0][1][2] !== $expected) throw new Exception('Syslog sender mismatch');
    $writes = [];
    icct_nms_netflow_save(91, ['hostname'=>'192.0.2.91'], ['exporter_address'=>$input, 'collector'=>'192.0.2.10']);
    if ($writes[1][1][2] !== $expected) throw new Exception('NetFlow sender mismatch');
    $writes = [];
    icct_nms_save_trap_sender(91, ['trap_enabled'=>1, 'trap_source_address'=>$input]);
    if ($writes[0][1][1] !== $expected) throw new Exception('Trap sender mismatch');
}
try {
    icct_nms_save_syslog(91, ['hostname'=>'offline-device.invalid'], ['source_address'=>'']);
    throw new RuntimeException('Hostname should require a numeric override');
} catch (InvalidArgumentException $e) {}
echo "PASS: blank and whitespace inherit device IP; explicit IPv4/IPv6 preserved; no DNS fallback.\n";
