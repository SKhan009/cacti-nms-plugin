<?php
/** Read-only live VM and SNMPv3 test. Ephemeral profiles are supplied by run_local_validation.py. */
if(PHP_SAPI!=='cli') {http_response_code(403);exit;}
$opts=getopt('',array('cacti-root:','profiles:','host-id:'));
if(empty($opts['cacti-root']) || empty($opts['profiles']) || empty($opts['host-id'])) exit(2);
require($opts['cacti-root'].'/include/cli_check.php');
require_once(dirname(__DIR__, 2).'/plugins/topology/includes/discovery.php');
try {
    $_SESSION=array('sess_user_id'=>(int)read_config_option('admin_user'));
    $host=tp_host(tp_id($opts['host-id']));
    if(!in_array($host['hostname'],array('127.0.0.1','localhost','::1'),true)) throw new RuntimeException('This test requires an explicitly selected local device.');
    $original=$host;
    $profiles=json_decode(file_get_contents($opts['profiles']),true,512,JSON_THROW_ON_ERROR);
    function verified($value,$label) {if(!$value) throw new RuntimeException($label);echo "PASS: $label\n";}
    function rejected($fn,$label) {
        try {$fn();} catch(RuntimeException $e) {echo "PASS: $label\n";return;}
        throw new LogicException('Unexpected success: '.$label);
    }
    function probe($h,$interfaces=false) {
        if((string)$h['snmp_version']==='3') return tp_snmp_isolated($h,'probe',microtime(true)+30);
        $s=tp_discovery_session($h);
        try {
            $deadline=microtime(true)+30;$budget=5000;
            $name=tp_snmp_scalar($s,'1.3.6.1.2.1.1.5.0',$deadline);
            $up=tp_snmp_scalar($s,'1.3.6.1.2.1.1.3.0',$deadline);
            if($name['type']!==4 || $name['value']==='' || $up['type']!==67) throw new RuntimeException('Invalid live system response.');
            if(!$interfaces) return array();
            $values=array();
            foreach(array('1.3.6.1.2.1.2.2.1','1.3.6.1.2.1.31.1.1.1.1','1.3.6.1.2.1.31.1.1.1.18') as $root) $values+=tp_snmp_subtree($s,$root,$deadline,$budget);
            return tp_interfaces($values);
        } finally {$s->close();}
    }
    $ports=probe($host,true);
    verified(count($ports)>0,'existing local Cacti device: live system and IF-MIB reads with saved credentials');
    foreach(array('lldp','cdp') as $protocol) rejected(function()use($host,$protocol){tp_collect($host,$protocol,microtime(true)+40);},'local VM without '.$protocol.' MIB fails explicitly');
    foreach($profiles['net_snmp'] as $label=>$profile) {
        $h=array_replace($host,$profile);
        $v3ports=probe($h,true);
        verified(count($v3ports)>0,'live VM SNMPv3 '.$label.': system and IF-MIB reads');
        // The existing VM view exposes ifDescr but excludes ifName; compare the shared IF-MIB column.
        $nativePorts=array_column($ports,'description','index');$v3Ports=array_column($v3ports,'description','index');ksort($nativePorts);ksort($v3Ports);
        verified($nativePorts===$v3Ports,'SNMPv3 '.$label.' reports the same real VM interface indices and descriptions');
        verified(count(array_filter(array_column($v3ports,'name')))>0,'SNMPv3 '.$label.' exposes IF-MIB interface names');
    }
    $h=array_replace($host,$profiles['net_snmp']['SHA/AES authPriv']);
    foreach(array(
        'wrong username'=>array('snmp_username'=>'nonexistent-validation-user'),
        'wrong authentication key'=>array('snmp_password'=>'invalid-validation-auth'),
        'wrong privacy key'=>array('snmp_priv_passphrase'=>'invalid-validation-priv'),
        'authNoPriv against a priv-required agent'=>array('snmp_priv_protocol'=>'[None]','snmp_priv_passphrase'=>''),
        'noAuthNoPriv against a priv-required agent'=>array('snmp_auth_protocol'=>'[None]','snmp_password'=>'','snmp_priv_protocol'=>'[None]','snmp_priv_passphrase'=>''),
        'unknown context'=>array('snmp_context'=>'no-such-context'),
        'missing authentication key'=>array('snmp_password'=>''),
        'missing privacy key'=>array('snmp_priv_passphrase'=>''),
        'explicit authoritative engine ID'=>array('snmp_engine_id'=>'8000000001020304')
    ) as $label=>$change) {
        $bad=array_replace($h,$change);$before=$bad;
        rejected(function()use($bad){probe($bad);},$label.' fails without security or context fallback');
        verified($bad===$before,$label.' does not rewrite device settings');
    }
    foreach(array('lldp','cdp') as $protocol) rejected(function()use($h,$protocol){tp_collect($h,$protocol,microtime(true)+40);},'SNMPv3 live VM missing '.$protocol.' MIB is rejected');
    verified(count(probe($h,true))>0,'correct SNMPv3 credentials still succeed after all negative tests');
    $snap=array();
    foreach($profiles['simulator'] as $index=>$profile) {
        $h=array_replace($host,$profile);$id=100001+$index;
        foreach(array('lldp','cdp') as $protocol) {
            $data=tp_collect($h,$protocol,microtime(true)+45);
            verified(count($data['neighbors'])===1,'SNMPv3 simulator named context '.$index.' returns one '.$protocol.' neighbor');
            $data['neighbors']=tp_observation_history(array(),$data['neighbors'],time());
            $snap[$id.'|'.$protocol]=array('host_id'=>$id,'protocol'=>$protocol,'status'=>'success','valid'=>true,'data'=>$data);
        }
    }
    $r=tp_reconcile($snap,time(),900);
    verified(count($r['links'])===1 && $r['links'][0]['state']==='Reciprocal' && count($r['links'][0]['evidence'])===4,'SNMPv3 simulated LLDP/CDP: one reciprocal link with four observations');
    $bad=array_replace($h,array('snmp_context'=>'absent-record'));
    rejected(function()use($bad){tp_collect($bad,'lldp',microtime(true)+30);},'unknown simulator context never selects another record');
    verified(tp_host($host['id'])===$original,'core local device configuration and credentials remain unchanged');
    echo "LOCAL AND SNMPv3 VALIDATION COMPLETE\n";
} catch(Throwable $e) {
    // Only report our bounded validation/collector errors, never raw SNMP library exceptions.
    $message=($e instanceof LogicException || $e instanceof RuntimeException)?$e->getMessage():'Local validation failed.';
    foreach(array_merge(array($host ?? array()),array_values($profiles['net_snmp'] ?? array()),$profiles['simulator'] ?? array()) as $p) {
        foreach(array('snmp_community','snmp_password','snmp_priv_passphrase') as $key) if(!empty($p[$key])) $message=str_replace($p[$key],'[redacted]',$message);
    }
    fwrite(STDERR,'FAIL: '.substr($message,0,500)."\n");exit(1);
}
