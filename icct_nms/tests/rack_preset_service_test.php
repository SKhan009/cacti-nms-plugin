<?php
require __DIR__ . '/../presets/services/rack_preset_service.php';
$profiles=[];$calls=[];$assignedSites=[];$transactionCommands=[];
function db_fetch_cell($sql){return 'test';}
function icct_backend_current_user_id(){return 1;}
function icct_backend_require_management($realm){if($realm!==3)throw new Exception('Wrong realm');}
function db_fetch_cell_prepared($sql,$args){
 if(str_contains($sql,'GET_LOCK')||str_contains($sql,'RELEASE_LOCK'))return 1;
 if(str_contains($sql,'FROM plugin_icct_nms_racks'))return $GLOBALS['assignedSites']?11:0;
 if(str_contains($sql,'meta_value'))return $GLOBALS['profiles']?json_encode($GLOBALS['profiles']):null;
 return 0;
}
function icct_backend_classification_text($value,$limit){if(!is_string($value)||strlen($value)>$limit)throw new InvalidArgumentException('Invalid name');return trim($value);}
function icct_backend_topology_integer($value,$min,$max,$label){if(!is_scalar($value)||!preg_match('/^[0-9]+$/D',(string)$value)||$value<$min||$value>$max)throw new InvalidArgumentException('Invalid integer');return (int)$value;}
function icct_backend_category_execute($sql,$args=[]){
 if(!$args){$GLOBALS['transactionCommands'][]=$sql;if($sql==='START TRANSACTION')$GLOBALS['snapshot']=$GLOBALS['profiles'];if($sql==='ROLLBACK')$GLOBALS['profiles']=$GLOBALS['snapshot'];return;}
 if($args[0]==='rack_profiles')$GLOBALS['profiles']=json_decode($args[1],true);else $GLOBALS['calls'][]=[$sql,$args];
}
function icct_backend_topology_config_apply($action,$site,$input){$GLOBALS['calls'][]=[$action,$site,$input];if($action==='save_rack'&&$input['unit_count']<20)throw new InvalidArgumentException('Installed device exceeds capacity');}
function db_fetch_assoc_prepared($sql,$args){if(str_contains($sql,'DISTINCT site_id'))return $GLOBALS['assignedSites'];return [['id'=>11,'rack_number'=>1],['id'=>12,'rack_number'=>2]];}
function expectReject($input){try{icct_nms_save_rack_preset($input);}catch(InvalidArgumentException $e){return;}throw new Exception('Invalid preset accepted');}
$input=['rack_name'=>'Equipment Rack','unit_count'=>'42'];
icct_nms_save_rack_preset($input);$id=array_key_first($profiles);
if($profiles[$id]['rack_count']!==1||$profiles[$id]['unit_count']!==42)throw new Exception('Count or units lost');
expectReject(array_replace($input,['rack_name'=>'Other','unit_count'=>'101']));expectReject($input);
// A pre-existing group keeps both racks after count controls are removed.
$profiles[$id]['rack_count']=2;
$calls=[];$assignedSites=[['site_id'=>3]];
icct_nms_apply_site_rack_preset(3,$id);
if($calls[0][1]!==3||$calls[0][2]['name']!=='Equipment Rack'||$calls[1][2]['unit_count']!==42)throw new Exception('Site rack names or capacity lost');
$calls=[];icct_nms_save_rack_preset(array_replace($input,['rack_profile_id'=>$id,'rack_name'=>'Updated Rack','unit_count'=>'48']));
if($profiles[$id]['rack_count']!==2 || $calls[0][2]['name']!=='Updated Rack' || $calls[1][2]['name']!=='Updated Rack' || $calls[0][2]['unit_count']!==48)throw new Exception('Assigned racks not synchronized');
expectReject(array_replace($input,['rack_profile_id'=>$id,'unit_count'=>'10']));
if(end($transactionCommands)!=='ROLLBACK'||$profiles[$id]['unit_count']!==48)throw new Exception('Unsafe capacity update not rolled back');
expectReject(['action'=>'delete_rack_profile','rack_profile_id'=>$id]);$assignedSites=[];
if(icct_nms_save_rack_preset(['action'=>'delete_rack_profile','rack_profile_id'=>$id])!=='Rack configuration deleted.'||isset($profiles[$id]))throw new Exception('Unused rack configuration not deleted');
echo "Site-owned rack presets, synchronization, occupied capacity rollback and deletion protection passed.\n";
