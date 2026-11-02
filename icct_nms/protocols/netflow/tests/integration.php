<?php
/** Run using native Cacti APIs with connection-local tables. Never writes saved device settings. */
if(PHP_SAPI!=='cli')exit(1);
try{
require '/var/www/html/cacti/include/cli_check.php';api_plugin_load_realms();require_once $config['base_path'].'/lib/auth.php';
if(session_status()===PHP_SESSION_NONE)session_start();$_SESSION=['sess_user_id'=>1];
require_once __DIR__.'/../../../shared/services/bootstrap.php';require_once __DIR__.'/../services/netflow_collector.php';icct_nms_backend();date_default_timezone_set('Asia/Kolkata');
$tables=['meta','netflow_devices','netflow_state','netflow_buckets','netflow_metrics','netflow_events','netflow_flows','netflow_files'];
foreach($tables as $short){$table='plugin_icct_nms_'.$short;if(!db_execute('CREATE TEMPORARY TABLE qa_nf_copy LIKE '.$table))throw new RuntimeException('Temporary isolation failed');if($short==='meta'&&!db_execute('INSERT INTO qa_nf_copy SELECT * FROM '.$table))throw new RuntimeException('Metadata isolation failed');if(!db_execute('CREATE TEMPORARY TABLE '.$table.' LIKE qa_nf_copy'))throw new RuntimeException('Temporary isolation failed');if($short==='meta')db_execute('INSERT INTO '.$table.' SELECT * FROM qa_nf_copy');db_execute('DROP TEMPORARY TABLE qa_nf_copy');}
function nfAssert($condition,$message){if(!$condition)throw new RuntimeException($message);}
$host=db_fetch_row("SELECT * FROM host WHERE deleted='' ORDER BY id LIMIT 1");$id=(int)$host['id'];
$nfSettings=icct_nms_netflow_defaults();$nfSettings['collector']='127.0.0.1';$nfSettings['port']=22055;$nfSettings['exporter_address']='127.0.0.77';
foreach([5,7,9,10] as $version){$nfSettings['version']=$version;icct_nms_netflow_save($id,$host,$nfSettings);nfAssert((int)icct_nms_netflow_assignment($id)['version']===$version,'Version save failed');}
try{icct_nms_netflow_save($id,$host,array_replace($nfSettings,['version'=>8]));throw new LogicException('Bad version accepted');}catch(InvalidArgumentException $e){}
nfAssert(icct_nms_netflow_cidr('10.1.2.3','10.0.0.0/8')&&!icct_nms_netflow_cidr('11.1.2.3','10.0.0.0/8')&&icct_nms_netflow_cidr('2001:db8::1','2001:db8::/32'),'CIDR matching failed');
$assignment=icct_nms_netflow_assignment($id);$count=0;$nfOptions=getopt('',['captures:']);$nfCapturePath=$nfOptions['captures']??'/tmp/icct-netflow-qa/127-0-0-77';
$nfRecord=['src4_addr'=>'10.0.0.1','dst4_addr'=>'10.0.0.2','first'=>date('c',time()-1),'last'=>date('c'),'in_bytes'=>100,'in_packets'=>10];
$nfNormalized=icct_nms_netflow_normalize($nfRecord,5,array_replace($assignment,['sampling_rate'=>100]));nfAssert($nfNormalized['bytes']===10000&&$nfNormalized['traffic_scope']==='LAN','Configured sampling fallback or LAN classification failed');
$nfNormalized=icct_nms_netflow_normalize($nfRecord+['sampled'=>1],5,array_replace($assignment,['sampling_rate'=>100]));nfAssert($nfNormalized['bytes']===100,'Advertised sampling was multiplied twice');
$nfNormalized=icct_nms_netflow_normalize($nfRecord+['input_snmp'=>2],9,array_replace($assignment,['wan_interfaces'=>'2']));nfAssert($nfNormalized['traffic_scope']==='WAN','Explicit WAN interface classification did not override LAN prefixes');

foreach(glob($nfCapturePath.'/nfcapd.20*') as $file)$count+=icct_nms_netflow_import_file($assignment,$file);
nfAssert($count===4,'All four wire fixtures must import exactly once, got '.$count);
nfAssert(array_map('intval',array_column(db_fetch_assoc('SELECT DISTINCT version FROM plugin_icct_nms_netflow_flows ORDER BY version'),'version'))===[5,7,9,10],'Actual decoded versions incorrect');
$totals=db_fetch_row('SELECT SUM(bytes) AS bytes,SUM(packets) AS packets,MIN(first_seen) AS first_seen,MAX(last_seen) AS last_seen FROM plugin_icct_nms_netflow_flows');nfAssert((int)$totals['bytes']===49360&&(int)$totals['packets']===40,'Counters changed while decoding');
foreach(glob($nfCapturePath.'/nfcapd.20*') as $file)nfAssert(icct_nms_netflow_import_file($assignment,$file)===0,'Capture retry duplicated counters');
nfAssert(strtotime($totals['last_seen'])-strtotime($totals['first_seen'])===1,'Wire timestamps were not preserved');
$read=icct_nms_netflow_read($id,['hours'=>24]);nfAssert((int)$read['totals']['flows']===4&&count($read['rankings']['conversations'])===1,'Read model totals/conversations wrong');
foreach([5,7,9,10] as $version)nfAssert((int)icct_nms_netflow_read($id,['version'=>$version])['totals']['flows']===1,'Version filtering failed');
nfAssert((int)icct_nms_netflow_read($id,['src_addr'=>'192.0.2.99'])['totals']['flows']===0,'Source filter leaks unrelated records');
try{icct_nms_netflow_read(16777215,[]);throw new LogicException('Forbidden device read accepted');}catch(RuntimeException $e){}
try{icct_nms_netflow_read($id,['start'=>'2020-01-01','end'=>'2026-01-01']);throw new LogicException('Unbounded date filter accepted');}catch(InvalidArgumentException $e){}
$nfSettings=array_replace($nfSettings,['scan_threshold'=>2,'syn_pps_threshold'=>1,'bandwidth_limit_mbps'=>1]);icct_nms_netflow_save($id,$host,$nfSettings);
db_execute('UPDATE plugin_icct_nms_netflow_flows SET last_seen=DATE_SUB(NOW(),INTERVAL 10 MINUTE),received_at=DATE_SUB(NOW(),INTERVAL 10 MINUTE)');
$sample=db_fetch_row('SELECT * FROM plugin_icct_nms_netflow_flows LIMIT 1');unset($sample['id']);
foreach([['10.22.1.1',135],['10.22.1.2',445]] as $i=>[$dst,$port]){$row=array_replace($sample,['capture_key'=>hash('sha256','anomaly-fixture-'.$i),'dst_addr'=>$dst,'dst_port'=>$port,'tcp_flags'=>'......S.','bytes'=>50000000,'packets'=>10000,'last_seen'=>date('Y-m-d H:i:s'),'received_at'=>date('Y-m-d H:i:s')]);db_execute_prepared('INSERT INTO plugin_icct_nms_netflow_flows('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')',array_values($row));}
icct_nms_netflow_anomalies($id);nfAssert(count(icct_nms_netflow_read($id,[])['events'])===4,'Anomaly report lost findings');nfAssert((int)db_fetch_cell("SELECT COUNT(*) FROM plugin_icct_nms_netflow_events WHERE state='Active'")===4,'DoS, scanning, worm-like traffic and bandwidth anomalies were not all detected');
db_execute('UPDATE plugin_icct_nms_netflow_flows SET last_seen=DATE_SUB(NOW(),INTERVAL 10 MINUTE),received_at=DATE_SUB(NOW(),INTERVAL 10 MINUTE)');icct_nms_netflow_anomalies($id);nfAssert((int)db_fetch_cell("SELECT COUNT(*) FROM plugin_icct_nms_netflow_events WHERE state='Active'")===4,'Missing data incorrectly cleared anomalies');
db_execute('UPDATE plugin_icct_nms_netflow_flows SET last_seen=NOW(),received_at=NOW(),packets=1,bytes=10,tcp_flags=\'...A....\',dst_addr=\'10.22.1.1\',dst_port=443');icct_nms_netflow_anomalies($id);nfAssert((int)db_fetch_cell("SELECT COUNT(*) FROM plugin_icct_nms_netflow_events WHERE state='Active'")===0,'Fresh normal observations failed to resolve anomalies');
icct_nms_netflow_remove($id);nfAssert(!icct_nms_netflow_assignment($id)&&db_fetch_cell('SELECT COUNT(*) FROM plugin_icct_nms_netflow_flows')>0,'Removing assignment should retain history');
echo "PASS: all four wire formats, device saves, decoded counters, exactly-once imports, filtered reports, authorization, classifications, anomaly detection/recovery and history retention.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()." at ".$e->getFile().":".$e->getLine()."\n");foreach($e->getTrace() as $frame)fwrite(STDERR,($frame["function"]??"")." ".($frame["line"]??"")."\n");exit(1);}
