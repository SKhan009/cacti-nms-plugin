<?php
/** Native parser/template QA in connection-local temporary tables. No persistent templates or hosts. */
if(PHP_SAPI!=='cli')exit(1);
define('IN_CACTI_INSTALL',true);require '/var/www/html/cacti/include/cli_check.php';api_plugin_load_realms();require_once $config['base_path'].'/lib/auth.php';
require_once __DIR__ . '/../../shared/services/bootstrap.php';icct_nms_backend();require_once __DIR__ . '/../../presets/services/device_type_service.php';require_once __DIR__ . '/../../protocols/snmp/mibs/services/mib_repository_service.php';
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
check(icct_mib_list()[0]['rows']===$bundle['rows'],'Chunked template associations reload from database');
check(icct_mib_objects($bundle)===$preview['records'],'Full object metadata remains available after saving');
check(icct_mib_file($bundle,0)===file_get_contents($path),'Original uploaded MIB retained with checksum verification');
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
icct_mib_wizard_reparse($stored['id'],$resolved);
check(icct_mib_wizard_get($stored['id'])['object_count']===1&&icct_mib_file($stored,0)===$main,'Dependency reparse refreshes saved file in place');


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
check(icct_backend_device_activate_imported_templates($qaHost,$activationBundle['host_template_id'])===1,'Applying MIB device template creates a native Cacti graph');
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
echo "MIB repository integration passed in temporary tables.\n";

} catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");$failed=true;}
finally{foreach($testQueryFiles as $file)if(is_file($file))unlink($file);}
if(!empty($failed))exit(1);
