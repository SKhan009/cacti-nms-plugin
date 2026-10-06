<?php
require __DIR__.'/../../inventory/diagnostics/services/backend/diagnostics.php';
require __DIR__.'/../../inventory/diagnostics/services/backend/diagnostics_queue.php';
function check($value,$message){if(!$value)throw new RuntimeException($message);}
$profile=icct_backend_diag_profile_validate(['diagnostic_profile_name'=>'Device test','diagnostic_tools'=>['mtr_icmp','mtr_tcp'],'mtr_cycles'=>9,'mtr_background'=>1,'mtr_interval'=>60]);
check($profile['mtr_cycles']===9 && $profile['mtr_background']===1,'Saved settings missing');
foreach ([['mtr_cycles'=>0],['mtr_cycles'=>31],['mtr_interval'=>59],['mtr_interval'=>3601],['diagnostic_tools'=>['ping']]] as $invalid) {
    try {icct_backend_diag_profile_validate($invalid+['diagnostic_profile_name'=>'test','diagnostic_tools'=>['mtr_icmp'],'mtr_background'=>1]);throw new Exception('Invalid settings accepted');}catch(InvalidArgumentException $expected){}
}
$row=$profile+['host_id'=>2,'hostname'=>'127.0.0.1','poller_id'=>1,'id'=>4,'disabled'=>'','description'=>'Test'];
foreach (['mtr_icmp','mtr_tcp'] as $tool) {
    [$args, $timeout]=icct_backend_diag_arguments($row,$tool,'mtr');
    check($args[array_search('--report-cycles',$args,true)+1]==='9','MTR count not used');
}
$original=icct_backend_diag_signature($row);$changed=$row;$changed['mtr_interval']=120;
check($original!==icct_backend_diag_signature($changed),'Monitoring changes must invalidate queued reports');
$busy=false;$revoked=false;$last=null;$inserted=[];$enabled=1;
function db_fetch_cell_prepared($sql,$args){
    global $busy,$revoked,$last,$enabled;
    if(str_contains($sql,'GET_LOCK')||str_contains($sql,'RELEASE_LOCK'))return 1;
    if(str_contains($sql,'plugin_config'))return $enabled;
    if(str_contains($sql,'status IN'))return $busy?99:null;
    if(str_contains($sql,'user_auth'))return $revoked?'':'on';
    if(str_contains($sql,'plugin_realms'))return 25;
    if(str_contains($sql,'MAX(requested_at)'))return $last;
    throw new Exception('Unexpected cell query');
}
function db_fetch_assoc_prepared($sql,$args){return [['host_id'=>2,'tools'=>'mtr_icmp,mtr_tcp','updated_by'=>7]];}
function db_fetch_row_prepared($sql,$args){return $GLOBALS['row'];}
function is_realm_allowed($realm,$user){return !$GLOBALS['revoked'];}
function is_device_allowed($host,$user){return !$GLOBALS['revoked'];}
function icct_backend_category_execute($sql,$args){$GLOBALS['inserted'][]=$args;}
// Check the installed executable; all database operations are stubbed and no probes run.
[, $binary]=icct_backend_diag_executable('mtr_icmp');
if($binary){
 check(icct_backend_mtr_monitor_once(1)===true && count($inserted)===1,'Due report not scheduled');
 check($inserted[0][0]===2 && $inserted[0][2]===7 && $inserted[0][3]==='mtr_icmp','Wrong device, owner or method');
 $busy=true;check(icct_backend_mtr_monitor_once(1)===false,'Foreground work must take priority');$busy=false;
 $last=date('Y-m-d H:i:s');check(icct_backend_mtr_monitor_once(1)===false,'Reports repeated before interval');$last=null;
 $revoked=true;check(icct_backend_mtr_monitor_once(1)===false,'Revoked owner scheduled');$revoked=false;
 $enabled=0;check(icct_backend_mtr_monitor_once(1)===false,'Disabled plugin scheduled');$enabled=1;
 $row['mtr_background']=0;check(icct_backend_mtr_monitor_once(1)===false,'Disabled monitoring scheduled');
}else throw new RuntimeException('MTR test requires installed mtr executable');
echo "MTR monitoring settings, scheduling, cadence and authorization tests passed\n";
