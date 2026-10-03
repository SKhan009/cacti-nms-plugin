<?php
// Template regression: permission gates, owner/device isolation, pagination and escaping.
$id=2; $_SESSION=['sess_user_id'=>7]; $_GET=['changes_page'=>99999,'diagnostics_page'=>2];
$management=true; $diagnosticAccess=true; $queries=[];
function is_realm_allowed($realm){global $management;return $management;}
function icct_nms_h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
$display=static fn($v)=>icct_nms_h($v ?: '—');
function icct_backend_diag_authorize_job($job){global $diagnosticAccess;if(!$diagnosticAccess)throw new RuntimeException('Denied');if($job!==['host_id'=>2,'user_id'=>7])throw new Exception('Bad owner');}
function icct_backend_diag_labels(){return ['ping'=>'Ping ICMP'];}
function db_fetch_cell_prepared($sql,$args){global $queries;$queries[]=[$sql,$args];return 51;}
function db_fetch_assoc_prepared($sql,$args){global $queries;$queries[]=[$sql,$args];if(str_contains($sql,'meta_value'))return [['meta_value'=>json_encode(['time'=>'now','user'=>'admin','action'=>'Saved','changes'=>['name'=>['before'=>'old','after'=>'<script>bad</script>']],'snapshot'=>['name'=>'saved']])]];return [['id'=>1,'tool'=>'ping','status'=>'complete','requested_at'=>'now','finished_at'=>'now','result_json'=>json_encode(['output'=>'<img src=x onerror=alert(1)>'])]];}
// Includes use caller scope for device and display variables.
function captureRecords(){global $id,$display;ob_start();include __DIR__.'/../templates/device_records.php';return ob_get_clean();}
$html=captureRecords();
assert(str_contains($html,'OFFSET 50')===false); // SQL must not leak into presentation.
assert(str_contains($html,'Configuration snapshot'));
assert(str_contains($html,'&lt;script&gt;'));
assert(str_contains($html,'&lt;img'));
assert(str_contains($html,'Page 3 of 3'));
foreach($queries as [$sql,$args]){
 if(str_contains($sql,'diagnostic_jobs'))assert($args===[2,7]);
 if(str_contains($sql,'meta_value'))assert(str_contains($sql,'OFFSET 50'));
 if(str_contains($sql,'result_json'))assert(str_contains($sql,'OFFSET 25'));
}
$diagnosticAccess=false;$queries=[];$html=captureRecords();assert(!str_contains($html,'View result'));foreach($queries as [$sql,$args])assert(!str_contains($sql,'diagnostic_jobs'));
$management=false;$queries=[];$html=captureRecords();assert(str_contains($html,'permission'));assert(!$queries);
echo "PASS: Records permissions, owner/device scope, escaped output, snapshots and independent pagination\n";
