<?php
/** Exercise native login records against connection-local fixtures on an installed Cacti instance. */
if (PHP_SAPI !== 'cli' || empty($argv[1])) {
    exit(2);
}
require rtrim($argv[1], '/') . '/include/cli_check.php';
require_once __DIR__ . '/../services/bootstrap.php';
icct_nms_backend();
require_once __DIR__ . '/../../protocols/syslog/services/cacti_auth.php';
require_once __DIR__ . '/../../fcaps/services/passive_fault_service.php';

set_exception_handler(static function (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
});
foreach (['user_log', 'plugin_icct_nms_syslog_events', 'plugin_icct_nms_meta',
    'plugin_icct_nms_syslog_devices'] as $table) {
    icct_backend_syslog_execute("CREATE TEMPORARY TABLE qa_structure LIKE $table", []);
    if ($table === 'plugin_icct_nms_meta') {
        icct_backend_syslog_execute("INSERT INTO qa_structure SELECT * FROM $table
            WHERE meta_key <> 'cacti_auth_since'", []);
    }
    icct_backend_syslog_execute("CREATE TEMPORARY TABLE $table LIKE qa_structure", []);
    if ($table === 'plugin_icct_nms_meta') {
        icct_backend_syslog_execute("INSERT INTO $table SELECT * FROM qa_structure", []);
    }
    icct_backend_syslog_execute('DROP TEMPORARY TABLE qa_structure', []);
}
$hostId = db_fetch_cell("SELECT id FROM host WHERE deleted='' AND disabled='' AND poller_id=1
    AND hostname IN ('localhost','127.0.0.1','::1') ORDER BY id LIMIT 1");
$userId = db_fetch_cell('SELECT MIN(id) FROM user_auth');
if (!$hostId || !$userId) {
    throw new RuntimeException('A local inventory device and a Cacti user are required.');
}
icct_backend_syslog_execute("INSERT INTO plugin_icct_nms_syslog_devices(host_id,enabled,source_address)
    VALUES (?,1,'127.0.0.1')", [$hostId]);
foreach ([0, 1, 2] as $result) {
    icct_backend_syslog_execute("INSERT INTO user_log(username,user_id,`time`,result,ip)
        VALUES (?,?,NOW()-INTERVAL 1 MINUTE,?,'192.0.2.10')", ['fixture' . $result, $userId, $result]);
}
icct_nms_auth_collect();
icct_nms_auth_collect();
$_SESSION['sess_user_id'] = $userId;
$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
icct_nms_auth_logout();
$rows = db_fetch_assoc("SELECT * FROM plugin_icct_nms_syslog_events WHERE program='cacti-auth' ORDER BY id");
$outcomes = ['login failed', 'login succeeded', 'cookie login', 'logout'];
$rule = icct_nms_passive_fault_validate([['source' => 'syslog', 'name' => 'Cacti login failed',
    'program' => 'cacti-auth', 'message' => 'login failed', 'severity' => 'Warning',
    'severity_code' => 4, 'facility_code' => 4, 'enabled' => true]]);
if (count($rows) !== count($outcomes)) {
    throw new RuntimeException('Expected four distinct events after retry.');
}
foreach ($rows as $i => $row) {
    if ((int) $row['host_id'] !== (int) $hostId || $row['source_ip'] !== '127.0.0.1' ||
        !str_starts_with($row['message'], 'Cacti ' . $outcomes[$i] . ' for user ') ||
        !str_ends_with($row['message'], 'from 192.0.2.10') ||
        (icct_nms_passive_fault_match($row, $rule) !== null) !== ($i === 0)) {
        throw new RuntimeException('Incorrect event attribution or alarm match for ' . $outcomes[$i]);
    }
}
echo "Authentication outcomes, logout, retry, source attribution and failed-login rule passed.\n";
