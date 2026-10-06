<?php
$disabled=false;$failWrite=false;$existing=false;$writes=[];$filters=[];
function icct_backend_protocol_enabled($id,$protocol){return !$GLOBALS['disabled'];}
function db_fetch_assoc_prepared($sql,$args){return [array_replace(['host_id'=>2,'source_address'=>'192.0.2.10','hostname'=>'test','max_severity'=>6,'transport'=>'udp'],$GLOBALS['filters'])];}
function db_fetch_cell_prepared($sql,$args){return $GLOBALS['presetJson'] ?? null;}
function db_fetch_row_prepared($sql,$args){return $GLOBALS['existing'] ? ['id'=>1] : [];}
function db_execute_prepared($sql,$args){$GLOBALS['writes'][]=[$sql,$args];return !$GLOBALS['failWrite'];}
require __DIR__ . '/../../protocols/syslog/services/backend/syslog.php';
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
$r=['source_ip'=>'192.0.2.10','hostname'=>'test','input_name'=>'icct-udp','severity_code'=>3,'facility_code'=>1,'message'=>'test message','timestamp'=>'2026-10-05T12:30:00Z'];
check(icct_backend_syslog_ingest($r,$reason)==='inserted','Valid mapped event');
check($writes[0][1][11]==='major','Severity mapping');
$existing=true;check(icct_backend_syslog_ingest($r,$reason)==='repeated','Duplicate aggregation');$existing=false;
check(icct_backend_syslog_ingest(array_replace($r,['severity_code'=>7]),$reason)==='dropped'&&$reason==='severity-filtered','Severity filter');
check(icct_backend_syslog_ingest(array_replace($r,['input_name'=>'icct-tcp']),$reason)==='dropped','Transport filter');
check(icct_backend_syslog_ingest(array_replace($r,['input_name'=>'unknown']),$reason)==='dropped','Unknown transport');
check(icct_backend_syslog_ingest(array_replace($r,['source_ip'=>'192.0.2.11']),$reason)==='dropped','Unmanaged source');
$disabled=true;check(icct_backend_syslog_ingest($r,$reason)==='dropped','Disabled binding');$disabled=false;
$filters=['severity_codes'=>'[3]','facility_codes'=>'[16]','match_strings'=>'[]'];
check(icct_backend_syslog_ingest($r,$reason)==='dropped'&&$reason==='facility-filtered','Selected facilities');
$filters=['severity_codes'=>'[3]','facility_codes'=>'[1]','match_strings'=>'["access denied"]'];
check(icct_backend_syslog_ingest($r,$reason)==='dropped'&&$reason==='keyword-filtered','Keyword policy');
check(icct_backend_syslog_ingest(array_replace($r,['message'=>'ACCESS DENIED to operator']),$reason)==='inserted','Case-insensitive match');
$filters=[];
$presetJson=json_encode(['syslog'=>['match_strings'=>'["auth failed"]']]);
check(icct_backend_syslog_ingest($r,$reason)==='dropped'&&$reason==='keyword-filtered','Legacy bindings inherit new preset fields');
check(icct_backend_syslog_ingest(array_replace($r,['message'=>'auth failed on device']),$reason)==='inserted','Inherited keyword accepted');
$filters=['match_strings'=>'[]'];
check(icct_backend_syslog_ingest($r,$reason)==='inserted','Explicit empty device override accepts all keywords');
$presetJson=null;$filters=[];
$failWrite=true;try{icct_backend_syslog_ingest($r,$reason);throw new RuntimeException('Failed storage was reported as successful');}catch(IcctSyslogStorageException $e){}
echo "Syslog policy, severity, dedupe, and storage failure tests passed.\n";
