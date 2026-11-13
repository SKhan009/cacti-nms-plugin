<?php

require __DIR__ . '/../../protocols/shared/services/protocol_service.php';
require __DIR__ . '/../../protocols/shared/services/backend/discovery.php';
$assignment = [];
$preset = [];
$enabledProtocols = ['snmp' => true, 'lldp' => true, 'cdp' => true];
function icct_backend_protocol_enabled($id, $protocol)
{
    return $GLOBALS['enabledProtocols'][$protocol] ?? false;
}
function db_fetch_row_prepared($sql, $args)
{
    return $GLOBALS['assignment'];
}
function read_config_option($key)
{
    return 300;
}
function icct_backend_current_user_id()
{
    return 1;
}
function icct_backend_require_management() {}
function icct_backend_require_device_access($id) {}
function db_fetch_cell_prepared($sql, $args)
{
    return 2;
}
function icct_backend_category_execute($sql, $args)
{
    if (str_contains($sql, 'INSERT INTO plugin_icct_nms_discovery_devices')) {
        $GLOBALS['assignment'] = $GLOBALS['preset'] + [
            'protocol' => '',
            'preset_id' => $args[1],
            'methods' => $args[2],
            'collection_enabled' => $args[3],
        ];
        return;
    }
    $GLOBALS['preset'] = [
        'protocol' => $args[1],
        'enabled' => $args[2],
        'interval_seconds' => $args[3],
        'stale_seconds' => $args[4],
        'refresh_seconds' => $args[5],
    ];
}
function db_fetch_cell($sql)
{
    return 1;
}
$timings = ['interval_seconds' => 300, 'stale_seconds' => 900, 'refresh_seconds' => 30];
function checkMethods($expected)
{
    if (icct_backend_nd_host_methods($GLOBALS['assignment'], false) !== $expected) {
        throw new RuntimeException('Unexpected saved methods');
    }
}
icct_nms_save_discovery(2, $timings + ['discovery_protocol' => 'lldp']);
checkMethods(['lldp']);
icct_nms_save_discovery(2, $timings + ['discovery_protocol' => 'cdp']);
checkMethods(['lldp', 'cdp']);
icct_nms_save_discovery(
    2,
    $timings + [
        'discovery_policy' => 1,
        'discovery_methods_present' => 1,
        'methods' => ['arp', 'fdb'],
        'collection_enabled' => 1,
    ],
);
checkMethods(['lldp', 'cdp', 'arp', 'fdb']);
icct_nms_save_discovery(
    2,
    $timings + ['discovery_policy' => 1, 'discovery_methods_present' => 1, 'methods' => ['fdb']],
);
checkMethods(['lldp', 'cdp', 'fdb']);
if ($assignment['collection_enabled']) {
    throw new RuntimeException('Collection was not disabled');
}
icct_nms_save_discovery(2, $timings + ['discovery_protocol' => 'lldp']);
checkMethods(['lldp', 'cdp', 'fdb']);
if ($assignment['collection_enabled']) {
    throw new RuntimeException('Protocol save re-enabled collection');
}
$before = $assignment;
try {
    icct_nms_save_discovery(
        2,
        $timings + [
            'discovery_policy' => 1,
            'discovery_methods_present' => 1,
            'methods' => ['lldp'],
        ],
    );
    throw new RuntimeException('Invalid observation accepted');
} catch (InvalidArgumentException $e) {
}
if ($assignment !== $before) {
    throw new RuntimeException('Invalid input changed assignment');
}
$assignment = [];
$preset = [];
icct_nms_save_discovery(2, $timings + ['discovery_policy' => 1, 'discovery_methods_present' => 1]);
icct_nms_save_discovery(2, $timings + ['discovery_protocol' => 'lldp']);
checkMethods(['lldp']);
if (!$assignment['collection_enabled']) {
    throw new RuntimeException('First neighbour protocol did not enable collection');
}
$assignment = [];
$preset = [];
icct_nms_save_discovery(
    2,
    $timings + [
        'discovery_policy' => 1,
        'discovery_methods_present' => 1,
        'methods' => ['arp', 'fdb'],
        'collection_enabled' => 1,
    ],
);
checkMethods(['arp', 'fdb']);
$enabledProtocols = ['snmp' => true, 'lldp' => false, 'cdp' => false];
if (icct_backend_nd_host_methods($assignment) !== ['arp', 'fdb']) {
    throw new RuntimeException('SNMP observations incorrectly depend on LLDP/CDP');
}
$enabledProtocols['snmp'] = false;
if (icct_backend_nd_host_methods($assignment) !== []) {
    throw new RuntimeException('Observations collected while SNMP disabled');
}
$enabledProtocols['snmp'] = true;
icct_nms_save_discovery(
    2,
    $timings + [
        'discovery_policy' => 1,
        'discovery_methods_present' => 1,
        'methods' => ['fdb'],
        'collection_enabled' => 1,
    ],
);
if (icct_backend_nd_host_methods($assignment) !== ['fdb']) {
    throw new RuntimeException('Cleared IP neighbour selection remains active');
}
echo "Device protocol selections, shared observations and disabled collection persistence passed.\n";

