<?php
require __DIR__.'/../includes/rack_preset_service.php';
$profiles=[];$calls=[];$assignedNodes=[];$transactionCommands=[];
function db_fetch_cell($sql){return 'test';}
function icct_backend_require_management($realm){if($realm!==3)throw new Exception('Wrong realm');}
function db_fetch_cell_prepared($sql,$args){global $profiles,$assignedNodes;if(str_contains($sql,'SELECT n.id'))return $assignedNodes?5:0;if(str_contains($sql,'GET_LOCK')||str_contains($sql,'RELEASE_LOCK'))return 1;return $profiles?json_encode($profiles):null;}
function icct_backend_classification_text($value,$limit){if(!is_string($value)||strlen($value)>$limit)throw new InvalidArgumentException('Invalid name');return trim($value);}
function icct_backend_topology_integer($value,$min,$max,$label){if(!is_scalar($value)||!preg_match('/^[0-9]+$/D',(string)$value)||$value<$min||$value>$max)throw new InvalidArgumentException('Invalid integer');return (int)$value;}
function icct_backend_category_execute($sql,$args=[]){global $profiles,$calls,$transactionCommands;if(!$args){$transactionCommands[]=$sql;return;}if($args[0]==='rack_profiles')$profiles=json_decode($args[1],true);else $calls[]=$args;}
function icct_backend_topology_config_apply($action,$site,$input){global $calls;$calls[]=[$action,$site,$input];if($action==='save_rack'&&$input['unit_count']<20)throw new InvalidArgumentException('Installed device exceeds capacity');}
function db_fetch_assoc_prepared($sql,$args){global $assignedNodes;if(str_contains($sql,'SELECT n.*'))return $assignedNodes;return [['id'=>11,'rack_number'=>1],['id'=>12,'rack_number'=>2]];}
function expectReject($input){try{icct_nms_save_rack_preset($input);}catch(InvalidArgumentException $e){return;}throw new Exception('Invalid preset accepted');}
$input=['rack_name'=>'Equipment Rack','rack_count'=>'2','unit_count'=>'42'];
icct_nms_save_rack_preset($input);$id=array_key_first($profiles);
if($profiles[$id]['rack_count']!==2||$profiles[$id]['unit_count']!==42)throw new Exception('Count or units lost');
expectReject(array_replace($input,['rack_name'=>'Other','rack_count'=>'0']));expectReject(array_replace($input,['rack_name'=>'Other','unit_count'=>'101']));expectReject($input);
icct_nms_apply_node_rack_preset(5,3,'Node',['rack_profile_id'=>$id]);
if($calls[0][2]['node_id']!==5||$calls[0][1]!==3||$calls[0][2]['rack_count']!==2)throw new Exception('Node application misbound');
if($calls[1][2]['name']!=='Equipment Rack 1'||$calls[2][2]['unit_count']!==42)throw new Exception('Rack names or capacity lost');
if($calls[3][0]!=='node_rack_profile_5')throw new Exception('Wrong node association');
echo "Rack preset validation, node/site binding and rack name/capacity application passed.\n";

$calls=[];$assignedNodes=[['id'=>5,'site_id'=>3,'name'=>'Vehicle','node_kind'=>'vehicle']];
icct_nms_save_rack_preset(array_replace($input,['rack_profile_id'=>$id,'unit_count'=>'48']));
if($calls[0][2]['node_kind']!=='vehicle'||$calls[1][2]['unit_count']!==48)throw new Exception('Assigned node not synchronized');
expectReject(array_replace($input,['rack_profile_id'=>$id,'unit_count'=>'10']));
if(end($transactionCommands)!=='ROLLBACK')throw new Exception('Unsafe capacity update not rolled back');
echo "Assigned node updates preserve kind and roll back unsafe capacity changes.\n";

expectReject(['action'=>'delete_rack_profile','rack_profile_id'=>$id]);
$assignedNodes=[];
if(icct_nms_save_rack_preset(['action'=>'delete_rack_profile','rack_profile_id'=>$id])!=='Rack configuration deleted.'||isset($profiles[$id]))throw new Exception('Unused rack configuration not deleted');
expectReject(['action'=>'delete_rack_profile','rack_profile_id'=>'']);
echo "Rack deletion protects assigned nodes and removes unused configurations.\n";
