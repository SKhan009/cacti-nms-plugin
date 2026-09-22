<?php
/*
 +-------------------------------------------------------------------------+
 | ICCT Rack Topology Plugin for Cacti                                     |
 | SPDX-License-Identifier: GPL-2.0-or-later                               |
 +-------------------------------------------------------------------------+
 */

function plugin_icct_rack_install($upgrade = false) {
    global $config;

    $cacti_version = defined('CACTI_VERSION') ? CACTI_VERSION : ($config['cacti_version'] ?? '0.0.0');
    /* FIX 2026-09-15: Match INFO and deployment guidance; require patched Cacti 1.2.31+. */
    if (version_compare($cacti_version, '1.2.31', '<')) {
        return false;
    }

    $plugin = 'icct_rack';

    /*
     * Cacti navigation integration.
     * config_arrays adds entries to the Console left navigation.
     * top_header_tabs/top_graph_header_tabs add a Rack tab to both header modes.
     * draw_navigation_text supplies breadcrumbs while inside the plugin.
     */
    api_plugin_register_hook($plugin, 'config_arrays', 'icct_rack_config_arrays', 'setup.php');
    api_plugin_register_hook($plugin, 'draw_navigation_text', 'icct_rack_draw_navigation_text', 'setup.php');
    api_plugin_register_hook($plugin, 'top_header_tabs', 'icct_rack_show_tab', 'setup.php');
    api_plugin_register_hook($plugin, 'top_graph_header_tabs', 'icct_rack_show_tab', 'setup.php');

    /* Cacti realm based permissions. */
    api_plugin_register_realm($plugin, 'icct_rack.php,ajax.php', 'View ICCT Rack Topology', 1);
    api_plugin_register_realm($plugin, 'icct_rack_admin.php', 'Manage ICCT Rack Topology', 1);

    include_once($config['base_path'] . '/plugins/icct_rack/lib/database.php');
    icct_rack_setup_database();

    if ($upgrade && api_plugin_is_enabled($plugin)) {
        api_plugin_enable_hooks($plugin);
    }

    return true;
}

function plugin_icct_rack_uninstall() {
    /*
     * Intentionally preserve plugin tables so an accidental uninstall does
     * not destroy rack placement/audit data. See database/uninstall.sql for
     * an explicit destructive removal script.
     */
    db_execute("DELETE FROM settings WHERE name IN ('icct_rack_refresh_seconds', 'icct_rack_schema_version')");
    return true;
}

function plugin_icct_rack_check_config() {
    global $config;

    include_once($config['base_path'] . '/plugins/icct_rack/lib/database.php');
    icct_rack_setup_database();

    return icct_rack_tables_ready();
}

function plugin_icct_rack_upgrade() {
    global $config;

    /* Re-register hooks/realms when an updated plugin package is installed. */
    plugin_icct_rack_install(true);

    include_once($config['base_path'] . '/plugins/icct_rack/lib/database.php');
    icct_rack_setup_database();

    $info = plugin_icct_rack_version();
    if (!empty($info['version'])) {
        db_execute_prepared(
            'UPDATE plugin_config SET version = ? WHERE directory = ?',
            [$info['version'], 'icct_rack']
        );
    }

    return true;
}

function plugin_icct_rack_version() {
    global $config;

    $info = parse_ini_file($config['base_path'] . '/plugins/icct_rack/INFO', true);
    return $info['info'];
}

/**
 * Add visible entries to Cacti's Console left navigation.
 * Cacti filters these pages through its realm authorization system.
 */
function icct_rack_config_arrays() {
    global $menu, $menu_glyphs;

    if (function_exists('api_plugin_is_enabled') && !api_plugin_is_enabled('icct_rack')) {
        return;
    }

    /* FIX 2026-09-15: Give Rack Topology its own visible Cacti sidebar section. */
    $menu[__('Rack Topology')]['plugins/icct_rack/icct_rack.php'] = __('Rack View');
    $menu[__('Rack Topology')]['plugins/icct_rack/icct_rack_admin.php'] = __('Manage racks');
    $menu_glyphs[__('Rack Topology')] = 'fas fa-server';

    /* Keep the built-in admin role usable immediately after installation. */
    if (function_exists('auth_augment_roles')) {
        auth_augment_roles(__('General Administration'), ['icct_rack.php', 'icct_rack_admin.php']);
    }
}

/**
 * Breadcrumb definitions for plugin pages.
 */
function icct_rack_draw_navigation_text($nav) {
    $nav['icct_rack.php:'] = [
        'title'   => __('Rack Topology'),
        'mapping' => 'index.php:',
        'url'     => 'icct_rack.php',
        'level'   => '1',
    ];

    $nav['icct_rack_admin.php:'] = [
        'title'   => __('Rack Topology Management'),
        'mapping' => 'index.php:,icct_rack.php:',
        'url'     => 'icct_rack_admin.php',
        'level'   => '2',
    ];

    return $nav;
}

/**
 * Add a Rack tab in both the Console and Graphs header.
 * Use an image because Cacti's top tab area is image-oriented in 1.2.x.
 */
function icct_rack_show_tab() {
    global $config;

    if (!function_exists('api_user_realm_auth') || !api_user_realm_auth('icct_rack.php')) {
        return;
    }

    $active = in_array(get_current_page(), ['icct_rack.php', 'icct_rack_admin.php'], true);
    $image  = $active ? 'tab_icct_rack_down.gif' : 'tab_icct_rack.gif';
    $url    = $config['url_path'] . 'plugins/icct_rack/icct_rack.php';
    $src    = $config['url_path'] . 'plugins/icct_rack/images/' . $image;

    print '<a href="' . html_escape($url) . '"><img src="' . html_escape($src) . '" alt="' . __esc('Rack Topology') . '" title="' . __esc('ICCT Rack Topology') . '"></a>';
}
