<?php

/** Receiver OID adapter and persistence policy; fixture values are not real locations. */
require __DIR__ . '/../services/location_service.php';
$observed = ['.1.2.3' => 1, '.1.2.4' => 12970000, '.1.2.5' => 77590000];
$writes = [];
function check($ok, $message)
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
function icct_backend_topology_integer($v, $min, $max, $name)
{
    if (filter_var($v, FILTER_VALIDATE_INT) === false || $v < $min || $v > $max) {
        throw new InvalidArgumentException($name);
    }
    return (int) $v;
}
function icct_backend_require_management($realm)
{
}
function icct_backend_require_device_access($id)
{
    if ($id !== 7) {
        throw new RuntimeException('Device access denied');
    }
}
function icct_backend_current_user_id()
{
    return 1;
}
function db_fetch_row_prepared($sql, $params)
{
    return ['id' => 7, 'hostname' => '192.0.2.7', 'poller_id' => 2];
}
function db_fetch_cell_prepared($sql, $params)
{
    return 2;
}
function icct_backend_protocol_enabled($id, $protocol)
{
    return true;
}
function icct_backend_nd_discovery_session($host)
{
    return new class () {
        public function close()
        {
        }
    };
}
function icct_backend_nd_snmp_scalar($session, $oid, $deadline)
{
    return ['value' => $GLOBALS['observed'][$oid]];
}
function icct_backend_category_execute($sql, $params)
{
    $GLOBALS['writes'][] = [$sql, $params];
}
$input = [
    'enabled' => 1,
    'poller_id' => 2,
    'transport' => 'snmp',
    'host_id' => 7,
    'latitude_oid' => '.1.2.4',
    'longitude_oid' => '.1.2.5',
    'fix_oid' => '.1.2.3',
    'fix_values' => '1',
    'scale' => 1000000,
    'interval' => 30,
    'max_age' => 120,
    'timeout' => 3,
];
$v = icct_nms_gnss_validate($input);
$fix = icct_nms_gnss_probe($v, 2);
check(
    $fix['latitude'] === 12.97 && $fix['longitude'] === 77.59,
    'Coordinate OID scale was not applied',
);
icct_nms_gnss_save($input);
$json = $writes[0][1][1];
check(
    !str_contains($json, 'password') && !str_contains($json, 'community'),
    'GNSS source copied credentials',
);
$observed['.1.2.3'] = 0;
try {
    icct_nms_gnss_probe($v, 2);
    throw new LogicException('Invalid receiver fix accepted');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'no valid fix'), 'Wrong fix failure');
}
$observed['.1.2.3'] = 1;
$observed['.1.2.4'] = 999000000;
try {
    icct_nms_gnss_probe($v, 2);
    throw new LogicException('Invalid latitude accepted');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'outside'), 'Wrong coordinate failure');
}
foreach (
    [
        ['interval' => 4],
        ['max_age' => 10, 'interval' => 30],
        ['transport' => 'tcp', 'address' => '', 'port' => 1],
        ['transport' => 'file', 'path' => '/etc/passwd'],
        ['transport' => 'serial', 'path' => '/tmp/device'],
        ['latitude_oid' => 'not-an-oid'],
    ] as $bad
) {
    try {
        icct_nms_gnss_validate(array_replace($input, $bad));
        throw new LogicException('Invalid GNSS source accepted');
    } catch (InvalidArgumentException $e) {
    }
}
echo "PASS: GNSS SNMP OIDs, divisor, valid/no-fix states, coordinate bounds, source validation and no copied secrets.\n";
