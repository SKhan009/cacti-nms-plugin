<?php
/** Real subprocesses; file-backed fake Cacti DB models snapshots and advisory-lock exclusion. */
if (PHP_SAPI !== 'cli') exit(1);
set_error_handler(static function ($n,$m,$f,$l) { throw new ErrorException($m,0,$n,$f,$l); });
require __DIR__ . '/../../protocols/snmp/services/backend/discovery_snmp.php';
require __DIR__ . '/../../protocols/snmp/services/sample_lifecycle.php';
const SNMP_STRING_OUTPUT_ASCII = 2;
const SNMP_STRING_OUTPUT_HEX = 3;
const SNMP_STRING_OUTPUT_GUESS = 1;
const SNMP_METHOD_PHP = 1;
$config = ['poller_id'=>4];
$host = ['id'=>37,'hostname'=>'192.0.2.37','poller_id'=>4,'disabled'=>'','deleted'=>'','snmp_version'=>2,
    'snmp_port'=>161,'snmp_community'=>'fixture-secret','snmp_timeout'=>500,'max_oids'=>10,
    'snmp_context'=>'','snmp_engine_id'=>'','snmp_username'=>'','snmp_password'=>'','snmp_auth_protocol'=>'',
    'snmp_priv_passphrase'=>'','snmp_priv_protocol'=>''];
