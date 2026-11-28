<?php
if (PHP_SAPI !== 'cli') exit(1);
/** Regression tests exercise real collectors with fake native data; no network or Cacti mutations. */
set_error_handler(static function ($n,$m,$f,$l) { throw new ErrorException($m,0,$n,$f,$l); });
require __DIR__ . '/../../ports/services/backend/ports.php';
require __DIR__ . '/../../protocols/snmp/services/backend/discovery_snmp.php';
require __DIR__ . '/../../ports/services/backend/interface_rates.php';
$config = ['poller_id'=>4];
$items = $cache = $meta = $schemas = $hosts = [];
function read_config_option($name) { return $name === 'snmp_retries' ? 2 : 300; }
function db_fetch_assoc_prepared($sql,$args) {
    global $items,$cache;
    if (str_contains($sql,'FROM host WHERE')) return array_values(array_filter($GLOBALS['hosts'], static fn($r)=>$r['poller_id']===$args[0] && in_array($r['id'],array_slice($args,1),true)));
    if (str_contains($sql,'FROM host AS h')) return $GLOBALS['definitions'];
    if (str_contains($sql,'FROM poller_item')) return array_values(array_filter($items, static fn($r)=>
        str_contains($sql,'local_data_id IN') ? ($r['poller_id']===$args[0] && in_array($r['local_data_id'],array_slice($args,1),true))
            : ($r['host_id']===$args[0] && $r['poller_id']===$args[1])));
    if (str_contains($sql,'FROM host_snmp_cache')) return array_values(array_filter($cache,static fn($r)=>$r['host_id']===$args[0]));
    throw new RuntimeException('Unexpected read: '.$sql);
}
function db_fetch_cell_prepared($sql,$args) { if (str_contains($sql,'GET_LOCK') || str_contains($sql,'RELEASE_LOCK') || str_contains($sql,'FROM poller WHERE')) return 1; return $GLOBALS['meta'][$args[0]] ?? ''; }
function db_fetch_row_prepared($sql,$args) { if (str_contains($sql,'SELECT baseline_value')) return []; return $GLOBALS['hosts'][$args[0]] ?? []; }
function db_execute_prepared($sql,$args) {
    if (!str_starts_with($sql,'INSERT INTO plugin_icct_nms_meta')) throw new RuntimeException('Unexpected write');
    $GLOBALS['meta'][$args[0]]=$args[1]; return true;
}
function get_data_query_array($id) { return $GLOBALS['schemas'][$id] ?? []; }
function format_snmp_string($value,$included,$format) { return trim($value); }
if (!defined('SNMP_STRING_OUTPUT_ASCII')) define('SNMP_STRING_OUTPUT_ASCII',2);
if (!defined('SNMP_STRING_OUTPUT_HEX')) define('SNMP_STRING_OUTPUT_HEX',3);
function checkReuse($ok,$message) { if (!$ok) throw new RuntimeException($message); }
function resetReuse() {
    icct_nms_snmp_reuse_reset();
    // Existing scenarios replace the fake database independently; discard their collected snapshots too.
    foreach (array_keys($GLOBALS['meta']) as $key) if (str_starts_with($key,'snmp_shared_')) unset($GLOBALS['meta'][$key]);
}
$host=['id'=>37,'hostname'=>'198.51.100.17','poller_id'=>4,'disabled'=>'','snmp_version'=>2,
    'snmp_port'=>1161,'snmp_context'=>'lab','snmp_engine_id'=>'','snmp_username'=>'','snmp_community'=>'fixture-only',
    'snmp_password'=>'','snmp_auth_protocol'=>'','snmp_priv_passphrase'=>'','snmp_priv_protocol'=>'',
    'snmp_timeout'=>2000,'max_oids'=>12];
$hosts[37]=$host;
$meta['cacti_snmp_profile_37']=json_encode(['signature'=>icct_nms_snmp_profile($host),'time'=>time()-60]);
$in='1.3.6.1.2.1.31.1.1.1.6.9';
$items[] = array_replace($host,['host_id'=>37,'local_data_id'=>81,'rrd_name'=>'traffic_in',
    'arg1'=>'.'.$in,'rrd_step'=>60]);
