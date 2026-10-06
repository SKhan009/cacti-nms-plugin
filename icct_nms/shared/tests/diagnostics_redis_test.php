<?php
require __DIR__ . '/../../inventory/diagnostics/services/backend/diagnostics_redis.php';
$config=['base_path'=>'icct-redis-test-'.bin2hex(random_bytes(8))];
if(icct_backend_diag_redis(['PING'])!=='PONG')throw new Exception('Local Redis unavailable');
$key=icct_backend_diag_redis_key('test');
$value="safe\r\n$ unicode ✓";
if(icct_backend_diag_redis(['SET',$key,$value,'EX',5])!=='OK'||icct_backend_diag_redis(['GET',$key])!==$value)throw new Exception('RESP framing/TTL result failed');
if(icct_backend_diag_redis(['TTL',$key])<1)throw new Exception('Redis result did not expire');
function db_fetch_row_prepared($sql,$args){return $GLOBALS['job'];}
$job=['id'=>7,'host_id'=>2,'user_id'=>1,'poller_id'=>1,'tool'=>'ping','config_hash'=>'abc','status'=>'complete','finished_at'=>'now','result_json'=>'{"output":"test"}'];
icct_backend_diag_redis_publish(7);
if(icct_backend_diag_redis_result($job)!==$job['result_json'])throw new Exception('Cached result unavailable');
foreach(['id','host_id','user_id','poller_id','tool','config_hash','status','finished_at'] as $field){$changed=$job;$changed[$field]='different';if(icct_backend_diag_redis_result($changed)!==null)throw new Exception('Mismatched '.$field.' accepted');}
icct_backend_diag_redis_enqueue(1,7);icct_backend_diag_redis_enqueue(1,8);
if(icct_backend_diag_redis_wake(1)!=='7'||icct_backend_diag_redis_wake(1)!=='8'||icct_backend_diag_redis_wake(1)!==null)throw new Exception('FIFO notifications failed');
icct_backend_diag_redis(['DEL',$key,icct_backend_diag_redis_key('result:7')]);
$config['icct_nms_redis_socket']='/tmp/icct-missing-redis.sock';
if(icct_backend_diag_redis(['PING'])!==null)throw new Exception('Unavailable transport did not fall back');
echo "PASS: Redis FIFO, binary framing, TTL, owner/device/collector/config guards and outage fallback\n";
