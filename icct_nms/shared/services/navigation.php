<?php
/**
 * Register the Inventory console menu while this presentation plugin is enabled.
 */

/**
 * Add the presentation entry point to the existing Cacti console navigation.
 */
function icct_nms_navigation()
{
    global $config, $menu, $menu_glyphs;
    if (!api_plugin_is_enabled('icct_nms')) {
        return;
    }
    // Cacti's native external-link flag bypasses its AJAX #main fragment loader.
    // The responsive Inventory is a complete document and opens in its own tab.
    $menu['ICCT NMS']['EXTERNAL::' . $config['url_path'] . 'plugins/icct_nms/topology.php'] = 'Dashboard';
    $url = $config['url_path'] . 'plugins/icct_nms/inventory.php';
    $menu['ICCT NMS']['EXTERNAL::' . $url] = 'Inventory';
    $menu['ICCT NMS']['EXTERNAL::' . $config['url_path'] . 'plugins/icct_nms/presets.php'] = 'Presets';
    $menu['ICCT NMS']['EXTERNAL::' . $config['url_path'] . 'plugins/icct_nms/topology_configuration.php'] = 'Topology Configuration';
    $menu['ICCT NMS']['EXTERNAL::' . $config['url_path'] . 'plugins/icct_nms/mib_repository.php'] = 'MIB Repository';
    $menu['ICCT NMS']['EXTERNAL::' . $config['url_path'] . 'plugins/icct_nms/syslog.php'] = 'Syslog Console';
    $menu_glyphs['ICCT NMS'] = 'fas fa-network-wired';
}
