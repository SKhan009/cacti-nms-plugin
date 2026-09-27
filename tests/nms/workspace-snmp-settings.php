<?php
require __DIR__.'/../../plugins/nms/includes/workspace/snmp_settings.php';
$retry='1';function read_config_option($name){global $retry;return $retry;}
function verify($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
$host=['snmp_version'=>2,'snmp_timeout'=>1000];
verify(nms_workspace_bounded_snmp($host)['nms_snmp_retries']===1,'Uses effective Cacti global retry setting');
verify(nms_workspace_bounded_snmp($host+['nms_snmp_retries'=>0])['nms_snmp_retries']===0,'Explicit collector retry override matches transport');
foreach(['-1','abc',[],null] as $retry){try{nms_workspace_bounded_snmp($host);throw new LogicException('Unexpected acceptance');}catch(RuntimeException $e){verify(true,'Invalid retry settings rejected');}}
$retry='3';
verify(nms_workspace_bounded_snmp($host)['nms_snmp_retries']===2,'Higher native retry count is capped for verification');
verify(nms_workspace_bounded_snmp($host+['nms_snmp_retries'=>5])['nms_snmp_retries']===2,'Explicit retry override cannot exceed verification budget');
verify(!array_key_exists('nms_snmp_retries',$host),'Original polling configuration is not mutated');
$retry='2';
foreach([['snmp_version'=>0],['snmp_timeout'=>0],['snmp_timeout'=>2001]] as $bad){try{nms_workspace_bounded_snmp(array_replace($host,$bad));throw new LogicException('Unexpected acceptance');}catch(RuntimeException $e){verify(true,'Disabled or unbounded SNMP settings rejected');}}
echo "12 assertions passed.\n";
