<?php
/** Cacti lifecycle only; ordinary page requests never create or migrate tables. */
function plugin_topology_version() {
    return parse_ini_file(__DIR__ . '/INFO', true)['info'];
}
/** Install the independent topology metadata and native navigation realms. */
function plugin_topology_install() { plugin_topology_check_config(); }
/** Apply the checked additive schema and register native Cacti hooks. */
function plugin_topology_check_config() {
    require_once(__DIR__ . '/includes/model.php');
    tp_schema();
    tp_setup_registration();
    tp_exec('UPDATE plugin_config SET version=? WHERE directory=?', array(plugin_topology_version()['version'], 'topology'));
    return true;
}
/** Register through a Cacti-supported setup entrypoint and preserve current lifecycle state. */
function tp_setup_registration() {
    $enabled=(int)db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory=?',array('topology'))===1;
    api_plugin_register_hook('topology', 'config_arrays', 'tp_navigation', 'setup.php');
    api_plugin_register_hook('topology', 'draw_navigation_text', 'tp_breadcrumbs', 'setup.php');
    api_plugin_register_realm('topology', 'topo_start.php', 'Topology guided setup', 1);
    api_plugin_register_realm('topology', 'topo_view.php', 'View topology inventory', 1);
    api_plugin_register_realm('topology', 'topo_setup.php', 'Manage topology device segments', 1);
    api_plugin_register_realm('topology', 'topo_sim.php', 'Manage topology simulator imports', 1);
    // Retain the old realm mapping for authenticated bookmark redirects.
    api_plugin_register_realm('topology', 'topo_links.php', 'Legacy topology page redirect', 1);
    api_plugin_register_realm('topology', 'topo_discovery.php', 'Configure and run topology discovery', 1);
    api_plugin_register_realm('topology', 'topo_canvas.php', 'View and configure topology', 1);
    api_plugin_register_realm('topology', 'topo_ports.php', 'Configure physical port profiles', 1);
    if($enabled) api_plugin_enable_hooks('topology'); else api_plugin_disable_hooks('topology');
}
/** Use the same explicit migration path for upgrades. */
function plugin_topology_upgrade() { return plugin_topology_check_config(); }
/** Preserve inventory, provenance and simulator files on uninstall; no core object is deleted. */
function plugin_topology_uninstall() {}
/** Extend only the native Console menu, without CSS, overlays or replacement headers. */
function tp_navigation() {
    global $menu, $menu_glyphs;
    if (!api_plugin_is_enabled('topology')) return;
    require_once(__DIR__.'/templates/submenu.php');
    $menu['Topology Configuration']=array();
    foreach(tp_sidebar_items() as $file=>$label)$menu['Topology Configuration']['plugins/topology/'.$file]=$label;
    $menu_glyphs['Topology Configuration'] = 'fas fa-network-wired';
}
/** Register breadcrumbs for the unique plugin page names. */
function tp_breadcrumbs($nav) {
    global $config;
    foreach (array('topo_start.php'=>'Topology Setup Guide', 'topo_canvas.php'=>'Topology View', 'topo_ports.php'=>'Physical Ports', 'topo_setup.php'=>'Topology Setup', 'topo_sim.php'=>'Topology Simulator', 'topo_discovery.php'=>'Topology Discovery') as $file=>$title) {
        $nav[$file . ':'] = array('title'=>$title, 'mapping'=>'index.php:', 'url'=>$config['url_path'].'plugins/topology/'.$file, 'level'=>1);
        if($file==='topo_sim.php') $nav[$file.':upload']=array('title'=>'Upload SNMP Record','mapping'=>'index.php:,topo_sim.php:','url'=>$config['url_path'].'plugins/topology/'.$file.'?action=upload','level'=>2);
    }
    return $nav;
}
