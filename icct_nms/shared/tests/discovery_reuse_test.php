<?php
if (PHP_SAPI !== 'cli') exit(1);
set_error_handler(static function($n,$m,$f,$l){throw new ErrorException($m,0,$n,$f,$l);});
require __DIR__.'/../../inventory/discovery/services/discovery_reuse.php';
require __DIR__.'/../../inventory/discovery/services/discovery_collector.php';
require __DIR__.'/../../inventory/discovery/services/inventory_events.php';
require __DIR__.'/../../dashboard/location/services/location_service.php';
foreach (['PING_ICMP'=>1,'PING_SNMP'=>2,'AVAIL_PING'=>3,'AVAIL_SNMP_AND_PING'=>1,'AVAIL_SNMP_OR_PING'=>4,'HOST_UP'=>3,'SNMP_STRING_OUTPUT_ASCII'=>2] as $n=>$v) if(!defined($n))define($n,$v);
$config=['poller_id'=>7];
$host=['id'=>43,'hostname'=>'192.0.2.43','poller_id'=>7,'disabled'=>'','deleted'=>'','snmp_version'=>2,'snmp_port'=>1161,
 'snmp_community'=>'test-only','snmp_context'=>'','snmp_engine_id'=>'','snmp_username'=>'','snmp_password'=>'',
 'snmp_auth_protocol'=>'','snmp_priv_passphrase'=>'','snmp_priv_protocol'=>'','snmp_timeout'=>500,'max_oids'=>10,
 'last_updated'=>date('Y-m-d H:i:s'),'ping_method'=>PING_ICMP,'availability_method'=>AVAIL_PING,'status'=>HOST_UP];
$credential=array_replace($host,['id'=>19,'snmp_id'=>8,'sequence'=>1]);
$hosts=[$host];$credentials=[$credential];$native=[];$running=0;$meta=[];$items=[];$cache=[];$reads=[];$writes=[];$assigned=[];$snapshot=[];$allowed=true;$enabled=true;$lock=true;
$network=['id'=>12,'poller_id'=>7,'enabled'=>'on','snmp_id'=>8,'subnet_range'=>'192.0.2.43','native_started'=>time()-10,'ping_method'=>PING_ICMP];
function read_config_option($n){return $n==='snmp_retries'?1:300;}
function db_fetch_assoc_prepared($sql,$a){
 if(str_contains($sql,'FROM automation_snmp_items'))return $GLOBALS['credentials'];
 if(str_contains($sql,'FROM host WHERE hostname'))return array_values(array_filter($GLOBALS['hosts'],fn($h)=>$h['hostname']===$a[0]&&$h['poller_id']===$a[1]&&$h['disabled']===''&&$h['deleted']===''));
 if(str_contains($sql,'FROM poller_item'))return $GLOBALS['items'];
 if(str_contains($sql,'FROM host_snmp_cache'))return $GLOBALS['cache'];
 if(str_contains($sql,'FROM host WHERE poller_id'))return $GLOBALS['hosts'];
 throw new RuntimeException('Unexpected query '.$sql);
}
function db_fetch_row_prepared($sql,$a){
 if(str_contains($sql,'FROM plugin_icct_nms_scan_jobs'))return $GLOBALS['scanJob']??[];
 if(str_contains($sql,'FROM automation_networks'))return $GLOBALS['network'];
 if(str_contains($sql,'FROM automation_devices')){ $n=$GLOBALS['native'];return $n&&$n['network_id']===$a[0]&&$n['ip']===$a[1]&&$n['time']>=$a[2]&&$n['time']<=$a[3]?$n:[]; }
 if(str_contains($sql,'FROM plugin_icct_nms_discovery_snapshots'))return $GLOBALS['snapshot'];
 if(str_contains($sql,'FROM host WHERE id'))return $GLOBALS['host'];
 throw new RuntimeException('Unexpected row '.$sql);
}
function db_fetch_cell_prepared($sql,$a){
 if(str_contains($sql,'automation_processes'))return $GLOBALS['running'];
 if(str_contains($sql,'GET_LOCK'))return $GLOBALS['lock']?1:0;
 if(str_contains($sql,'RELEASE_LOCK'))return 1;
 return $GLOBALS['meta'][$a[0]]??'';
}
function db_execute_prepared($sql,$a){$GLOBALS['meta'][$a[0]]=$a[1];return true;}
function icct_backend_category_execute($sql,$a=[]){$GLOBALS['writes'][]=[$sql,$a];if(str_contains($sql,'INSERT INTO plugin_icct_nms_meta'))$GLOBALS['meta'][$a[0]]=$a[1];}
function icct_backend_require_device_access($id){if(!$GLOBALS['allowed'])throw new RuntimeException('Denied');}
function icct_backend_protocol_enabled($id,$p){return $GLOBALS['enabled'];}
function icct_backend_inventory_snmp_failed($v){return $v===false||$v===null||$v===''||$v==='U';}
function cacti_snmp_get(...$a){$GLOBALS['reads'][]=$a[2];return str_ends_with($a[2],'.2.0')?'.1.3.6.1.4.1.9':'fresh';}
function icct_backend_nd_hosts(){return $GLOBALS['assigned'];}
function icct_backend_nd_host_methods($h){return ['lldp'];}
function icct_backend_nd_hash($h){return 'actual-profile';}
function icct_backend_nd_collect_direct($h,$m,$d){throw new RuntimeException('Unexpected duplicate neighbour collection');}
function icct_backend_nd_discovery_session($h){return new class {function close(){}};}
function icct_backend_nd_snmp_scalar($s,$o,$d){$GLOBALS['reads'][]=$o;return ['value'=>str_ends_with($o,'.1')?'1':(str_ends_with($o,'.2')?'120000':'340000')];}
function checkDiscovery($v,$m){if(!$v)throw new RuntimeException($m);}
function resetDiscovery(){icct_nms_snmp_reuse_reset();$GLOBALS['reads']=[];}
$v=['poller_id'=>7,'snmp_id'=>8,'methods'=>['icmp','snmp'],'run_limit'=>60];
$job=['network_id'=>12,'poller_id'=>7,'created_at'=>date('Y-m-d H:i:s')];
$v['native_after']=$network['native_started'];$v['native_signature']=icct_nms_scan_native_signature($network);
checkDiscovery(icct_nms_scan_native_context($job,$v)===null,'Must wait for native start');
$network['native_started']++;$running=1;
checkDiscovery(icct_nms_scan_native_context($job,$v)===null,'Must wait for native completion');
$running=0;checkDiscovery(icct_nms_scan_native_context($job,$v)['id']===12,'Completed native run resumes enrichment');
$bound=$v;$bound['native_run']=$network['native_started']-1;
try{icct_nms_scan_native_context($job,$bound);throw new LogicException('Replaced native run accepted');}catch(RuntimeException $e){}
$credentials[0]['snmp_community']='changed';
try{icct_nms_scan_native_context($job,$v);throw new LogicException('Changed credentials accepted');}catch(RuntimeException $e){}
$credentials=[$credential];
$v['native_context']=$network;
$hosts=[];$native=['network_id'=>12,'ip'=>$host['hostname'],'time'=>time(),'up'=>1,'snmp'=>1,
 'sysName'=>'router','sysDescr'=>'router description','sysLocation'=>'lab','sysContact'=>'ops','sysUptime'=>'12300']+$credential;
