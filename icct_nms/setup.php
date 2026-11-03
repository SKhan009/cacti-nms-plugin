<?php

/** Independent Inventory plugin lifecycle. Device ownership remains with Cacti core. */
function plugin_icct_nms_version()
{
    return [
        'name' => 'icct_nms',
        'version' => '1.4.6',
        'longname' => 'ICCT NMS Inventory',
        'author' => 'NMS Project',
        'homepage' => 'https://www.cacti.net/',
        'email' => 'admin@localhost',
        'compat' => '1.2.31',
    ];
}
function icct_nms_check_dependencies()
{
    if (version_compare(PHP_VERSION, '8.0.0', '<')) {
        return false;
    }
    $version = (string) db_fetch_cell('SELECT cacti FROM version LIMIT 1');
    foreach (['json', 'session', 'mbstring', 'openssl', 'pdo_mysql', 'xml'] as $extension) {
        if (!extension_loaded($extension)) {
            return false;
        }
    }
    return version_compare($version, '1.2.31', '>=');
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
    if (!icct_nms_check_dependencies()) {
        $message = 'ICCT NMS requires Cacti 1.2.31+, PHP 8.0+ and PHP json/session/mbstring/openssl/pdo_mysql/xml. Run installation/cli/setup.py plan to review OS requirements.';
        cacti_log($message, false, 'ICCT NMS');
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $message . PHP_EOL);
        } elseif (function_exists('raise_message')) {
            raise_message('icct_nms_requirements', $message, MESSAGE_LEVEL_ERROR);
        }
        return false;
    }
    require_once __DIR__ . '/shared/services/schema.php';
    icct_nms_schema_install();
    icct_nms_setup_registration();
    if (
        !db_execute_prepared('UPDATE plugin_config SET version=? WHERE directory=?', [
            plugin_icct_nms_version()['version'],
            'icct_nms',
        ])
    ) {
        throw new RuntimeException('ICCT NMS upgrade version could not be saved.');
    }
    return true;
}
/** Registration must run from a Cacti-approved lifecycle setup entry point. */
function icct_nms_setup_registration()
{
    $enabled =
        (int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory=?', [
            'icct_nms',
        ]) === 1;
    api_plugin_register_hook(
        'icct_nms',
        'config_arrays',
        'icct_nms_navigation',
        'shared/services/navigation.php',
    );
    api_plugin_register_hook(
        'icct_nms',
        'poller_bottom',
        'icct_nms_poller_bottom',
        'shared/services/polling.php',
    );
    api_plugin_register_realm(
        'icct_nms',
        'installation.php,collectors.php,discovery.php,netflow.php,alarms.php,mib_repository.php,topology_configuration.php,rack_placement.php,topology.php,inventory.php,device.php,protocol.php,export.php,diagnostics.php,wizard_save.php,wizard_templates.php,presets.php,ports.php,syslog.php',
        'View ICCT NMS Inventory',
        1,
    );
    $enabled ? api_plugin_enable_hooks('icct_nms') : api_plugin_disable_hooks('icct_nms');
    return true;
}
/** Keep ICCT configuration and core devices for a later reinstall. */
function plugin_icct_nms_uninstall()
{
}
