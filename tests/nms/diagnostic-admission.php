<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/plugins/nms/includes/diagnostics_queue.php';
$mode='ready';$inserts=0;$released=0;
function nms_require_management($realm){}
function nms_require_device_access($id){}
function nms_current_user_id(){return 1;}
function db_fetch_row_prepared($sql,$args){return ['host_id'=>1,'hostname'=>'127.0.0.1','description'=>'Test','poller_id'=>1,'disabled'=>'','id'=>1,'name'=>'Test','tools'=>'ping','ping_count'=>1,'trace_hops'=>5,'bandwidth_seconds'=>1];}
function db_fetch_cell_prepared($sql,$args){
 global $mode,$released;
 if(str_contains($sql,'RELEASE_LOCK')) {$released++;return 1;}
 if(str_contains($sql,'GET_LOCK') || str_contains($sql,'FROM poller'))return 1;
 if(str_contains($sql,'meta_value'))return $mode==='offline'?false:json_encode(['tools'=>['ping'=>$mode!=='missing']]);
 if(str_contains($sql,'COUNT(*)'))return $mode==='busy'?1:0;
 throw new Exception($sql);
}
function nms_category_execute($sql,$args){global $inserts;$inserts++;}
function db_fetch_cell($sql){return 12;}
foreach(['offline','missing','busy'] as $mode){
 $rejected=false;try{nms_diag_run(1,'ping');}catch(RuntimeException $e){$rejected=true;}
 if(!$rejected || $inserts)throw new Exception('Must reject without creating test: '.$mode);
}
$mode='ready';if(nms_diag_run(1,'ping')!==12||$inserts!==1||$released!==4)throw new Exception('Ready admission/lock release');
echo "PASS: offline, missing tool and busy refused without insertion; ready accepted; locks released.\n";