$updates=['native-path'=>['local_data_id'=>81,'times'=>[time()-5=>['traffic_in'=>'18446744070000000000']]]];
$original=$updates;
icct_nms_snmp_capture($updates);
checkReuse($updates===$original,'The native poller update array must remain unchanged');
checkReuse(!str_contains(implode('',$meta),'fixture-only'),'Credentials must not be saved with samples');
$calls=0;
$read=static function()use(&$calls){$calls++;return 'unnecessary';};
checkReuse(icct_nms_snmp_read($host,'.'.$in,$read)==='18446744070000000000' && $calls===0,'Reuse exact native Counter64 without polling');
$wire=icct_nms_snmp_wire(icct_nms_snmp_known($host,$in),70);
checkReuse($wire['value']==='18446744070000000000','Typed conversion must retain Counter64 precision');
checkReuse($wire['_sample_time']===array_key_first($updates['native-path']['times']),'Retain native timestamp');
// Stale, missing, unknown and reconfigured native sources must never become invented zeros.
$saved=json_decode($meta['cacti_snmp_samples_37'],true);
$saved['values'][$in]['time']=time()-121;
$meta['cacti_snmp_samples_37']=json_encode($saved); resetReuse();
checkReuse(icct_nms_snmp_read($host,$in,$read)===false && $calls===0,'Do not duplicate a late native polling item');
$saved['values'][$in]['time']=time(); $saved['values'][$in]['value']='U';
$meta['cacti_snmp_samples_37']=json_encode($saved); resetReuse();
checkReuse(icct_nms_snmp_read($host,$in,$read)==='U' && $calls===0,'Unknown is not zero or a new network request');
$saved['values'][$in]['value']='0'; $meta['cacti_snmp_samples_37']=json_encode($saved); resetReuse();
checkReuse(icct_nms_snmp_read($host,$in,$read)==='0','A genuine native zero remains valid');
$changed=$host; $changed['snmp_port']=2161;
$hosts[37]=$changed;
checkReuse(icct_nms_snmp_read($changed,$in,$read)==='unnecessary' && $calls===1,'Different endpoint/security profiles must not share a sample');
$hosts[37]=$host;
$calls=0;
$oid='1.3.6.1.4.1.55555.1.0'; resetReuse();
$collect=static function()use(&$calls){$calls++;return '42';};
checkReuse(icct_nms_snmp_read($host,$oid,$collect)==='42','Collect a missing OID');
checkReuse(icct_nms_snmp_read($host,$oid,$collect)==='42' && $calls===1,'Inventory and alarms share a single missing-OID read');
$root='1.3.6.1.2.1.2.2.1.2';
$meta['cacti_snmp_profile_37']=json_encode(['signature'=>icct_nms_snmp_profile($host),'time'=>time()-60]);
$cache=[['host_id'=>37,'snmp_query_id'=>73,'field_name'=>'actualField','oid'=>'.'.$root.'.9','field_value'=>'port-nine','sample_time'=>time()-20],
    ['host_id'=>37,'snmp_query_id'=>73,'field_name'=>'actualField','oid'=>'.'.$root.'.11','field_value'=>'port-eleven','sample_time'=>time()-20]];
$schemas[73]=['oid_index'=>$root,'fields'=>['actualField'=>['direction'=>'input','method'=>'walk','source'=>'value','oid'=>'.'.$root]]];
resetReuse(); $walks=0; $gets=[];
$walk=static function()use(&$walks){$walks++;return [];};
$get=static function($oid)use(&$gets){$gets[]=$oid;return 'new-port';};
$rows=icct_nms_snmp_column($host,$root,$walk,$get);
checkReuse(count($rows)===2 && $walks===0 && !$gets,'A complete fresh native query column needs no network');
$rows=icct_nms_snmp_column($host,$root,$walk,$get,[9,11,14]);
checkReuse(count($rows)===3 && $gets===[$root.'.14'] && !$walks,'Partial coverage requests only the missing interface instance');
$cache[0]['sample_time']=time()-601; $cache[1]['sample_time']=time()-601; resetReuse();
icct_nms_snmp_column($host,$root,$walk,$get);
checkReuse($walks===1,'Old query data cannot be presented as fresh interface evidence');
$cache=[]; resetReuse();
$walks=0; $root='1.3.6.1.2.1.2.2.1.1';
$walk=static function()use(&$walks,$host,$root){$walks++;$rows=[$root.'.9'=>'9'];
    foreach($rows as $oid=>$v) icct_nms_snmp_remember($host,$oid,$v);return $rows;};
