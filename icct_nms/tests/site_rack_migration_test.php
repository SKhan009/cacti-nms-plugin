<?php
/** Native migration checks confined to connection-local temporary tables. */
if(PHP_SAPI!=='cli')exit(1);
include '/var/www/html/cacti/include/cli_check.php';
require __DIR__.'/../includes/schema.php';
if(!db_execute('CREATE TEMPORARY TABLE qa_copy LIKE plugin_icct_nms_meta')||!db_execute('CREATE TEMPORARY TABLE plugin_icct_nms_meta LIKE qa_copy')||!db_execute('DROP TEMPORARY TABLE qa_copy'))throw new RuntimeException('Isolation failed');
if(!db_execute("CREATE TEMPORARY TABLE plugin_icct_nms_rack_nodes(id INT UNSIGNED PRIMARY KEY,site_id INT UNSIGNED,name VARCHAR(150),node_kind VARCHAR(16),updated_by INT,updated_at DATETIME)")||!db_execute("CREATE TEMPORARY TABLE plugin_icct_nms_racks(id INT UNSIGNED PRIMARY KEY,node_id INT UNSIGNED,rack_number INT,name VARCHAR(150),unit_count INT,updated_by INT,updated_at DATETIME,UNIQUE KEY node_rack(node_id,rack_number))"))throw new RuntimeException('Isolation failed');
db_execute("INSERT INTO plugin_icct_nms_rack_nodes(id,site_id,name,node_kind,updated_by,updated_at) VALUES(1,6,'Old A','node',1,NOW()),(2,7,'Old B','node',1,NOW())");
db_execute("INSERT INTO plugin_icct_nms_racks(id,node_id,rack_number,name,unit_count,updated_by,updated_at) VALUES(17,1,1,'Rack A',24,1,NOW()),(18,2,1,'Rack B',42,1,NOW())");
$preferences=['selected'=>0,'node_scope'=>'preset','dashboards'=>[['name'=>'Operations','node_id'=>2,'widgets'=>['topology','recent'],'columns'=>3]],'deleted'=>['index'=>0,'dashboard'=>['node_id'=>1,'widgets'=>['topology']]]];
foreach(['node_rack_profile_1'=>'0123456789abcdef','device_node_id_14'=>'1','dashboard_user_1'=>json_encode($preferences),'unrelated'=>'keep'] as $key=>$value)db_execute_prepared('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',[$key,$value]);
icct_nms_schema_sites_migration();
$racks=db_fetch_assoc('SELECT * FROM plugin_icct_nms_racks ORDER BY id');
if(array_column($racks,'id')!=[17,18]||array_column($racks,'site_id')!=[6,7]||$racks[0]['profile_id']!=='0123456789abcdef'||$racks[0]['unit_count']!=24)throw new RuntimeException('Racks changed during migration');
$p=json_decode(db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['dashboard_user_1']),true);
if($p['dashboards'][0]['site_id']!==7||$p['deleted']['dashboard']['site_id']!==6||$p['dashboards'][0]['name']!=='Operations'||$p['dashboards'][0]['columns']!==3||isset($p['node_scope'],$p['dashboards'][0]['node_id']))throw new RuntimeException('Dashboard migration lost preferences');
if(db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['unrelated'])!=='keep'||db_fetch_cell("SHOW COLUMNS FROM plugin_icct_nms_racks LIKE 'node_id'"))throw new RuntimeException('Migration cleanup incorrect');
icct_nms_schema_sites_migration();
echo "PASS: site migration preserves rack IDs/capacities, profiles and dashboard layouts, removes retired fields and safely repeats.\n";
