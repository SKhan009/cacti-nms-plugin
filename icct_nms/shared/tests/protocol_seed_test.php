<?php

/** Installation/upgrade and per-format save regression without touching device tables. */
require __DIR__ . '/../../protocols/shared/services/protocol_seed.php';
$meta = [];
$writes = 0;
$transaction = null;
$failProfile = false;
function read_config_option($key)
{
    return 300;
}
function db_fetch_cell_prepared($sql, $args)
{
    global $meta;
    return str_contains($sql, 'meta_value') ? $meta[$args[0]] ?? false : 1;
}
function db_execute_prepared($sql, $args)
{
    global $meta, $writes, $transaction, $failProfile;
    if ($sql === 'START TRANSACTION') {
        $transaction = $meta;
        return true;
    }
    if ($sql === 'ROLLBACK') {
        $meta = $transaction;
        $transaction = null;
        return true;
    }
    if ($sql === 'COMMIT') {
        $transaction = null;
        return true;
    }
    if (!str_contains($sql, 'plugin_icct_nms_meta')) {
        throw new RuntimeException('Preset changed device tables.');
    }
    if ($failProfile && $args[0] === 'protocol_defaults') {
        return false;
    }
    $meta[$args[0]] = $args[1];
    $writes++;
    return true;
}
function icct_backend_category_execute($sql, $args)
{
    if (!db_execute_prepared($sql, $args)) {
        throw new RuntimeException('Fixture write failed.');
    }
}
function check($ok, $message)
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
icct_nms_seed_protocol_presets();
$defaults = icct_nms_protocol_presets();
$profiles = icct_nms_netflow_profiles();
check(count($defaults) === 8 && count($profiles) === 4, 'Fresh install missing presets.');
foreach ([5, 7, 9, 10] as $v) {
    $checked = icct_nms_netflow_validate($profiles[$v]);
    check((int) $checked['version'] === $v, 'Wrong profile version.');
}
check($profiles[10]['port'] === 4739 && $profiles[9]['port'] === 2055, 'Wrong starter port.');
check(!str_contains(json_encode($defaults), '10.0.2.15'), 'Shipped a development VM address.');
$before = $writes;
icct_nms_seed_protocol_presets();
check($writes === $before, 'Install not idempotent.');
foreach ([5 => 2056, 7 => 2057, 9 => 2059, 10 => 4740] as $v => $port) {
    icct_nms_save_protocol_preset(
        array_replace($profiles[$v], [
            'preset_protocol' => 'netflow',
            'port' => $port,
            'password' => 'never-copy',
        ]),
    );
}
$profiles = icct_nms_netflow_profiles();
foreach ([5 => 2056, 7 => 2057, 9 => 2059, 10 => 4740] as $v => $port) {
    check($profiles[$v]['port'] === $port, 'Version save overwrote another preset.');
}
check(!str_contains(json_encode($meta), 'never-copy'), 'Saved credential.');
check(
    (int) icct_nms_protocol_presets()['netflow']['version'] === 10,
    'New devices do not inherit selected preset.',
);
$metaBefore = $meta;
$failProfile = true;
try {
    icct_nms_save_protocol_preset(
        array_replace($profiles[9], ['preset_protocol' => 'netflow', 'port' => 2060]),
    );
    throw new LogicException('Expected failure.');
} catch (RuntimeException $e) {
}
check($meta === $metaBefore, 'Failed save partially committed.');
$failProfile = false;
// Upgrade an older deployment: retain the current custom values and fill missing formats.
$meta = [
    'protocol_defaults' => json_encode([
        'netflow' => array_replace(icct_nms_netflow_defaults(), [
            'collector' => '192.0.2.44',
            'port' => 22055,
        ]),
        'ssh' => ['port' => 2222, 'username' => 'custom', 'password' => 'obsolete-secret'],
    ]),
];
icct_nms_seed_protocol_presets();
check(icct_nms_netflow_profiles()[9]['port'] === 22055, 'Upgrade lost active profile.');
check(icct_nms_protocol_presets()['ssh']['port'] === 2222, 'Upgrade overwrote custom preset.');
check(!str_contains(json_encode($meta), 'obsolete-secret'), 'Unsafe legacy field retained.');
$before = $writes;
icct_nms_protocol_presets();
icct_nms_netflow_profiles();
check($writes === $before, 'Read request wrote presets.');
echo "PASS: fresh install, upgrade, four independent formats, rollback, secret exclusion, and read-only loading.\n";