resetDiscovery();$r=icct_nms_scan_reuse($host['hostname'],$v,microtime(true)+10);
checkDiscovery($r['icmp']===true&&$r['snmp']['ok']&&$reads===['.1.3.6.1.2.1.1.2.0'],'Native identity collects only the absent sysObjectID');
$r=icct_nms_scan_reuse($host['hostname'],$v,microtime(true)+10);
checkDiscovery(count($reads)===1,'Missing field is shared across observations');
$native['snmp']=0;resetDiscovery();$r=icct_nms_scan_reuse($host['hostname'],$v,microtime(true)+10);
checkDiscovery($r['snmp']===['ok'=>false]&&!$reads,'Failed native SNMP attempt is not repeated');
$native=[];resetDiscovery();$r=icct_nms_scan_reuse($host['hostname'],$v,microtime(true)+10);
checkDiscovery($r['icmp_owned']&&$r['icmp']===null&&!$reads,'No native result stays unknown, not false success');
$network['ping_method']=PING_SNMP;$v['native_context']=$network;
$r=icct_nms_scan_reuse($host['hostname'],$v,microtime(true)+10);
checkDiscovery($r['snmp']===null&&!$reads,'Absent native SNMP evidence remains missing, allowing normal discovery rather than hiding an unretained result');
$hosts=[$host];unset($v['native_context']);
$meta['cacti_snmp_profile_43']=json_encode(['signature'=>icct_nms_snmp_profile($host),'time'=>time()-60]);
foreach(['sysName'=>'router','sysDescr'=>'description','sysObjectID'=>'.1.3.6.1.4.1.9','sysLocation'=>'site','sysContact'=>'ops'] as $k=>$val)$hosts[0]['snmp_'.$k]=$val;
resetDiscovery();$r=icct_nms_scan_reuse($host['hostname'],$v,microtime(true)+10);
checkDiscovery($r['host']['id']===43&&$r['icmp']===true&&$reads===['.1.3.6.1.2.1.1.3.0'],'Known native identity reused without re-authentication');
resetDiscovery();$onlyPing=$v;$onlyPing['methods']=['icmp'];$r=icct_nms_scan_reuse($host['hostname'],$onlyPing,microtime(true)+10);
checkDiscovery(!$reads&&$r['icmp']===true,'ICMP-only discovery must not collect SNMP');
$allowed=false;checkDiscovery(icct_nms_scan_known_host($host['hostname'],$v)===null,'Device ACL must apply');$allowed=true;
$other=$v;$other['poller_id']=9;checkDiscovery(icct_nms_scan_known_host($host['hostname'],$other)===null,'Collector isolation');
$assigned=[['id'=>43,'enabled'=>1,'collection_enabled'=>1,'stale_seconds'=>60]];
$snapshot=['status'=>'success','config_hash'=>'actual-profile','succeeded_at'=>date('Y-m-d H:i:s'),'data_json'=>'{"neighbors":[{"name":"peer"}]}'];
checkDiscovery(count(icct_nms_scan_neighbours($host,'lldp',microtime(true)+10)['neighbors'])===1,'Fresh neighbour snapshot reused');
$snapshot['status']='running';checkDiscovery(isset(icct_nms_scan_neighbours($host,'lldp',microtime(true)+10)['warning']),'Assigned worker owns pending evidence');
echo "PASS: Native run barriers, credential changes, missing-only discovery, failure/unknown states, ICMP-only mode, ACL/collector isolation and neighbour ownership.\n";

