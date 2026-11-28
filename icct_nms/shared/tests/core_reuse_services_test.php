<?php
if (PHP_SAPI !== 'cli') exit(1);
set_error_handler(static function($n,$m,$f,$l){throw new ErrorException($m,0,$n,$f,$l);});
require __DIR__.'/../services/polling.php';
require __DIR__.'/../services/native_rrd.php';
require __DIR__.'/../services/backend/functions.php';
require __DIR__.'/../../inventory/services/backend/device_manager.php';
$config=['poller_id'=>4];$_SESSION=['sess_user_id'=>7];
$logs=$order=$fetches=$apiCalls=$permissionQueries=[];
$association=[];$queryCache=0;$validUser=true;$simple=false;
function checkCore($ok,$why){if(!$ok)throw new RuntimeException($why);}
function read_config_option($n){return ['auth_method'=>1,'graph_auth_method'=>2,'poller_interval'=>300][$n]??'';}
function cacti_log($m,...$args){$GLOBALS['logs'][]=$m;}
function rrdtool_function_fetch(...$args){$GLOBALS['fetches'][]=$args;return ['values'=>[null,0,10],'data_source_names'=>['counter']];}
function auth_valid_user($id){return $GLOBALS['validUser'];}
function get_allowed_devices($where,$order,$limit,&$total,$user){checkCore($total===-1&&$user===7,'Use native bulk ACL without a redundant count');return [['id'=>1],['id'=>3]];}
function get_simple_device_perms($user){return $GLOBALS['simple'];}
function get_policies($user){checkCore($user===7,'Correct ACL principal');return ['fixture-policy'];}
function get_policy_where($method,$policies,$where){checkCore($method===2&&$policies===['fixture-policy'],'Use native policy semantics');return $where.' AND h.id=8';}
function db_fetch_assoc($sql){$GLOBALS['permissionQueries'][]=$sql;return [['id'=>8]];}
function db_fetch_row_prepared($sql,$a){return $GLOBALS['association'];}
function db_fetch_cell_prepared($sql,$a){return $GLOBALS['queryCache'];}
function api_device_dq_add(...$a){$GLOBALS['apiCalls'][]=['add',$a];run_data_query($a[0],$a[1]);}
function api_device_dq_change(...$a){$GLOBALS['apiCalls'][]=['change',$a];run_data_query($a[0],$a[1]);}
function run_data_query(...$a){$GLOBALS['apiCalls'][]=['recache',$a];}
icct_nms_poller_collectors([
    'inventory'=>static function(){$GLOBALS['order'][]='inventory';throw new RuntimeException('fixture failure');},
    'identity'=>static function(){$GLOBALS['order'][]='identity';},
    'ports'=>static function(){$GLOBALS['order'][]='ports';},
]);
checkCore($order===['inventory','identity','ports']&&count($logs)===1,'An earlier failure must not skip later stages or retry');
$a=icct_nms_rrd_fetch(10,100,1000,300);$b=icct_nms_rrd_fetch(10,100,1000,300);
checkCore($a===$b&&count($fetches)===1,'Identical RRD fetches share one native operation');
icct_nms_rrd_fetch(10,100,1001,300);icct_nms_rrd_fetch(10,100,1000,300,true,null,'MAX');
$_SESSION['sess_user_id']=9;icct_nms_rrd_fetch(10,100,1000,300);
checkCore(count($fetches)===4,'Window, CF and user boundaries must not share fetch results');
$_SESSION['sess_user_id']=7;
checkCore(icct_backend_allowed_device_ids()===[1=>true,3=>true,8=>true],'Authorized disabled devices remain visible');
checkCore(count($permissionQueries)===1&&str_contains($permissionQueries[0],"h.disabled='on'")&&str_contains($permissionQueries[0],'h.id=8'),'Disabled inclusion still uses native restrictions');
$validUser=false;checkCore(icct_backend_allowed_device_ids()===[],'Invalid principal receives no inventory');$validUser=true;
icct_backend_device_prepare_query(37,91);
checkCore(array_column($apiCalls,0)===['add','recache'],'Adding uses the native propagation API and exactly one reload');
$apiCalls=[];$association=['reindex_method'=>1];icct_backend_device_prepare_query(37,91);
checkCore(array_column($apiCalls,0)===['change','recache'],'Changing must not recache twice');
$apiCalls=[];$association=['reindex_method'=>2];$queryCache=12;icct_backend_device_prepare_query(37,91);
checkCore(!$apiCalls,'An existing populated association must not trigger a walk');
$queryCache=0;icct_backend_device_prepare_query(37,91);
checkCore(array_column($apiCalls,0)===['recache'],'Empty unchanged association can still load native query data');
echo "PASS: Collector isolation, exact RRD request reuse, native bulk ACL including disabled devices, and single native query recache.\n";
