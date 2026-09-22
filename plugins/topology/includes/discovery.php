<?php
/** Bounded background discovery using Cacti's native SNMP session and device configuration. */
require_once(__DIR__.'/model.php');
require_once(__DIR__.'/neighbors.php');

/** Read configured protocol choices literally; both means two independent collectors. */
function tp_protocols($choice) {
    if($choice==='none') return array();
    if($choice==='both') return array('lldp','cdp');
    if(in_array($choice,array('lldp','cdp'),true)) return array($choice);
    throw new RuntimeException('Configure an explicit discovery preference for this device.');
}
/** Invalidate old observations when core endpoint/security/assignment configuration changes. */
function tp_discovery_hash($host) {
    $values=array();
    foreach(array('hostname','site_id','unit_id','poller_id','disabled','snmp_version','snmp_community','snmp_username','snmp_password','snmp_auth_protocol','snmp_priv_passphrase','snmp_priv_protocol','snmp_context','snmp_engine_id','snmp_port','snmp_timeout') as $key) $values[$key]=$host[$key] ?? null;
    $values['unit_id']=(int)$values['unit_id'];
    return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
}
/** List only visible core devices in this node, with their plugin-owned protocol preference. */
function tp_discovery_hosts($unitId) {
    $unit=tp_unit($unitId);$out=array();
    $prefs=array_column(db_fetch_assoc_prepared('SELECT host_id,protocol,unit_id FROM plugin_topology_devices WHERE unit_id=?',array($unit['id'])),null,'host_id');
    foreach(get_allowed_devices() as $host) if(isset($prefs[$host['id']])) {
        $host=tp_host($host['id']);$host['protocol']=$prefs[$host['id']]['protocol'];$host['unit_id']=(int)$unit['id'];$out[$host['id']]=$host;
    }
    return $out;
}
/** Save explicit cadence and staleness policy without changing Cacti's normal polling interval. */
function tp_discovery_save($site,$enabled,$interval,$stale) {
    tp_require_core('topo_discovery.php');$site=(int)tp_unit($site)['id'];
    if((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_topology_devices d JOIN host h ON h.id=d.host_id WHERE d.unit_id=? AND h.deleted=""',array($site))!==count(tp_discovery_hosts($site)))throw new RuntimeException('Access to every assigned device is required to change the shared collection policy.');
    $interval=tp_id($interval);$stale=tp_id($stale);
    if(!in_array((string)$enabled,array('0','1'),true) || $interval<60 || $interval>86400 || $stale<$interval*2 || $stale>604800) throw new InvalidArgumentException('Interval must be 60–86400 seconds; stale age must be at least two intervals and at most seven days.');
    tp_exec('INSERT INTO plugin_topology_discovery_sites (unit_id,enabled,interval_seconds,stale_seconds,updated_by,updated_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),interval_seconds=VALUES(interval_seconds),stale_seconds=VALUES(stale_seconds),updated_by=VALUES(updated_by),updated_at=NOW()',array($site,(int)$enabled,$interval,$stale,(int)$_SESSION['sess_user_id']));
    tp_audit('discovery_settings',$site);
}
/** Queue a job atomically; no SNMP calls or shell commands occur in a web request. */
function tp_discovery_queue($site) {
    tp_require_core('topo_discovery.php');$site=(int)tp_unit($site)['id'];$lock='topology_queue_'.$site;
    if(!db_fetch_cell_prepared('SELECT unit_id FROM plugin_topology_discovery_sites WHERE unit_id=?',array($site))) throw new RuntimeException('Save discovery settings first.');
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,2)',array($lock))!==1) throw new RuntimeException('The discovery queue is busy.');
    try {
        $id=(int)db_fetch_cell_prepared('SELECT id FROM plugin_topology_jobs WHERE unit_id=? AND state IN ("queued","running") ORDER BY id LIMIT 1',array($site));
        if($id) return $id;
        tp_exec('INSERT INTO plugin_topology_jobs (unit_id,user_id,state,requested_at) VALUES (?,?,"queued",NOW())',array($site,(int)$_SESSION['sess_user_id']));
        $id=(int)db_fetch_cell('SELECT LAST_INSERT_ID()');
        tp_exec('UPDATE plugin_topology_discovery_sites SET last_queued=NOW() WHERE unit_id=?',array($site));
        tp_audit('queue_discovery',$id);return $id;
    } finally {db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',array($lock));}
}
/** Reject incomplete/error SNMP responses without revealing native security parameters. */
function tp_snmp_value($response,$oid) {
    if(!is_object($response) || !isset($response->type,$response->value) || in_array((int)$response->type,array(128,129,130),true)) throw new RuntimeException('SNMP object is unavailable or not readable: '.$oid);
    if(strlen((string)$response->value)>4096) throw new RuntimeException('SNMP value exceeds the discovery size limit.');
    return array('type'=>(int)$response->type,'value'=>(string)$response->value);
}
/** Read one mandatory scalar with explicit native-session error handling. */
function tp_snmp_scalar($session,$oid,$deadline) {
    if(microtime(true)>$deadline) throw new RuntimeException('Discovery time limit reached.');
    $response=@$session->get($oid);
    if($response===false || $session->getErrno()) throw new RuntimeException('SNMP request failed or the required MIB object is not readable: '.$oid);
    return tp_snmp_value($response,$oid);
}
/** Walk via bounded GETNEXT, retaining type and octets; never accept a partial walk after failure. */
function tp_snmp_subtree($session,$root,$deadline,&$budget) {
    $values=array();$cursor=$root;
    while(true) {
        if(microtime(true)>$deadline || $budget<=0) throw new RuntimeException('Discovery time or 5000-object limit reached; partial result discarded.');
        $budget--;$result=@$session->getnext(array($cursor));
        if($result===false || $session->getErrno()) {
            // PHP reports a valid SNMPv2 endOfMibView as an error; other errors must fail.
            if($session->getErrno()===8 && preg_match('/No more variables left|End of MIB|endOfMibView/i',$session->getError())) break;
            throw new RuntimeException('SNMP table read failed: '.$root.'. Partial result discarded.');
        }
        if(!is_array($result) || count($result)!==1) throw new RuntimeException('Unexpected SNMP GETNEXT response.');
        $oid=ltrim((string)array_key_first($result),'.');$v=current($result);
        if(!preg_match('/^[0-9]+(?:\.[0-9]+)+$/D',$oid) || tp_oid_compare($oid,$cursor)<=0) throw new RuntimeException('Non-increasing or malformed SNMP OID.');
        if(strpos($oid,$root.'.')!==0) break;
        $values[$oid]=tp_snmp_value($v,$oid);$cursor=$oid;
    }
    return $values;
}
/** Compare numeric OID arcs, not lexicographic strings. */
function tp_oid_compare($a,$b) {
    $a=explode('.',$a);$b=explode('.',$b);
    for($i=0;$i<min(count($a),count($b));$i++) if((float)$a[$i]!=(float)$b[$i]) return (float)$a[$i]<(float)$b[$i]?-1:1;
    return count($a)<=>count($b);
}
/** Validate the explicitly configured SNMPv3 level before the native helper can downgrade missing keys. */
function tp_snmp_security($host) {
    if((string)$host['snmp_version']!=='3') return 'SNMPv2c';
    if($host['snmp_username']==='' || strlen($host['snmp_username'])>32) throw new RuntimeException('SNMPv3 requires a security name of 1–32 bytes.');
    $auth=$host['snmp_auth_protocol'];$priv=$host['snmp_priv_protocol'];
    if(!in_array($auth,array('[None]','MD5','SHA','SHA224','SHA256','SHA384','SHA512'),true) || !in_array($priv,array('[None]','DES','AES','AES128','AES192','AES192C','AES256','AES256C'),true)) throw new RuntimeException('SNMPv3 algorithm is not a recognized native Cacti selection.');
    $hasAuth=$auth!=='[None]';$hasPriv=$priv!=='[None]';
    if(($hasAuth && strlen($host['snmp_password'])<8) || (!$hasAuth && $host['snmp_password']!=='')) throw new RuntimeException('SNMPv3 authentication settings are inconsistent; security was not downgraded.');
    if(($hasPriv && strlen($host['snmp_priv_passphrase'])<8) || (!$hasPriv && $host['snmp_priv_passphrase']!=='')) throw new RuntimeException('SNMPv3 privacy settings are inconsistent; encryption was not disabled.');
    if($hasPriv && !$hasAuth) throw new RuntimeException('SNMPv3 privacy requires authentication.');
    if(strlen($host['snmp_context'])>32) throw new RuntimeException('SNMPv3 context exceeds 32 bytes.');
    return $hasPriv?'authPriv':($hasAuth?'authNoPriv':'noAuthNoPriv');
}
/** Open one native Cacti session with the selected endpoint and security settings, without alternate transport. */
function tp_discovery_session($host) {
    global $config;
    static $profiles=array();
    require_once($config['base_path'].'/lib/snmp.php');
    if(!$config['php_snmp_support'] || !extension_loaded('snmp')) throw new RuntimeException('Discovery requires the installed PHP SNMP extension; no alternate transport is selected.');
    if($host['disabled']!=='' || (int)$host['poller_id']!==(int)$config['poller_id']) throw new RuntimeException('Device is disabled or assigned to another collector.');
    if(!in_array((string)$host['snmp_version'],array('2','3'),true)) throw new RuntimeException('Discovery requires explicitly configured SNMPv2c or SNMPv3.');
    if($host['snmp_engine_id']!=='') throw new RuntimeException('An explicit authoritative SNMP engine ID is not supported by this native PHP session; configuration was not ignored.');
    $security=tp_snmp_security($host);
    if((string)$host['snmp_version']==='3') {
        $fingerprint=hash('sha256',json_encode(array($security,$host['snmp_auth_protocol'],$host['snmp_password'],$host['snmp_priv_protocol'],$host['snmp_priv_passphrase']),JSON_THROW_ON_ERROR));
        $name=$host['snmp_username'];
        if(isset($profiles[$name]) && !hash_equals($profiles[$name],$fingerprint)) throw new RuntimeException('A changed SNMPv3 profile requires an isolated collection process; cached keys were not reused.');
        $profiles[$name]=$fingerprint;
    }
    $timeout=(int)$host['snmp_timeout'];$retries=read_config_option('snmp_retries');
    if($timeout<1 || $timeout>5000 || !preg_match('/^[0-3]$/D',(string)$retries)) throw new RuntimeException('Discovery requires native timeout 1–5000 ms and native retries 0–3.');
    $session=false;
    try {
        $session=@cacti_snmp_session($host['hostname'],$host['snmp_community'],$host['snmp_version'],$host['snmp_username'],$host['snmp_password'],$host['snmp_auth_protocol'],$host['snmp_priv_passphrase'],$host['snmp_priv_protocol'],$host['snmp_context'],$host['snmp_engine_id'],$host['snmp_port'],$timeout,(int)$retries,1,1);
        // Cacti 1.2.31 does not check setSecurity's boolean return. Confirm the exact profile.
        if($session && (string)$host['snmp_version']==='3') {
            $ok=@$session->setSecurity($security,$host['snmp_auth_protocol']==='[None]'?'':$host['snmp_auth_protocol'],$host['snmp_password'],$host['snmp_priv_protocol']==='[None]'?'':$host['snmp_priv_protocol'],$host['snmp_priv_passphrase'],$host['snmp_context']);
            if(!$ok) {$session->close();$session=false;}
        }
    } catch(Throwable $e) {
        if($session) $session->close();
        throw new RuntimeException('Native Cacti SNMP session rejected the selected settings or algorithms.');
    }
    if(!$session) throw new RuntimeException('Native Cacti SNMP session rejected the selected settings or algorithms.');
    $session->valueretrieval=SNMP_VALUE_OBJECT|SNMP_VALUE_PLAIN;
    $session->enum_print=true;$session->oid_increasing_check=true;
    return $session;
}
/** Run a v3 read in a fresh native PHP process, preventing Net-SNMP USM key reuse across profiles. */
function tp_snmp_isolated($host,$operation,$jobDeadline) {
    if(PHP_SAPI!=='cli' || !function_exists('proc_open')) throw new RuntimeException('SNMPv3 discovery requires CLI process isolation; no shared-session fallback is allowed.');
    if(!in_array($operation,array('lldp','cdp','probe'),true)) throw new RuntimeException('Unknown isolated SNMP operation.');
    $deadline=min($jobDeadline,microtime(true)+30);
    if(microtime(true)>=$deadline) throw new RuntimeException('Discovery time limit reached.');
    $fields=array('hostname','disabled','poller_id','snmp_version','snmp_community','snmp_username','snmp_password','snmp_auth_protocol','snmp_priv_passphrase','snmp_priv_protocol','snmp_context','snmp_engine_id','snmp_port','snmp_timeout');
    $selected=array_intersect_key($host,array_flip($fields));
    $payload=json_encode(array('host'=>$selected,'operation'=>$operation,'deadline'=>$deadline),JSON_THROW_ON_ERROR);
    if(strlen($payload)>16384) throw new RuntimeException('SNMP settings exceed the isolated request size limit.');
    // Credentials travel only through an anonymous pipe, never argv, environment, files or logs.
    $process=@proc_open(array(PHP_BINARY,__DIR__.'/snmp_process.php'),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('file','/dev/null','a')),$pipes);
    if(!is_resource($process)) throw new RuntimeException('Could not start isolated native SNMP collection.');
    $output='';$exit=-1;
    try {
        $written=0;
        while($written<strlen($payload)) {
            $n=@fwrite($pipes[0],substr($payload,$written));
            if(!$n) throw new RuntimeException('Could not send isolated SNMP settings.');
            $written+=$n;
        }
        fclose($pipes[0]);unset($pipes[0]);stream_set_blocking($pipes[1],false);
        while(true) {
            $output.=stream_get_contents($pipes[1]);
            if(strlen($output)>4194304) throw new RuntimeException('Isolated SNMP response exceeds the size limit.');
            $state=proc_get_status($process);
            if(!$state['running']) {$exit=$state['exitcode'];$output.=stream_get_contents($pipes[1]);break;}
            if(microtime(true)>$deadline) throw new RuntimeException('Isolated SNMP collection reached its time limit; partial result discarded.');
            usleep(20000);
        }
    } finally {
        foreach($pipes as $pipe) if(is_resource($pipe)) fclose($pipe);
        $state=proc_get_status($process);
        if($state['running']) proc_terminate($process,9);
        proc_close($process);
    }
    $result=json_decode($output,true);
    if($exit!==0 || !is_array($result) || !isset($result['ok'])) throw new RuntimeException('Isolated SNMP collection failed; no partial result published.');
    if(!$result['ok']) throw new RuntimeException($result['error']);
    return $result['data'];
}
/** Read system identity and live interfaces for local validation through the same native transport. */
function tp_probe_direct($host) {
    $session=tp_discovery_session($host);$deadline=microtime(true)+30;$budget=5000;
    try {
        $name=tp_snmp_scalar($session,'1.3.6.1.2.1.1.5.0',$deadline);
        $up=tp_snmp_scalar($session,'1.3.6.1.2.1.1.3.0',$deadline);
        if($name['type']!==4 || $name['value']==='' || $up['type']!==67) throw new RuntimeException('Invalid live system response.');
        $values=array();
        foreach(array('1.3.6.1.2.1.2.2.1','1.3.6.1.2.1.31.1.1.1.1','1.3.6.1.2.1.31.1.1.1.18') as $root) $values+=tp_snmp_subtree($session,$root,$deadline,$budget);
        return tp_interfaces($values);
    } finally {$session->close();}
}
/** Collect one explicitly selected protocol, isolating v3 keys for every request. */
function tp_collect($host,$protocol,$jobDeadline) {
    if((string)$host['snmp_version']==='3') return tp_snmp_isolated($host,$protocol,$jobDeadline);
    return tp_collect_direct($host,$protocol,$jobDeadline);
}
/** Single-process collection; v3 callers enter only through the isolated child. */
function tp_collect_direct($host,$protocol,$jobDeadline) {
    $session=tp_discovery_session($host);
    $deadline=min($jobDeadline,microtime(true)+30);$budget=5000;$values=array();
    try {
        $uptime='1.3.6.1.2.1.1.3.0';$before=tp_snmp_scalar($session,$uptime,$deadline);
        if($before['type']!==67) throw new RuntimeException('sysUpTime must be TimeTicks.');
        foreach(array('1.3.6.1.2.1.2.2.1','1.3.6.1.2.1.31.1.1.1.1','1.3.6.1.2.1.31.1.1.1.18') as $root) $values+=tp_snmp_subtree($session,$root,$deadline,$budget);
        if($protocol==='lldp') {
            $values+=tp_snmp_subtree($session,'1.0.8802.1.1.2.1.3',$deadline,$budget);
            $values+=tp_snmp_subtree($session,'1.0.8802.1.1.2.1.4.1.1',$deadline,$budget);
            $data=tp_parse_lldp($values,tp_interfaces($values));
        } elseif($protocol==='cdp') {
            $values+=tp_snmp_subtree($session,'1.3.6.1.4.1.9.9.23.1',$deadline,$budget);
            $data=tp_parse_cdp($values,tp_interfaces($values));
        } else throw new RuntimeException('Unknown discovery protocol.');
        $after=tp_snmp_scalar($session,$uptime,$deadline);
        if($after['type']!==67 || (float)$after['value']<(float)$before['value']) throw new RuntimeException('Device restarted or uptime wrapped during collection; result discarded.');
        $data['collected']=time();return $data;
    } finally {$session->close();}
}
/** Publish each successful protocol snapshot atomically; retain old evidence explicitly on failure. */
function tp_discovery_device($host,$protocol,$deadline) {
    $hash=tp_discovery_hash($host);
    $old=db_fetch_row_prepared('SELECT * FROM plugin_topology_snapshots WHERE host_id=? AND protocol=?',array($host['id'],$protocol));
    tp_exec('INSERT INTO plugin_topology_snapshots (host_id,protocol,unit_id,status,attempted_at,config_hash,data_json) VALUES (?,?,?,"running",NOW(),?,"{}") ON DUPLICATE KEY UPDATE status="running",error="",attempted_at=NOW()',array($host['id'],$protocol,$host['unit_id'],$hash));
    try {
        $data=tp_collect($host,$protocol,$deadline);
        $previous=$old && $old['config_hash']===$hash?json_decode($old['data_json'],true,512,JSON_THROW_ON_ERROR):array();
        $data['neighbors']=tp_observation_history($previous['neighbors'] ?? array(),$data['neighbors'],time());
        // Discard a response if the native device or preference changed while the request was running.
        $current=tp_host($host['id']);$current['unit_id']=(int)db_fetch_cell_prepared('SELECT unit_id FROM plugin_topology_devices WHERE host_id=?',array($host['id']));
        $pref=db_fetch_cell_prepared('SELECT protocol FROM plugin_topology_devices WHERE host_id=?',array($host['id']));
        if(tp_discovery_hash($current)!==$hash || !in_array($protocol,tp_protocols($pref),true)) throw new RuntimeException('Device configuration changed during discovery; result discarded.');
        tp_exec('UPDATE plugin_topology_snapshots SET unit_id=?,status="success",error="",succeeded_at=NOW(),config_hash=?,data_json=? WHERE host_id=? AND protocol=?',array($host['unit_id'],$hash,json_encode($data,JSON_THROW_ON_ERROR),$host['id'],$protocol));
        return true;
    } catch(Throwable $e) {
        $message=$e instanceof RuntimeException?$e->getMessage():'Unexpected discovery failure; no partial result published.';
        tp_exec('UPDATE plugin_topology_snapshots SET status="failed",error=? WHERE host_id=? AND protocol=?',array(substr($message,0,255),$host['id'],$protocol));
        return false;
    }
}
/** Resolve current visible snapshots; exclude devices that moved site or no longer select a protocol. */
function tp_discovery_evidence($site,$hosts) {
    $out=array();
    foreach(db_fetch_assoc_prepared('SELECT * FROM plugin_topology_snapshots WHERE unit_id=?',array($site)) as $s) {
        if(!isset($hosts[$s['host_id']]) || !in_array($s['protocol'],tp_protocols($hosts[$s['host_id']]['protocol']),true)) continue;
        $s['valid']=hash_equals($s['config_hash'],tp_discovery_hash($hosts[$s['host_id']]));
        $s['data']=json_decode($s['data_json'],true,512,JSON_THROW_ON_ERROR);
        $out[$s['host_id'].'|'.$s['protocol']]=$s;
    }
    return $out;
}