icct_nms_snmp_column($host,$root,$walk,$get);
$before=count($gets);
$typed=icct_nms_snmp_column($host,$root,$walk,$get,[],2);
checkReuse($walks===1 && count($gets)===$before && $typed[$root.'.9']['value']==='9','Discovery reuses a preceding plain interface walk');
$other=$host; $other['id']=93; $hosts[93]=$other; resetReuse(); $calls=0;
checkReuse(icct_nms_snmp_read($other,$in,$collect)==='42' && $calls===1,'Never reuse another device sample');
$remote=$host; $remote['poller_id']=99; resetReuse(); $calls=0;
try { icct_nms_snmp_read($remote,$in,$collect); throw new LogicException('Wrong collector accepted'); }
catch (RuntimeException $error) { checkReuse($calls===0,'A different collector must not probe the host'); }
$bad=icct_nms_snmp_wire(['value'=>'U','time'=>time()],70);
checkReuse($bad===null,'Unknown wire counters must remain absent');
checkReuse(icct_nms_snmp_wire(['source'=>'query','value'=>'123','time'=>time()],70)===null, 'Reindex-time query values cannot replace regularly collected raw counters');
checkReuse(icct_nms_snmp_wire(['value'=>'00:11:22:33:44:55','time'=>time()],4)===null,'Formatted MAC labels cannot be guessed into raw identity bytes');
echo "PASS: Native hook capture, exact Counter64/zero/timestamps, stale/unknown guards, security/device/collector isolation, native query reuse and missing-only collection.\n";

// Execute the inventory collector, not only the reuse helper, against a native zero sample.
require __DIR__ . '/../../inventory/services/backend/inventory.php';
function cacti_sizeof($value) { return count($value); }
function icct_backend_inventory_display_name($value) { return $value; }
function icct_backend_category_execute($sql,$args) { $GLOBALS['inventoryWrites'][]=[$sql,$args]; return true; }
function cacti_snmp_get(...$args) { $GLOBALS['wireGets']++; return 'unexpected'; }
$directory=sys_get_temp_dir().'/icct-native-reuse-'.bin2hex(random_bytes(8));
mkdir($directory); mkdir($directory.'/lib'); file_put_contents($directory.'/lib/snmp.php','<?php');
try {
    $config['base_path']=$directory;
    $definitions=[array_replace($host,['host_id'=>$host['id'],'inventory_key'=>'native-test','oid'=>'.'.$in,'import_id'=>1])];
    $meta['cacti_snmp_samples_37']=json_encode($saved);
    $wireGets=0; $inventoryWrites=[]; resetReuse();
    checkReuse(icct_backend_collect_inventory_values()===1,'Inventory collector must process native definitions');
    checkReuse($wireGets===0 && count($inventoryWrites)===1,'Inventory must not issue an extra native SNMP GET');
    [$sql,$args]=$inventoryWrites[0];
    checkReuse($args[5]==='0' && $args[7]===$saved['values'][$in]['time'],'Inventory must retain zero and original collection time');
    checkReuse(str_contains($sql,'last_success = VALUES(last_success)'),'Inventory updates must preserve the source timestamp too');
} finally {
    unlink($directory.'/lib/snmp.php'); rmdir($directory.'/lib'); rmdir($directory);
}
echo "PASS: Actual inventory collection uses native samples and preserves database timestamps without an extra GET.\n";

$items[0]['rrd_step']=1800; resetReuse(); $wireGets=0;
$slow=icct_nms_snmp_read($host,$in,static function()use(&$wireGets){$wireGets++;return 'fresh-required';});
checkReuse($slow==='0' && $wireGets===0, 'A current observation from a slower native source is reusable');
$saved['values'][$in]['time']=time()-301; $meta['cacti_snmp_samples_37']=json_encode($saved); resetReuse();
$slow=icct_nms_snmp_read($host,$in,static function()use(&$wireGets){$wireGets++;return 'fresh-required';});
checkReuse($slow==='fresh-required' && $wireGets===1, 'Slower native polling must not suppress the plugin required measurement cadence');
$items[0]['rrd_step']=600; resetReuse(); $wireGets=0;
$saved['values'][$in]['time']=time()-500; $meta['cacti_snmp_samples_37']=json_encode($saved);
checkReuse(icct_nms_snmp_read_interval($host,$in,900,static function()use(&$wireGets){$wireGets++;return 'unnecessary';})==='0' && $wireGets===0,
    'A slow consumer must use its own cadence rather than the global polling interval');
