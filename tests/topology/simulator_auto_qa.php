<?php
/** Explicit live QA fixture; staging and inspection are separate from the systemd worker. */
if(PHP_SAPI!=='cli')exit(2);
$o=getopt('',array('cacti-root:','mode:'));
if(empty($o['cacti-root'])||!in_array($o['mode']??'',array('stage','verify'),true))exit(2);
require($o['cacti-root'].'/include/cli_check.php');
require_once(dirname(__DIR__, 2).'/plugins/topology/includes/simulator.php');
$_SESSION=array('sess_user_id'=>(int)read_config_option('admin_user'));
function qa($ok,$message){if(!$ok)throw new RuntimeException($message);echo 'PASS: '.$message.PHP_EOL;}
try{
 $name='Topology Upload QA 0.11.0';$community='topology-upload-qa-0110';$fixture=file_get_contents(__DIR__.'/fixtures/qa-mixed-oids.snmprec');
 $records=tp_records($fixture);$metrics=array_filter($records,function($r){return $r['metric'];});qa(count($records)===11&&count($metrics)===5,'11 records contain exactly 5 graphable numeric OIDs');
 $r=db_fetch_row_prepared('SELECT * FROM plugin_topology_imports WHERE community=?',array($community));
 if($o['mode']==='stage'){
  if(!$r){$category=db_fetch_cell('SELECT id FROM plugin_topology_categories WHERE active=1 ORDER BY id LIMIT 1');$id=tp_sim_stage($name,tp_scope_id(),$category,$community,$fixture);echo 'Queued QA import '.$id.PHP_EOL;}else echo 'QA import already staged'.PHP_EOL;
  exit;
 }
 qa($r&&$r['auto_provision']&&$r['state']==='provisioned','automatic worker provisioned upload');$id=(int)$r['id'];$host=(int)$r['host_id'];
 qa(tp_sim_probe($r,tp_sim_config())==='topology-upload-qa-0110','live simulator identity matches record');
 $sources=db_fetch_assoc_prepared('SELECT o.oid,o.object_id,d.data_template_id FROM plugin_topology_objects o JOIN data_local d ON d.id=o.object_id WHERE o.import_id=? AND o.object_type="data_source" ORDER BY o.oid',array($id));
 qa(count($sources)===5,'exactly 5 native data sources');
 foreach($sources as $ds){
  qa(isset($metrics[$ds['oid']]),'data source maps to a metric OID: '.$ds['oid']);
  $oid=db_fetch_cell_prepared('SELECT i.value FROM data_input_data i JOIN data_template_data d ON d.id=i.data_template_data_id JOIN data_input_fields f ON f.id=i.data_input_field_id WHERE d.local_data_id=? AND f.data_name="oid"',array($ds['object_id']));
  qa($oid===$ds['oid'],'native OID input matches record');
  $type=db_fetch_cell_prepared('SELECT data_source_type_id FROM data_template_rrd WHERE local_data_id=?',array($ds['object_id']));
  qa((int)$type===(in_array($metrics[$oid]['tag'],array('65','70'))?2:1),'native Counter/Gauge type matches ASN.1 tag');
 }
 qa((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_local WHERE host_id=?',array($host))===5,'exactly 5 native graphs');
 $before=db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_topology_objects WHERE import_id=?',array($id));
 qa(tp_sim_provision($id)===$host,'repeated provision returns same host');
 qa($before===db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_topology_objects WHERE import_id=?',array($id)),'retry creates no duplicates');
 try{tp_sim_stage('Duplicate QA',tp_scope_id(),$r['category_id'],'topology-duplicate-0110',$fixture);throw new Exception('Duplicate accepted');}catch(InvalidArgumentException $e){echo 'PASS: duplicate upload rejected'.PHP_EOL;}
 echo 'QA HOST '.$host.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL);exit(1);}
