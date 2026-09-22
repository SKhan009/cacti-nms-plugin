<?php
/** Explicit two-switch lab integration. Live modes read SNMP; failure mode verifies retained evidence. */
if(PHP_SAPI!=='cli') {http_response_code(403);exit;}
$options=getopt('',array('cacti-root:','unit-id:','host-a:','host-b:','community-a:','community-b:','mode:'));
if(empty($options['cacti-root'])) die("Supply --cacti-root.\n");
require($options['cacti-root'].'/include/cli_check.php');
require_once($options['cacti-root'].'/plugins/topology/includes/discovery.php');
// A test assertion must report a failed test, not invoke Cacti's fatal-plugin shutdown handler.
try {
$_SESSION=array('sess_user_id'=>(int)read_config_option('admin_user'));
function confirm($v,$label) {if(!$v) throw new RuntimeException($label);echo 'PASS: '.$label."\n";}
function refuses($fn,$label) {try{$fn();}catch(RuntimeException $e){echo 'PASS: '.$label."\n";return;}throw new LogicException('Accepted: '.$label);}
$site=(int)tp_unit($options['unit-id'] ?? '')['id'];$hosts=tp_discovery_hosts($site);
$a=tp_id($options['host-a'] ?? '');$b=tp_id($options['host-b'] ?? '');
confirm(isset($hosts[$a],$hosts[$b]),'explicit test hosts belong to the node');
$mode=$options['mode'] ?? '';
if($mode==='failed') {
    foreach(array($a,$b) as $id) {
        $before=db_fetch_row_prepared('SELECT * FROM plugin_topology_snapshots WHERE host_id=? AND protocol="lldp"',array($id));
        confirm($before && $before['status']==='success','successful baseline exists');
        confirm(!tp_discovery_device($hosts[$id],'lldp',microtime(true)+30),'unavailable simulator rejects live collection');
        $after=db_fetch_row_prepared('SELECT * FROM plugin_topology_snapshots WHERE host_id=? AND protocol="lldp"',array($id));
        confirm($after['data_json']===$before['data_json'] && $after['succeeded_at']===$before['succeeded_at'] && $after['status']==='failed','failure retains evidence and last-success time without substituting samples');
    }
    $r=tp_reconcile(tp_discovery_evidence($site,$hosts),time(),900);
    confirm(count($r['links'])===1 && !$r['links'][0]['current'],'retained connection is historical after failure');exit;
}
if($mode==='baseline') {
    $snap=tp_discovery_evidence($site,$hosts);$r=tp_reconcile($snap,time(),900);
    confirm(count($r['links'])===1 && $r['links'][0]['state']==='Reciprocal','persisted lab link is reciprocal and deduplicated');
    $changed=$hosts;$changed[$a]['snmp_port']=1163;
    $invalid=tp_discovery_evidence($site,$changed);
    confirm(!$invalid[$a.'|lldp']['valid'],'changed core endpoint invalidates old snapshots');
    $onlyA=tp_discovery_evidence($site,array($a=>$hosts[$a]));
    confirm(count(tp_reconcile($onlyA,time(),900)['links'])===0,'hidden peer is not resolved or drawn');
    $_SESSION=array('sess_user_id'=>3);
    refuses(function()use($site){tp_discovery_queue($site);},'restricted user cannot queue discovery');exit;
}
if(!in_array($mode,array('lldp','cdp','both'),true)) throw new RuntimeException('Choose a supported explicit test mode.');
$snap=array();
foreach(array($a=>'a',$b=>'b') as $id=>$suffix) {
    $h=$hosts[$id];
    if(empty($options['community-'.$suffix])) throw new RuntimeException('Supply explicit lab test communities.');
    $h['snmp_community']=$options['community-'.$suffix];
    foreach(tp_protocols($mode) as $protocol) {
        $data=tp_collect($h,$protocol,microtime(true)+60);
        $data['neighbors']=tp_observation_history(array(),$data['neighbors'],time());
        $snap[$id.'|'.$protocol]=array('host_id'=>$id,'protocol'=>$protocol,'status'=>'success','valid'=>true,'data'=>$data);
        confirm(count($data['neighbors'])===1,'live '.$protocol.' table contains one neighbor for '.$id);
    }
    if($mode==='cdp') refuses(function()use($h){tp_collect($h,'lldp',microtime(true)+60);},'LLDP against a CDP-only agent fails without CDP fallback');
    if($mode==='lldp') refuses(function()use($h){tp_collect($h,'cdp',microtime(true)+60);},'CDP against an LLDP-only agent fails without LLDP fallback');
}
$r=tp_reconcile($snap,time(),900);
confirm(count($r['links'])===1 && $r['links'][0]['state']==='Reciprocal','live '.$mode.' pair produces one reciprocal connection');
confirm(count($r['links'][0]['evidence'])===($mode==='both'?4:2),'all live protocol observations are preserved');
echo "LIVE DISCOVERY TEST COMPLETE\n";
} catch(Throwable $e) {fwrite(STDERR,'FAIL: '.$e->getMessage()."\n");exit(1);}