// Native samples carry the original GNSS observation time; faster requested cadence remains supported.
resetDiscovery();$state=&icct_nms_snmp_samples($host);
$base='1.3.6.1.4.1.55555.7';
foreach([1=>'1',2=>'120000',3=>'340000'] as $i=>$value)$state['values'][$base.'.'.$i]=['value'=>$value,'time'=>time()-7,'step'=>300,'source'=>'native'];
$fix=icct_nms_gnss_probe(['transport'=>'snmp','host_id'=>43,'timeout'=>3,'interval'=>30,'max_age'=>120,
 'fix_oid'=>$base.'.1','fix_values'=>['1'],'latitude_oid'=>$base.'.2','longitude_oid'=>$base.'.3','scale'=>10000],7);
checkDiscovery(!$reads&&$fix['latitude']===12.0&&$fix['longitude']===34.0&&$fix['source_time']===time()-7,'GNSS native reuse and source timestamp');
$state['values'][$base.'.2']['time']=time()-60;$reads=[];
$value=icct_nms_snmp_read_interval($host,$base.'.2',30,static function(){ $GLOBALS['reads'][]='missing cadence';return '120001';});
checkDiscovery($value==='120001'&&count($reads)===1,'A slower native source cannot suppress faster configured GNSS collection');
$stale=$state['values'];
foreach($state['values'] as &$sample){$sample['time']=time()-130;}unset($sample);
try {icct_nms_gnss_probe(['transport'=>'snmp','host_id'=>43,'timeout'=>3,'interval'=>300,'max_age'=>120,
 'fix_oid'=>$base.'.1','fix_values'=>['1'],'latitude_oid'=>$base.'.2','longitude_oid'=>$base.'.3','scale'=>10000],7);throw new LogicException('Stale fix accepted');}catch(RuntimeException $e){}
$state['values']=$stale;
icct_nms_inventory_observe(7);
$events=count(array_filter($writes,fn($w)=>str_contains($w[0],'INSERT INTO plugin_icct_nms_inventory_events')));
icct_nms_inventory_observe(7);checkDiscovery(count(array_filter($writes,fn($w)=>str_contains($w[0],'INSERT INTO plugin_icct_nms_inventory_events')))===$events,'Unchanged native observations do not create events');
$before=count($writes);$lock=false;icct_nms_inventory_observe(7);checkDiscovery(count($writes)===$before,'Concurrent observers must not duplicate change events');
echo "PASS: GNSS native reuse, true source timestamps, faster-cadence collection and observer concurrency guard.\n";

$lock=true;$scanJob=['id'=>61,'cancel_requested'=>1];$before=count($reads);
checkDiscovery(icct_nms_scan_work(7)===61&&str_contains(end($writes)[0],"state='cancelled'")&&count($reads)===$before,'Waiting native jobs must cancel without waiting for native completion or probing');
echo "PASS: Superseded native runs are rejected and waiting jobs cancel without network requests.\n";
