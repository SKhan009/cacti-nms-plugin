<?php
/** Native record saves must not require a proxy/simulator flag for shared endpoints. */
require __DIR__.'/../../inventory/services/backend/device_manager.php';
$snmp_versions=[0=>'None',1=>'v1',2=>'v2c',3=>'v3'];
$snmp_auth_protocols=['[None]'=>'None','SHA'=>'SHA'];
$snmp_priv_protocols=['[None]'=>'None','AES'=>'AES'];
$availability_options=[0=>'None',2=>'SNMP'];$ping_methods=[1=>'ICMP'];
$fields_host_edit=['max_oids'=>[],'device_threads'=>[]];
$saves=[];$duplicateName=false;
function icct_backend_core_field_value($name,$field,$value){return (int)$value;}
function db_fetch_cell_prepared($sql,$args){
    if(str_contains($sql,'description = ?'))return $GLOBALS['duplicateName']?1:0;
    if(str_contains($sql,'meta_value'))return null;
    // A duplicate endpoint already exists in the fixture database.
    return 1;
}
function db_fetch_assoc_prepared($sql,$args){return [];}
function api_device_save(...$args){$GLOBALS['saves'][]=$args;return $args[0]?:88;}
function icct_backend_managed_object_record($type,$id){}
function cacti_log($message,$stdout,$tag){throw new RuntimeException($message);}
$input=['description'=>'Real device','hostname'=>'192.0.2.10','host_template_id'=>0,'site_id'=>0,'poller_id'=>1,
    'snmp_version'=>2,'snmp_port'=>161,'snmp_timeout'=>500,'snmp_community'=>'fixture-community','snmp_username'=>'',
    'snmp_password'=>'','snmp_auth_protocol'=>'[None]','snmp_priv_protocol'=>'[None]','snmp_priv_passphrase'=>'',
    'max_oids'=>10,'device_threads'=>1,'availability_method'=>2,'ping_method'=>1,'ping_port'=>0,'ping_timeout'=>400,
    'ping_retries'=>1,'notes'=>'','snmp_context'=>'','snmp_engine_id'=>'','external_id'=>'','location'=>''];
if(icct_backend_device_save(2,$input)!==2)throw new RuntimeException('Existing endpoint save failed');
if(icct_backend_device_save(0,$input+['proxy'=>false])!==88)throw new RuntimeException('Shared endpoint required simulator mode');
$v3=array_replace($input,['snmp_version'=>3,'snmp_community'=>'','snmp_username'=>'fixture-user','snmp_context'=>'context-A']);
icct_backend_device_save(3,$v3);
icct_backend_device_save(4,array_replace($v3,['snmp_context'=>'context-B','poller_id'=>2]));
if($saves[2][20]!=='context-A'||$saves[3][20]!=='context-B'||$saves[3][24]!==2)throw new RuntimeException('Native context or collector was changed');
icct_backend_device_save(5,array_replace($input,['snmp_version'=>0,'snmp_community'=>'','availability_method'=>0]));
$duplicateName=true;$before=count($saves);
try{icct_backend_device_save(6,$input);throw new LogicException('Duplicate name accepted');}
catch(InvalidArgumentException $e){if(!str_contains($e->getMessage(),'name'))throw $e;}
$duplicateName=false;
foreach([['snmp_port'=>0],['snmp_community'=>''],['snmp_version'=>3,'snmp_username'=>'']] as $bad){
    try{icct_backend_device_save(2,array_replace($input,$bad));throw new LogicException('Invalid SNMP settings accepted');}
    catch(InvalidArgumentException $e){}
}
if(count($saves)!==$before)throw new RuntimeException('Invalid input reached Cacti save');
echo "PASS: existing/shared endpoints, native SNMP contexts and collectors, non-SNMP saves, duplicate names and SNMP validation.\n";
