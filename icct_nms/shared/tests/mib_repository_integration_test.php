<?php
/** Native parser/template QA in connection-local temporary tables. No persistent templates or hosts. */
if(PHP_SAPI!=='cli')exit(1);
define('IN_CACTI_INSTALL',true);require '/var/www/html/cacti/include/cli_check.php';api_plugin_load_realms();require_once $config['base_path'].'/lib/auth.php';
require_once __DIR__ . '/../../shared/services/bootstrap.php';icct_nms_backend();require_once __DIR__ . '/../../presets/services/device_type_service.php';require_once __DIR__ . '/../../protocols/snmp/mibs/services/mib_repository_service.php';
require_once __DIR__.'/../../protocols/snmp/mibs/services/mib_browser_service.php';
require_once __DIR__.'/../../protocols/snmp/mibs/services/mib_generation_service.php';
require_once __DIR__.'/../../protocols/snmp/mibs/services/mib_staging_service.php';
require_once __DIR__.'/../../protocols/snmp/mibs/services/mib_upload_session_service.php';
$_SESSION=['sess_user_id'=>1];
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
$testQueryFiles=[];
try {
foreach(['data_local','graph_local','host_template','host_template_graph','host_template_snmp_query','host_snmp_query','host_snmp_cache','snmp_query','snmp_query_graph','snmp_query_graph_rrd','data_template','data_template_data','data_template_rrd','data_input_data','graph_templates','graph_templates_graph','graph_templates_item','graph_template_input','graph_template_input_defs','plugin_icct_nms_meta','settings','colors','host_graph','poller_item','poller_reindex','poller_command','data_source_stats_hourly','data_source_stats_daily','data_source_stats_weekly','data_source_stats_monthly','data_source_stats_yearly'] as $table){
 if(!db_execute("CREATE TEMPORARY TABLE qa_mib_copy LIKE `$table`"))throw new RuntimeException('Isolation failed');db_execute("INSERT INTO qa_mib_copy SELECT * FROM `$table`");db_execute("CREATE TEMPORARY TABLE `$table` LIKE qa_mib_copy");db_execute("INSERT INTO `$table` SELECT * FROM qa_mib_copy");db_execute('DROP TEMPORARY TABLE qa_mib_copy');
}
// The fixture begins with no MIB definitions in the isolated connection.
db_execute("DELETE FROM plugin_icct_nms_meta WHERE meta_key LIKE 'mib_%'");
db_execute("DELETE did FROM data_input_data did JOIN data_input_fields f ON f.id=did.data_input_field_id WHERE f.data_name='oid' AND did.value<>''");
$types=icct_nms_device_types();$type=array_key_first($types);$path=__DIR__ . '/../../shared/assets/mibs/IF-MIB.txt';
$preview=icct_mib_preview(['name'=>['IF-MIB.txt'],'tmp_name'=>[$path],'error'=>[UPLOAD_ERR_OK]],$type);
check(count($preview['records'])>30,'IF-MIB parses multiple full object definitions');
$scanEvents=[];$scanPreview=icct_mib_preview(['name'=>['IF-MIB.txt'],'tmp_name'=>[$path],'error'=>[UPLOAD_ERR_OK]],$type,false,function($event)use(&$scanEvents){$scanEvents[]=$event;});
$scanCounts=array_values(array_filter($scanEvents,fn($event)=>$event['phase']==='scan'));$lastScan=end($scanCounts);
check($scanCounts[0]['processed']===0&&$lastScan['processed']===count($scanPreview['records'])&&$lastScan['total']===count($scanPreview['records'])&&array_column($scanCounts,'processed')===range(0,count($scanPreview['records'])),'Live scan progress reports each actual parsed OID without simulated counts');

foreach([['name'=>[]],['name'=>['one.mib','two.txt']],['name'=>['bad.php']],['name'=>['bad.mib.php']]] as $invalidUpload){try{icct_mib_validate_upload_selection($invalidUpload);throw new LogicException('Invalid file selection accepted');}catch(InvalidArgumentException $e){check(true,'Upload rejects invalid extension or multiple files');}}
icct_mib_validate_upload_selection(['name'=>['VENDOR.MIB']]);check(true,'Uppercase MIB extension accepted');
$unassignedPreview=icct_mib_preview(['name'=>['IF-MIB.txt'],'tmp_name'=>[$path],'error'=>[UPLOAD_ERR_OK]],'__mib_unassigned__');
$unassignedIndex=null;foreach($unassignedPreview['records'] as $i=>$object)if($object['numeric']&&!$object['table']){$unassignedPreview['records'][$i]['base_oid']='1.3.6.1.4.1.32473.98765.900';$unassignedPreview['records'][$i]['oid']='1.3.6.1.4.1.32473.98765.900.0';$unassignedIndex=$i;break;}
$unassignedPlan=icct_mib_plan($unassignedPreview,['type_id'=>'__mib_unassigned__','template_name'=>'Unassigned upload QA']);
$unassignedBundle=icct_mib_save($unassignedPreview,$unassignedPlan);
check($unassignedBundle['type_name']==='Not assigned'&&(int)$unassignedBundle['category_id']===0&&empty($unassignedBundle['rows']),'Single-file upload saves without assigning a device type or creating templates');
$unassignedBundle=icct_mib_generation_save($unassignedBundle['id'],2,$unassignedIndex);$unassignedBundle=icct_mib_generation_save($unassignedBundle['id'],3);$unassignedBundle=icct_mib_generation_save($unassignedBundle['id'],4);
check($unassignedBundle['host_template_id']>0&&$unassignedBundle['type_name']==='Not assigned','Uploads without a device type generate native Cacti data, graph and device templates');


$nativeMethods=icct_mib_collection_names();
check(icct_mib_collection_options(['table'=>false],$nativeMethods)===['get'=>$nativeMethods['get']],'Scalar collection shows only the installed Cacti GET method');
check(array_keys(icct_mib_collection_options(['table'=>true],$nativeMethods))===['indexed','get'],'Table collection shows installed native methods without an Auto option');
check(icct_mib_collection_options(['table'=>true],['get'=>'Native GET','get_available'=>true,'indexed_available'=>false])===['get'=>'Native GET'],'Unavailable Cacti methods are omitted');
check((bool)array_filter($preview['records'],fn($r)=>$r['description']&&$r['syntax']&&$r['access']),'Syntax, access and descriptions retained');
check((bool)array_filter($preview['records'],fn($r)=>$r['table']&&$r['numeric']),'Indexed IF-MIB counters require reviewed instance OIDs');
$selected=[];$records=[];foreach($preview['records'] as $i=>$r)if($r['numeric']&&!$r['table']&&!$r['enum']){$selected[$i]=1;$records[$i]=['oid'=>$r['oid'],'label'=>$r['label'],'data_name'=>'MIB QA - '.$r['label'],'graph_name'=>'MIB QA - '.$r['label'],'ds_type'=>$r['ds_type'],'units'=>$r['units'],'min'=>'U','max'=>'U'];break;}
check((bool)$records,'IF-MIB readable scalar fixture selected');
$input=['type_id'=>$type,'template_name'=>'MIB QA Device','create_data'=>1,'create_graph'=>1,'create_device'=>1,'selected'=>$selected,'records'=>$records];
$hosts=(int)db_fetch_cell('SELECT COUNT(*) FROM host');$data=(int)db_fetch_cell('SELECT COUNT(*) FROM data_local');$graphs=(int)db_fetch_cell('SELECT COUNT(*) FROM graph_local');
$metricKey=array_key_first($records);$input['records'][$metricKey]+= ['graph_type'=>5,'cf'=>3,'color'=>'00CF00','legend'=>'Interface count','statistics'=>'0','threshold_low'=>'0','threshold_high'=>'80'];
$plan=icct_mib_plan($preview,$input);$bundle=icct_mib_save($preview,$plan);
$graphId=$bundle['rows'][0]['graph_template_id'];
check(icct_mib_bounds('INTEGER (-8..9)')===['min'=>'-8','max'=>'9'],'Declared MIB numeric bounds populate automatically');
check(icct_mib_bounds('Counter64')===['min'=>'0','max'=>'U'],'Counters use nonnegative rates without inventing an RRD maximum');
check(icct_mib_bounds('INTEGER {up(1),down(2)}')===['min'=>'U','max'=>'U'],'Enumeration values do not invent numeric bounds');
$qaHost=2;$dataId=9000001;$localGraph=9000001;
check(is_device_allowed($qaHost,1)&&icct_backend_protocol_enabled($qaHost,'snmp'),'Fixture device is authorized and SNMP enabled');
$tid=$bundle['rows'][0]['data_template_id'];
db_execute_prepared('INSERT INTO data_local (id,host_id,data_template_id) VALUES(?,?,?)',[$dataId,$qaHost,$tid]);
$native=db_fetch_row_prepared('SELECT * FROM data_template_data WHERE data_template_id=? AND local_data_id=0',[$tid]);$oldInput=$native['id'];unset($native['id']);$native['local_data_id']=$dataId;$newInput=sql_save($native,'data_template_data');
foreach(db_fetch_assoc_prepared('SELECT * FROM data_input_data WHERE data_template_data_id=?',[$oldInput]) as $field){$field['data_template_data_id']=$newInput;sql_save($field,'data_input_data',['data_template_data_id','data_input_field_id']);}
$rrdRow=db_fetch_row_prepared('SELECT * FROM data_template_rrd WHERE data_template_id=? AND local_data_id=0',[$tid]);unset($rrdRow['id']);$rrdRow['local_data_id']=$dataId;$localRrd=sql_save($rrdRow,'data_template_rrd');
db_execute_prepared('INSERT INTO graph_local (id,host_id,graph_template_id) VALUES(?,?,?)',[$localGraph,$qaHost,$graphId]);
$gg=db_fetch_row_prepared('SELECT * FROM graph_templates_graph WHERE graph_template_id=? AND local_graph_id=0',[$graphId]);unset($gg['id']);$gg['local_graph_id']=$localGraph;sql_save($gg,'graph_templates_graph');
foreach(db_fetch_assoc_prepared('SELECT * FROM graph_templates_item WHERE graph_template_id=? AND local_graph_id=0',[$graphId]) as $item){unset($item['id']);$item['local_graph_id']=$localGraph;if($item['task_item_id'])$item['task_item_id']=$localRrd;sql_save($item,'graph_templates_item');}
$fetched=icct_mib_device_inputs($preview,$qaHost)[$metricKey][0]??[];
require_once __DIR__.'/../../protocols/snmp/mibs/services/mib_usage_service.php';
$usage=icct_mib_repository_usage([$bundle])[$bundle['id']];
check($usage['data_sources']>=1&&in_array($qaHost,array_map('intval',array_column($usage['devices'],'id')),true),'MIB file usage resolves actual native data sources and device associations');
check(($fetched['oid']??'')===$plan['records'][0]['oid']&&(int)($fetched['graph_type']??0)===5&&(int)($fetched['cf']??0)===3,'Device instance OID and native graph item settings fetched together');
check(($fetched['statistics']??'')==='0'&&($fetched['threshold_high']??'')=='80'&&($fetched['threshold_low']??'')=='0','Native graph statistics and threshold lines remain synchronized');
db_execute_prepared('DELETE FROM data_input_data WHERE data_template_data_id=?',[$newInput]);
foreach(['data_template_data','data_template_rrd'] as $table)db_execute_prepared('DELETE FROM '.$table.' WHERE local_data_id=?',[$dataId]);
foreach(['graph_templates_graph','graph_templates_item'] as $table)db_execute_prepared('DELETE FROM '.$table.' WHERE local_graph_id=?',[$localGraph]);
db_execute_prepared('DELETE FROM data_local WHERE id=?',[$dataId]);db_execute_prepared('DELETE FROM graph_local WHERE id=?',[$localGraph]);
try{icct_mib_device_inputs($preview,0);throw new LogicException('Invalid host accepted');}catch(InvalidArgumentException $e){check(true,'Invalid device cannot supply MIB inputs');}

check((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_templates_item WHERE graph_template_id=? AND graph_type_id=5 AND consolidation_function_id=3 AND text_format=?',[$graphId,'Interface count'])===1,'Reviewed graph item, legend and consolidation function saved');
check((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_templates_item WHERE graph_template_id=? AND graph_type_id=2 AND value IN (?,?)',[$graphId,'0','80'])===2,'Upper and lower thresholds create native HRULE items, including zero');
check((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_templates_item WHERE graph_template_id=? AND graph_type_id=9',[$graphId])===0,'Optional statistics removed');
check((int)db_fetch_cell('SELECT COUNT(*) FROM graph_template_input_defs d LEFT JOIN graph_templates_item i ON i.id=d.graph_template_item_id WHERE i.id IS NULL')===0,'Graph input references remain valid');
check($bundle['host_template_id']>0&&$bundle['rows'][0]['data_template_id']>0&&$bundle['rows'][0]['graph_template_id']>0,'All three native template types created');
check(db_fetch_cell_prepared('SELECT name FROM data_template WHERE id=?',[$bundle['rows'][0]['data_template_id']])===array_values($records)[0]['data_name'],'Exact reviewed names used, no NMS prefix');
check((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM host_template_graph WHERE host_template_id=?',[$bundle['host_template_id']])===1,'Graph linked to device template');
check(icct_mib_wizard_get($bundle['id'])['rows']===$bundle['rows'],'Chunked template associations reload from database');
check(icct_mib_objects($bundle)===$preview['records'],'Full object metadata remains available after saving');
check(icct_mib_file($bundle,0)===file_get_contents($path),'Original uploaded MIB retained with checksum verification');
$beforeEdit=icct_mib_wizard_get($bundle['id']);
icct_mib_repository_edit($bundle['id'],'Edited repository',(string)$type);
$afterEdit=icct_mib_wizard_get($bundle['id']);
check($afterEdit['name']==='Edited repository'&&$afterEdit['rows']===$beforeEdit['rows']&&$afterEdit['files']===$beforeEdit['files'],'Inline repository edit preserves uploaded files and native template associations');
check(icct_mib_file($afterEdit,0)===file_get_contents($path),'Download remains intact after repository edit');
foreach([['<invalid>',(string)$type],['Valid','not-a-saved-type']] as $invalid){
    try{icct_mib_repository_edit($bundle['id'],$invalid[0],$invalid[1]);throw new RuntimeException('Invalid repository edit accepted');}
    catch(InvalidArgumentException $e){check(icct_mib_wizard_get($bundle['id'])['name']==='Edited repository','Invalid repository edit leaves saved metadata intact');}
}
icct_mib_repository_edit($bundle['id'],$beforeEdit['name'],(string)$type);

check($hosts===(int)db_fetch_cell('SELECT COUNT(*) FROM host')&&$data===(int)db_fetch_cell('SELECT COUNT(*) FROM data_local')&&$graphs===(int)db_fetch_cell('SELECT COUNT(*) FROM graph_local'),'Preparation creates no live device/data/graph instances');
try{icct_mib_save($preview,$plan);throw new LogicException('Replay accepted');}catch(InvalidArgumentException $e){check(str_contains($e->getMessage(),'already saved'),'Duplicate confirmation cannot recreate templates');}
$bad=$input;unset($bad['create_data']);try{icct_mib_plan($preview,$bad);throw new LogicException('Dependency accepted');}catch(InvalidArgumentException $e){check(str_contains($e->getMessage(),'require'),'Graph dependency validated');}
$auto=['type_id'=>$type,'template_name'=>'MIB QA Auto','create_data'=>1,'create_graph'=>1,'create_device'=>1,'selected'=>[]];
foreach($preview['records'] as $i=>$r)if($r['numeric'])$auto['selected'][$i]=1;
$autoPlan=icct_mib_plan($preview,$auto);
check(count($autoPlan['records'])===count($auto['selected']),'All numeric objects plan automatically from MIB defaults');
foreach($autoPlan['records'] as $r)if($r['table']){
 check($r['deferred_instance']&&$r['oid']===$r['base_oid'],'Table template preserves base OID without inventing an instance');
 $deferredPreview=$preview;$deferredPreview['id']=bin2hex(random_bytes(16));$deferredPlan=$autoPlan;$deferredPlan['name']='MIB QA Indexed';$r['data_name'].=' Indexed';$r['graph_name'].=' Indexed';$deferredPlan['records']=[$r];
 $queryFile=hash('sha256',icct_mib_query_xml($r)).'.xml';foreach([__DIR__.'/../../protocols/snmp/mibs/queries/'.$queryFile,$config['base_path'].'/resource/snmp_queries/icct_nms/'.$queryFile] as $file)if(!is_file($file))$testQueryFiles[]=$file;
 $deferredBundle=icct_mib_save($deferredPreview,$deferredPlan);$pair=$deferredBundle['rows'][0];
 check($pair['snmp_query_id']>0&&$pair['snmp_query_graph_id']>0,'Reusable table template creates native indexed query and graph association');
 check($deferredBundle['rows'][0]['deferred_instance'],'Indexed table records retain the base OID for discovery');break;
}
$before=icct_mib_file($bundle,0);
icct_mib_set_deleted($bundle['id'],true);
check(!db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['mib_repository_'.$bundle['id']]),'Delete removes upload from active list');
check(icct_mib_file($bundle,0)===$before&&db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE id=?',[$graphId]),'Delete retains files and generated graph templates');
icct_mib_set_deleted($bundle['id'],false);
check((bool)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['mib_repository_'.$bundle['id']]),'Undo restores the upload');
try{icct_mib_set_deleted("bad%",true);throw new LogicException('Invalid delete accepted');}catch(InvalidArgumentException $e){check(true,'Delete validates upload IDs');}
// More than 100 selections survive a single packed field and server validation.
$large=$preview;$large['records']=[];$bulk=$input;$bulk['selected']=[];$bulk['records']=[];
for($i=0;$i<120;$i++){
 $r=$preview['records'][array_key_first($records)];$r['base_oid']='1.3.6.1.4.1.999999.'.($i+1);$r['oid']=$r['base_oid'].'.0';$large['records'][]=$r;
 $bulk['selected'][$i]='1';$bulk['records'][$i]=array_replace($records[array_key_first($records)],['oid'=>$r['oid'],'data_name'=>'Bulk data '.$i,'graph_name'=>'Bulk graph '.$i]);
}
$packed=$bulk;unset($packed['selected'],$packed['records']);$packed['review_payload']=json_encode(['selected'=>$bulk['selected'],'records'=>$bulk['records']]);
check(count(icct_mib_plan($large,icct_mib_review_input($packed))['records'])===120,'120 selected OIDs preserved without max_input_vars truncation');
foreach($preview['records'] as $i=>$r)if($r['numeric']&&$r['table']){
 $indexed=$input;$indexed['selected']=[$i=>1];$indexed['records']=[$i=>array_replace($records[array_key_first($records)],['oid'=>'','instance_index'=>'1001.2'])];
 check(icct_mib_plan($preview,$indexed)['records'][0]['oid']===$r['base_oid'].'.1001.2','Explicit table index produces full instance OID');
 $indexed['records'][$i]['oid']=$r['base_oid'].'.999';try{icct_mib_plan($preview,$indexed);throw new LogicException('Conflicting OID accepted');}catch(InvalidArgumentException $e){check(str_contains($e->getMessage(),'disagree'),'Conflicting index and full OID rejected');}break;
}
foreach(['threshold_high'=>'NaN','color'=>'<script>','graph_type'=>'99'] as $key=>$value){
 $bad=$input;$bad['records'][$metricKey][$key]=$value;try{icct_mib_plan($preview,$bad);throw new LogicException('Invalid graph setting accepted');}catch(InvalidArgumentException $e){check(true,'Invalid graph setting rejected: '.$key);}
}
foreach([['data'=>true,'graph'=>false,'device'=>false],['data'=>false,'graph'=>false,'device'=>true],['data'=>false,'graph'=>false,'device'=>false]] as $n=>$options){$p=$preview;$p['id']=bin2hex(random_bytes(16));$q=$plan;$q['options']=$options;$q['name']='MIB QA Mode '.$n;foreach($q['records'] as &$r){$r['data_name'].=' '.$n;$r['graph_name'].=' '.$n;}unset($r);if($options['data']){try{icct_mib_save($p,$q);throw new LogicException('Duplicate OID accepted');}catch(InvalidArgumentException $e){check(str_contains($e->getMessage(),'already exists'),'Duplicate data OID rejected in alternate creation mode');}continue;}$b=icct_mib_save($p,$q);check((bool)$b['host_template_id']===$options['device']&&(bool)$b['rows'][0]['data_template_id']===$options['data']&&!$b['rows'][0]['graph_template_id'],'Selected creation options respected: '.$n);}
// Missing imports retain original bytes, while template creation stays blocked.
$fixtureDir=icct_mib_private_directory();
try{
 $dependency="QA-REPO-BASE-MIB DEFINITIONS ::= BEGIN\nIMPORTS enterprises FROM SNMPv2-SMI;\nqaRepoRoot OBJECT IDENTIFIER ::= { enterprises 999998 }\nEND\n";
 $main="QA-REPO-CHILD-MIB DEFINITIONS ::= BEGIN\nIMPORTS OBJECT-TYPE, Integer32 FROM SNMPv2-SMI qaRepoRoot FROM QA-REPO-BASE-MIB;\nqaRepoMetric OBJECT-TYPE\nSYNTAX Integer32\nMAX-ACCESS read-only\nSTATUS current\nDESCRIPTION \"Database dependency regression fixture.\"\n::= { qaRepoRoot 1 }\nEND\n";
 $mainPath=$fixtureDir.'/child.txt';$depPath=$fixtureDir.'/base.txt';file_put_contents($mainPath,$main);file_put_contents($depPath,$dependency);
 $upload=['name'=>['child.txt'],'tmp_name'=>[$mainPath],'error'=>[UPLOAD_ERR_OK]];
 $unresolved=icct_mib_preview($upload,$type,true);
 check(str_contains($unresolved['parse_error'],'QA-REPO-BASE-MIB')&&!$unresolved['records'],'Missing imports produce a file-only review');
 try{icct_mib_plan($unresolved,$input);throw new LogicException('Unresolved template accepted');}catch(InvalidArgumentException $e){check(str_contains($e->getMessage(),'missing dependencies'),'Unresolved MIB cannot create templates');}
 $filePlan=icct_mib_plan($unresolved,['type_id'=>$type,'template_name'=>'QA Unresolved Files']);$stored=icct_mib_save($unresolved,$filePlan);
 check(icct_mib_file($stored,0)===$main&&!$stored['host_template_id']&&!$stored['rows'],'Confirmed unresolved files saved in database without templates');
 $depPreview=icct_mib_preview(['name'=>['base.txt'],'tmp_name'=>[$depPath],'error'=>[UPLOAD_ERR_OK]],$type);
 icct_mib_save($depPreview,icct_mib_plan($depPreview,['type_id'=>$type,'template_name'=>'QA Repository Dependency']));
 $resolved=icct_mib_preview($upload,$type);
 check(in_array('QA-REPO-BASE-MIB',$resolved['dependencies'],true)&&count($resolved['records'])===1&&$resolved['records'][0]['base_oid']==='1.3.6.1.4.1.999998.1','Saved database import resolves OIDs without reuploading dependency');
 foreach(['IF-MIB','IP-MIB','BRIDGE-MIB','CISCO-ENTITY-SENSOR-MIB'] as $module){
  $modulePreview=icct_mib_preview(['name'=>[$module.'.txt'],'tmp_name'=>[__DIR__.'/../../shared/assets/mibs/'.$module.'.txt'],'error'=>[UPLOAD_ERR_OK]],$type);
  check(count($modulePreview['records'])>0,$module.' and all its imports resolve using only the plugin folder');
 }
}finally{foreach(glob($fixtureDir.'/*')?:[] as $file)unlink($file);rmdir($fixtureDir);}
$expired=$preview;$expired['created']=time()-3601;try{icct_mib_save($expired,$plan);throw new LogicException('Expired accepted');}catch(InvalidArgumentException $e){check(str_contains($e->getMessage(),'expired'),'Expired review rejected');}

$wizardPreview=$preview;$wizardPreview['id']=bin2hex(random_bytes(16));$wizardPreview['created']=time();
$wizard=icct_mib_save($wizardPreview,icct_mib_plan($wizardPreview,['type_id'=>$type,'template_name'=>'QA Wizard']));
check(!$wizard['rows']&&!$wizard['host_template_id']&&icct_mib_file($wizard,0)===file_get_contents($path),'Wizard saves original MIB before any templates');
try{icct_mib_wizard_save($wizard['id'],['step'=>2]);throw new LogicException('Out-of-order save accepted');}catch(InvalidArgumentException $e){check(true,'Wizard blocks skipping unsaved steps');}
$wizardInput=$input;foreach($preview['records'] as $i=>$candidate)if($candidate['numeric']&&!$candidate['table']&&$i!==$metricKey){$wizardInput['selected']=[$i=>1];$wizardInput['records']=[$i=>['oid'=>$candidate['oid'],'label'=>$candidate['label']]];$metricKey=$i;break;}$wizardInput['step']=1;$wizardInput['template_name']='QA Wizard';
$wizardInput['records'][$metricKey]['data_name']='QA Wizard Data';$wizardInput['records'][$metricKey]['graph_name']='QA Wizard Graph';
$wizard=icct_mib_wizard_save($wizard['id'],$wizardInput);$wizardData=$wizard['rows'][0]['data_template_id'];
check($wizard['wizard_step']===1&&$wizardData>0&&!$wizard['rows'][0]['graph_template_id']&&!$wizard['host_template_id'],'First step creates only selected data source templates');
check(icct_mib_wizard_get($wizard['id'])['rows'][0]['settings']['data_name']==='QA Wizard Data','Saved step resumes with reviewed settings');
try{icct_mib_wizard_save($wizard['id'],$wizardInput);throw new LogicException('Duplicate stage accepted');}catch(InvalidArgumentException $e){check(true,'Wizard rejects repeated Save without duplicating templates');}
$wizardGraph=['step'=>2,'records'=>[$metricKey=>['graph_name'=>'QA Wizard Custom Graph','legend'=>'Custom legend','color'=>'FF0000','graph_type'=>6,'cf'=>2,'statistics'=>'1','threshold_high'=>'90']]];
$badWizard=$wizardGraph;$badWizard['records'][$metricKey]['color']='invalid';
try{icct_mib_wizard_save($wizard['id'],$badWizard);throw new LogicException('Invalid setting accepted');}catch(InvalidArgumentException $e){check(icct_mib_wizard_get($wizard['id'])['wizard_step']===1,'Invalid graph setting keeps current saved step intact');}
$wizard=icct_mib_wizard_save($wizard['id'],$wizardGraph);$wizardGraphId=$wizard['rows'][0]['graph_template_id'];
check($wizard['wizard_step']===2&&$wizard['rows'][0]['data_template_id']===$wizardData&&$wizardGraphId>0&&!$wizard['host_template_id'],'Graph step reuses saved data source and delays device template');
check((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_templates_item WHERE graph_template_id=? AND graph_type_id=6 AND consolidation_function_id=2 AND text_format=?',[$wizardGraphId,'Custom legend'])===1,'User graph edits persist in native template items');
$wizard=icct_mib_wizard_save($wizard['id'],['step'=>3,'type_id'=>$type,'template_name'=>'QA Wizard Edited Device']);
check($wizard['wizard_step']===3&&db_fetch_cell_prepared('SELECT name FROM host_template WHERE id=?',[$wizard['host_template_id']])==='QA Wizard Edited Device','Final step saves edited device template name');
check((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM host_template_graph WHERE host_template_id=? AND graph_template_id=?',[$wizard['host_template_id'],$wizardGraphId])===1,'Device template links the exact saved graph');
// Automatic generation uses the same native APIs, isolated from persistent Cacti tables.
$generationPreview=$preview;$generationPreview['id']=bin2hex(random_bytes(16));$generationPreview['created']=time();
$generationIndex=null;
foreach($generationPreview['records'] as $i=>$candidate)if($candidate['numeric']&&!$candidate['table']){
 $generationPreview['records'][$i]['base_oid']='1.3.6.1.4.1.32473.98765.1';$generationPreview['records'][$i]['oid']='1.3.6.1.4.1.32473.98765.1.0';$generationIndex=$i;break;
}
$generated=icct_mib_save($generationPreview,icct_mib_plan($generationPreview,['type_id'=>$type,'template_name'=>'QA Automatic Generation']));
check(icct_mib_generation_results($generated,2)===[],'No success results before generation is committed');
try{icct_mib_generation_save($generated['id'],2,$generationIndex,['generation_review'=>1,'selected'=>[]]);throw new LogicException('Empty review accepted');}catch(InvalidArgumentException $e){check(icct_mib_wizard_get($generated['id'])['rows']===[],'Empty review does not create native templates');}
$generated=icct_mib_generation_save($generated['id'],2,$generationIndex,['selected'=>[$generationIndex=>1],'records'=>[$generationIndex=>['data_name'=>'QA Reviewed Generation Data','active'=>'0','fault_presets'=>[['name'=>'QA Sensor High','threshold'=>'75','condition'=>'at_or_above','severity'=>'Critical','enabled'=>true,'description'=>'Sensor high','corrective_action'=>'Check sensor']]]]]);$generatedData=$generated['rows'][0]['data_template_id'];
check(count($generated['rows'])===1&&$generatedData>0&&!$generated['rows'][0]['graph_template_id'],'Create generates only the selected OID data template');
check(db_fetch_cell_prepared('SELECT name FROM data_template WHERE id=?',[$generatedData])==='QA Reviewed Generation Data','Generation saves the reviewed per-OID settings');
check(db_fetch_cell_prepared('SELECT active FROM data_template_data WHERE data_template_id=? AND local_data_id=0',[$generatedData])==='','Reviewed Data Source Active state is saved in native Cacti');
check(icct_mib_generation_results($generated,2)[0]['id']===$generatedData,'Generation results reference the saved native template');
try{icct_mib_generation_save($generated['id'],2,$generationIndex);throw new LogicException('Duplicate Generate accepted');}catch(InvalidArgumentException $e){check(true,'Repeated Generate cannot duplicate data templates');}
// An old browser tab must never create the remaining metrics implicitly or partially.
$beforeBundles=count(icct_mib_list());$beforeData=(int)db_fetch_cell('SELECT COUNT(*) FROM data_template');
$newIndexes=array_keys(icct_mib_generation_input($generated,2)['selected']);$freshIndex=$newIndexes[0];
$invalidAttempts=[
 ['object'=>null,'review'=>[]],
 ['object'=>null,'review'=>['selected'=>[$generationIndex=>1,$freshIndex=>1],'records'=>[]]],
 ['object'=>$freshIndex,'review'=>['selected'=>[$freshIndex=>1],'records'=>[$freshIndex=>['data_name'=>'QA Reviewed Generation Data']]]],
 ['object'=>$freshIndex,'review'=>['selected'=>[$freshIndex=>1],'records'=>[$freshIndex=>['min'=>'100','max'=>'0']]]],
];
if(count($newIndexes)>1)$invalidAttempts[]=['object'=>null,'review'=>['selected'=>[$newIndexes[0]=>1,$newIndexes[1]=>1],'records'=>[$newIndexes[0]=>['data_name'=>'Repeated batch name'],$newIndexes[1]=>['data_name'=>'repeated batch name']]]];
foreach($invalidAttempts as $attempt){try{icct_mib_generation_save($generated['id'],2,$attempt['object'],$attempt['review']);throw new LogicException('Invalid or repeated generation accepted');}catch(InvalidArgumentException $e){check(count(icct_mib_list())===$beforeBundles&&(int)db_fetch_cell('SELECT COUNT(*) FROM data_template')===$beforeData,'Repeat, stale selection, duplicate name or invalid bounds rejected without saving any batch');}}
check((int)db_fetch_cell_prepared('SELECT IS_FREE_LOCK(?)',['icct_mib_templates'])===1,'Generation releases the lock after rejected requests');
$generated=icct_mib_generation_save($generated['id'],3);
$graphCount=(int)db_fetch_cell('SELECT COUNT(*) FROM graph_templates');
try{icct_mib_generation_save($generated['id'],3);throw new LogicException('Repeated graph generation accepted');}catch(InvalidArgumentException $e){check((int)db_fetch_cell('SELECT COUNT(*) FROM graph_templates')===$graphCount,'Repeated graph generation creates no duplicate graph templates');}

check($generated['rows'][0]['data_template_id']===$generatedData&&$generated['rows'][0]['graph_template_id']>0,'Graph generation reuses the selected native data template');
require_once __DIR__.'/../../fcaps/services/fcaps_service.php';
$presetGraph=$generated['rows'][0]['graph_template_id'];
check((bool)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['graph_fault_presets_'.$presetGraph]),'Fault presets travel with the generated graph template');
db_execute_prepared('INSERT INTO host_graph(host_id,graph_template_id) VALUES(?,?)',[$qaHost,$presetGraph]);
$inherited=array_values(array_filter(icct_nms_fault_rules($qaHost),fn($rule)=>($rule['template_id']??0)===$presetGraph));
check(count($inherited)===1&&$inherited[0]['corrective_action']==='Check sensor'&&icct_nms_fault_state($inherited[0],75)==='Critical'&&icct_nms_fault_state($inherited[0],74)==='Normal','Associated device inherits inclusive fault preset and auto clears below threshold');
check(!array_filter(icct_nms_fault_rules(999999),fn($rule)=>($rule['template_id']??0)===$presetGraph),'Preset does not leak to an unrelated device');
$validatedPreset=icct_nms_fault_validate($inherited,icct_nms_fault_catalogue(),[$presetGraph]);
check($validatedPreset[0]['preset_key']===$inherited[0]['preset_key']&&$validatedPreset[0]['description']==='Sensor high','Device FCAPS validation retains preset identity and description');
$originalFaults=db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['fault_rules_'.$qaHost]);$override=$inherited[0];$override['enabled']=false;
db_execute_prepared('REPLACE INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',['fault_rules_'.$qaHost,json_encode([$override])]);
$overridden=array_values(array_filter(icct_nms_fault_rules($qaHost),fn($rule)=>($rule['template_id']??0)===$presetGraph));
check(count($overridden)===1&&!$overridden[0]['enabled'],'Device override disables inherited preset without duplication');
if($originalFaults!==false&&$originalFaults!==null)db_execute_prepared('REPLACE INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',['fault_rules_'.$qaHost,$originalFaults]);else db_execute_prepared('DELETE FROM plugin_icct_nms_meta WHERE meta_key=?',['fault_rules_'.$qaHost]);
db_execute_prepared('DELETE FROM host_graph WHERE host_id=? AND graph_template_id=?',[$qaHost,$presetGraph]);
db_execute_prepared('INSERT INTO graph_local(id,host_id,graph_template_id) VALUES(?,?,?)',[9000022,$qaHost,$presetGraph]);
check(count(array_filter(icct_nms_fault_rules($qaHost),fn($rule)=>($rule['template_id']??0)===$presetGraph))===1,'Native indexed graph instances inherit presets without a host_graph entry');
db_execute_prepared('DELETE FROM graph_local WHERE id=?',[9000022]);
$generated=icct_mib_generation_save($generated['id'],4);
check($generated['host_template_id']>0&&(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM host_template_graph WHERE host_template_id=? AND graph_template_id=?',[$generated['host_template_id'],$generated['rows'][0]['graph_template_id']])===1,'Device generation associates the saved native graph');
$deviceTemplateCount=(int)db_fetch_cell('SELECT COUNT(*) FROM host_template');
try{icct_mib_generation_save($generated['id'],4);throw new LogicException('Repeated device generation accepted');}catch(InvalidArgumentException $e){check((int)db_fetch_cell('SELECT COUNT(*) FROM host_template')===$deviceTemplateCount,'Repeated device generation creates no duplicate device template');}
$remaining=icct_mib_generation_input($generated,2);$nextIndex=array_key_first($remaining['selected']);
$additional=icct_mib_generation_save($generated['id'],2,$nextIndex);
check($additional['id']!==$generated['id']&&count($additional['rows'])===1&&icct_mib_wizard_get($generated['id'])['host_template_id']===$generated['host_template_id'],'Another Create preserves previous generated template associations');
icct_mib_wizard_reparse($stored['id'],$resolved);
check(icct_mib_wizard_get($stored['id'])['object_count']===1&&icct_mib_file($stored,0)===$main,'Dependency reparse refreshes saved file in place');


// Read-only mode renders all saved steps without mutation controls or a tree.
$management=true;$viewBundle=$generated;$viewTemplate=__DIR__.'/../../protocols/snmp/mibs/templates/mib_configuration_view.php';
foreach([1,2,3,4] as $viewStage){ob_start();require $viewTemplate;$viewHtml=ob_get_clean();check(strpos($viewHtml,'<form')===false&&strpos($viewHtml,'<input')===false&&strpos($viewHtml,'data-fault-edit')===false&&strpos($viewHtml,'mib-browser')===false,'Read-only step '.$viewStage.' contains no editing forms or MIB tree');}
$viewStage=2;ob_start();require $viewTemplate;$viewHtml=ob_get_clean();check(strpos($viewHtml,'QA Reviewed Generation Data')!==false&&strpos($viewHtml,'QA Sensor High')!==false,'Read-only data step shows saved native data source and fault preset');
$viewStage=3;ob_start();require $viewTemplate;$viewHtml=ob_get_clean();check(strpos($viewHtml,'Template unavailable')===false&&strpos($viewHtml,'Graph Legend')!==false,'Read-only graph step resolves the saved native graph');
$viewStage=4;ob_start();require $viewTemplate;$viewHtml=ob_get_clean();check(strpos($viewHtml,'Associated Graph Templates')!==false&&strpos($viewHtml,'Template unavailable')===false,'Read-only device step shows the saved device template and graph associations');
$viewBundle['rows']=[];$viewBundle['host_template_id']=0;$viewStage=2;ob_start();require $viewTemplate;$viewHtml=ob_get_clean();check(strpos($viewHtml,'No data sources have been generated')!==false,'Read-only mode does not invent templates for an ungenerated stage');

// Optional RRD execution uses an isolated folder and never writes the installation's RRA directory.
if(getenv('ICCT_QA_RRD')==='1'){
    $rrdQaDir=sys_get_temp_dir().'/icct-mib-rrd-'.bin2hex(random_bytes(8));mkdir($rrdQaDir,0700);$config['rra_path']=$rrdQaDir;
    register_shutdown_function(function()use($rrdQaDir){foreach(glob($rrdQaDir.'/*')?:[] as $file)unlink($file);rmdir($rrdQaDir);});
}
$sourceBytes=file_get_contents(__DIR__.'/../../shared/assets/mibs/IF-MIB.txt');
foreach(['BOM'=>"\xEF\xBB\xBF".$sourceBytes,'CRLF'=>str_replace("\n","\r\n",$sourceBytes),'UTF16'=>"\xFF\xFE".iconv('UTF-8','UTF-16LE',$sourceBytes)] as $encoding=>$bytes){
    $encodedPreview=icct_mib_preview(['name'=>['IF-MIB.txt'],'stored_content'=>[$bytes]],$type);
    check(count($encodedPreview['records'])===count($preview['records']),$encoding.' MIB reparses in memory without a writable plugin folder');
}
try{icct_mib_preview(['name'=>['download.txt'],'stored_content'=>['<html>Download MIB</html>']],$type);throw new LogicException('HTML accepted');}catch(InvalidArgumentException $e){check(str_contains($e->getMessage(),'not a MIB definition'),'HTML download pages receive an actionable error');}
$activationPreview=$preview;$activationBundle=$bundle;$scalar=null;foreach($preview['records'] as $i=>$record)if($record['oid']===$bundle['rows'][0]['oid']){$scalar=$i;break;}
$directGraphId=(int)$activationBundle['rows'][0]['graph_template_id'];
db_execute_prepared('REPLACE INTO host_graph(host_id,graph_template_id) VALUES(?,?)',[$qaHost,$directGraphId]);
check(icct_backend_device_activate_imported_templates($qaHost,0,$directGraphId)===1,'Selecting a MIB graph directly creates native graph and data sources without its device template');
$activatedGraph=db_fetch_row_prepared('SELECT * FROM graph_local WHERE host_id=? AND graph_template_id=?',[$qaHost,$activationBundle['rows'][0]['graph_template_id']]);
$activatedData=db_fetch_cell_prepared('SELECT local_data_id FROM data_template_rrd WHERE id=(SELECT task_item_id FROM graph_templates_item WHERE local_graph_id=? AND task_item_id>0 LIMIT 1)',[$activatedGraph['id']]);
check((int)$activatedData>0,'Native graph links its live Cacti RRD data source');
$poller=db_fetch_row_prepared('SELECT arg1 AS oid,rrd_path,rrd_name FROM poller_item WHERE local_data_id=?',[$activatedData]);
check($poller&&$poller['oid']===$activationPreview['records'][$scalar]['oid']&&!empty($poller['rrd_path'])&&!empty($poller['rrd_name']),'Native Cacti poller contains exact MIB OID, RRD path and data source name');
check(icct_backend_device_activate_imported_templates($qaHost,$activationBundle['host_template_id'])===0,'Saving the device again does not duplicate MIB graphs or data sources');

if(getenv('ICCT_QA_RRD')==='1'){
    require_once $config['library_path'].'/rrd.php';
    $host=db_fetch_row_prepared('SELECT * FROM host WHERE id=?',[$qaHost]);
    $reading=cacti_snmp_get($host['hostname'],$host['snmp_community'],$poller['oid'],$host['snmp_version']);
    check(is_numeric($reading),'Generated MIB OID returns a numeric reading from the local SNMP device');
    $updated=rrdtool_function_update([$poller['rrd_path']=>['local_data_id'=>$activatedData,'times'=>[time()=>[$poller['rrd_name']=>$reading]]]]);
    check($updated===1&&is_file($poller['rrd_path'])&&filesize($poller['rrd_path'])>0,'Cacti creates and updates the generated MIB RRD through its native poller RRD API');
}
// Session generation must not touch native tables or query files before Add.
$sessionText="SESSION-QA-MIB DEFINITIONS ::= BEGIN\nIMPORTS OBJECT-TYPE, Integer32, enterprises FROM SNMPv2-SMI;\nsessionQa OBJECT IDENTIFIER ::= { enterprises 999997 }\nsessionValue OBJECT-TYPE\nSYNTAX Integer32\nMAX-ACCESS read-only\nSTATUS current\nDESCRIPTION \"Session-only preparation fixture.\"\n::= { sessionQa 1 }\nEND";
$sessionPreview=icct_mib_preview(['name'=>['session-qa.mib'],'stored_content'=>[$sessionText]],'__mib_unassigned__',true);
$sessionBundle=icct_mib_save($sessionPreview,icct_mib_plan($sessionPreview,['type_id'=>'__mib_unassigned__','template_name'=>'Session QA']));
$sessionSelection=icct_mib_generation_input($sessionBundle,2)['selected'];
$sessionToken=icct_mib_generation_draft_put($sessionBundle['id'],'ready',['values'=>['selected'=>$sessionSelection,'records'=>[]],'object'=>null]);
$snapshot=function(){ $state=[];foreach(['data_template','graph_templates','host_template','plugin_icct_nms_meta','snmp_query','data_local','graph_local'] as $table)$state[$table]=(int)db_fetch_cell('SELECT COUNT(*) FROM '.$table);$state['query_files']=glob(__DIR__.'/../../protocols/snmp/mibs/queries/*.xml')?:[];return $state;};
$sessionBefore=$snapshot();$events=[];$eventSink=function($event)use(&$events){$events[]=$event;};
try{icct_mib_staging_generate($sessionBundle['id'],$sessionToken,3,$eventSink);throw new LogicException('Skipped local stage accepted');}catch(InvalidArgumentException $e){check($snapshot()===$sessionBefore,'Skipping preparation stages writes nothing');}
$sessionObject=array_key_first($sessionSelection);
$_SESSION['icct_mib_generation_'.$sessionToken]['review']['values']['records'][$sessionObject]=['graph_name'=>'<not a graph name>','color'=>'invalid'];
icct_mib_staging_review($sessionBundle['id'],$sessionToken,2,['records'=>[$sessionObject=>['active'=>'0','collection_method'=>'get','ds_type'=>'1','oid'=>'1.3.6.1.4.1.999997.999.0']]]);
check(!isset($_SESSION['icct_mib_generation_'.$sessionToken]['review']['values']['records'][$sessionObject]['oid']),'Read-only polling OID ignores submitted overrides');
foreach([2,3,4] as $stage){
    $work=icct_mib_staging_generate($sessionBundle['id'],$sessionToken,$stage,$eventSink);
    $pending=icct_mib_staging_pending($sessionBundle['id']);check($pending['token']===$sessionToken&&$pending['data_sources']===1&&$snapshot()===$sessionBefore,'Prepared work is discoverable for Resume without being counted as saved templates');
    check($snapshot()===$sessionBefore,'Generate stage '.$stage.' changes only the session, with no native/meta/query-file writes');
    check(count($work['results'][$stage])===1,'Prepared stage '.$stage.' contains the actual selected definition');
    check(array_keys($work['plans'])===range(2,$stage),'Stage '.$stage.' prepares no later-stage plans');
    if($stage===2){
        check($work['plans'][2]['options']===['data'=>true,'graph'=>false,'device'=>false]&&!isset($work['plans'][2]['records'][0]['graph_name']),'Step 2 validates data sources only and defers graph settings');
        try{icct_mib_staging_generate($sessionBundle['id'],$sessionToken,3,$eventSink);throw new LogicException('Invalid graph settings accepted');}catch(InvalidArgumentException $e){check($_SESSION['icct_mib_generation_'.$sessionToken]['work']['stage']===2,'Graph errors are reported only in step 3 and preserve prepared data sources');}
        icct_mib_staging_review($sessionBundle['id'],$sessionToken,3,['records'=>[$sessionObject=>['graph_name'=>'Session QA Edited Graph','color'=>'00CF00','data_name'=>'Must not change data source']]]);
        check(!isset($_SESSION['icct_mib_generation_'.$sessionToken]['review']['values']['records'][$sessionObject]['data_name'])&&$snapshot()===$sessionBefore,'Prepared review saves only current-stage fields to the session');
    }
    if($stage===3)check($work['results'][3][0]['name']==='Session QA Edited Graph'&&$work['plans'][3]['options']['device']===false,'Step 3 uses graph changes made after data-source verification');
}
$unchangedDraft=$_SESSION['icct_mib_generation_'.$sessionToken];
icct_mib_staging_selection($sessionBundle['id'],$sessionToken,['selected'=>$sessionSelection,'records'=>[]]);
check($_SESSION['icct_mib_generation_'.$sessionToken]===$unchangedDraft&&$snapshot()===$sessionBefore,'Unchanged step 1 preserves all prepared stages without writes');
$selectionTestToken=icct_mib_generation_draft_put($sessionBundle['id'],'ready',$unchangedDraft['review']);
$_SESSION['icct_mib_generation_'.$selectionTestToken]['work']=$unchangedDraft['work'];
icct_mib_staging_selection($sessionBundle['id'],$selectionTestToken,['selected'=>[],'records'=>[]]);
check($_SESSION['icct_mib_generation_'.$selectionTestToken]['work']['stage']===1&&$_SESSION['icct_mib_generation_'.$selectionTestToken]['selection_changed']&&$snapshot()===$sessionBefore,'Changed step 1 invalidates preparation only in the session');
unset($_SESSION['icct_mib_generation_'.$selectionTestToken]);
check(count(array_filter($events,fn($event)=>$event['event']==='item'))===3,'Live progress emits one actual prepared item per stage');
$preparedData=$work['results'][2];
$_SESSION['icct_mib_generation_'.$sessionToken]['review']['values']['records'][$sessionObject]['graph_name']='Session QA Regenerated Graph';
$work=icct_mib_staging_generate($sessionBundle['id'],$sessionToken,3,$eventSink);
check($work['results'][2]===$preparedData&&!isset($work['results'][4],$work['plans'][4])&&$work['stage']===3,'Regenerating graph settings preserves data sources and invalidates the later device step');
check($work['stage']===3&&$snapshot()===$sessionBefore,'Prepared graph stage is available for Add without creating a device template');
icct_mib_staging_generate($sessionBundle['id'],$sessionToken,4,$eventSink);

$sessionPlan=$_SESSION['icct_mib_generation_'.$sessionToken]['work']['plans'][4];
$_SESSION['icct_mib_generation_'.$sessionToken]['work']['plans'][4]['name']='Changed session name';
try{icct_mib_staging_commit($sessionBundle['id'],$sessionToken);throw new LogicException('Changed preview accepted');}catch(InvalidArgumentException $e){check($snapshot()===$sessionBefore,'Changed preparation is rejected before any Add writes');}
$_SESSION['icct_mib_generation_'.$sessionToken]['work']['plans'][4]=$sessionPlan;
// A previously prepared draft may predate automatic Class/query resolution.
$_SESSION['icct_mib_generation_'.$sessionToken]['work']['plans'][4]['device_template']['class']='';
$_SESSION['icct_mib_generation_'.$sessionToken]['work']['plans'][4]['device_template']['queries']=[];
$_SESSION['icct_mib_generation_'.$sessionToken]['work']['plans'][4]['device_template']['legacy_default']=true;
$sessionSaved=icct_mib_staging_commit($sessionBundle['id'],$sessionToken);$sessionAfter=$snapshot();check(icct_mib_staging_pending($sessionBundle['id'])===null,'Saving removes the prepared Resume state');
check($sessionSaved['host_template_id']>0,'Save refreshes automatic device defaults from an older prepared draft without invalidating data and graphs');
check($sessionAfter['data_template']===$sessionBefore['data_template']+1&&$sessionAfter['graph_templates']===$sessionBefore['graph_templates']+1&&$sessionAfter['host_template']===$sessionBefore['host_template']+1,'Final Add creates all native template types');
check($sessionAfter['data_local']===$sessionBefore['data_local']&&$sessionAfter['graph_local']===$sessionBefore['graph_local'],'Final Add creates no live device instances');
check($sessionSaved['rows'][0]['settings']['active']==='0'&&$sessionSaved['rows'][0]['settings']['resolved_method']==='get','Data source editor active and collection settings reach saved definitions');
check($sessionSaved['wizard_step']===3&&count($sessionSaved['rows'])===1,'Final Add saves completed bundle associations');
try{icct_mib_staging_commit($sessionBundle['id'],$sessionToken);throw new LogicException('Repeated Add accepted');}catch(InvalidArgumentException $e){check($snapshot()===$sessionAfter,'Repeated Add cannot create duplicates');}

$cancelBefore=$snapshot();
$cancelPreview=icct_mib_preview(['name'=>['cancel-qa.mib'],'stored_content'=>[str_replace(['SESSION-QA-MIB','999997'],['UPLOAD-CANCEL-QA-MIB','999994'],$sessionText)]],'__mib_unassigned__',true);
$cancelToken=bin2hex(random_bytes(16));icct_mib_upload_session_store($cancelToken,$cancelPreview);
check($snapshot()===$cancelBefore,'Reading and staging an upload writes no repository records, native templates or query files');
icct_mib_upload_session_discard($cancelToken);
try{icct_mib_upload_session_commit($cancelToken,false);throw new LogicException('Cancelled file was saved');}catch(InvalidArgumentException $e){check($snapshot()===$cancelBefore,'Cancelled upload cannot be saved later');}
$saveToken=bin2hex(random_bytes(16));icct_mib_upload_session_store($saveToken,$cancelPreview);
$savedUpload=icct_mib_upload_session_commit($saveToken,false);$afterUploadSave=$snapshot();
check(count(icct_mib_list())>0&&icct_mib_wizard_get($savedUpload['id'])['files'][0]['name']==='cancel-qa.mib','Only explicit Save adds the pending file to the repository');
foreach(['data_template','graph_templates','host_template','snmp_query','data_local','graph_local'] as $table)check($afterUploadSave[$table]===$cancelBefore[$table],'Saving the file alone does not create '.$table.' records');
check(icct_mib_upload_session_commit($saveToken,false)['id']===$savedUpload['id']&&$snapshot()===$afterUploadSave,'Retrying explicit Save returns the same upload without duplicate writes');
$expiredToken=bin2hex(random_bytes(16));icct_mib_upload_session_store($expiredToken,$cancelPreview);$_SESSION['icct_mib_uploads'][$expiredToken]['created']=time()-3601;
try{icct_mib_upload_session_commit($expiredToken,false);throw new LogicException('Expired upload accepted');}catch(InvalidArgumentException $e){check($snapshot()===$afterUploadSave,'Expired pending files cannot be saved');}
// Repository identity is independent of filenames; commit repeats the check under lock.
$duplicateText=str_replace(['SESSION-QA-MIB','999997'],['REPO-DUP-QA-MIB','999993'],$sessionText);
$duplicatePreview=icct_mib_preview(['name'=>['original.mib'],'stored_content'=>[$duplicateText]],'__mib_unassigned__',true);
icct_mib_save($duplicatePreview,icct_mib_plan($duplicatePreview,['type_id'=>'__mib_unassigned__','template_name'=>'Repository duplicate QA']));
$renamed=$duplicatePreview;$renamed['id']=bin2hex(random_bytes(16));$renamed['files'][0]['name']='renamed.txt';
check(icct_mib_repository_comparison($renamed)['blocked'],'Exact bytes under a new filename are blocked during repository comparison');
$duplicateToken=bin2hex(random_bytes(16));icct_mib_upload_session_store($duplicateToken,$renamed);$beforeDuplicate=$snapshot();
try{icct_mib_upload_session_commit($duplicateToken,false);throw new LogicException('Exact repository duplicate saved');}catch(IcctMibRepositoryConflict $e){check($e->comparison['blocked']&&$snapshot()===$beforeDuplicate,'Save rejects exact duplicates without writing any records');}
$variantText=str_replace('Session-only preparation fixture.','Changed repository variant.',$duplicateText);
$variantPreview=icct_mib_preview(['name'=>['revision.mib'],'stored_content'=>[$variantText]],'__mib_unassigned__',true);
$variantComparison=icct_mib_repository_comparison($variantPreview);check($variantComparison['blocked']&&$variantComparison['conflicts'][0]['changed_oids']===1,'Same-module changed content is blocked with the changed OID details');
$variantToken=bin2hex(random_bytes(16));icct_mib_upload_session_store($variantToken,$variantPreview);
try{icct_mib_upload_session_commit($variantToken,false);throw new LogicException('Unacknowledged variant saved');}catch(IcctMibRepositoryConflict $e){check($snapshot()===$beforeDuplicate,'Variant cannot save without acknowledging its current warning');}
try{icct_mib_upload_session_commit($variantToken,false,$variantComparison['fingerprint']);throw new LogicException('Module uniqueness bypassed');}catch(IcctMibRepositoryConflict $e){check($e->comparison['blocked']&&$snapshot()===$beforeDuplicate,'Acknowledgment cannot create a second repository entry for the same module');}
$raceText=str_replace(['SESSION-QA-MIB','999997'],['REPO-RACE-QA-MIB','999992'],$sessionText);
$race=icct_mib_preview(['name'=>['race.mib'],'stored_content'=>[$raceText]],'__mib_unassigned__',true);$raceToken=bin2hex(random_bytes(16));icct_mib_upload_session_store($raceToken,$race);
$other=$race;$other['id']=bin2hex(random_bytes(16));icct_mib_save($other,icct_mib_plan($other,['type_id'=>'__mib_unassigned__','template_name'=>'Concurrent repository QA']));$beforeRace=$snapshot();
try{icct_mib_upload_session_commit($raceToken,false);throw new LogicException('Concurrent duplicate accepted');}catch(IcctMibRepositoryConflict $e){check($e->comparison['blocked']&&$snapshot()===$beforeRace,'A duplicate introduced after file reading is blocked at final Save');}
icct_mib_set_deleted($other['id'],true);
$replacement=$race;$replacement['id']=bin2hex(random_bytes(16));icct_mib_save($replacement,icct_mib_plan($replacement,['type_id'=>'__mib_unassigned__','template_name'=>'Replacement repository QA']));$beforeRestore=$snapshot();
try{icct_mib_set_deleted($other['id'],false,true);throw new LogicException('Undo restored a duplicate module');}catch(IcctMibRepositoryConflict $e){check($e->comparison['blocked']&&$snapshot()===$beforeRestore,'Undo cannot reintroduce a second entry for an existing module');}
icct_mib_set_deleted($savedUpload['id'],true);icct_mib_set_deleted($savedUpload['id'],false,true);check(icct_mib_wizard_get($savedUpload['id'])['id']===$savedUpload['id'],'Undo still restores a unique repository entry');
// Combined graphs keep every source's native graph inputs and indexed output mapping.
$groupRecords=[];
foreach($autoPlan['records'] as $r)if($r['table']&&preg_replace('/\.[0-9]+$/','',$r['base_oid'])==='1.3.6.1.2.1.2.2.1'){$r['graph_name']='Grouped Interface QA';$r['data_name'].=' Grouped QA';$groupRecords[]=$r;if(count($groupRecords)===2)break;}
check(count($groupRecords)===2,'Two real IF-MIB columns selected for combined graph testing');
check(count(icct_mib_graph_groups($groupRecords))===1,'Two selected sources with a shared graph name prepare one graph template');
$separate=$groupRecords;$separate[1]['graph_name'].=' Separate';check(count(icct_mib_graph_groups($separate))===2,'Separate source names prepare two independent graph templates');
$incompatible=$groupRecords;$incompatible[1]['base_oid']='1.3.6.1.4.1.32473.99.1';try{icct_mib_graph_groups($incompatible);throw new LogicException('Incompatible indexes combined');}catch(InvalidArgumentException $e){check(true,'Combined indexed graphs reject different table indexes');}
$queryFilesBefore=array_merge(glob(__DIR__.'/../../protocols/snmp/mibs/queries/*.xml')?:[],glob($config['base_path'].'/resource/snmp_queries/icct_nms/*.xml')?:[]);
$graphsBefore=(int)db_fetch_cell('SELECT COUNT(*) FROM graph_templates');
$groupRecords[0]['graph_items']=[array_replace(array_map(fn($f)=>$f['default'],icct_mib_core_graph_item_fields()),['text_format'=>'Grouped extra item QA'])];
$groupEntries=icct_mib_native_graph_group(icct_mib_graph_groups($groupRecords)[0]);
$testQueryFiles=array_merge($testQueryFiles,array_diff(array_merge(glob(__DIR__.'/../../protocols/snmp/mibs/queries/*.xml')?:[],glob($config['base_path'].'/resource/snmp_queries/icct_nms/*.xml')?:[]),$queryFilesBefore));
$groupGraph=$groupEntries[0]['pair']['graph_template_id'];$queryGraph=$groupEntries[0]['pair']['snmp_query_graph_id'];
check((int)db_fetch_cell('SELECT COUNT(*) FROM graph_templates')===$graphsBefore+1&&$groupEntries[1]['pair']['graph_template_id']===$groupGraph,'Native combined generation creates exactly one graph template for two data templates');
check((int)db_fetch_cell_prepared('SELECT COUNT(DISTINCT task_item_id) FROM graph_templates_item WHERE graph_template_id=? AND task_item_id>0',[$groupGraph])===2,'Combined graph items reference both native RRD definitions');
check((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_templates_item WHERE graph_template_id=? AND text_format=?',[$groupGraph,'Grouped extra item QA'])===1,'Prepared additional item persists through combined native graph creation');
check((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM snmp_query_graph_rrd WHERE snmp_query_graph_id=?',[$queryGraph])===2,'Combined indexed graph maps both outputs to their respective data sources');
$queryPath=db_fetch_cell_prepared('SELECT xml_path FROM snmp_query WHERE id=?',[$groupEntries[0]['pair']['snmp_query_id']]);$xml=simplexml_load_file(str_replace('<path_cacti>',$config['base_path'],$queryPath));
check(isset($xml->fields->source0,$xml->fields->source1)&&strval($xml->fields->source0->oid)!==strval($xml->fields->source1->oid),'Native combined query exports each selected OID independently under a shared index');
check((int)db_fetch_cell('SELECT COUNT(*) FROM graph_template_input_defs d LEFT JOIN graph_templates_item i ON i.id=d.graph_template_item_id WHERE i.id IS NULL')===0,'Combined graph input definitions retain valid item references');
// Device step retains independent multi-graph selection and links exactly that subset.
$deviceText=str_replace(['SESSION-QA-MIB','999997'],['DEVICE-GRAPH-QA-MIB','999991'],$sessionText);
$extra="\nsecondValue OBJECT-TYPE\nSYNTAX Integer32\nMAX-ACCESS read-only\nSTATUS current\nDESCRIPTION \"Second value.\"\n::= { sessionQa 2 }\nthirdValue OBJECT-TYPE\nSYNTAX Integer32\nMAX-ACCESS read-only\nSTATUS current\nDESCRIPTION \"Third value.\"\n::= { sessionQa 3 }\nEND";
$deviceText=preg_replace('/END$/',$extra,$deviceText);
$devicePreview=icct_mib_preview(['name'=>['device-graph-qa.mib'],'stored_content'=>[$deviceText]],'__mib_unassigned__',true);
$deviceBundle=icct_mib_save($devicePreview,icct_mib_plan($devicePreview,['type_id'=>'__mib_unassigned__','template_name'=>'Device Multi Graph QA']));
$deviceSelection=icct_mib_generation_input($deviceBundle,2)['selected'];$deviceToken=icct_mib_generation_draft_put($deviceBundle['id'],'ready',['values'=>['selected'=>$deviceSelection,'records'=>[]],'object'=>null]);
icct_mib_staging_generate($deviceBundle['id'],$deviceToken,2,$eventSink);$deviceWork=icct_mib_staging_generate($deviceBundle['id'],$deviceToken,3,$eventSink);$graphNames=array_column($deviceWork['results'][3],'name');
$deviceBefore=$snapshot();
$coreGraphId=(int)db_fetch_cell("SELECT id FROM graph_templates WHERE name='SNMP - Generic OID Template'");
check(isset(icct_mib_device_graph_options($deviceBundle,$deviceWork['plans'][3]['records'])['core:'.$coreGraphId]),'Device graph choices include installed native Cacti graph templates by ID');
$nativeDeviceFields=icct_mib_device_core_fields();$chosenClass=(string)array_key_first($nativeDeviceFields['class']['choices']);$chosenQuery=(int)array_key_first(icct_mib_device_queries());
icct_mib_staging_review($deviceBundle['id'],$deviceToken,4,['device_name'=>'Device Selected Graphs QA','device_class'=>$chosenClass,'device_queries'=>json_encode([$chosenQuery]),'device_graphs'=>json_encode([$graphNames[0],$graphNames[2],'core:'.$coreGraphId])]);
$deviceWork=icct_mib_staging_generate($deviceBundle['id'],$deviceToken,4,$eventSink);
check($snapshot()===$deviceBefore&&count($deviceWork['plans'][4]['device_template']['graphs'])===2,'Device preparation retains two selected graph templates in the session without database writes');
try{icct_mib_staging_review($deviceBundle['id'],$deviceToken,4,['device_name'=>'Device Selected Graphs QA','device_graphs'=>'[]']);throw new LogicException('Empty device graph selection accepted');}catch(InvalidArgumentException $e){check(true,'Device template requires at least one prepared graph');}
try{icct_mib_staging_review($deviceBundle['id'],$deviceToken,4,['device_name'=>'Device Selected Graphs QA','device_graphs'=>'["unprepared"]']);throw new LogicException('Foreign graph accepted');}catch(InvalidArgumentException $e){check(true,'Device template rejects graph names outside this prepared batch');}
$deviceSaved=icct_mib_staging_commit($deviceBundle['id'],$deviceToken);
check(count($deviceSaved['rows'])===3&&(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM host_template_graph WHERE host_template_id=?',[$deviceSaved['host_template_id']])===3,'One device template links the two prepared selections and one existing Cacti graph without copying it');
check(db_fetch_cell_prepared('SELECT name FROM host_template WHERE id=?',[$deviceSaved['host_template_id']])==='Device Selected Graphs QA','Device template uses its reviewed name');
check((bool)db_fetch_cell_prepared('SELECT graph_template_id FROM host_template_graph WHERE host_template_id=? AND graph_template_id=?',[$deviceSaved['host_template_id'],$coreGraphId]),'Core graph selection links the original native graph ID');
check(db_fetch_cell_prepared('SELECT class FROM host_template WHERE id=?',[$deviceSaved['host_template_id']])===$chosenClass,'Device Class selected from Cacti core is saved in host_template.class');
check((bool)db_fetch_cell_prepared('SELECT snmp_query_id FROM host_template_snmp_query WHERE host_template_id=? AND snmp_query_id=?',[$deviceSaved['host_template_id'],$chosenQuery]),'Selected native data query is associated with the saved device template');
$coreDefaults=icct_mib_device_core_defaults($deviceSaved,[]);check($coreDefaults['class']===$chosenClass&&in_array($chosenQuery,$coreDefaults['queries'],true),'Saved native Class and data query selections prefill the device editor');
check($coreDefaults['name']==='Device Selected Graphs QA'&&in_array('core:'.$coreGraphId,$coreDefaults['graphs'],true),'Existing device template name and saved core graph associations prefill dynamically');
$graphOnlyText=str_replace(['SESSION-QA-MIB','999997'],['GRAPH-ONLY-QA-MIB','999990'],$sessionText);
$graphOnlyPreview=icct_mib_preview(['name'=>['graph-only-qa.mib'],'stored_content'=>[$graphOnlyText]],'__mib_unassigned__',true);
$graphOnlyBundle=icct_mib_save($graphOnlyPreview,icct_mib_plan($graphOnlyPreview,['type_id'=>'__mib_unassigned__','template_name'=>'Graph Only QA']));
$graphOnlyToken=icct_mib_generation_draft_put($graphOnlyBundle['id'],'ready',['values'=>['selected'=>icct_mib_generation_input($graphOnlyBundle,2)['selected'],'records'=>[]],'object'=>null]);
icct_mib_staging_generate($graphOnlyBundle['id'],$graphOnlyToken,2,$eventSink);icct_mib_staging_generate($graphOnlyBundle['id'],$graphOnlyToken,3,$eventSink);
$graphOnlyFields=icct_mib_core_graph_fields();$graphOnlyDefaults=icct_mib_core_graph_defaults('Graph Only QA','');
check(isset($graphOnlyFields['image_format_id']['choices'],$graphOnlyFields['legend_position']['choices'],$graphOnlyFields['right_axis_formatter']['choices']),'Graph image, legend and axis choices load from installed Cacti form definitions');
$graphOnlyDefaults['width']='812';$graphOnlyDefaults['height']='240';$graphOnlyDefaults['base_value']='1024';$graphOnlyDefaults['auto_scale']='';$graphOnlyDefaults['multiple']='';$graphOnlyDefaults['test_source']='on';
$graphOnlyIndex=array_key_first(icct_mib_generation_input($graphOnlyBundle,2)['selected']);
icct_mib_staging_review($graphOnlyBundle['id'],$graphOnlyToken,3,['records'=>[$graphOnlyIndex=>['graph_options'=>json_encode($graphOnlyDefaults)]]]);icct_mib_staging_generate($graphOnlyBundle['id'],$graphOnlyToken,3,$eventSink);
try{icct_mib_graph_options(['image_format_id'=>'99999'],'QA','');throw new LogicException('Uninstalled image format accepted');}catch(InvalidArgumentException $e){check(true,'Graph options reject values not offered by installed Cacti');}
$beforeGraphOnly=$snapshot();$graphOnlySaved=icct_mib_staging_commit($graphOnlyBundle['id'],$graphOnlyToken);$afterGraphOnly=$snapshot();
check($afterGraphOnly['data_template']===$beforeGraphOnly['data_template']+1&&$afterGraphOnly['graph_templates']===$beforeGraphOnly['graph_templates']+1&&$afterGraphOnly['host_template']===$beforeGraphOnly['host_template'],'Add after step 3 saves data and graph templates without requiring or creating a device template');
$nativeGraphOptions=db_fetch_row_prepared('SELECT width,height,base_value,auto_scale FROM graph_templates_graph WHERE graph_template_id=? AND local_graph_id=0',[$graphOnlySaved['rows'][0]['graph_template_id']]);check((int)$nativeGraphOptions['width']===812&&(int)$nativeGraphOptions['height']===240&&(int)$nativeGraphOptions['base_value']===1024&&$nativeGraphOptions['auto_scale']==='','Reviewed graph options persist to native Cacti graph template fields');
$nativeFlags=db_fetch_row_prepared('SELECT multiple,test_source FROM graph_templates WHERE id=?',[$graphOnlySaved['rows'][0]['graph_template_id']]);check($nativeFlags['multiple']===''&&$nativeFlags['test_source']==='on','Multiple Instances and Test Data Sources switches persist to native graph template flags');
check($graphOnlySaved['wizard_step']===2&&empty($graphOnlySaved['host_template_id']),'Graph-only save retains its correct completed stage');
$skipText=str_replace(['SESSION-QA-MIB','999997'],['OPTIONAL-DEVICE-QA-MIB','999989'],$sessionText);
$skipPreview=icct_mib_preview(['name'=>['optional-device-qa.mib'],'stored_content'=>[$skipText]],'__mib_unassigned__',true);
$skipBundle=icct_mib_save($skipPreview,icct_mib_plan($skipPreview,['type_id'=>'__mib_unassigned__','template_name'=>'Optional Device QA']));
$skipToken=icct_mib_generation_draft_put($skipBundle['id'],'ready',['values'=>['selected'=>icct_mib_generation_input($skipBundle,2)['selected'],'records'=>[]],'object'=>null]);foreach([2,3,4] as $stage)icct_mib_staging_generate($skipBundle['id'],$skipToken,$stage,$eventSink);
$skipBefore=$snapshot();$skipSaved=icct_mib_staging_commit($skipBundle['id'],$skipToken,false);$skipAfter=$snapshot();
check($skipAfter['host_template']===$skipBefore['host_template']&&empty($skipSaved['host_template_id'])&&$skipSaved['wizard_step']===2,'Optional device step can be skipped even after preparation without creating a device template');
check($skipAfter['data_template']===$skipBefore['data_template']+1&&$skipAfter['graph_templates']===$skipBefore['graph_templates']+1,'Skipping the device template still saves prepared data source and graph templates');
try{icct_mib_device_details(str_repeat('X',$nativeDeviceFields['name']['max_length']+1),$chosenClass);throw new LogicException('Oversized native device name accepted');}catch(InvalidArgumentException $e){check(true,'Device template name obeys the installed native schema length');}
try{icct_mib_device_details('QA','unavailable-core-class');throw new LogicException('Unknown class accepted');}catch(InvalidArgumentException $e){check(true,'Device Class rejects values outside installed Cacti choices');}
$classChoices=icct_mib_device_core_fields()['class']['choices'];$autoKey=(string)array_key_first($classChoices);
$autoBundle=['name'=>'Automatic defaults QA','type_id'=>'__auto_test__','type_name'=>$autoKey,'host_template_id'=>0,'rows'=>[]];
check(icct_mib_device_core_defaults($autoBundle,[])['class']===$autoKey,'New device Class automatically matches an installed Cacti choice from saved device type');
$autoBundle['rows']=[['graph_template_id'=>$coreGraphId]];
check(in_array('core:'.$coreGraphId,icct_mib_device_core_defaults($autoBundle,[])['graphs'],true),'Saved MIB native graph is automatically selected by identity');
$nativeQueryPath=(string)db_fetch_cell_prepared('SELECT xml_path FROM snmp_query WHERE id=?',[(int)$groupEntries[0]['pair']['snmp_query_id']]);
check(str_starts_with($nativeQueryPath,'<path_cacti>/resource/snmp_queries/icct_nms/')&&is_file(str_replace('<path_cacti>',$config['base_path'],$nativeQueryPath)),'Indexed query saves directly to the native Cacti resource directory');
$queryOptions=icct_mib_device_graph_options([], $groupRecords);
check($queryOptions['Grouped Interface QA']['pending_queries']===['Grouped Interface QA'],'Prepared indexed MIB graph exposes its required query before any native save');
check(icct_mib_device_required_queries([],[],['core:'.$groupGraph])===[(int)$groupEntries[0]['pair']['snmp_query_id']],'Selected native indexed graph automatically resolves its real Cacti data query');
check(icct_mib_device_required_queries([],[],[])===[],'Unselected graphs do not add unrelated native data queries');
$itemFields=icct_mib_core_graph_item_fields();
check(isset($itemFields['graph_type_id']['choices'],$itemFields['cdef_id']['choices'],$itemFields['gprint_id']['choices']),'Graph item editor loads native Cacti type and preset choices');
$extraDefaults=array_map(fn($f)=>$f['default'],$itemFields);$extraDefaults['text_format']='Added item QA';
$extraItem=icct_mib_graph_items([$extraDefaults])[0];$beforeItemCount=(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_templates_item WHERE graph_template_id=?',[$groupGraph]);
$extraRrd=(int)db_fetch_cell_prepared('SELECT id FROM data_template_rrd WHERE data_template_id=? AND local_data_id=0',[$groupEntries[0]['pair']['data_template_id']]);
icct_mib_native_add_graph_items($groupGraph,$extraRrd,[$extraItem]);
check((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_templates_item WHERE graph_template_id=?',[$groupGraph])===$beforeItemCount+1,'Add Item persists a native graph template item');
$extraId=(int)db_fetch_cell_prepared('SELECT id FROM graph_templates_item WHERE graph_template_id=? AND text_format=? AND task_item_id=?',[$groupGraph,'Added item QA',$extraRrd]);
check($extraId>0&&(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_template_input_defs WHERE graph_template_item_id=?',[$extraId])>0,'Added graph item retains its native RRD source and graph input mapping');
try{icct_mib_graph_items([array_replace($extraItem,['cdef_id'=>'999999999'])]);throw new LogicException('Invalid item preset accepted');}catch(InvalidArgumentException $e){check(true,'Graph items reject presets outside installed Cacti options');}
check(!icct_mib_can_finish(['wizard_step'=>0,'host_template_id'=>0]),'Finish remains disabled for a prepared unsaved configuration');
check(icct_mib_can_finish($deviceSaved),'Finish is available after saving the native device template');
check(str_contains(icct_mib_saved_destination($deviceSaved,$sessionToken),'finish=1'),'Successful device Save with its ready token opens the Finish confirmation page');
check(str_ends_with(icct_mib_saved_destination($deviceSaved),'?saved=1'),'Saved device without a preparation token returns to the repository');
$viewBundle=$deviceSaved;$viewStage=4;$_GET['finish']=1;ob_start();require $viewTemplate;$finishHtml=ob_get_clean();unset($_GET['finish']);
check(str_contains($finishHtml,'data-mib-finish')&&str_contains($finishHtml,'Templates saved successfully'),'Saved device configuration renders the enabled Finish action and success message');
echo "MIB repository integration passed in temporary tables.\n";

} catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");$failed=true;}
finally{foreach($testQueryFiles as $file)if(is_file($file))unlink($file);}
if(!empty($failed))exit(1);
