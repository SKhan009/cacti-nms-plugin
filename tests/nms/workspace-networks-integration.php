<?php
/** QA database integration; temporary native-table fixture and exact cleanup, no scan launched. */
if(PHP_SAPI!=='cli' || empty($argv[1]))exit('Supply QA Cacti root');
require $argv[1].'/include/global.php';
require_once $argv[1].'/plugins/nms/includes/functions.php';
$includes=$argv[2] ?? $argv[1].'/plugins/nms/includes';
require_once $includes.'/workspace/audit.php';
$schema=file_get_contents($includes.'/workspace/schema.php');
$schema=str_replace(['function nms_workspace_schema(', 'CREATE TABLE IF NOT EXISTS'],['function qa_workspace_schema(', 'CREATE TEMPORARY TABLE'],$schema);
eval(substr($schema,5));qa_workspace_schema();
$source=file_get_contents($includes.'/workspace/networks.php');
$source=str_replace("__DIR__",var_export($includes.'/workspace',true),$source);
$source=preg_replace('/\bnms_([a-z_]+)(?=\s*\()/','qa_nms_$1',$source);
eval(substr($source,5));
$manage=true;
function qa_nms_require_management($realm) {global $manage;if(!$manage || $realm!==23)throw new RuntimeException('Denied');}
function qa_nms_workspace_audit($action,$detail,$host_id,$network_id){nms_workspace_audit($action,$detail,$host_id,$network_id,0);}
function verify($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
function reject($fn,$message){try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('Unexpected success: '.$message);}
$id=0;$name='QA-readings-network-'.bin2hex(random_bytes(6));
$poller=(int)db_fetch_cell('SELECT id FROM poller ORDER BY id LIMIT 1');$snmp=(int)db_fetch_cell('SELECT id FROM automation_snmp ORDER BY id LIMIT 1');
$before=db_fetch_assoc('SELECT id,host_id,graph_template_id FROM graph_local ORDER BY id');
$hostBefore=db_fetch_assoc('SELECT id,hostname,poller_id,site_id FROM host ORDER BY id');
$input=['network_id'=>0,'name'=>$name,'subnet_range'=>'192.0.2.0/29','poller_id'=>$poller,'site_id'=>0,'snmp_id'=>$snmp,'sched_type'=>1,'start_at'=>date('Y-m-d H:i:s'),'recur_every'=>1,'threads'=>1,'run_limit'=>60];
try {
$id=qa_nms_workspace_network_save($input);
$row=db_fetch_row_prepared('SELECT * FROM automation_networks WHERE id=?',[$id]);
verify($row['enabled']==='' && $row['add_to_cacti']==='' && (int)$row['total_ips']===6,'Native network saved disabled with exact target count');
db_execute_prepared('UPDATE automation_networks SET dns_servers=?,notification_email=? WHERE id=?',['192.0.2.53','qa@example.invalid',$id]);
$edit=array_replace($input,['network_id'=>$id,'revision'=>qa_nms_workspace_network_revision($row),'name'=>$name.' edited']);
qa_nms_workspace_network_save($edit);
$row=db_fetch_row_prepared('SELECT * FROM automation_networks WHERE id=?',[$id]);
verify($row['dns_servers']==='192.0.2.53' && $row['notification_email']==='qa@example.invalid','Advanced native settings preserved');
verify((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_workspace_audit WHERE network_id=?',[$id])===2,'Create and edit both produce an audit event');
reject(fn()=>qa_nms_workspace_network_save($edit),'Stale form revision rejected');
$manage=false;reject(fn()=>qa_nms_workspace_network_save($input),'Automation permission enforced');$manage=true;
verify($before===db_fetch_assoc('SELECT id,host_id,graph_template_id FROM graph_local ORDER BY id'),'Native graph associations unchanged');
verify($hostBefore===db_fetch_assoc('SELECT id,hostname,poller_id,site_id FROM host ORDER BY id'),'Existing device addresses and collectors unchanged');
} finally {if($id)db_execute_prepared('DELETE FROM automation_networks WHERE id=? AND name IN (?,?)',[$id,$name,$name.' edited']);}
