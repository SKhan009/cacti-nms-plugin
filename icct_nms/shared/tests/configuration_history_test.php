<?php
require __DIR__ . '/../../configuration/services/configuration_history.php';
function verify($ok,$message){if(!$ok)throw new RuntimeException($message);}
$out=[];icct_nms_configuration_pick(['port'=>22,'password'=>'secret','private_key'=>'key'],['port'],'ssh',$out);
verify($out===['ssh.port'=>'22'],'Only explicit fields may enter backups');
$changes=icct_nms_configuration_changes(['device.hostname'=>'old','ssh.port'=>'22'],['device.hostname'=>'new','snmp.port'=>'161']);
verify($changes['device.hostname']===['before'=>'old','after'=>'new'],'Before and after are preserved');
verify($changes['ssh.port']['after']===null,'Removed setting recorded');
verify($changes['snmp.port']['before']===null,'Added setting recorded');
verify(icct_nms_configuration_changes($out,$out)===[],'Unchanged settings generate no changes');
echo "Credential allowlist and added/removed/changed configuration comparisons passed.\n";
$records=[];$fixtureHost=['description'=>'Fixture','hostname'=>'192.0.2.1','snmp_port'=>161,'snmp_community'=>'secret'];
function icct_nms_device($id){global $fixtureHost;if($id===99)throw new RuntimeException('Access denied');return $fixtureHost;}
function icct_backend_require_management($realm){}
function icct_nms_metadata($id){return [];}
function db_fetch_row_prepared($sql,$args){return [];}
function db_fetch_cell_prepared($sql,$args){return str_contains($sql,'GET_LOCK')||str_contains($sql,'RELEASE_LOCK')?1:'tester';}
function icct_nms_discovery_assignment($id){return [];}
function icct_backend_serial_assignment($id){return [];}
function icct_backend_protocol_enabled($id,$protocol){return true;}
function icct_nms_graph_associations($id){return [['id'=>4]];}
function icct_nms_meta($key){global $records;return $records[$key] ?? '';}
function icct_backend_category_execute($sql,$args){global $records;$records[$args[0]]=$args[1];}
$_SESSION['sess_user_id']=1;
icct_nms_configuration_record(1,'Created');$first=json_decode($records['configuration_latest_1'],true);
verify(!isset($first['snapshot']['device.snmp_community']),'Real snapshot excludes SNMP community');
$count=count($records);icct_nms_configuration_record(1,'Unchanged');verify(count($records)===$count,'Identical auto saves are deduplicated');
$fixtureHost['hostname']='192.0.2.2';icct_nms_configuration_record(1,'Edited');$second=json_decode($records['configuration_latest_1'],true);
verify($second['changes']['device.hostname']===['before'=>'192.0.2.1','after'=>'192.0.2.2'],'Saved edits have accurate history');
icct_nms_configuration_record(2,'Created');verify(json_decode($records['configuration_latest_2'],true)['changes']['device.hostname']['before']===null,'Device history is isolated');
$count=count($records);icct_nms_configuration_record(1,'Manual',true);verify(count($records)===$count+1,'Manual backup stores unchanged settings');
try{icct_nms_configuration_record(99,'Denied');throw new RuntimeException('Access check was bypassed');}catch(RuntimeException $e){verify($e->getMessage()==='Access denied','Device access is enforced');}
echo "Automatic/manual backups, deduplication, before/after history, access checks and device isolation passed.\n";
