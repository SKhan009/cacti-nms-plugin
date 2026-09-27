<?php
require __DIR__.'/../../plugins/nms/includes/workspace/service_checks.php';
$count=0;function check($ok,$message){global $count;if(!$ok)throw new RuntimeException($message);$count++;echo "PASS: $message\n";}
function rejects($input){try{nms_workspace_service_spec($input);}catch(InvalidArgumentException $e){return true;}return false;}
check(nms_workspace_service_spec(['kind'=>'https'])['port']===443,'HTTPS defaults to validated standard endpoint');
foreach([['kind'=>'ftp'],['kind'=>'http','port'=>0],['kind'=>'http','timeout'=>11],['kind'=>'http','path'=>'//other'],['kind'=>'http','path'=>'/?token=secret'],['kind'=>'http','path'=>"/\r\nInjected"],['kind'=>'dns','name'=>'-bad','expected_address'=>'127.0.0.1'],['kind'=>'dns','name'=>'ok.test','record_type'=>'AAAA','expected_address'=>'127.0.0.1']] as $input)check(rejects($input),'Rejects invalid, unbounded or ambiguous configuration');
check(nms_workspace_service_target(['hostname'=>'2001:db8::1'])==='2001:db8::1','IPv6 device target retained');
try{nms_workspace_service_target(['hostname'=>'http://other/path']);check(false,'Invalid target accepted');}catch(InvalidArgumentException $e){check(true,'URL cannot replace authorized device hostname');}
$spec=nms_workspace_service_spec(['kind'=>'dns','name'=>'app.test','expected_address'=>'192.0.2.5']);
$header=';; ->>HEADER<<- opcode: QUERY, status: NOERROR, id: 1';
check(nms_workspace_service_dns_result($header."\napp.test. 60 IN A 192.0.2.5",$spec)['passed'],'Expected DNS address satisfies criteria');
check(!nms_workspace_service_dns_result($header."\nunrelated.test. 60 IN A 192.0.2.5",$spec)['passed'],'Unrelated DNS answer cannot satisfy criteria');
check(nms_workspace_service_dns_result($header."\napp.test. 60 IN CNAME alias.test.\nalias.test. 60 IN A 192.0.2.5",$spec)['passed'],'Returned CNAME chain resolves expected address');
check(nms_workspace_service_dns_result(str_replace('NOERROR','NXDOMAIN',$header),$spec)['category']==='dns_response_error','DNS response failure differs from transport failure');
check(nms_workspace_service_dns_result('no servers could be reached',$spec)['category']==='dns_transport','Missing DNS reply is not application health');
if(isset($argv[1])) {
    $port=(int)$argv[1];$host=['hostname'=>'127.0.0.1'];
    $base=['kind'=>'http','port'=>$port,'timeout'=>1,'contains'=>'service ready'];
    $r=nms_workspace_service_probe($host,$base);check($r['passed']&&!isset($r['body']),'HTTP loopback status and body criterion succeeds without persisting body');
    $r=nms_workspace_service_probe($host,array_replace($base,['contains'=>'absent']));check(!$r['passed']&&$r['category']==='criteria_mismatch','Unexpected HTTP content fails');
    $r=nms_workspace_service_probe($host,array_replace($base,['path'=>'/redirect']));check(!$r['passed']&&$r['http_status']===302,'Redirect is not followed to another target');
    $r=nms_workspace_service_probe($host,array_replace($base,['path'=>'/denied']));check($r['category']==='access_denied','Access-denied response is identified');
    $r=nms_workspace_service_probe($host,array_replace($base,['path'=>'/large']));check($r['category']==='response_limit','Oversized response is bounded and rejected');
    $r=nms_workspace_service_probe($host,array_replace($base,['path'=>'/slow']));check($r['category']==='timeout','Slow HTTP service times out');
    $r=nms_workspace_service_probe($host,['kind'=>'tcp','port'=>$port,'timeout'=>1]);check($r['passed']&&$r['category']==='connected'&&strpos($r['message'],'does not prove')!==false,'TCP connection is explicitly not application health');
    if(isset($argv[2])){$r=nms_workspace_service_probe($host,['kind'=>'https','port'=>(int)$argv[2],'timeout'=>1]);check(!$r['passed']&&$r['category']==='tls_validation','Untrusted HTTPS certificate is rejected');}
}
if(isset($argv[3])) {
    $dns=['kind'=>'dns','port'=>(int)$argv[3],'timeout'=>1,'name'=>'app.test','expected_address'=>'192.0.2.5'];$host=['hostname'=>'127.0.0.1'];
    $r=nms_workspace_service_probe($host,$dns);check($r['passed'],'Collector DNS command accepts expected loopback fixture answer');
    $r=nms_workspace_service_probe($host,array_replace($dns,['name'=>'missing.test']));check(!$r['passed']&&$r['rcode']==='NXDOMAIN','Collector DNS command reports NXDOMAIN');
    $r=nms_workspace_service_probe($host,array_replace($dns,['record_type'=>'AAAA','expected_address'=>'2001:db8::5']));check($r['passed'],'Collector DNS command validates an IPv6 answer');
}
echo "$count service-check assertions passed.\n";
