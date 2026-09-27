<?php
/** Actual collector-loopback SNMP using capped native retry settings; no configuration writes. */
if(PHP_SAPI!=='cli'||empty($argv[1])||empty($argv[2]))exit('Supply Cacti root and staged settings module');
require $argv[1].'/include/global.php';
require_once $argv[1].'/plugins/nms/includes/functions.php';
require_once $argv[1].'/plugins/nms/includes/discovery_snmp.php';
require $argv[2];
$host=db_fetch_row('SELECT * FROM host WHERE id=2');if(!$host||$host['hostname']!=='127.0.0.1')throw new RuntimeException('Explicit loopback fixture required');
$before=$host;$retryBefore=read_config_option('snmp_retries');
// Exercise a native-style retry setting above the verification budget for this call only.
$probe=$host;$probe['nms_snmp_retries']=3;$probe=nms_workspace_bounded_snmp($probe);
if($probe['nms_snmp_retries']!==2)throw new RuntimeException('Retry cap did not apply');
$identity=nms_nd_collect_identity($probe,microtime(true)+18);
if(!isset($identity['uptime']))throw new RuntimeException('Actual loopback agent did not return the mandatory uptime scalar');
if($before!==db_fetch_row('SELECT * FROM host WHERE id=2')||$retryBefore!==read_config_option('snmp_retries'))throw new RuntimeException('Native polling settings changed');
echo "PASS: actual collector-loopback SNMP returned uptime with verification retries capped at 2; native host and global settings unchanged.\n";
