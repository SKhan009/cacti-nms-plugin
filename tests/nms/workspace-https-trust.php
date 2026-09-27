<?php
/** Real loopback TLS; trust is supplied only through this CLI process's curl.cainfo. */
if (PHP_SAPI !== 'cli' || count($argv) !== 4) exit("Supply engine path, TLS port and trusted|untrusted mode\n");
require $argv[1];
$spec=['kind'=>'https','port'=>(int)$argv[2],'timeout'=>3,'contains'=>'service ready'];
$count=0;
function verify($ok,$message) { global $count; if(!$ok)throw new RuntimeException($message); $count++; echo "PASS: $message\n"; }
$r=nms_workspace_service_probe(['hostname'=>'127.0.0.1'],$spec);
if($argv[3]==='untrusted') {
    verify(!$r['passed'] && $r['category']==='tls_validation' && !$r['tls_verified'],'Default trust rejects the private fixture certificate');
} elseif($argv[3]==='trusted') {
    verify($r['passed'] && $r['category']==='criteria_met' && $r['tls_verified'] && $r['http_status']===200,'Trusted HTTPS verifies chain and IP identity and satisfies response criteria');
    verify(!isset($r['body']) && !isset($r['certificate']) && $r['elapsed_ms']>=0,'Structured result retains timing without response body or certificate');
    $r=nms_workspace_service_probe(['hostname'=>'127.0.0.1'],array_replace($spec,['contains'=>'not present']));
    verify(!$r['passed'] && $r['tls_verified'] && $r['category']==='criteria_mismatch','Valid TLS alone cannot satisfy incorrect application criteria');
    $r=nms_workspace_service_probe(['hostname'=>'localhost'],$spec);
    verify(!$r['passed'] && $r['category']==='tls_validation' && !$r['tls_verified'],'Trusted CA does not bypass hostname validation');
} else throw new InvalidArgumentException('Unknown test mode');
echo "$count actual loopback TLS assertions passed.\n";
