<?php
/** Real SQL round trips use connection-local temporary tables only. */
if(PHP_SAPI!=='cli')exit(1);
include '/var/www/html/cacti/include/cli_check.php';
require __DIR__.'/../services/schema.php';
require __DIR__.'/../../presets/services/rack_preset_service.php';
require __DIR__.'/../../dashboard/rack-view/services/rack_view_service.php';
require __DIR__.'/../../dashboard/topology/services/backend/topology_config.php';
function icct_backend_require_management($realm){}
function icct_backend_require_device_access($id){}
function icct_backend_current_user_id(){return 1;}
function icct_backend_category_execute($sql,$args=[]){if(!db_execute_prepared($sql,$args))throw new RuntimeException('SQL failed');}
function icct_backend_classification_text($value,$limit){return trim((string)$value);}
function checkRack($yes,$message){if(!$yes)throw new RuntimeException($message);}
foreach(['plugin_icct_nms_meta','plugin_icct_nms_racks','plugin_icct_nms_rack_devices','host'] as $table){
 checkRack(db_execute('CREATE TEMPORARY TABLE qa_copy LIKE '.$table),'Copy failed');
 checkRack(db_execute('CREATE TEMPORARY TABLE '.$table.' LIKE qa_copy'),'Isolation failed');
 db_execute('DROP TEMPORARY TABLE qa_copy');
}
$key='0123456789abcdef';$empty='fedcba9876543210';
$profiles=[$key=>['name'=>'Rack 1','rack_count'=>6,'unit_count'=>12],$empty=>['name'=>'Rack 2','rack_count'=>1,'unit_count'=>24]];
db_execute_prepared('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',['rack_profiles',json_encode($profiles)]);
db_execute("INSERT INTO plugin_icct_nms_racks(id,site_id,profile_id,rack_number,name,unit_count,updated_by,updated_at) VALUES(21,3,'$key',1,'Rack 1',12,1,NOW()),(22,3,'$key',2,'Rack 1',12,1,NOW()),(23,4,'$key',1,'Rack 1',12,1,NOW())");
db_execute("INSERT INTO host(id,site_id,hostname,description,deleted) VALUES(1001,3,'a','A',''),(1002,4,'b','B','')");
db_execute('INSERT INTO plugin_icct_nms_rack_devices(host_id,rack_id,start_unit,unit_height,updated_by,updated_at) VALUES(1001,21,6,2,1,NOW()),(1002,23,1,1,1,NOW())');
icct_nms_schema_rack_catalogue_migration();
$choices=icct_nms_device_rack_choices(1001);
checkRack(count($choices)===3,'Empty preset missing or empty copies retained');
checkRack(count(array_unique(array_column($choices,'name')))===3,'Rack names duplicated');
checkRack(array_unique(array_map('intval',array_column($choices,'site_id')))===[0],'Catalogue remains site-dependent');
checkRack(db_fetch_cell('SELECT rack_id FROM plugin_icct_nms_rack_devices WHERE host_id=1001')==21 && db_fetch_cell('SELECT start_unit FROM plugin_icct_nms_rack_devices WHERE host_id=1001')==6,'Placement changed');
checkRack(db_fetch_cell('SELECT rack_id FROM plugin_icct_nms_rack_devices WHERE host_id=1002')==23,'Second occupied rack lost');
checkRack(!db_fetch_cell('SELECT id FROM plugin_icct_nms_racks WHERE id=22'),'Empty duplicate retained');
$before=json_encode($choices);icct_nms_schema_rack_catalogue_migration();checkRack(json_encode(icct_nms_device_rack_choices(1001))===$before,'Migration not idempotent');
$rack2=(int)db_fetch_cell_prepared('SELECT id FROM plugin_icct_nms_racks WHERE profile_id=?',[$empty]);
icct_nms_rack_place(1001,3,$rack2,[23,24]);
checkRack(db_fetch_cell('SELECT rack_id FROM plugin_icct_nms_rack_devices WHERE host_id=1001')==$rack2,'Global rack placement failed');
try{icct_nms_save_rack_preset(['rack_profile_id'=>$empty,'rack_name'=>'Rack 2','unit_count'=>12]);throw new RuntimeException('Occupied rack shrunk');}catch(InvalidArgumentException $expected){}
checkRack(db_fetch_cell_prepared('SELECT unit_count FROM plugin_icct_nms_racks WHERE profile_id=?',[$empty])==24,'Capacity rollback failed');
icct_nms_save_rack_preset(['rack_profile_id'=>$empty,'rack_name'=>'Main Rack','unit_count'=>30]);
checkRack(db_fetch_cell_prepared('SELECT unit_count FROM plugin_icct_nms_racks WHERE profile_id=?',[$empty])==30,'Capacity not synchronized');
checkRack(strpos(icct_nms_rack_delete_block_reason($empty),'devices are assigned')!==false,'Occupied rack not blocked in UI');
try{icct_nms_save_rack_preset(['action'=>'delete_rack_profile','rack_profile_id'=>$empty]);throw new RuntimeException('Occupied rack deleted');}catch(InvalidArgumentException $expected){checkRack(strpos($expected->getMessage(),'devices are assigned')!==false,'Unclear delete error');}
checkRack(db_fetch_cell_prepared('SELECT id FROM plugin_icct_nms_racks WHERE profile_id=?',[$empty])==$rack2,'Rejected delete removed rack');
icct_nms_save_rack_preset(['rack_name'=>'Empty Rack','unit_count'=>18]);
checkRack(count(icct_nms_device_rack_choices())===4,'New empty rack not available immediately');
checkRack(icct_nms_rack_delete_block_reason(array_key_last(icct_nms_rack_presets()))==='','Empty rack incorrectly blocked');
checkRack(db_fetch_cell('SELECT site_id FROM host WHERE id=1001')==3,'Rack placement changed device site');
echo "PASS: unique rack catalogue, empty presets, preserved placements, site-independent selection, capacity synchronization and safe rollback.\n";
