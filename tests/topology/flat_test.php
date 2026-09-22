<?php
/** Destructive tests exclusively in a disposable database cloned from Cacti. */
if(PHP_SAPI!=='cli')exit(2);
$o=getopt('',array('cacti-root:','database:'));
if(empty($o['cacti-root'])||!preg_match('/^topology_flat_qa_[a-z0-9_]+$/D',$o['database']??''))exit(2);
require($o['cacti-root'].'/include/cli_check.php');
$database_hostname='localhost';$database_port='3306';$database_default=$o['database'];
if(!db_connect_real('localhost','root','',$database_default,'mysql','3306',1))exit(2);
require_once(dirname(__DIR__, 2).'/plugins/topology/includes/flow.php');
try{
 function check_flat($ok,$label){if(!$ok)throw new RuntimeException($label);print "PASS: $label\n";}
 function reject_flat($fn,$label){try{$fn();}catch(RuntimeException|InvalidArgumentException $e){print "PASS: $label\n";return;}throw new RuntimeException('Accepted: '.$label);}
 function digest_flat($sql){return hash('sha256',json_encode(db_fetch_assoc($sql)));}
 check_flat(db_fetch_cell('SELECT DATABASE()')===$o['database'],'isolated database');
 $_SESSION=array('sess_user_id'=>(int)read_config_option('admin_user'));
 $core=digest_flat('SELECT * FROM host ORDER BY id');$sites=digest_flat('SELECT * FROM sites ORDER BY id');
 $ports=digest_flat('SELECT * FROM plugin_topology_ports ORDER BY id');$cables=digest_flat('SELECT id,port_a,port_b,created_by,created_at FROM plugin_topology_cables ORDER BY id');
 $positions=digest_flat('SELECT p.host_id,p.x,p.y FROM plugin_topology_positions p JOIN plugin_topology_devices d ON d.host_id=p.host_id AND d.unit_id=p.unit_id ORDER BY p.host_id');
 $graphs=digest_flat('SELECT * FROM graph_local ORDER BY id');$data=digest_flat('SELECT * FROM data_local ORDER BY id');
 $policies=db_fetch_assoc('SELECT * FROM plugin_topology_discovery_sites');
 // A cloned running row does not represent a live worker in this isolated database.
 tp_exec('UPDATE plugin_topology_jobs SET state="failed" WHERE state="running"');
 tp_schema();$scope=tp_scope_id();
 check_flat($core===digest_flat('SELECT * FROM host ORDER BY id')&&$sites===digest_flat('SELECT * FROM sites ORDER BY id'),'migration preserves Cacti devices, credentials and Sites');
 check_flat($ports===digest_flat('SELECT * FROM plugin_topology_ports ORDER BY id')&&$cables===digest_flat('SELECT id,port_a,port_b,created_by,created_at FROM plugin_topology_cables ORDER BY id'),'migration preserves ports, mappings and connections');
 check_flat($positions===digest_flat('SELECT host_id,x,y FROM plugin_topology_positions WHERE unit_id='.$scope.' ORDER BY host_id'),'migration preserves device positions');
 check_flat($graphs===digest_flat('SELECT * FROM graph_local ORDER BY id')&&$data===digest_flat('SELECT * FROM data_local ORDER BY id'),'migration preserves graphs and data sources');
 if(count($policies)===1){$p=db_fetch_row_prepared('SELECT * FROM plugin_topology_discovery_sites WHERE unit_id=?',array($scope));check_flat($p['enabled']===$policies[0]['enabled']&&$p['interval_seconds']===$policies[0]['interval_seconds'],'migration preserves discovery schedule');}
 $state=digest_flat('SELECT * FROM plugin_topology_positions ORDER BY unit_id,host_id');tp_schema();check_flat($state===digest_flat('SELECT * FROM plugin_topology_positions ORDER BY unit_id,host_id'),'migration is idempotent');
 check_flat(tp_flow_steps()===array(1=>'Device Categories',2=>'Port Profiles',3=>'Assign Devices'),'three-step flow without Site or Node');
 check_flat(tp_assign_devices(1,1,array(9,10))===2,'bulk category and profile assignment');
 check_flat($ports===digest_flat('SELECT * FROM plugin_topology_ports ORDER BY id')&&$cables===digest_flat('SELECT id,port_a,port_b,created_by,created_at FROM plugin_topology_cables ORDER BY id'),'reapply preserves connected ports and mappings');
 $before=digest_flat('SELECT * FROM plugin_topology_devices ORDER BY host_id');
 reject_flat(function(){tp_assign_devices(1,1,array(2,999999));},'invalid device rejects entire batch');
 check_flat($before===digest_flat('SELECT * FROM plugin_topology_devices ORDER BY host_id')&&!db_fetch_cell('SELECT id FROM plugin_topology_ports WHERE host_id=2'),'failed batch rolls back assignments and ports');
 tp_exec('UPDATE host SET site_id=0 WHERE id=2');tp_assign_devices(1,1,array(2));
 check_flat(isset(tp_physical_hosts($scope)[2]),'device without Site can be assigned directly');
 $cat=tp_category_save(0,'QA category','',1,'#0f766e');$profile=tp_profile_save(0,$cat,'QA Ports','Port ',1,2,'RJ45');
 reject_flat(function()use($profile){tp_assign_devices(1,$profile,array(9));},'category/profile mismatch rejected');
 check_flat(count(tp_canvas_data($scope,$cat)['devices'])===0,'category filter restricts canvas');
 check_flat(count(tp_canvas_data($scope,1)['cables'])>0,'existing cable remains visible');
 tp_ports_apply($scope,2,0);tp_assign_devices($cat,$profile,array(2));
 $otherPort=(int)db_fetch_cell('SELECT id FROM plugin_topology_ports WHERE host_id=2 ORDER BY ordinal LIMIT 1');
 $freePort=(int)db_fetch_cell('SELECT id FROM plugin_topology_ports WHERE host_id=9 AND ordinal=1');
 tp_cable_add($scope,$freePort,$otherPort);$filtered=tp_canvas_data($scope,1);
 check_flat(in_array($freePort,$filtered['connectedPorts'],true)&&count($filtered['cables'])===1,'category filter retains occupied port state when peer is hidden');
 $_SESSION=array('sess_user_id'=>3);reject_flat(function(){tp_assign_devices(1,1,array(9));},'read-only user cannot bulk assign');
 print "FLAT FLOW CHECKS COMPLETE\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
