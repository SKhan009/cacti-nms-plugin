<?php
/** Native Cacti IPv4 planning only: read-only, no discovery or packets. */
if(PHP_SAPI!=='cli' || empty($argv[1]) || empty($argv[2]))exit('Supply Cacti root and scan_targets.php path');
require $argv[1].'/include/global.php';
require $argv[2];
function verify($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
$targets=nms_scan_targets('192.0.2.0/23','nms_scan_native_ipv4');
verify(count($targets)===510 && $targets[0]==='192.0.2.1' && end($targets)==='192.0.3.254','Native IPv4 adapter expands more than 256 addresses using core subnet rules');
$combined=nms_scan_targets("192.0.2.0/23\n192.0.2.1\n2001:db8::1",'nms_scan_native_ipv4');
verify(count($combined)===511 && end($combined)==='2001:db8::1','Mixed IPv4/IPv6 scan preserves native expansion and deduplicates');
$rejected=false;try{nms_scan_targets('192.0.0.0/16','nms_scan_native_ipv4');}catch(InvalidArgumentException $e){$rejected=true;}
verify($rejected,'Oversized native IPv4 range is rejected before enumeration');
echo "3 assertions passed (native planning only; no network probes).\n";
