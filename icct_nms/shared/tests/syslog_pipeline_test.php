<?php
if(!function_exists('mb_strlen')){function mb_strlen($s){return strlen($s);}function mb_stripos($h,$n){return stripos($h,$n);}}
$GLOBALS['spool_state']=[];$GLOBALS['events']=[];$GLOBALS['next_id']=1;
function icct_backend_protocol_enabled($id,$protocol){return true;}
function db_fetch_assoc_prepared($sql,$args){
 if(str_contains($sql,'plugin_icct_nms_syslog_devices')){if(($args[0]??'')==='192.0.2.10'||($args[1]??'')==='192.0.2.10')return [['host_id'=>7,'source_address'=>'192.0.2.10','hostname'=>'router1','max_severity'=>7,'transport'=>'both','severity_codes'=>'[0,1,2,3,4,5,6,7]','facility_codes'=>'[0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23]','match_strings'=>'[]','description'=>'Router 1','poller_id'=>1]];return[];}
 return[];
}
function db_fetch_cell_prepared($sql,$args){if(str_contains($sql,'syslog_dedupe_seconds'))return 60;if(str_contains($sql,'protocol_defaults'))return null;return null;}
function db_fetch_row_prepared($sql,$args){
 if(str_contains($sql,'plugin_icct_nms_syslog_ingest_state'))return $GLOBALS['spool_state'];
 if(str_contains($sql,'fingerprint')){foreach($GLOBALS['events'] as $id=>$e)if($e['host_id']==$args[0]&&$e['fingerprint']===$args[1])return['id'=>$id];return[];}
 return[];
}
function db_execute_prepared($sql,$args){
 if(str_contains($sql,'INSERT INTO plugin_icct_nms_syslog_events')){$id=$GLOBALS['next_id']++;$GLOBALS['events'][$id]=['host_id'=>$args[0],'origin'=>$args[1],'message'=>$args[14],'fingerprint'=>$args[15],'repeat_count'=>1];return true;}
 if(str_contains($sql,'UPDATE plugin_icct_nms_syslog_events SET repeat_count')){$id=(int)$args[2];$GLOBALS['events'][$id]['repeat_count']++;return true;}
 if(str_contains($sql,'plugin_icct_nms_syslog_ingest_state')){$GLOBALS['spool_state']=['source_key'=>$args[0],'source_path'=>$args[1],'source_inode'=>$args[2],'source_offset'=>$args[3],'last_record_at'=>$args[4],'records_ingested'=>$args[5],'records_dropped'=>$args[6],'errors'=>$args[7]];return true;}
 if(str_contains($sql,'plugin_icct_nms_meta'))return true;
 if(str_contains($sql,'DELETE FROM plugin_icct_nms_syslog_ingest_state')){$GLOBALS['spool_state']=[];return true;}
 return true;
}
require __DIR__.'/../../protocols/syslog/services/backend/syslog.php';
function sp_check($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
$f=tempnam(sys_get_temp_dir(),'icct_syslog_');
$base=['hostname'=>'router1','input_name'=>'icct-udp','program'=>'ICCTTEST','facility'=>'local0','facility_code'=>16,'severity'=>'err','severity_code'=>3,'timestamp'=>'2026-10-12T12:00:00Z'];
$lines=[array_merge($base,['source_ip'=>'192.0.2.10','message'=>'Interface down']),array_merge($base,['source_ip'=>'192.0.2.10','message'=>'Interface down']),array_merge($base,['source_ip'=>'192.0.2.99','message'=>'Unknown sender']),'{bad json',array_merge($base,['source_ip'=>'192.0.2.10','severity'=>'warning','severity_code'=>4,'message'=>'UPS battery low'])];
$payload='';foreach($lines as $l)$payload.=(is_array($l)?json_encode($l):$l)."\n";file_put_contents($f,$payload);
$r=icct_backend_syslog_import_spool_once($f,100);sp_check($r['processed']===5,'Spool processed count wrong.');sp_check($r['inserted']===2&&$r['repeated']===1,'Insert/dedupe pipeline failed.');sp_check($r['dropped']===1&&($r['drop_reasons']['unmapped-source']??0)===1,'Unmapped sender policy failed.');sp_check($r['errors']===1,'Malformed JSON handling failed.');sp_check(count($GLOBALS['events'])===2&&$GLOBALS['events'][1]['repeat_count']===2&&$GLOBALS['events'][1]['origin']==='syslog','Console event storage/dedupe state failed.');
$r2=icct_backend_syslog_import_spool_once($f,100);sp_check($r2['processed']===0,'Saved spool cursor did not prevent duplicate replay.');
file_put_contents($f,json_encode(array_merge($base,['source_ip'=>'192.0.2.10','message'=>'After rotation']))."\n");
$r3=icct_backend_syslog_import_spool_once($f,100);sp_check($r3['processed']===1&&$r3['inserted']===1,'Truncated/replaced spool was not read from start.');
unlink($f);echo "Syslog NDJSON spool -> mapping -> filter -> dedupe -> storage cursor -> rotation pipeline tests passed.\n";
