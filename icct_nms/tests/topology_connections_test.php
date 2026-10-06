<?php
require __DIR__ . '/../presets/services/connection_service.php';
require __DIR__ . '/../dashboard/topology/services/topology_configuration_service.php';
$stored=[];$management=true;$denied=[];
function icct_backend_require_management($realm){if(!$GLOBALS['management'])throw new RuntimeException('Denied');}
function icct_backend_require_device_access($id){if(in_array($id,$GLOBALS['denied'],true))throw new RuntimeException('Denied device');}
function icct_backend_topology_integer($value,$min,$max,$name){if(!is_scalar($value)||!ctype_digit((string)$value)||$value<$min||$value>$max)throw new InvalidArgumentException($name);return(int)$value;}
function icct_backend_current_user_id(){return 1;}
function db_fetch_cell_prepared($sql,$args){if(str_contains($sql,'LOCK'))return 1;if(str_contains($sql,'FROM host'))return $args[0]<5?$args[0]:0;if($args[0]==='connection_profiles')return null;return json_encode($GLOBALS['stored']);}
function icct_backend_category_execute($sql,$args){$GLOBALS['stored']=json_decode($args[1],true);}
function rejects($cb){try{$cb();}catch(InvalidArgumentException|RuntimeException $e){return;}throw new LogicException('Invalid request accepted');}
$profile=array_key_first(icct_nms_connections());$input=['source'=>'1','target'=>'2','profile'=>$profile,'source_port'=>'Gi0/1','target_port'=>'Gi0/2'];
icct_nms_connection_save($input);$key=array_key_first($stored);if($stored[$key]['target']!==2)throw new LogicException('Link not persisted');
rejects(fn()=>icct_nms_connection_save($input));rejects(fn()=>icct_nms_connection_save(array_replace($input,['source'=>'2','target'=>'1','source_port'=>'Gi0/2','target_port'=>'Gi0/1'])));
foreach([['target'=>'1'],['target'=>'5'],['profile'=>'missing'],['source_port'=>str_repeat('x',101)],['source'=>[]]] as $bad)rejects(fn()=>icct_nms_connection_save(array_replace($input,$bad)));
icct_nms_connection_save($input+['link_id'=>$key]);if(count($stored)!==1)throw new LogicException('Edit duplicated link');
$denied=[2];rejects(fn()=>icct_nms_connection_delete($key));rejects(fn()=>icct_nms_connection_save($input+['link_id'=>$key]));$denied=[];
$management=false;rejects(fn()=>icct_nms_connection_delete($key));$management=true;icct_nms_connection_delete($key);if($stored)throw new LogicException('Link not removed');
echo "Manual connection create/edit/remove, duplicate/self/missing-device validation and permissions passed.\n";
function icct_backend_nd_host_methods($host){return ['lldp'];}
function icct_backend_nd_hash($host){return 'current-config';}
$host=['enabled'=>1,'collection_enabled'=>1,'stale_seconds'=>900];$snapshot=['status'=>'success','protocol'=>'lldp','config_hash'=>'current-config','succeeded_at'=>date('Y-m-d H:i:s')];
if(!icct_nms_discovery_current($snapshot,$host))throw new LogicException('Current discovery rejected');
foreach([['status'=>'queued'],['protocol'=>'cdp'],['config_hash'=>'old-config'],['succeeded_at'=>date('Y-m-d H:i:s',time()-1000)]] as $bad)if(icct_nms_discovery_current(array_replace($snapshot,$bad),$host))throw new LogicException('Invalid discovery treated as current');
if(icct_nms_discovery_current($snapshot,array_replace($host,['collection_enabled'=>0])))throw new LogicException('Disabled collection remains current');
echo "Discovery freshness, enabled methods, configuration signature and disabled collection checks passed.\n";

function icct_backend_nd_hosts(){return [$GLOBALS['host']+['id'=>1]];}
function db_fetch_assoc_prepared($sql,$args){return [array_replace($GLOBALS['snapshot'],['data_json'=>json_encode(['neighbors'=>[['remote_name'=>'Selected LLDP peer']]])]),array_replace($GLOBALS['snapshot'],['protocol'=>'cdp','data_json'=>json_encode(['neighbors'=>[['remote_name'=>'Unselected CDP peer']]])])];}
$devices=[['id'=>1,'description'=>'Reporter']];
$rows=icct_nms_topology_discovery_rows($devices);
if(count($rows)!==1 || $rows[0]['protocol']!=='LLDP')throw new LogicException('Unselected method leaked into results');
$host['collection_enabled']=0;
if(icct_nms_topology_discovery_rows($devices))throw new LogicException('Disabled collection leaked into results');
echo "Connection results follow selected methods and collection state.\n";