require __DIR__ . '/../../dashboard/topology/services/topology_configuration_service.php';
function db_fetch_assoc_prepared($sql, $args)
{
    return $GLOBALS['summarySnapshots'] ?? [];
}
$assignment += ['host_id' => 2, 'disabled' => ''];
$assignment['enabled'] = 1;
$assignment['collection_enabled'] = 1;
$assignment['stale_seconds'] = 900;
$summarySnapshots = [
    [
        'protocol' => 'fdb',
        'status' => 'success',
        'config_hash' => icct_backend_nd_hash($assignment),
        'succeeded_at' => date('Y-m-d H:i:s'),
        'data_json' => json_encode([
            'endpoints' => [['mac' => '00:11:22:33:44:55'], ['present' => false]],
        ]),
    ],
    [
        'protocol' => 'arp',
        'status' => 'success',
        'config_hash' => icct_backend_nd_hash($assignment),
        'succeeded_at' => date('Y-m-d H:i:s'),
        'data_json' => json_encode(['endpoints' => [['ip' => '2001:db8::1']]]),
    ],
];
$summary = icct_nms_device_discovery_summary(2, $assignment);
if (
    count($summary) !== 1 ||
    $summary[0]['method'] !== 'fdb' ||
    $summary[0]['count'] !== 1 ||
    $summary[0]['status'] !== 'Current'
) {
    throw new RuntimeException(
        'Diagnostic discovery summary includes unselected/absent observations',
    );
}
$summarySnapshots[0]['config_hash'] = 'old';
if (icct_nms_device_discovery_summary(2, $assignment)[0]['count'] !== null) {
    throw new RuntimeException('Old topology configuration shown as current');
}
$enabledProtocols['snmp'] = false;
if (icct_nms_device_discovery_summary(2, $assignment)[0]['status'] !== 'Disabled') {
    throw new RuntimeException('Disabled SNMP reported as active discovery');
}
echo "Diagnostic/topology summaries share selected protocols, disabled state, hashes and current evidence.\n";

$enabledProtocols['snmp'] = true;
$summarySnapshots[0]['config_hash'] = icct_backend_nd_hash($assignment);
foreach (['queued' => 'Queued', 'running' => 'Running'] as $state => $label) {
    $summarySnapshots[0]['status'] = $state;
    $row = icct_nms_device_discovery_summary(2, $assignment)[0];
    if ($row['status'] !== $label || $row['count'] !== null) {
        throw new RuntimeException('Pending discovery misreported as current or stale');
    }
}
$summarySnapshots[0]['config_hash'] = 'old';
if (icct_nms_device_discovery_summary(2, $assignment)[0]['status'] !== 'Stale') {
    throw new RuntimeException('Old pending configuration reported as active');
}
echo "Queued/running discovery status synchronized; previous configuration excluded.\n";

require __DIR__ . '/../../ports/services/backend/ports.php';

function db_fetch_assoc($sql)
{
    return [$GLOBALS['assignment']];
}

$assignment['id'] = 2;
$assignment['protocol'] = 'arp,fdb';
$assignment['methods'] = 'arp,fdb';
$mac = '00:11:22:33:44:55';
$secondMac = '00:11:22:33:44:66';
$fdb = ['ifindex' => 7, 'status' => 3, 'mac' => $mac, 'vlans' => [10]];
$arp = ['ifindex' => 7, 'state' => 'observed', 'mac' => $mac, 'ip' => '192.0.2.7'];
$makeSnapshot = static fn($method, $rows) => [
    'protocol' => $method,
    'status' => 'success',
    'config_hash' => icct_backend_nd_hash($assignment),
    'succeeded_at' => date('Y-m-d H:i:s'),
    'data_json' => json_encode(['endpoints' => $rows]),
];
$summarySnapshots = [
    $makeSnapshot('fdb', [
        $fdb,
        array_replace($fdb, ['vlans' => [10, 20]]),
        array_replace($fdb, ['mac' => $secondMac]),
        array_replace($fdb, ['ifindex' => 0]),
        array_replace($fdb, ['ifindex' => 9, 'status' => 4]),
    ]),
    $makeSnapshot('arp', [
        $arp,
        $arp,
        array_replace($arp, ['ifindex' => 8, 'ip' => '192.0.2.8']),
        array_replace($arp, ['ip' => '192.0.2.9', 'neighbor_state' => 'Stale neighbour cache']),
    ]),
];
$connections = icct_nms_port_connections(2, [['index' => 7, 'name' => 'port7']], true);
$learned = $connections[7]['endpoints'];
if (
    array_keys($connections) !== [7] ||
    count($learned) !== 2 ||
    $learned[$mac]['ips'] !== ['192.0.2.7'] ||
    array_values($learned[$mac]['vlans']) !== [10, 20] ||
    $learned[$secondMac]['ips'] !== [] ||
    isset($connections[7]['addresses']) ||
    isset($connections[7]['names'])
) {
    throw new RuntimeException(
        'Learned endpoints must be unique, scoped to the observed interface and distinct from LLDP/CDP',
    );
}
$observations = icct_nms_topology_discovery_rows([['id' => 2, 'description' => 'Reporter']]);
$arpObservations = array_values(
    array_filter($observations, static fn($row) => $row['protocol'] === 'ARP'),
);
if ($arpObservations[0]['mac'] !== $mac || $arpObservations[0]['address'] !== $arp['ip']) {
    throw new RuntimeException('Discovery rows must expose the collected ARP MAC and IP together');
}
$summarySnapshots[1]['succeeded_at'] = date('Y-m-d H:i:s', time() - 901);
$connections = icct_nms_port_connections(2, [], true);
if ($connections[7]['endpoints'][$mac]['ips'] !== []) {
    throw new RuntimeException('An expired ARP snapshot must not supply IP matches');
}
$summarySnapshots[0]['config_hash'] = 'old';
if (icct_nms_port_connections(2, [], true) !== []) {
    throw new RuntimeException(
        'FDB from an old SNMP configuration must not supply learned endpoints',
    );
}
echo "Discovery MAC evidence, same-interface IP matches, deduplication and stale-reading exclusion passed.\n";
