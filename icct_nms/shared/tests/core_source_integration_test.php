<?php
/** Run on the Cacti host: connection-local tables isolate all fixture edits. */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require '/var/www/html/cacti/include/cli_check.php';
api_plugin_load_realms();
require_once $config['base_path'] . '/lib/auth.php';
$_SESSION = ['sess_user_id' => 1];
require_once __DIR__ . '/../services/bootstrap.php';
require_once __DIR__ . '/../../inventory/services/inventory.php';
require_once __DIR__ . '/../../presets/services/device_type_service.php';
require_once __DIR__ . '/../../graphs/services/graph_service.php';
require_once __DIR__ . '/../../dashboard/map/services/map_service.php';
require_once __DIR__ . '/../../dashboard/widgets/services/dashboard_service.php';
require_once __DIR__ . '/../../dashboard/widgets/services/lnms_service.php';
icct_nms_backend();
function core_source_check($ok, $message)
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
    echo "PASS $message\n";
}
$tables = ['host', 'sites'];
foreach ($tables as $table) {
    foreach (
        [
            "CREATE TEMPORARY TABLE icct_source_copy LIKE `$table`",
            "INSERT INTO icct_source_copy SELECT * FROM `$table`",
            "CREATE TEMPORARY TABLE `$table` LIKE icct_source_copy",
            "INSERT INTO `$table` SELECT * FROM icct_source_copy",
            'DROP TEMPORARY TABLE icct_source_copy',
        ]
        as $sql
    ) {
        if (!db_execute($sql)) {
            throw new RuntimeException('Unable to isolate core tables');
        }
    }
}
try {
    $id = (int) db_fetch_cell("SELECT id FROM host WHERE deleted='' ORDER BY id LIMIT 1");
    $site = (int) db_fetch_cell('SELECT id FROM sites ORDER BY id LIMIT 1');
    if (!$id || !$site) {
        throw new RuntimeException('This test requires one native device and site.');
    }
    db_execute_prepared('UPDATE sites SET name=? WHERE id=?', ['Core source QA site', $site]);
    db_execute_prepared('UPDATE host SET description=?,site_id=?,poller_id=0 WHERE id=?', [
        'Core source QA device',
        $site,
        $id,
    ]);
    $inventory = array_column(icct_nms_inventory(), null, 'id');
    core_source_check(
        $inventory[$id]['description'] === 'Core source QA device' &&
            $inventory[$id]['site_name'] === 'Core source QA site' &&
            (int) $inventory[$id]['poller_id'] === 0,
        'Inventory immediately reads native device, site and collector edits',
    );
    $map = icct_nms_dashboard_scope(icct_nms_map_data(), $site);
    $devices = $map['unlocated'];
    foreach ($map['sites'] as $row) {
        $devices = array_merge($devices, $row['devices']);
    }
    $devices = array_column($devices, null, 'id');
    core_source_check(
        isset($devices[$id]) &&
            $devices[$id]['name'] === 'Core source QA device' &&
            (int) $devices[$id]['poller_id'] === 0,
        'Dashboard immediately reads native device identity and membership',
    );
    $scoped = icct_nms_dashboard_poller_scope($map, 1);
    $devices = $scoped['unlocated'];
    foreach ($scoped['sites'] as $row) {
        $devices = array_merge($devices, $row['devices']);
    }
    core_source_check(
        !in_array($id, array_map('intval', array_column($devices, 'id')), true),
        'Reassigned native device leaves the previous collector view',
    );
    foreach ([0, 12, 23] as $zoom) {
        db_execute_prepared('UPDATE sites SET zoom=? WHERE id=?', [$zoom, $site]);
        $lnms = icct_nms_lnms_data([], $site);
        core_source_check(
            $lnms['site_view']['zoom'] === $zoom,
            'Map payload immediately reads native sites.zoom=' . $zoom,
        );
    }
    db_execute_prepared("UPDATE host SET deleted='on' WHERE id=?", [$id]);
    core_source_check(
        !isset(array_column(icct_nms_inventory(), null, 'id')[$id]),
        'Native deleted device leaves inventory without a plugin sync job',
    );
} finally {
    foreach ($tables as $table) {
        db_execute("DROP TEMPORARY TABLE IF EXISTS `$table`");
    }
}
echo "PASS persistent Cacti data unchanged; temporary fixtures removed\n";
