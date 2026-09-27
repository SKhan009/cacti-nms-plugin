<?php
/** Actual SNMP transport against synthetic agents on temporary local /32 addresses. */
if(PHP_SAPI!=='cli'||empty($argv[1]))exit('Supply Cacti root');
require $argv[1].'/include/global.php';require_once $argv[1].'/plugins/nms/includes/functions.php';
require_once $argv[1].'/plugins/nms/includes/discovery_snmp.php';
require_once $argv[1].'/plugins/nms/includes/workspace/candidates.php';
$base=db_fetch_row('SELECT * FROM host WHERE id=2');if(!$base||$base['hostname']!=='127.0.0.1')throw new RuntimeException('Expected collector-loopback configuration');
$base['snmp_version']=2;$base['snmp_community']='nms-qa';$base['snmp_port']=1163;$base['snmp_timeout']=500;$base['nms_snmp_retries']=0;
$evidence=[];
foreach(['192.0.2.249','192.0.2.250','192.0.2.251'] as $target){
 if(nms_workspace_candidate_target($target)!==$target)throw new RuntimeException('Fixture is not eligible for actual candidate path');
 $host=$base;$host['hostname']=$target;$identity=nms_nd_collect_identity($host,microtime(true)+18);
 if(empty($identity['uptime'])||!nms_nd_chassis_key($identity))throw new RuntimeException('Synthetic endpoint lacks required identity');
 $addresses=array_column($identity['own_addresses'],'address');if(!in_array($target,$addresses,true))throw new RuntimeException('Endpoint does not report its own address');
 $evidence[$target]=$identity;
 echo "PASS: $target returned uptime, chassis and matching owned address through native SNMP transport.\n";
}
if(nms_nd_chassis_key($evidence['192.0.2.249'])!==nms_nd_chassis_key($evidence['192.0.2.250']))throw new RuntimeException('Management endpoints do not match');
if(nms_nd_chassis_key($evidence['192.0.2.250'])===nms_nd_chassis_key($evidence['192.0.2.251']))throw new RuntimeException('Neighbour fixture identity must be distinct');
echo "PASS: two management addresses share synthetic chassis evidence; neighbour is distinct. No device/configuration rows changed.\n";
