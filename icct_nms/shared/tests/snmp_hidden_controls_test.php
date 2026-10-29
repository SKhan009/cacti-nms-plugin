<?php
/** Hidden reachability fields are omitted by FormData; their native values must survive saves. */
require __DIR__.'/../../inventory/services/device_service.php';
require __DIR__.'/../../dashboard/topology/services/backend/topology_config.php';
$old=['snmp_version'=>2,'snmp_community'=>'fixture','snmp_port'=>161,'snmp_timeout'=>500,'max_oids'=>10,
'availability_method'=>2,'ping_method'=>1,'ping_timeout'=>400,'ping_retries'=>2,'snmp_username'=>'',
'snmp_auth_protocol'=>'[None]','snmp_priv_protocol'=>'[None]','snmp_context'=>'','snmp_password'=>'saved-secret',
'snmp_priv_passphrase'=>'','snmp_engine_id'=>'','external_id'=>'','location'=>'','ping_port'=>0];
$fields_host_edit=array_fill_keys(array_keys($old),[]);$saved=[];
function icct_backend_core_field_value($name,$field,$value){return $value;}
function icct_backend_device_save($id,$values){$GLOBALS['saved']=$values;return $id;}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
$input=$old;unset($input['ping_method']);$input['snmp_password']='';
check(icct_nms_save_snmp(86,$old,$input)===86&&$saved['ping_method']===1,'SNMP-only save retains omitted ping method');
check($saved['snmp_password']==='saved-secret','Blank credential replacement preserves the saved secret');
$input['availability_method']=0;unset($input['ping_timeout'],$input['ping_retries']);
icct_nms_save_snmp(86,$old,$input);
check($saved['ping_method']===1&&$saved['ping_timeout']===400&&$saved['ping_retries']===2,'No detection retains all omitted ping settings');
foreach([1,3,4] as $method){
 $input=$old;$input['availability_method']=$method;unset($input['ping_method']);
 try{icct_nms_save_snmp(86,$old,$input);throw new LogicException('Missing visible field accepted');}
 catch(InvalidArgumentException $error){check(str_contains($error->getMessage(),'ping_method'),'Ping-based method '.$method.' still requires visible ping method');}
}
$input=$old;$input['ping_timeout']=0;
try{icct_nms_save_snmp(86,$old,$input);throw new LogicException('Invalid timeout accepted');}
catch(InvalidArgumentException $error){check(true,'Explicit invalid timeout remains rejected');}
$input=$old;
foreach(['ping_method','snmp_auth_protocol','snmp_password','snmp_priv_protocol','snmp_priv_passphrase'] as $field)unset($input[$field]);
icct_nms_save_snmp(86,$old,$input);
check($saved['snmp_auth_protocol']==='[None]'&&$saved['snmp_priv_protocol']==='[None]','Fresh v2 form saves with all hidden credential controls omitted');
$fresh=$old;$fresh['snmp_auth_protocol']='';$fresh['snmp_priv_protocol']='';
icct_nms_save_snmp(86,$fresh,$input);
check($saved['snmp_auth_protocol']==='[None]'&&$saved['snmp_priv_protocol']==='[None]','Empty native defaults normalize to Cacti None for hidden protocols');
$input['snmp_version']=3;$input['snmp_username']='qa-user';$input['snmp_security_level']='noAuthNoPriv';
icct_nms_save_snmp(86,$old,$input);
check($saved['snmp_auth_protocol']==='[None]'&&$saved['snmp_priv_protocol']==='[None]','V3 noAuthNoPriv accepts omitted inactive credential controls');
echo "SNMP hidden control save regression tests passed.\n";
