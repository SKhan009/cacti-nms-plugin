<?php

/** Real protocol transports against private QA agents; refuse the live Cacti database. */
if (PHP_SAPI !== 'cli' || !getenv('ICCT_DISCOVERY_QA_DIR')) {
    fwrite(STDERR, "Run through the isolated discovery QA harness.\n");
    exit(2);
}
require '/var/www/html/cacti/include/cli_check.php';
if (!str_starts_with((string) db_fetch_cell('SELECT DATABASE()'), 'icct_discovery_qa_')) {
    throw new RuntimeException('Protocol matrix requires a disposable QA database.');
}
api_plugin_load_realms();
require_once $config['base_path'] . '/lib/auth.php';
require_once __DIR__ . '/../../../shared/services/bootstrap.php';
icct_nms_backend();
require_once __DIR__ . '/../services/discovery_workflow.php';
require_once __DIR__ . '/../services/discovery_collector.php';
require_once __DIR__ . '/../../../protocols/ssh/vendor/autoload.php';
require_once __DIR__ . '/../../../protocols/ssh/strict_ssh.php';
$_SESSION = ['sess_user_id' => 1];
$checks = [];
function protocolCheck($label, $passed)
{
    $GLOBALS['checks'][] = ['name' => $label, 'passed' => (bool) $passed];
}
function protocolCredential($label, $version, $values = [])
{
    $original = db_fetch_row('SELECT * FROM automation_snmp_items ORDER BY id LIMIT 1');
    if (!$original) {
        throw new RuntimeException('Native SNMP credential fixture is unavailable.');
    }
    $set = sql_save(['id' => 0, 'name' => 'Isolated QA ' . $label], 'automation_snmp');
    if (!$set) {
        throw new RuntimeException('Native credential-set creation failed.');
    }
    $item = array_replace($original, [
        'id' => 0,
        'snmp_id' => $set,
        'sequence' => 1,
        'snmp_version' => $version,
        'snmp_port' => 161,
        'snmp_timeout' => 200,
        'snmp_retries' => 0,
        'snmp_community' => $version === 3 ? '' : 'qa-discovery',
        'snmp_username' => '',
        'snmp_password' => '',
        'snmp_auth_protocol' => '[None]',
        'snmp_priv_passphrase' => '',
        'snmp_priv_protocol' => '[None]',
        'snmp_context' => '',
        'snmp_engine_id' => '',
    ], $values);
    if (!sql_save($item, 'automation_snmp_items')) {
        throw new RuntimeException('Native credential item creation failed.');
    }
    return (int) $set;
}
$profiles = [
    'SNMP v1' => [1, []],
    'SNMP v2c' => [2, []],
    'SNMP v3 noAuthNoPriv' => [3, ['snmp_username' => 'qaNoAuth']],
    'SNMP v3 authNoPriv' => [3, [
        'snmp_username' => 'qaAuth',
        'snmp_password' => 'qaAuthPass1234',
        'snmp_auth_protocol' => 'SHA',
    ]],
    'SNMP v3 authPriv' => [3, [
        'snmp_username' => 'qaPrivate',
        'snmp_password' => 'qaAuthPass1234',
        'snmp_auth_protocol' => 'SHA',
        'snmp_priv_passphrase' => 'qaPrivPass1234',
        'snmp_priv_protocol' => 'AES',
    ]],
];
$sets = [];
foreach ($profiles as $label => [$version, $values]) {
    $sets[$label] = protocolCredential($label, $version, $values);
    $probe = icct_nms_scan_snmp('127.0.0.1', ['snmp_id' => $sets[$label]]);
    protocolCheck(
        $label . ' through native Cacti credential discovery',
        $probe['ok'] &&
            (int) $probe['device']['snmp_version'] === $version &&
            $probe['device']['snmp_sysName'] === 'ICCT-Isolated-QA-Agent',
    );
}
$probe = icct_nms_scan_snmp('::1', ['snmp_id' => $sets['SNMP v2c']]);
protocolCheck('IPv6 SNMP target through native Cacti', $probe['ok']);
$bad = protocolCredential('invalid community', 2, ['snmp_community' => 'wrong-community']);
protocolCheck('Wrong SNMP community never adopts another credential set', !icct_nms_scan_snmp('127.0.0.1', ['snmp_id' => $bad])['ok']);
$bad = protocolCredential('invalid v3 password', 3, [
    'snmp_username' => 'qaPrivate',
    'snmp_password' => 'wrong-auth-password',
    'snmp_auth_protocol' => 'SHA',
    'snmp_priv_passphrase' => 'qaPrivPass1234',
    'snmp_priv_protocol' => 'AES',
]);
protocolCheck('Wrong SNMPv3 password rejected after an earlier successful probe', !icct_nms_scan_snmp('127.0.0.1', ['snmp_id' => $bad])['ok']);
$badPrivacy = protocolCredential('invalid v3 privacy', 3, [
    'snmp_username' => 'qaPrivate',
    'snmp_password' => 'qaAuthPass1234',
    'snmp_auth_protocol' => 'SHA',
    'snmp_priv_passphrase' => 'wrong-privacy-password',
    'snmp_priv_protocol' => 'AES',
]);
protocolCheck('Wrong SNMPv3 privacy password is rejected', !icct_nms_scan_snmp('127.0.0.1', ['snmp_id' => $badPrivacy])['ok']);
$mixed = protocolCredential('ordered v3 credentials', 3, [
    'snmp_username' => 'qaPrivate',
    'snmp_password' => 'wrong-auth-password',
    'snmp_auth_protocol' => 'SHA',
    'snmp_priv_passphrase' => 'qaPrivPass1234',
    'snmp_priv_protocol' => 'AES',
]);
$correct = db_fetch_row_prepared('SELECT * FROM automation_snmp_items WHERE snmp_id=?', [$sets['SNMP v3 authPriv']]);
$correct['id'] = 0;
$correct['snmp_id'] = $mixed;
$correct['sequence'] = 2;
$correctId = sql_save($correct, 'automation_snmp_items');
$ordered = icct_nms_scan_snmp('127.0.0.1', ['snmp_id' => $mixed]);
protocolCheck('Only a successful explicitly configured credential is selected', $ordered['ok'] && (int) $ordered['credential']['id'] === (int) $correctId);
protocolCheck('Unresponsive SNMP target remains unavailable', !icct_nms_scan_snmp('127.0.0.2', ['snmp_id' => $sets['SNMP v2c']])['ok']);
try {
    icct_nms_scan_snmp('127.0.0.1', ['snmp_id' => $sets['SNMP v2c']], microtime(true) - 1);
    protocolCheck('Expired discovery deadline fails without another credential', false);
} catch (RuntimeException $error) {
    protocolCheck('Expired discovery deadline fails without another credential', true);
}
// A fresh process distinguishes authentication failure from process-local native session reuse.
$childCode = <<<'PROBE'
require '/var/www/html/cacti/include/cli_check.php';
if (!str_starts_with((string) db_fetch_cell('SELECT DATABASE()'), 'icct_discovery_qa_')) exit(2);
require_once '/var/www/html/cacti/plugins/icct_nms/shared/services/bootstrap.php';
icct_nms_backend();
require_once '/var/www/html/cacti/plugins/icct_nms/inventory/discovery/services/discovery_collector.php';
$probe = icct_nms_scan_snmp('127.0.0.1', ['snmp_id' => (int) $argv[1]]);
echo json_encode(['ok' => $probe['ok']]);
PROBE;
$pipes = [];
$child = proc_open([PHP_BINARY, '-r', $childCode, (string) $bad], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($child)) {
    throw new RuntimeException('Cannot start isolated credential verification.');
}
$childOutput = stream_get_contents($pipes[1]);
stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$childExit = proc_close($child);
protocolCheck('Fresh native process rejects the same wrong SNMPv3 password', $childExit === 0 && json_decode($childOutput, true, 32, JSON_THROW_ON_ERROR)['ok'] === false);