$directory = sys_get_temp_dir() . '/icct-shared-' . bin2hex(random_bytes(8));
mkdir($directory,0700);
$items = $locks = [];
$lockAvailable = $saveAvailable = true;
function read_config_option($n) { return $n === 'snmp_retries' ? 1 : 300; }
function format_snmp_string($v,$i,$f) { return trim($v); }
function snmp_get_method(...$args) { return 2; } // The native configuration explicitly selects the command-line transport.
function cacti_snmp_get(...$args) { $GLOBALS['nativeTransportCalls'][]=$args;return 'configured-native'; }
function cacti_snmp_walk(...$args) { $GLOBALS['nativeTransportCalls'][]=$args;return [['oid'=>$args[2].'.1','value'=>'configured-native']]; }
function cachePath($k) { return $GLOBALS['directory'].'/'.hash('sha256',$k).'.json'; }
function putMeta($k,$v) { file_put_contents(cachePath($k),json_encode($v,JSON_THROW_ON_ERROR)); }
function getMeta($k) { $p=cachePath($k); return is_file($p)?json_decode(file_get_contents($p),true):null; }
function db_fetch_cell_prepared($sql,$a) {
    global $locks,$lockAvailable,$directory;
    if (str_contains($sql,'GET_LOCK')) {
        if (!$lockAvailable) return 0;
        $handle=fopen($directory.'/'.hash('sha256',$a[0]).'.lock','c');
        if (!flock($handle,LOCK_EX)) throw new RuntimeException('Fixture lock failed');
        $locks[$a[0]]=$handle; return 1;
    }
    if (str_contains($sql,'RELEASE_LOCK')) {
        flock($locks[$a[0]],LOCK_UN); fclose($locks[$a[0]]); unset($locks[$a[0]]); return 1;
    }
    $v=getMeta($a[0]); return $v===null?'':json_encode($v,JSON_THROW_ON_ERROR);
}
function db_fetch_row_prepared($sql,$a) { return $GLOBALS['host']['id']===$a[0]?$GLOBALS['host']:[]; }
function db_fetch_assoc_prepared($sql,$a) {
    if (str_contains($sql,'FROM poller_item')) return array_values(array_filter($GLOBALS['items'], static fn($i)=>($i['action']??0)===0 &&
        (!str_contains($sql,'local_data_id IN') || in_array($i['local_data_id'],array_slice($a,1),true))));
    if (str_contains($sql,'FROM host_snmp_cache')) return [];
    if (str_contains($sql,'FROM host WHERE')) return [$GLOBALS['host']];
    throw new RuntimeException('Unexpected query: '.$sql);
}
function db_execute_prepared($sql,$a) {
    if (!$GLOBALS['saveAvailable']) return false;
    if (str_starts_with($sql,'DELETE FROM plugin_icct_nms_meta')) {
        foreach ($a as $key) if (is_file(cachePath($key))) unlink(cachePath($key));
        return true;
    }
    if (!str_starts_with($sql,'INSERT INTO plugin_icct_nms_meta')) throw new RuntimeException('Unexpected mutation');
    file_put_contents(cachePath($a[0]),$a[1]); return true;
}
function checkShared($value,$why) { if (!$value) throw new RuntimeException($why); }
function workerReset() { icct_nms_snmp_reuse_reset(); }
function expectFailure($work,$why) {
    try { $work(); } catch (RuntimeException $error) { return; }
    throw new LogicException($why);
}
$oid='1.3.6.1.4.1.55555.1.0';
try {
    $calls=0;
    $collect=static function()use(&$calls){$calls++;return '0';};
    checkShared(icct_nms_snmp_read($host,$oid,$collect)==='0','First zero');
    $time=icct_nms_snmp_known($host,$oid)['time']; workerReset();
    checkShared(icct_nms_snmp_read($host,$oid,$collect)==='0' && $calls===1,'Another worker must reuse the zero');
    checkShared(icct_nms_snmp_known($host,$oid)['time']===$time,'Do not renew source timestamps');
    checkShared(!str_contains(file_get_contents(cachePath(icct_nms_snmp_shared_key($host))),'fixture-secret'),'Never store credentials');

    $binary="\x00\xff\x80 quoted \" \\";
    icct_nms_snmp_shared($host,static fn()=>icct_nms_snmp_remember_wire($host,$oid.'.2',['type'=>4,'value'=>$binary]));
    workerReset();
    $wire=icct_nms_snmp_shared($host,static fn()=>icct_nms_snmp_wire(icct_nms_snmp_known($host,$oid.'.2'),4));
    checkShared($wire['value']===$binary,'Raw octets must survive database JSON and another worker');

    expectFailure(static fn()=>icct_nms_snmp_read($host,$oid.'.3',static function(){throw new RuntimeException('Agent unavailable');}), 'Failure should remain visible');
    workerReset();
    checkShared(icct_nms_snmp_read($host,$oid.'.3',$collect)===false && $calls===1,'Do not retry another worker failure in the same cycle');
    $lockAvailable=false;
    expectFailure(static fn()=>icct_nms_snmp_read($host,$oid.'.4',$collect),'Busy lock must not collect without ownership');
    checkShared($calls===1,'No lock-contention fallback'); $lockAvailable=true;

    $old=$host; $host['snmp_port']=1161; workerReset();
    expectFailure(static fn()=>icct_nms_snmp_read($old,$oid,$collect),'Stale host configuration must be rejected');
    checkShared(icct_nms_snmp_read($host,$oid,$collect)==='0' && $calls===2,'Changed endpoint invalidates shared observations');
    $host['disabled']='on'; workerReset();
    expectFailure(static fn()=>icct_nms_snmp_read($host,$oid,$collect),'Disabled device must not collect');
    $host['disabled']=''; $host['poller_id']=9; workerReset();
    expectFailure(static fn()=>icct_nms_snmp_read($host,$oid,$collect),'Wrong collector must not collect');
    $host['poller_id']=4;
    $saved=getMeta(icct_nms_snmp_shared_key($host)); $saved['cycle']--;
    putMeta(icct_nms_snmp_shared_key($host),$saved); workerReset();
    icct_nms_snmp_read($host,$oid,$collect); checkShared($calls===3,'New cycle must not reuse expired plugin observations');

    $items=[array_replace($host,['host_id'=>37,'local_data_id'=>81,'rrd_name'=>'metric','arg1'=>$oid.'.5','rrd_step'=>60])];
    icct_nms_snmp_capture([['local_data_id'=>81,'times'=>[time()-1=>['metric'=>'18446744070000000000']]]]);
    checkShared(icct_nms_snmp_read($host,$oid.'.5',$collect)==='18446744070000000000' && $calls===3,'Reuse raw native counters before RRD conversion');
    $saved=getMeta('cacti_snmp_samples_37');$saved['values'][$oid.'.5']['time']=time()-121;putMeta('cacti_snmp_samples_37',$saved);workerReset();
    checkShared(icct_nms_snmp_read($host,$oid.'.5',$collect)===false && $calls===3,'Stale native owner must not trigger fallback');
    $items=[]; // No worker reset: ownership must be revalidated after native datasource deletion.
    checkShared(icct_nms_snmp_read($host,$oid.'.5',$collect)==='0' && $calls===4,'Removed native ownership must not permanently suppress a plugin metric');

    if (!function_exists('pcntl_fork')) throw new RuntimeException('pcntl is required for the concurrent-worker test');
    $pids=[];
    foreach ([1,2] as $worker) {
        $pid=pcntl_fork();
        if ($pid===-1) throw new RuntimeException('Could not fork test worker');
        if ($pid===0) {
            workerReset();
            $result=icct_nms_snmp_read($host,$oid.'.99',static function()use($directory){
                file_put_contents($directory.'/requests.log',"GET\n",FILE_APPEND|LOCK_EX);
                usleep(200000); return 'concurrent-value';
            });
            exit($result==='concurrent-value'?0:1);
        }
        $pids[]=$pid;
    }
    foreach ($pids as $pid) { pcntl_waitpid($pid,$status); checkShared(pcntl_wexitstatus($status)===0,'Concurrent worker failed'); }
    checkShared(file_get_contents($directory.'/requests.log')==="GET\n",'Two real processes must issue exactly one GET');

    $session=new class {
        public int $gets=0;
        public function get($oid) {$this->gets++;return (object)['type'=>67,'value'=>(string)(1000+$this->gets)];}
        public function getErrno(){return 0;}
    };
    $GLOBALS['icct_nms_snmp_sessions']=new WeakMap();
    $GLOBALS['icct_nms_snmp_sessions'][$session]=$host;
    $uptime='1.3.6.1.2.1.1.3.0';
    $first=icct_backend_nd_snmp_scalar($session,$uptime,INF,false,67);
    workerReset();
    $again=icct_backend_nd_snmp_scalar($session,$uptime,INF,false,67);
    $after=icct_backend_nd_snmp_scalar($session,$uptime,INF,true,67);
    checkShared($first===$again&&$session->gets===2&&$after['value']!==$first['value'],'Shared uptime must not suppress the explicit post-collection reboot guard');

    $root='1.3.6.1.4.1.55555.20'; $walks=0;
    $walk=static function()use($host,$root,&$walks){$walks++;icct_nms_snmp_remember_wire($host,$root.'.7',['type'=>4,'value'=>"port\x00raw"]);return [$root.'.7'=>'Hex-STRING: 70 6F 72 74 00 72 61 77'];};
    icct_nms_snmp_column($host,$root,$walk,static fn($o)=>throw new RuntimeException('Unexpected GET'));
    workerReset();
    $rows=icct_nms_snmp_column($host,$root,static fn()=>throw new RuntimeException('Duplicate WALK'),static fn($o)=>throw new RuntimeException('Duplicate GET'),[],4);
    checkShared($rows[$root.'.7']['value']==="port\x00raw"&&$walks===1,'A typed worker must reuse a plain consumer’s original raw column');
    $bulk=new class {
        public array $calls=[];
        public bool $fail=false;
        public function walk(...$args){$this->calls[]=$args;return $this->fail?false:[$args[0].'.7'=>(object)['type'=>4,'value'=>"port\x00raw"]];}
        public function getErrno(){return $this->fail?1:0;}
    };
    $bulkRows=icct_nms_snmp_bulk_column($bulk,$root,23);
    checkShared($bulk->calls===[['.'.$root,false,23,0]]&&$bulkRows[$root.'.7']['value']==="port\x00raw",
        'Typed ports must retain native bulk size and exact values');
    $bulk->fail=true;
    expectFailure(static fn()=>icct_nms_snmp_bulk_column($bulk,$root,23),'Failed bulk walk must not switch to GETNEXT or another transport');
    $nativeTransportCalls=[];
    checkShared(icct_nms_snmp_typed_get($host,$oid.'.300')==='configured-native','Honor Cacti configured non-PHP transport without requiring php-snmp');
    $nativeRows=icct_nms_snmp_shared($host,static fn()=>icct_nms_snmp_walk_plain($host,$root.'.301'));
    checkShared($nativeRows[$root.'.301.1']==='configured-native'&&count($nativeTransportCalls)===2,'Native transport selection performs one operation and no alternate retry');

    $fastOid=$oid.'.200';$fastCalls=0;
    $items=[array_replace($host,['host_id'=>37,'local_data_id'=>82,'rrd_name'=>'fast','arg1'=>$fastOid,'rrd_step'=>60])];
    icct_nms_snmp_capture([['local_data_id'=>82,'times'=>[time()-40=>['fast'=>'older-native']]]]);
    $fast=static function()use(&$fastCalls){$fastCalls++;return 'required-faster-sample';};
    icct_nms_snmp_read_interval($host,$fastOid,30,$fast);workerReset();
    checkShared(icct_nms_snmp_read_interval($host,$fastOid,30,$fast)==='required-faster-sample'&&$fastCalls===1,
        'Faster consumers must share their missing-cadence observation across workers too');

    $items[]=array_replace($items[0],['local_data_id'=>83,'rrd_name'=>'other','arg1'=>$oid.'.201']);
    $pids=[];
    foreach ([82=>'fast',83=>'other'] as $dataId=>$name) {
        $pid=pcntl_fork();
        if ($pid===-1) throw new RuntimeException('Could not fork native publisher');
        if ($pid===0) {
            icct_nms_snmp_capture([['local_data_id'=>$dataId,'times'=>[time()=>[$name=>(string)$dataId]]]]);
            exit(0);
        }
        $pids[]=$pid;
    }
    foreach ($pids as $pid) { pcntl_waitpid($pid,$status);checkShared(pcntl_wexitstatus($status)===0,'Native publisher failed'); }
    $published=getMeta('cacti_snmp_samples_37')['values'];
    checkShared($published[$fastOid]['value']==='82'&&$published[$oid.'.201']['value']==='83','Concurrent native batches must merge without losing either source');

    $oldSnapshot=getMeta(icct_nms_snmp_shared_key($host));
    $arguments=['host_id'=>37,'snmp_query_id'=>91,'result'=>false];
    checkShared(icct_nms_snmp_query_changed($arguments)===$arguments,'A native query hook must not change core arguments or results');
    putMeta(icct_nms_snmp_shared_key($host),$oldSnapshot); // A writer which began before reindex completes afterward.
    workerReset();$before=$calls;
    icct_nms_snmp_read($host,$oid,$collect);
    checkShared($calls===$before+1,'Reindex generation must reject an old in-flight snapshot');
    icct_nms_snmp_capture([['local_data_id'=>82,'times'=>[time()-40=>['fast'=>'before-reindex']]]]);
    checkShared(empty(getMeta('cacti_snmp_samples_37')['values']),'Pre-reindex native output must not revive old interface indexes');
    $before=getMeta(icct_nms_snmp_shared_key($host).'_epoch');
    checkShared(icct_nms_snmp_device_saved($host)===$host&&getMeta(icct_nms_snmp_shared_key($host).'_epoch')===$before,
        'Unchanged native device saves do not invalidate valid observations');
    $changed=array_replace($host,['snmp_context'=>'changed-context']);
    checkShared(icct_nms_snmp_device_saved($changed)===$changed&&getMeta(icct_nms_snmp_shared_key($host).'_epoch')!==$before,
        'Native security changes invalidate samples and return the save record untouched');
    checkShared(icct_nms_snmp_device_removed([37])===[37],'Deletion hook preserves the native device ID list');

    $saveAvailable=false; workerReset();
    expectFailure(static fn()=>icct_nms_snmp_read($host,$oid.'.100',$collect),'Cache write failure must not be reported as a successful shared sample');
    checkShared(!$locks,'Exceptions must release all acquired locks');
    echo "PASS: Cross-worker persistence, binary fidelity, timestamps, failure sharing, ownership/lifecycle, native counters, no fallback and concurrent single collection.\n";
} finally {
    foreach (glob($directory.'/*') as $path) unlink($path);
    rmdir($directory);
}
