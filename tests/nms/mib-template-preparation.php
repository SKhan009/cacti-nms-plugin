<?php
/** CLI QA: native template APIs write only connection-local temporary tables. */
if(PHP_SAPI!=='cli')exit(1);
$bootstrap=getenv('NMS_CACTI_BOOTSTRAP');
if(!$bootstrap)throw new RuntimeException('Set NMS_CACTI_BOOTSTRAP.');
define('IN_CACTI_INSTALL',true);
require $bootstrap;
api_plugin_load_realms();
require_once $config['base_path'].'/lib/auth.php';
require_once $config['base_path'].'/plugins/nms/includes/mib_templates.php';
$_SESSION=['sess_user_id'=>1];
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
$tables=['host_template','host_template_graph','data_template','data_template_data','data_template_rrd','data_input_data','graph_templates','graph_templates_graph','graph_templates_item','graph_template_input','graph_template_input_defs','plugin_nms_category_templates','plugin_nms_managed_objects','plugin_nms_meta','settings'];
foreach($tables as $table){
 check(db_execute("CREATE TEMPORARY TABLE `qa_mib_copy` LIKE `$table`"),'Create isolated '.$table);
 db_execute("INSERT INTO qa_mib_copy SELECT * FROM `$table`");
 db_execute("CREATE TEMPORARY TABLE `$table` LIKE qa_mib_copy");
 db_execute("INSERT INTO `$table` SELECT * FROM qa_mib_copy");
 db_execute('DROP TEMPORARY TABLE qa_mib_copy');
}
$category=(int)nms_categories()[0]['id'];
$file='/usr/share/snmp/mibs/SNMPv2-MIB.txt';
check(is_file($file),'Standard MIB fixture available');
$preview=nms_mib_preview(['name'=>['SNMPv2-MIB.txt'],'tmp_name'=>[$file],'error'=>[UPLOAD_ERR_OK]],0,'NMS Isolated MIB QA',$category);
check(count($preview['records'])>0,'MIB parsed without a device or SNMP session');
check(count(array_filter($preview['skipped'],static fn($s)=>strpos($s,'table column')!==false))>0,'Table columns excluded pending live indexes');
$hosts=(int)db_fetch_cell('SELECT COUNT(*) FROM host');
$data=(int)db_fetch_cell('SELECT COUNT(*) FROM data_local');
$graphs=(int)db_fetch_cell('SELECT COUNT(*) FROM graph_local');
$bundle=nms_mib_prepare_templates($preview,array_map('strval',array_keys($preview['records'])));
check(count($bundle['rows'])===count($preview['records']),'Every selected scalar receives native templates');
check((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM host_template_graph WHERE host_template_id=?',[$bundle['host_template_id']])===count($bundle['rows']),'Graphs linked to device template');
check($hosts===(int)db_fetch_cell('SELECT COUNT(*) FROM host') && $data===(int)db_fetch_cell('SELECT COUNT(*) FROM data_local') && $graphs===(int)db_fetch_cell('SELECT COUNT(*) FROM graph_local'),'No device, local data source or graph created before Add device');
$rejected=false;try{nms_mib_prepare_templates($preview,['0']);}catch(InvalidArgumentException $e){$rejected=true;}
check($rejected,'Duplicate template name rejected');
check((bool)db_fetch_cell_prepared('SELECT meta_value FROM plugin_nms_meta WHERE meta_key=?',['mib_bundle_'.$bundle['host_template_id']]),'Prepared bundle persisted in isolated storage');