$before = (int) db_fetch_cell('SELECT COUNT(*) FROM host');
$jobId = icct_nms_scan_queue([
    'name' => 'Isolated multi-protocol discovery QA',
    'ranges' => "127.0.0.1\n::1",
    'methods' => ['icmp', 'snmp', 'ports', 'cdp', 'lldp'],
    'ports' => '22,65534',
    'poller_id' => (int) $config['poller_id'],
    'site_id' => 0,
    'snmp_id' => $sets['SNMP v2c'],
    'timeout_ms' => 300,
    'run_limit' => 60,
]);
icct_nms_scan_work((int) $config['poller_id']);
$rows = db_fetch_assoc_prepared('SELECT * FROM plugin_icct_nms_scan_results WHERE job_id=? ORDER BY id', [$jobId]);
protocolCheck('IPv4 and IPv6 job results recorded', count($rows) === 2);
foreach ($rows as $row) {
    $evidence = json_decode($row['evidence_json'], true, 512, JSON_THROW_ON_ERROR);
    $label = $row['address'];
    protocolCheck($label . ' actual ICMP and SNMP response', $evidence['icmp'] && $evidence['snmp']);
    protocolCheck($label . ' actual open and closed TCP ports', $evidence['ports']['open'] === [22] && $evidence['ports']['closed'] === 1 && $evidence['ports']['errors'] === 0 && $evidence['ports']['timed_out'] === 0);
    protocolCheck($label . ' no credentials in discovery evidence', !preg_match('/qa-discovery|qaAuthPass|qaPrivPass/', $row['evidence_json']));
    foreach (['cdp', 'lldp'] as $method) {
        protocolCheck($label . ' unsupported ' . strtoupper($method) . ' is explicit', empty($evidence['neighbors'][$method]) && !empty($evidence['method_errors'][$method]));
    }
}
$displayRows = icct_nms_scan_rows(array_column(db_fetch_assoc('SELECT * FROM host'), null, 'id'));
foreach ($rows as $row) {
    $display = array_values(array_filter($displayRows, static function ($display) use ($row) {
        return (int) $display['id'] === (int) $row['id'];
    }));
    protocolCheck(
        $row['address'] . ' CDP and LLDP warnings reach visible result details',
        count($display) === 1 && str_contains($display[0]['detail'], 'CDP:') && str_contains($display[0]['detail'], 'LLDP:')
    );
}
protocolCheck('Discovery alone never creates native devices', (int) db_fetch_cell('SELECT COUNT(*) FROM host') === $before);
foreach (['ssh', 'serial', 'syslog', 'netflow', 'ntp', 'tacacs'] as $method) {
    try {
        icct_nms_scan_validate(['name' => 'Invalid method QA', 'ranges' => '127.0.0.1', 'methods' => [$method]]);
        protocolCheck($method . ' cannot be silently treated as a discovery probe', false);
    } catch (InvalidArgumentException $error) {
        protocolCheck($method . ' cannot be silently treated as a discovery probe', true);
    }
}
$fixture = getenv('ICCT_DISCOVERY_QA_DIR') . '/fixtures/';
$key = phpseclib3\Crypt\PublicKeyLoader::loadPrivateKey(file_get_contents($fixture . 'client_key'));
$ssh = new NmsStrictSsh('127.0.0.1', 22, 3);
protocolCheck('Strict SSH private-key authentication over a real transport', $ssh->login('root', $key) && trim($ssh->exec('uname -s')) === 'Linux');
$ssh->disconnect();
$ssh = new NmsStrictSsh('127.0.0.1', 22, 3);
protocolCheck('Strict SSH password authentication over a real transport', $ssh->login('root', 'IsolatedQaPassword2026') && trim($ssh->exec('uname -s')) === 'Linux');
$ssh->disconnect();
$ssh = new NmsStrictSsh('127.0.0.1', 22, 3);
protocolCheck('Incorrect SSH password fails explicitly', !$ssh->login('root', 'wrong-password'));
$ssh->disconnect();
try {
    (new NmsStrictSsh('127.0.0.1', 22, 3))->login('root');
    protocolCheck('SSH requires one explicit credential', false);
} catch (RuntimeException $error) {
    protocolCheck('SSH requires one explicit credential', str_contains($error->getMessage(), 'Exactly one'));
}
echo json_encode(['checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit(count(array_filter($checks, fn ($check) => !$check['passed'])) ? 1 : 0);
