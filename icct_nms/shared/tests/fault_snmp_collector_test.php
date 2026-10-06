<?php
require __DIR__ . '/../../fcaps/services/fcaps_service.php';
$fixture=sys_get_temp_dir().'/icct-snmp-fault-'.getmypid();mkdir($fixture.'/lib',0700,true);foreach(['rrd','snmp'] as $file)file_put_contents($fixture.'/lib/'.$file.'.php','<?php');$config=['base_path'=>$fixture];define('SNMP_STRING_OUTPUT_ASCII',1);
$host=['id'=>5,'hostname'=>'192.0.2.15','snmp_version'=>2,'snmp_community'=>'private-fixture','snmp_username'=>'','snmp_password'=>'','snmp_auth_protocol'=>'','snmp_priv_passphrase'=>'','snmp_priv_protocol'=>'','snmp_context'=>'','snmp_port'=>161,'snmp_timeout'=>1000,'snmp_engine_id'=>''];
$base=['source'=>'snmp','parameter'=>'Battery temperature','oid'=>'1.3.6.1.4.1.999.1.0','scale'=>0.1,'units'=>'°C','minimum'=>null,'maximum'=>40,'condition'=>'above','severity'=>'Warning','enabled'=>true];$rules=[$base,array_replace($base,['maximum'=>50,'severity'=>'Critical'])];$writes=[];$reads=0;$response='INTEGER: 450';$enabled=true;
function icct_backend_inventory_collector_id(){return 1;}
function read_config_option($key){return $key==='poller_interval'?300:1;}
function icct_backend_protocol_enabled($id,$protocol){if($id!==5||$protocol!=='snmp')throw new RuntimeException('Incorrect host');return $GLOBALS['enabled'];}
function db_fetch_assoc_prepared($sql,$args){if(str_contains($sql,'SELECT h.*'))return [$GLOBALS['host']];throw new RuntimeException('Direct SNMP incorrectly requires a graph');}
function db_fetch_cell_prepared($sql,$args){return json_encode($GLOBALS['rules']);}
function icct_backend_category_execute($sql,$args){$GLOBALS['writes'][]=$args;}
function cacti_snmp_get(...$args){if($args[0]!=='192.0.2.15'||$args[1]!=='private-fixture')throw new RuntimeException('Incorrect device credentials');$GLOBALS['reads']++;return $GLOBALS['response'];}
function latest(){return json_decode(end($GLOBALS['writes'])[1],true)['results'];}
icct_backend_collect_faults();$results=latest();if($reads!==1||$results[0]['value']!==45||$results[0]['state']!=='Warning'||$results[1]['state']!=='Normal')throw new RuntimeException('OID caching, scaling or evaluation failed');
$response='No Such Instance';icct_backend_collect_faults();if(latest()[0]['state']!=='Unknown'||latest()[0]['value']!==null)throw new RuntimeException('Failed SNMP falsely raised or cleared alarm');
$enabled=false;$before=$reads;icct_backend_collect_faults();if($reads!==$before||latest()[0]['state']!=='Unknown')throw new RuntimeException('Disabled SNMP polled');
foreach(['rrd','snmp'] as $file)unlink($fixture.'/lib/'.$file.'.php');rmdir($fixture.'/lib');rmdir($fixture);
echo "SNMP collector uses assigned device credentials, caches OIDs, scales readings, respects disabled protocols and reports failures as Unknown.\n";
