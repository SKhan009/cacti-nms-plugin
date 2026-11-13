<?php

require_once __DIR__ . '/installation/services/runtime.php';

/** Independent Inventory plugin lifecycle. Device ownership remains with Cacti core. */
function plugin_icct_nms_version()
{
    return [
        'name' => 'icct_nms',
        'version' => '1.4.20',
        'longname' => 'ICCT NMS Inventory',
        'author' => 'NMS Project',
        'homepage' => 'https://www.cacti.net/',
        'email' => 'admin@localhost',
        'compat' => '1.2.0',
    ];
}

function icct_nms_dependency_issues()
{
    $issues = icct_nms_php_requirement_issues();
    return $issues ?: icct_nms_cacti_requirement_issues();
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
    $issues = icct_nms_dependency_issues();
    if ($issues) {
        $message =
            'ICCT NMS installation stopped. ' .
            implode(' ', $issues) .
            ' See installation/README.md for offline package setup.';
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
        'installation.php,collectors.php,discovery.php,netflow.php,alarms.php,mib_repository.php,' .
            'topology_configuration.php,rack_placement.php,topology.php,inventory.php,device.php,' .
            'protocol.php,export.php,diagnostics.php,wizard_save.php,wizard_templates.php,presets.php,' .
            'ports.php,syslog.php,ssh_terminal.php,ssh_console.php',
        'View ICCT NMS Inventory',
        1,
    );
    $enabled ? api_plugin_enable_hooks('icct_nms') : api_plugin_disable_hooks('icct_nms');
    return true;
}
/** Keep ICCT configuration and core devices for a later reinstall. */
function plugin_icct_nms_uninstall() {}
