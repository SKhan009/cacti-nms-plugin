<?php

/** Reuse the private broker fixture; secrets and host-key edits stay in its disposable database. */
require '/var/www/html/cacti/include/cli_check.php';
if (!str_starts_with((string) db_fetch_cell('SELECT DATABASE()'), 'icct_discovery_qa_')) {
    throw new RuntimeException('Disposable NTP SSH database required.');
}
api_plugin_load_realms();
require_once $config['base_path'] . '/lib/auth.php';
$_SESSION = ['sess_user_id' => 1];
require_once __DIR__ . '/../../../shared/services/bootstrap.php';
icct_nms_backend();
require_once __DIR__ . '/../../shared/services/protocol_preset_service.php';
$settings = array_replace(icct_nms_protocol_presets()['ntp'], [
    'method' => 'ssh',
]);
icct_nms_ntp_save(2, $settings);
function awaitNtp($status)
{
    $deadline = microtime(true) + 22;
    do {
        $state = icct_nms_ntp_current(2);
        if ($state['status'] === $status) {
            return $state;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException(
        'NTP SSH worker did not reach ' . $status . ': ' . json_encode($state),
    );
}
$current = awaitNtp('Synchronized');
if (
    $current['sample']['source_address'] !== '127.0.0.1' ||
    $current['sample']['jitter_ms'] === null ||
    $current['sample']['peer_delay_ms'] === null ||
    (int) db_fetch_cell('SELECT monitoring FROM plugin_icct_nms_ssh_devices WHERE host_id=2') !== 0
) {
    throw new RuntimeException('NTP SSH source incorrect or health monitoring changed.');
}
$key = db_fetch_cell('SELECT host_key FROM plugin_icct_nms_ssh_devices WHERE host_id=2');
try {
    // A nonmatching approved pin must stop collection before credential authentication.
    db_execute_prepared(
        'UPDATE plugin_icct_nms_ssh_devices SET host_key=?,revision=revision+1 WHERE host_id=2',
        ['ssh-rsa ' . base64_encode('wrong-private-fixture-pin')],
    );
    if (icct_nms_ntp_current(2)['sample'] !== null) {
        throw new RuntimeException('NTP reading survived a changed SSH identity.');
    }
    db_execute(
        'UPDATE plugin_icct_nms_ntp_monitor_state SET last_attempt=DATE_SUB(NOW(),INTERVAL 1 DAY) WHERE host_id=2',
    );
    $failed = awaitNtp('Unavailable');
    if ($failed['sample'] !== null || !str_contains($failed['error'], 'host key changed')) {
        throw new RuntimeException(
            'Changed host key did not produce an explicit NTP collection failure.',
        );
    }
} finally {
    db_execute_prepared(
        'UPDATE plugin_icct_nms_ssh_devices SET host_key=?,revision=revision+1 WHERE host_id=2',
        [$key],
    );
}
echo "PASS: scheduled NTP over real pinned SSH, actual chrony source, unchanged health-monitoring switch and changed-key failure without fallback.\n";
