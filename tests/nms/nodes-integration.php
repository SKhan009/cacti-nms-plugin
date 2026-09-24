<?php
/** Run on a test Cacti database: php nodes-integration.php /path/to/cacti.
 * Uses real SQL and node services with a controlled ACL fixture; removes only its own nodes.
 */
if (PHP_SAPI !== 'cli' || empty($argv[1])) exit("Supply the test Cacti path\n");
require $argv[1].'/include/global.php';
// Isolate fixture helpers from the enabled plugin's native auth functions.
$source=file_get_contents($argv[1].'/plugins/nms/includes/nodes/service.php');
$source=preg_replace('/\bnms_([a-z_]+)(?=\s*\()/','qa_nms_$1',$source);
$source=str_replace("'nms_node_id'","'qa_nms_node_id'",$source);
eval(substr($source,5));
$allowed=[];$manage=true;
function qa_nms_require_management(){global $manage;if(!$manage)throw new RuntimeException('Denied');}
function qa_nms_require_device_access($id){global $allowed;if(!in_array((int)$id,$allowed,true))throw new RuntimeException('Denied device');}
function qa_nms_visible_host_sql(){global $allowed;return 'h.id IN ('.implode(',',$allowed ?: [0]).')';}
function qa_nms_current_user_id(){return 0;}
function qa_nms_classification_text($text,$limit){if(!is_string($text)||strlen($text)>$limit)throw new InvalidArgumentException('Invalid text');return trim($text);}
function qa_nms_category_execute($sql,$params=[]){if(!db_execute_prepared($sql,$params))throw new RuntimeException('SQL failed');}
function verify($ok,$msg){if(!$ok)throw new RuntimeException($msg);echo "PASS: $msg\n";}
function reject($fn,$msg){try{$fn();}catch(Throwable $e){echo "PASS: $msg\n";return;}throw new RuntimeException('Accepted: '.$msg);}
// Native realm check sees the existing administrative account only for node listing.
$_SESSION['sess_user_id']=(int)db_fetch_cell("SELECT id FROM user_auth WHERE username='admin'");
$hosts=db_fetch_assoc("SELECT h.id,h.site_id FROM host h LEFT JOIN plugin_nms_node_devices m ON m.host_id=h.id WHERE h.deleted='' AND h.site_id>0 AND m.host_id IS NULL ORDER BY h.site_id,h.id");
$bySite=[];foreach($hosts as $h)$bySite[$h['site_id']][]=(int)$h['id'];
$site=0;foreach($bySite as $s=>$ids)if(count($ids)>=2){$site=(int)$s;break;}
if(!$site)exit("Need two unassigned test devices at one site\n");
$members=array_slice($bySite[$site],0,2);$allowed=array_map('intval',array_column($hosts,'id'));
$foreign=null;foreach($hosts as $h)if((int)$h['site_id']!==$site){$foreign=(int)$h['id'];break;}
if(!$foreign)exit("Need a device at another site\n");
$before=db_fetch_assoc("SELECT id,site_id,hostname,snmp_community,poller_id,host_template_id FROM host ORDER BY id");
$graphsBefore=db_fetch_assoc('SELECT id,host_id,graph_template_id FROM graph_local ORDER BY id');
$created=[];$prefix='QA-'.bin2hex(random_bytes(5));
try{
 $input=['name'=>$prefix,'code'=>$prefix,'site_id'=>$site,'members'=>$members];
 $id=qa_nms_node_save($input);$created[]=$id;
 verify(count(qa_nms_node_members($id))===2,'one node stores multiple devices');
 reject(fn()=>qa_nms_node_save($input),'duplicate node identity rejected');
 reject(fn()=>qa_nms_node_save(array_replace($input,['node_id'=>$id,'members'=>[$foreign]])),'cross-site assignment rejected');
 verify(count(qa_nms_node_members($id))===2,'failed save preserves original membership');
 reject(fn()=>qa_nms_node_save(array_replace($input,['name'=>$prefix.'-other','code'=>$prefix.'-other'])),'implicit reassignment rejected');
 $allowed=[$members[0]];
 verify(count(qa_nms_node_members($id))===1,'member query filters inaccessible devices');
 reject(fn()=>qa_nms_node_save(array_replace($input,['node_id'=>$id,'members'=>[$members[0]]])),'hidden memberships cannot be overwritten');
 $allowed=array_map('intval',array_column($hosts,'id'));
 qa_nms_node_save(array_replace($input,['node_id'=>$id,'name'=>$prefix.' edited','members'=>[$members[0]]]));
 verify(count(qa_nms_node_members($id))===1,'edit and explicit unassignment persist');
 $lock=qa_nms_nodes_lock();try{qa_nms_node_assign_device($members[1],$id);}finally{qa_nms_nodes_unlock($lock);}
 verify(count(qa_nms_node_members($id))===2,'device assignment service attaches explicit member');
 $destination=qa_nms_node_save(['name'=>$prefix.' destination','code'=>$prefix.'-dst','site_id'=>$site,'members'=>[]]);$created[]=$destination;
 $remove=['node_id'=>$id,'confirm_code'=>$prefix,'member_action'=>'move','target_node_id'=>$destination];
 reject(fn()=>qa_nms_node_remove(array_replace($remove,['confirm_code'=>'wrong'])),'removal requires matching confirmation');
 reject(fn()=>qa_nms_node_remove(array_replace($remove,['target_node_id'=>$id])),'self move rejected');
 $allowed=[$members[0]];reject(fn()=>qa_nms_node_remove($remove),'removal cannot alter hidden members');
 $allowed=array_map('intval',array_column($hosts,'id'));
 qa_nms_node_remove($remove);
 verify(!db_fetch_cell_prepared('SELECT id FROM plugin_nms_nodes WHERE id=?',[$id]) && count(qa_nms_node_members($destination))===2,'remove and move preserves both members in destination');
 qa_nms_node_remove(['node_id'=>$destination,'confirm_code'=>$prefix.'-dst','member_action'=>'unassign']);
 verify(!db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_node_devices WHERE node_id=?',[$destination]),'remove and unassign clears membership only');
 verify($graphsBefore===db_fetch_assoc('SELECT id,host_id,graph_template_id FROM graph_local ORDER BY id'),'native graphs preserved after removal');
 $manage=false;reject(fn()=>qa_nms_node_save($input),'management permission enforced');$manage=true;
 verify($before===db_fetch_assoc("SELECT id,site_id,hostname,snmp_community,poller_id,host_template_id FROM host ORDER BY id"),'native device connection settings unchanged');
}finally{foreach($created as $id){db_execute_prepared('DELETE FROM plugin_nms_node_devices WHERE node_id=?',[$id]);db_execute_prepared('DELETE FROM plugin_nms_nodes WHERE id=?',[$id]);}}
