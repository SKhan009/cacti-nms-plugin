<?php
/** Independent Inventory plugin lifecycle. Device ownership remains with Cacti core. */
function plugin_icct_nms_version()
{
    return [
        'name' => 'icct_nms',
        'version' => '1.1.0',
        'longname' => 'ICCT NMS Inventory',
        'author' => 'NMS Project',
        'homepage' => 'https://www.cacti.net/',
        'email' => 'admin@localhost',
        'compat' => '1.2.31'
    ];
}
function icct_nms_check_dependencies()
{
    return true;
}
function plugin_icct_nms_install()
{
    return plugin_icct_nms_check_config();
}
function plugin_icct_nms_upgrade()
{
    return plugin_icct_nms_check_config();
}
function plugin_icct_nms_check_config()
{
    require_once __DIR__ . '/includes/schema.php';
    icct_nms_schema_install();
    icct_nms_setup_registration();
    return true;
}
/** Registration must run from a Cacti-approved lifecycle setup entry point. */
function icct_nms_setup_registration()
{
    $enabled =
        (int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory=?', [
            'icct_nms'
        ]) === 1;
    api_plugin_register_hook(
        'icct_nms',
        'config_arrays',
        'icct_nms_navigation',
        'includes/navigation.php'
    );
    api_plugin_register_hook(
        'icct_nms',
        'poller_bottom',
        'icct_nms_poller_bottom',
        'includes/polling.php'
    );
    api_plugin_register_realm(
        'icct_nms',
        'mib_repository.php,topology_configuration.php,rack_placement.php,node_configuration.php,topology.php,inventory.php,device.php,protocol.php,export.php,diagnostics.php,wizard_save.php,wizard_templates.php,presets.php,ports.php',
        'View ICCT NMS Inventory',
        1
    );
    $enabled ? api_plugin_enable_hooks('icct_nms') : api_plugin_disable_hooks('icct_nms');
    return true;
}
/** Keep ICCT configuration and core devices for a later reinstall. */
function plugin_icct_nms_uninstall() {}
