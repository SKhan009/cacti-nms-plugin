<?php
$GLOBALS['calls']=[];
function db_fetch_cell_prepared($sql,$args){$GLOBALS['calls'][]=['type'=>'cell','sql'=>$sql,'args'=>$args];return 1;}
function db_fetch_assoc_prepared($sql,$args){$GLOBALS['calls'][]=['type'=>'assoc','sql'=>$sql,'args'=>$args];return [[
    'id'=>42,'host_id'=>7,'description'=>'Router 1','hostname'=>'192.0.2.10','event_time'=>'2026-10-12 12:00:00',
    'nms_severity'=>'major','event_origin'=>'syslog','source_ip'=>'192.0.2.10','facility'=>'local0','program'=>'ICCTTEST','message'=>'Ethernet1 link down',
    'repeat_count'=>3,'acknowledged'=>0,'ack_username'=>null
]];}
require __DIR__.'/../../protocols/syslog/services/backend/syslog.php';
function cq_check($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
$total=0;
$rows=icct_backend_syslog_events([7,8],[
    'host_id'=>7,'severity'=>'major','origin'=>'syslog','ack'=>'no','search'=>'Ethernet','since'=>'2026-10-12 00:00:00'
],50,0,$total);
cq_check($total===1&&count($rows)===1&&$rows[0]['id']===42,'Device event event retrieval failed.');
$assoc=array_values(array_filter($GLOBALS['calls'],fn($c)=>$c['type']==='assoc'))[0];
$sql=$assoc['sql'];
foreach(['e.host_id IN (?,?)','e.host_id=?','e.nms_severity=?','e.event_origin=?','e.acknowledged=0','e.message LIKE ?','e.event_time>=?','ORDER BY e.event_time DESC,e.id DESC','LIMIT 0,50'] as $needle){cq_check(str_contains($sql,$needle),'Device event query missing: '.$needle);}
cq_check($assoc['args'][0]===7&&$assoc['args'][1]===8&&$assoc['args'][2]===7&&$assoc['args'][3]==='major'&&$assoc['args'][4]==='syslog','Device event ACL/filter parameters are wrong.');
$GLOBALS['calls']=[];$total=99;$none=icct_backend_syslog_events([],[],50,0,$total);cq_check($none===[]&&$total===0&&$GLOBALS['calls']===[],'Empty ACL must return no events without querying.');
echo "Syslog event ACL, filter, search, time, acknowledgement and pagination query tests passed.\n";
