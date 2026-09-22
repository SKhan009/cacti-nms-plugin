<?php
/** Read-only/idempotent integration checks against the explicitly configured Phase 1 lab. */
if(PHP_SAPI!=='cli') { http_response_code(404);exit; }
$options=getopt('',array('cacti-root:','probe-must-fail','restricted-user-id:'));
if(empty($options['cacti-root'])) die("Supply --cacti-root explicitly.\n");
require($options['cacti-root'].'/include/cli_check.php');
require_once($options['cacti-root'].'/plugins/topology/includes/simulator.php');
try {
$_SESSION['sess_user_id']=(int)read_config_option('admin_user');
function verify($value,$message) { if(!$value) throw new RuntimeException($message); echo 'PASS: '.$message."\n"; }
function fails($fn,$message) { try{$fn();}catch(InvalidArgumentException $e){echo 'PASS: '.$message."\n";return;}catch(RuntimeException $e){echo 'PASS: '.$message."\n";return;}throw new RuntimeException('Expected rejection: '.$message); }
$rows=db_fetch_assoc('SELECT * FROM plugin_topology_imports WHERE state="provisioned" ORDER BY id');
verify(count($rows)===2,'two explicit lab imports exist');
$c=tp_sim_config();
if(isset($options['probe-must-fail'])) {
 foreach($rows as $row) fails(function()use($row,$c){tp_sim_probe($row,$c);},'stopped simulator produces a failed live check for '.$row['id']);
 exit;
}
$before=array();foreach(array('host','graph_local','data_local','host_template','data_template','graph_templates','plugin_topology_objects')as$table)$before[$table]=(int)db_fetch_cell('SELECT COUNT(*) FROM '.$table);
foreach($rows as $row) {
 verify(tp_sim_probe($row,$c)!=='','live SNMP identity for import '.$row['id']);
 verify(tp_sim_provision($row['id'])===(int)$row['host_id'],'repeated provisioning returns same device');
 $count=(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_topology_objects WHERE import_id=? AND object_type="data_source"',array($row['id']));
 verify($count===(int)$row['metric_count'],'every numeric metric has one native data source');
 verify((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM host_snmp_cache WHERE host_id=? AND field_name="ifDescr"',array($row['host_id']))===2,'two native interface descriptions were indexed');
}
$bad=$c;$bad['data_template_id']=2147483647;
fails(function()use($bad){tp_sim_templates($bad);},'missing configured template fails without substitution');
$bad=$rows[0];$bad['community']='topology-missing-community';
fails(function()use($bad,$c){tp_sim_probe($bad,$c);},'wrong community does not use recorded samples');
fails(function()use($rows){$r=$rows[0];tp_sim_stage('Duplicate',$r['unit_id'],$r['category_id'],'topology-duplicate-check',$r['content']);},'duplicate upload does not create extra objects');
fails(function(){tp_assignment_save('9 OR 1=1',1,'Switch','','lldp',3);},'malformed device ID rejected');
fails(function(){tp_assignment_save(2,1,'Switch','','lldp',3);},'device outside configured node rejected');
$adminSession=$_SESSION;
// Cacti caches realm decisions in the session; impersonation must not reuse admin decisions.
$restricted=tp_id($options['restricted-user-id'] ?? '');
verify((bool)db_fetch_cell_prepared('SELECT id FROM user_auth WHERE id=?',array($restricted)),'explicit restricted test account exists');
$_SESSION=array('sess_user_id'=>$restricted);
fails(function(){tp_require_core('host.php');},'restricted account cannot create core devices');
$_SESSION=$adminSession;
foreach($before as $table=>$count) verify((int)db_fetch_cell('SELECT COUNT(*) FROM '.$table)===$count,'no duplicate/partial objects in '.$table);
verify(!is_file($c['data_dir'].'/topology-duplicate-check.snmprec'),'no duplicate simulator file');
echo "INTEGRATION COMPLETE\n";
} catch(Throwable $e) {fwrite(STDERR,'FAIL: '.$e->getMessage()."\n");exit(1);}
