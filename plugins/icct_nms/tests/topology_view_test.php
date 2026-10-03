<?php
require __DIR__.'/../includes/topology_view_service.php';
$stored=[];$allowed=true;
function icct_backend_require_management($r){if(!$GLOBALS['allowed'])throw new RuntimeException('Denied');}
function icct_backend_require_device_access($id){if($id>5)throw new RuntimeException('Denied device');}
function icct_backend_topology_integer($v,$min,$max,$name){if(filter_var($v,FILTER_VALIDATE_INT)===false||$v<$min||$v>$max)throw new InvalidArgumentException($name);return(int)$v;}
function db_fetch_cell_prepared($sql,$args){if(str_contains($sql,'LOCK'))return 1;if(($args[0]??'')==='topology_manual_links')return json_encode($GLOBALS['manual']??[]);if(($args[0]??'')==='connection_profiles')return null;return json_encode($GLOBALS['stored']);}
function icct_backend_category_execute($sql,$args){$GLOBALS['stored']=json_decode($args[1],true);}
function reject($cb){try{$cb();throw new LogicException('Accepted invalid layout');}catch(InvalidArgumentException $e){}}
$rev=hash('sha256',json_encode($stored));icct_nms_topology_save([1=>[.2,.3]],$rev);
if($stored[1]!==[.2,.3])throw new LogicException('Position lost');
reject(fn()=>icct_nms_topology_save([1=>[.5,.5]],$rev));
$rev=hash('sha256',json_encode($stored));
foreach([[-1,.2],[.2,2],[.2],[.2,'bad'],[INF,.2]] as $point)reject(fn()=>icct_nms_topology_save([1=>$point],$rev));
try{icct_nms_topology_save([6=>[.2,.2]],$rev);throw new LogicException('Device permission bypass');}catch(RuntimeException $e){}
icct_nms_topology_save([2=>[.6,.4]],$rev);if(!isset($stored[1],$stored[2]))throw new LogicException('Save removed other device positions');
$allowed=false;try{icct_nms_topology_save([],hash('sha256',json_encode($stored)));throw new LogicException('Realm bypass');}catch(RuntimeException $e){}
echo "Topology persistence, bounds, stale writes and device/realm permissions passed.\n";
function is_realm_allowed($id){return true;}
function icct_nms_inventory(){return [['id'=>1,'category_id'=>1,'device_type'=>'Switch'],['id'=>2],['id'=>3]];}
function icct_nms_device_types(){return [['category_id'=>1,'name'=>'Switch','icon'=>'switch']];}
function icct_nms_type_icon_asset($key){return 'assets/'.$key.'.svg';}
function icct_nms_type_asset($type,$view){return 'assets/'.$view.'-'.$type['icon'].'.svg';}
function icct_nms_fault_observations($host){return $host['id']===1?[['state'=>'Critical'],['state'=>'Minor']]:[];}
function db_fetch_assoc_prepared($sql,$args){if(isset($GLOBALS['snapshots']))return $GLOBALS['snapshots'][$args[0]]??[];return $args[0]===1?[['protocol'=>'lldp','status'=>'success','config_hash'=>'test','succeeded_at'=>date('Y-m-d H:i:s'),'data_json'=>json_encode(['neighbors'=>[['present'=>true,'peer_label'=>'Switch'],['present'=>false,'peer_label'=>'Sensor'],['present'=>true,'management_addresses'=>['127.0.0.1']]]])]]:[];}
$diagram=icct_nms_topology_data(['unlocated'=>[['id'=>1,'name'=>'Host','address'=>'127.0.0.1'],['id'=>2,'name'=>'Switch','address'=>'127.0.0.1'],['id'=>3,'name'=>'Sensor','address'=>'127.0.0.1']],'sites'=>[]]);
if(count($diagram['links'])!==1||$diagram['links'][0]['target']!==2)throw new LogicException('Ambiguous or absent neighbour linked');
if($diagram['devices'][0]['fault_count']!==2)throw new LogicException('Fault counts are incomplete');
echo "Topology neighbour resolution and actual fault counts passed.\n";

function icct_backend_nd_hosts(){return array_map(fn($id)=>["id"=>$id,"enabled"=>1,"collection_enabled"=>1,"stale_seconds"=>900],[1,2,3]);}
function icct_backend_nd_host_methods($host){return ["lldp"];}
function icct_backend_nd_hash($host){return "test";}

$manual=["manual-link"=>["source"=>1,"target"=>3,"profile"=>array_key_first(icct_nms_connections()),"source_port"=>"Gi1","target_port"=>"Gi2"]];
$diagram=icct_nms_topology_data(["unlocated"=>[["id"=>1,"name"=>"Host","address"=>"127.0.0.1"],["id"=>2,"name"=>"Switch","address"=>"127.0.0.1"],["id"=>3,"name"=>"Sensor","address"=>"127.0.0.1"]],"sites"=>[]]);
if(count($diagram["links"])!==2||$diagram["links"][1]["protocol"]!=="manual"||!str_contains($diagram["links"][1]["label"],"Gi1"))throw new LogicException("Manual link missing from topology");
echo "Manual connections appear in dashboard topology with their port labels.\n";

$manual=[];
$makeSnapshot=fn($data,$hash='test')=>['protocol'=>'lldp','status'=>'success','config_hash'=>$hash,'succeeded_at'=>date('Y-m-d H:i:s'),'data_json'=>json_encode($data)];
$snapshots=[1=>[$makeSnapshot(['name'=>'CORE','identity'=>'4:core','neighbors'=>[['peer_key'=>'4:peer','remote_name'=>'Different display name','management_addresses'=>['127.0.0.1'],'local_port'=>'Gi1','remote_port'=>'Eth1']]])],2=>[$makeSnapshot(['name'=>'PEER','identity'=>'4:peer'])]];
$map=['unlocated'=>[['id'=>1,'name'=>'Host','address'=>'127.0.0.1'],['id'=>2,'name'=>'Switch','address'=>'127.0.0.1'],['id'=>3,'name'=>'Sensor','address'=>'127.0.0.1']],'sites'=>[]];
$diagram=icct_nms_topology_data($map);
if(count($diagram['links'])!==1||$diagram['links'][0]['target']!==2||!str_contains($diagram['links'][0]['label'],'Gi1'))throw new LogicException('Reported identity or port labels not resolved');
if($diagram['devices'][0]['network_asset']!=='assets/network-switch.svg')throw new LogicException('Topology did not use network device type asset');
$snapshots[2]=[$makeSnapshot(['name'=>'PEER','identity'=>'4:peer'],'stale-config')];
if(icct_nms_topology_data($map)['links'])throw new LogicException('Outdated identity created a link');
$snapshots[2]=[$makeSnapshot(['name'=>'PEER','identity'=>'4:peer'])];
$snapshots[3]=[$makeSnapshot(['name'=>'PEER','identity'=>'4:peer'])];
if(icct_nms_topology_data($map)['links'])throw new LogicException('Ambiguous reported identity created a link');
echo "Network type icons, reported identities, port labels and stale/ambiguous identity safeguards passed.\n";
