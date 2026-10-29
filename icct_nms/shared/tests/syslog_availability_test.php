<?php
if (!defined('HOST_UNKNOWN')) define('HOST_UNKNOWN',0);
if (!defined('HOST_DOWN')) define('HOST_DOWN',1);
if (!defined('HOST_RECOVERING')) define('HOST_RECOVERING',2);
if (!defined('HOST_UP')) define('HOST_UP',3);
if (!defined('HOST_ERROR')) define('HOST_ERROR',4);
if(!function_exists('mb_strlen')){function mb_strlen($s){return strlen($s);} function mb_stripos($h,$n){return stripos($h,$n);}}
$GLOBALS['rows']=[['host_id'=>23,'source_address'=>'','description'=>'MIMIC device','hostname'=>'172.195.123.23','status'=>HOST_UP,'disabled'=>'','status_event_count'=>0,'status_fail_date'=>'','status_rec_date'=>'']];
$GLOBALS['state']=[];$GLOBALS['writes']=[];
function icct_backend_host_status_name($status){return [HOST_UNKNOWN=>'Unknown',HOST_DOWN=>'Down',HOST_RECOVERING=>'Recovering',HOST_UP=>'Up',HOST_ERROR=>'Error'][$status]??'Invalid';}
function icct_backend_poller_interval(){return 300;}
function db_fetch_assoc($sql){return $GLOBALS['rows'];}
function db_fetch_assoc_prepared($sql,$args){return [];}
function db_fetch_cell($sql){return 'test';}
function icct_backend_protocol_enabled($id,$protocol){return !($GLOBALS['protocolDisabled']??false);}
function db_fetch_cell_prepared($sql,$args){return str_contains($sql,'GET_LOCK')?($GLOBALS['lockAvailable']??1):null;}
function db_fetch_row_prepared($sql,$args){if(strpos($sql,'syslog_availability_state')!==false)return $GLOBALS['state'];return [];}
function db_execute_prepared($sql,$args){$GLOBALS['writes'][]=[$sql,$args];return true;}
require __DIR__.'/../../protocols/syslog/services/backend/syslog.php';
function check($c,$m){if(!$c)throw new RuntimeException($m);}

$t=icct_backend_syslog_availability_transition(HOST_UP,0,HOST_DOWN,0);
check($t&&$t['severity_code']===3&&$t['old']==='Up'&&$t['new']==='Down','Up->Down is major availability event');
$t=icct_backend_syslog_availability_transition(HOST_DOWN,0,HOST_UP,0);
check($t&&$t['severity_code']===5&&$t['new']==='Up','Down->Up is recovery notice');
check(icct_backend_syslog_availability_transition(HOST_UP,0,HOST_UP,0)===null,'No duplicate event while unchanged');
$t=icct_backend_syslog_availability_transition(HOST_UP,0,HOST_UP,1);
check($t&&$t['new']==='Disabled','Disable transition is represented');

// First poll seeds state without flooding healthy devices.
$r=icct_backend_syslog_collect_availability_events();
check($r['checked']===1&&$r['initialized']===1&&$r['events']===0,'Initial healthy observation seeds only');

// Simulate Cacti changing host.status after the remote VM stops answering.
$GLOBALS['state']=['last_status'=>HOST_UP,'last_disabled'=>0];
$GLOBALS['rows'][0]['status']=HOST_DOWN;
$GLOBALS['writes']=[];
$r=icct_backend_syslog_collect_availability_events();
check($r['events']===1,'Down transition stored');
$eventWrites=array_values(array_filter($GLOBALS['writes'],fn($w)=>strpos($w[0],'plugin_icct_nms_syslog_events')!==false));
check(count($eventWrites)===1,'Exactly one availability event inserted');
$args=$eventWrites[0][1];
check($args[1]==='availability','Availability origin is explicit');
check($args[7]==='internal','Availability event is not mislabelled as UDP/TCP Syslog');
check($args[13]==='ICCT_AVAILABILITY','Availability program tag');
check(strpos($args[14],'Up to Down')!==false,'Down message identifies transition');

// Same status must not create another row.
$GLOBALS['state']=['last_status'=>HOST_DOWN,'last_disabled'=>0];
$GLOBALS['writes']=[];
$r=icct_backend_syslog_collect_availability_events();
check($r['events']===0&&$r['unchanged']===1,'Unchanged Down does not duplicate');

// Recovery creates one event.
$GLOBALS['rows'][0]['status']=HOST_UP;
$GLOBALS['state']=['last_status'=>HOST_DOWN,'last_disabled'=>0];
$GLOBALS['writes']=[];
$r=icct_backend_syslog_collect_availability_events();
check($r['events']===1,'Recovery stored');
$eventWrites=array_values(array_filter($GLOBALS['writes'],fn($w)=>strpos($w[0],'plugin_icct_nms_syslog_events')!==false));
check(strpos($eventWrites[0][1][14],'Down to Up')!==false,'Recovery message identifies transition');

// Competing collectors must not process the same transition.
$GLOBALS['lockAvailable']=0;$GLOBALS['writes']=[];
$r=icct_backend_syslog_collect_availability_events();
check($r['skipped']===1&&!$GLOBALS['writes'],'Busy collector skips all writes');
$GLOBALS['lockAvailable']=1;$GLOBALS['protocolDisabled']=true;$GLOBALS['writes']=[];
$r=icct_backend_syslog_collect_availability_events();
check($r['checked']===0&&$r['events']===0,'Protocol-disabled devices are excluded');
check(str_contains(file_get_contents(__DIR__.'/../../protocols/syslog/services/backend/syslog.php'),'INNER JOIN plugin_icct_nms_syslog_devices d'),'Only assigned devices are queried');
$GLOBALS['protocolDisabled']=false;
echo "PASS: automatic Cacti availability -> Syslog/Event console transition tests.\n";
