<?php
require __DIR__ . '/../../inventory/diagnostics/services/backend/diagnostics_queue.php';
$config=['base_path'=>'queue-test','icct_nms_redis_socket'=>'/tmp/icct-unavailable-redis.sock'];$pending=null;$inserts=0;
function icct_backend_require_management($realm){}
function icct_backend_require_device_access($host){}
function icct_backend_diag_available_labels(){return ['ping'=>'Ping','arp'=>'ARP'];}
function icct_backend_diag_assignment($host,$tool){return ['host_id'=>$host,'poller_id'=>1];}
function icct_backend_current_user_id(){return 7;}
function icct_backend_diag_signature($row){return 'saved';}
function db_fetch_cell($sql){return 50;}
function db_fetch_cell_prepared($sql,$args){if(str_contains($sql,'plugin_config')||str_contains($sql,'FROM poller')||str_contains($sql,'GET_LOCK')||str_contains($sql,'RELEASE_LOCK'))return 1;if(str_contains($sql,'COUNT(*) FROM plugin_icct_nms_diagnostic_jobs'))return $GLOBALS['pending']?1:0;throw new Exception('Unexpected cell');}
function db_fetch_row_prepared($sql,$args){
 if(str_contains($sql,'SELECT id FROM plugin_icct_nms_diagnostic_jobs'))return $GLOBALS['pending']&&$args===[7,2,'ping']?['id'=>50]:[];
 if(str_contains($sql,'plugin_icct_nms_meta'))return ['meta_value'=>'{"tools":{"ping":true,"arp":true}}','updated_at'=>'now'];
 if(str_contains($sql,'SELECT * FROM plugin_icct_nms_diagnostic_jobs'))return ['id'=>50,'result_json'=>''];
 throw new Exception('Unexpected row');
}
function icct_backend_category_execute($sql,$args){if(str_starts_with($sql,'INSERT INTO plugin_icct_nms_diagnostic_jobs'))$GLOBALS['inserts']++;}
if(icct_backend_diag_run(2,'ping')!==50||$inserts!==1)throw new Exception('Initial submission failed');
$pending=50;
if(icct_backend_diag_run(2,'ping')!==50||$inserts!==1)throw new Exception('Duplicate job created');
try{icct_backend_diag_run(2,'arp');throw new Exception('Concurrent test accepted');}catch(RuntimeException $expected){}
echo "PASS: first submission, idempotent duplicate and concurrent-test protection during Redis outage\n";
