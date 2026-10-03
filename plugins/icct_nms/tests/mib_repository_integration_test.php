<?php
/** Native parser/template QA in connection-local temporary tables. No persistent templates or hosts. */
if(PHP_SAPI!=='cli')exit(1);
define('IN_CACTI_INSTALL',true);require '/var/www/html/cacti/include/cli_check.php';api_plugin_load_realms();require_once $config['base_path'].'/lib/auth.php';
require_once __DIR__.'/../includes/bootstrap.php';icct_nms_backend();require_once __DIR__.'/../includes/device_type_service.php';require_once __DIR__.'/../includes/mib_repository_service.php';
$_SESSION=['sess_user_id'=>1];
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
foreach(['host_template','host_template_graph','data_template','data_template_data','data_template_rrd','data_input_data','graph_templates','graph_templates_graph','graph_templates_item','graph_template_input','graph_template_input_defs','plugin_icct_nms_meta','settings','colors'] as $table){
 if(!db_execute("CREATE TEMPORARY TABLE qa_mib_copy LIKE `$table`"))throw new RuntimeException('Isolation failed');db_execute("INSERT INTO qa_mib_copy SELECT * FROM `$table`");db_execute("CREATE TEMPORARY TABLE `$table` LIKE qa_mib_copy");db_execute("INSERT INTO `$table` SELECT * FROM qa_mib_copy");db_execute('DROP TEMPORARY TABLE qa_mib_copy');
}
$types=icct_nms_device_types();$type=array_key_first($types);$path=__DIR__.'/../assets/mibs/IF-MIB.txt';
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
foreach($preview['records'] as $i=>$r)if($r['numeric']&&$r['table']){$bad=$input;$bad['selected']=[$i=>1];$bad['records']=[$i=>['oid'=>$r['base_oid']]];try{icct_mib_plan($preview,$bad);throw new LogicException('Unindexed column accepted');}catch(InvalidArgumentException $e){check(str_contains($e->getMessage(),'instance'),'Table column without index rejected');}break;}
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
foreach([['data'=>true,'graph'=>false,'device'=>false],['data'=>false,'graph'=>false,'device'=>true],['data'=>false,'graph'=>false,'device'=>false]] as $n=>$options){$p=$preview;$p['id']=bin2hex(random_bytes(16));$q=$plan;$q['options']=$options;$q['name']='MIB QA Mode '.$n;foreach($q['records'] as &$r){$r['data_name'].=' '.$n;$r['graph_name'].=' '.$n;}unset($r);$b=icct_mib_save($p,$q);check((bool)$b['host_template_id']===$options['device']&&(bool)$b['rows'][0]['data_template_id']===$options['data']&&!$b['rows'][0]['graph_template_id'],'Selected creation options respected: '.$n);}
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
  $args=[read_config_option('path_snmptranslate')?:'snmptranslate','-M',__DIR__.'/../assets/mibs','-m',$module,'-Tz'];
  check(icct_mib_command($args)!=='',$module.' and all its imports resolve using only the plugin folder');
 }
}finally{foreach(glob($fixtureDir.'/*')?:[] as $file)unlink($file);rmdir($fixtureDir);}
$expired=$preview;$expired['created']=time()-3601;try{icct_mib_save($expired,$plan);throw new LogicException('Expired accepted');}catch(InvalidArgumentException $e){check(str_contains($e->getMessage(),'expired'),'Expired review rejected');}
echo "MIB repository integration passed in temporary tables.\n";