echo "PASS: Sampling-cadence compatibility and reindex-counter exclusion.\n";

$items[0]['rrd_step']=60;
$cache=[]; $updates['native-path']['times']=[time()=>['traffic_in'=>1.844674407e19]];
icct_nms_snmp_capture($updates); $wireGets=0;
checkReuse(icct_nms_snmp_read($host,$in,static function()use(&$wireGets){$wireGets++;return '18446744070000000000';})==='18446744070000000000' && $wireGets===1,
    'A native float cannot suppress collection of the missing exact Counter64 representation');
$cache=[['host_id'=>37,'snmp_query_id'=>11,'field_name'=>'actualField','oid'=>'.'.$oid,'field_value'=>'A','sample_time'=>time()],
    ['host_id'=>37,'snmp_query_id'=>12,'field_name'=>'actualField','oid'=>'.'.$oid,'field_value'=>'B','sample_time'=>time()]];
$schemas[11]=$schemas[12]=['fields'=>['actualField'=>['direction'=>'input','method'=>'walk','source'=>'value','oid'=>$oid]]];
resetReuse(); $wireGets=0;
$exact=static function()use(&$wireGets){$wireGets++;return 'actual';};
checkReuse(icct_nms_snmp_read($host,$oid,$exact)==='actual' && icct_nms_snmp_read($host,$oid,$exact)==='actual' && $wireGets===1,
    'Conflicting native cache values require one authoritative observation, never an arbitrary query choice');
echo "PASS: Precision-loss and ambiguous-source guards.\n";

class ReuseWireSession {
    public int $reads=0;
    public function get($oid) { $this->reads++; return (object)['type'=>4,'value'=>"port \"quoted\" \\ ".hex2bin('c3a9')]; }
    public function getErrno() { return 0; }
}
resetReuse(); $session=new ReuseWireSession();
$GLOBALS['icct_nms_snmp_sessions']=new WeakMap();
$GLOBALS['icct_nms_snmp_sessions'][$session]=$host;
$wire=icct_backend_nd_snmp_scalar($session,'1.3.6.1.4.1.55555.9.0',microtime(true)+10,false,4);
$again=icct_backend_nd_snmp_scalar($session,'1.3.6.1.4.1.55555.9.0',microtime(true)+10,false,4);
checkReuse($wire['value']===$again['value'] && $session->reads===1 && str_contains($again['value'],'"quoted"'),
    'Sharing wire observations must preserve quotes, backslashes and UTF-8 identity bytes');
echo "PASS: Lossless raw-octet reuse without reconstructing identity from formatted labels.\n";

$cache=[['host_id'=>37,'snmp_query_id'=>11,'field_name'=>'actualField','oid'=>'.'.$oid,'field_value'=>'friendly rewritten value','sample_time'=>time()]];
$schemas[11]['fields']['actualField']['rewrite_value']=[['match'=>'original','replace'=>'friendly rewritten value']];
resetReuse(); $wireGets=0;
checkReuse(icct_nms_snmp_read($host,$oid,$exact)==='actual' && $wireGets===1,'Rewritten native query labels must not replace actual SNMP values');
$schemas[11]['fields']['actualField']['rewrite_value']=[];
$schemas[11]['fields']['actualField']['source']='VALUE/REGEXP:.*';
resetReuse(); $wireGets=0;
checkReuse(icct_nms_snmp_read($host,$oid,$exact)==='actual' && $wireGets===1,'Derived query fields cannot impersonate raw OID observations');
echo "PASS: Native query transformations are excluded from raw-data reuse.\n";

$schemas[11]['fields']['actualField']['source']='value';
$meta['cacti_snmp_profile_37']=json_encode(['signature'=>'prior-SNMP-context','time'=>time()-60]);
resetReuse(); $wireGets=0;
checkReuse(icct_nms_snmp_read($host,$oid,$exact)==='actual' && $wireGets===1,'Query caches from before a connection/security change must not be reused');
echo "PASS: Connection changes invalidate old query-cache evidence.\n";
