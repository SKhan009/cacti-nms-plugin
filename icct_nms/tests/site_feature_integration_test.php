<?php
/** Read-only page render and service QA over a connection-local migrated schema. */
if(PHP_SAPI!=='cli')exit(1);
include '/var/www/html/cacti/include/cli_check.php';api_plugin_load_realms();require_once $config['base_path'].'/lib/auth.php';
if(db_fetch_cell("SHOW COLUMNS FROM plugin_icct_nms_racks LIKE 'node_id'"))foreach(['plugin_icct_nms_racks','plugin_icct_nms_rack_nodes','plugin_icct_nms_meta'] as $table){
 if(!db_execute("CREATE TEMPORARY TABLE qa_copy LIKE `$table`")||!db_execute("INSERT INTO qa_copy SELECT * FROM `$table`")||!db_execute("CREATE TEMPORARY TABLE `$table` LIKE qa_copy")||!db_execute("INSERT INTO `$table` SELECT * FROM qa_copy")||!db_execute('DROP TEMPORARY TABLE qa_copy'))throw new RuntimeException('Isolation failed');
}
require __DIR__ . '/../shared/services/schema.php';icct_nms_schema_sites_migration();
if(db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['icct_nms_schema_version'])!=='1.2.0')db_execute_prepared('UPDATE plugin_icct_nms_meta SET meta_value=? WHERE meta_key=?',['1.2.0','icct_nms_schema_version']);
if(session_status()===PHP_SESSION_NONE)session_start();
$_SESSION=['sess_user_id'=>1];
require_once __DIR__.'/../shared/services/bootstrap.php';
require_once __DIR__.'/../inventory/services/inventory.php';
require_once __DIR__.'/../presets/services/device_type_service.php';
require_once __DIR__.'/../graphs/services/graph_service.php';
require_once __DIR__.'/../dashboard/map/services/map_service.php';
require_once __DIR__.'/../dashboard/rack-view/services/rack_view_service.php';
require_once __DIR__.'/../dashboard/topology/services/topology_view_service.php';
require_once __DIR__.'/../dashboard/topology/services/topology_summary_service.php';
require_once __DIR__.'/../dashboard/topology/services/topology_link_service.php';
require_once __DIR__.'/../dashboard/topology/services/topology_mtr_service.php';
require_once __DIR__.'/../dashboard/widgets/services/dashboard_service.php';
require_once __DIR__.'/../presets/services/rack_preset_service.php';
require_once __DIR__.'/../shared/services/forms.php';
icct_nms_backend();
$mapData=icct_nms_map_data();$dashboardSites=icct_nms_dashboard_sites($mapData);$dashboardSiteId=0;$dashboardPreferences=icct_nms_dashboard_preferences();$dashboardReadings=icct_nms_dashboard_readings($mapData);$dashboardReadings['center']=icct_nms_dashboard_server();$dashboardView='rack';$mapConfigured=false;
ob_start();include __DIR__ . '/../dashboard/map/templates/map.php';$html=ob_get_clean();
if(strpos($html,'id="dashboardSite"')!==false||strpos($html,'id="rackViewSite"')!==false||preg_match('/Node Configuration|Select node|>Node<|dashboardNode|rackViewNode/',$html))throw new RuntimeException('Removed feature still rendered');
$before=db_fetch_assoc('SELECT host_id,rack_id,start_unit,unit_height FROM plugin_icct_nms_rack_devices ORDER BY host_id');
$choices=icct_nms_device_rack_choices();$rackData=icct_nms_rack_view_data();
foreach($rackData['racks'] as $rack)if(!isset($rack['site_id'])||isset($rack['node_id']))throw new RuntimeException('Rack ownership not migrated');
if($before!==db_fetch_assoc('SELECT host_id,rack_id,start_unit,unit_height FROM plugin_icct_nms_rack_devices ORDER BY host_id'))throw new RuntimeException('Placement changed');
echo 'PASS: dashboard/map/rack template renders with native site controls; '.count($choices).' rack choices and '.count($before).' placements retained.',"\n";
