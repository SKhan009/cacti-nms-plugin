<?php
/** Regression tests for identity matching and topology evidence without a live database. */
if(PHP_SAPI!=='cli') {http_response_code(403);exit;}
require_once(dirname(__DIR__, 2).'/plugins/topology/includes/neighbors.php');
require_once(dirname(__DIR__, 2).'/plugins/topology/includes/records.php');
require_once(dirname(__DIR__, 2).'/plugins/topology/includes/drawing.php');
function expect($value,$label) {if(!$value) throw new RuntimeException($label);echo 'PASS: '.$label."\n";}
function fixture($name) {
    $out=array();foreach(tp_records(file_get_contents(__DIR__.'/fixtures/protocols/'.$name.'.snmprec')) as $oid=>$r) $out[$oid]=array('type'=>$r['tag']==='4x'?4:(int)$r['tag'],'value'=>$r['tag']==='4x'?hex2bin($r['value']):$r['value']);return $out;
}
function snapshot($id,$protocol,$data) {
    $data['collected']=1000;$data['neighbors']=tp_observation_history(array(),$data['neighbors'],1000);
    return array('host_id'=>$id,'protocol'=>$protocol,'status'=>'success','valid'=>true,'data'=>$data);
}
$a=fixture('switch-a-dual');$b=fixture('switch-b-dual');
$la=tp_parse_lldp($a,tp_interfaces($a));$lb=tp_parse_lldp($b,tp_interfaces($b));
$ca=tp_parse_cdp($a,tp_interfaces($a));$cb=tp_parse_cdp($b,tp_interfaces($b));
$s=array('1|lldp'=>snapshot(1,'lldp',$la),'2|lldp'=>snapshot(2,'lldp',$lb));
$r=tp_reconcile($s,1000,900);expect(count($r['links'])===1 && $r['links'][0]['state']==='Reciprocal','reverse LLDP observations merge into one link');
$s['1|cdp']=snapshot(1,'cdp',$ca);$s['2|cdp']=snapshot(2,'cdp',$cb);
$r=tp_reconcile($s,1000,900);expect(count($r['links'])===1 && count($r['links'][0]['evidence'])===4,'dual protocols preserve four observations on one physical port pair');
$single=array('1|cdp'=>snapshot(1,'cdp',$ca),'2|cdp'=>snapshot(2,'cdp',$cb));$single['2|cdp']['data']['neighbors']=array();
expect(tp_reconcile($single,1000,900)['links'][0]['state']==='One-sided','one-sided evidence is not reciprocal');
$single['2|cdp']['status']='failed';expect(!tp_reconcile($single,1000,900)['links'][0]['current'],'failed peer identity cannot confirm a live connection');
expect(!tp_reconcile($s,2000,900)['links'][0]['current'],'old successful samples expire');
$bad=$s;$bad['3|lldp']=snapshot(3,'lldp',$lb);$amb=tp_reconcile($bad,1000,900);expect(count($amb['unresolved'])>0,'duplicate chassis identities remain ambiguous');
$renumber=$a;
foreach(array_keys($renumber) as $oid) if(strpos($oid,'1.0.8802.1.1.2.1.3.7.1.')===0) {$renumber[substr($oid,0,-1).'101']=$renumber[$oid];unset($renumber[$oid]);}
foreach(array_keys($renumber) as $oid) if(strpos($oid,'1.0.8802.1.1.2.1.4.1.1.')===0) {$renumber[str_replace('.0.1.1','.0.101.1',$oid)]=$renumber[$oid];unset($renumber[$oid]);}
$mapped=tp_parse_lldp($renumber,tp_interfaces($renumber));expect(current($mapped['neighbors'])['local_ifindex']===1,'LLDP local port 101 maps by ifName to ifIndex 1');
$unknown=$la;$n=array_key_first($unknown['neighbors']);$unknown['neighbors'][$n]['peer_key']='4:ffffffffffff';
$u=tp_reconcile(array(snapshot(1,'lldp',$unknown),snapshot(2,'lldp',$lb)),1000,900);expect(count($u['unresolved'])===1,'matching system name is never used instead of chassis identity');
$parallel=$s;
foreach($parallel as &$ss) {
    $extra=current($ss['data']['neighbors']);$extra['key']='parallel';$extra['local_ifindex']=2;$extra['remote_key']=$ss['protocol']==='lldp'?'5:'.bin2hex('Ethernet2'):bin2hex('Ethernet2');
    $ss['data']['neighbors']['parallel']=$extra;
    $ss['data']['ports'][]=array('key'=>$extra['remote_key'],'label'=>'Ethernet2','ifindex'=>2);
}unset($ss);
expect(count(tp_reconcile($parallel,1000,900)['links'])===2,'parallel cables remain separate');
$old=$s['1|lldp']['data']['neighbors'];$missing=tp_observation_history($old,array(),1010);
expect(!current($missing)['present'] && current($missing)['last_seen']===1000,'missing rows retain historical last-seen timestamps');
expect(tp_observation_history($old,array(),606000)===array(),'neighbor history expires after seven days');
expect(count(tp_reconcile($s,606000,900)['links'])===0,'expired history is not drawn when collection remains failed');
$down=$s;$down['1|lldp']['data']['interfaces'][1]['oper']='2';$down['1|cdp']['data']['interfaces'][1]['oper']='2';
$downLink=tp_reconcile($down,1000,900)['links'][0];
expect($downLink['state']==='Reciprocal' && tp_link_interfaces($downLink,$down,1000,900)==='Down / Up','interface-down and neighbor evidence remain distinct');
expect(!tp_evidence_fresh(1100,1000,900),'future-dated evidence is not current');
$bad=$a;unset($bad['1.0.8802.1.1.2.1.4.1.1.7.0.1.1']);
try {tp_parse_lldp($bad,tp_interfaces($bad));throw new LogicException('Incomplete LLDP row accepted');} catch(RuntimeException $e) {echo "PASS: incomplete row rejects the snapshot\n";}
$bad=$a;
foreach(array_keys($bad) as $oid) if(preg_match('/^1\.0\.8802\.1\.1\.2\.1\.4\.1\.1\.[4567]\./',$oid)) unset($bad[$oid]);
try {tp_parse_lldp($bad,tp_interfaces($bad));throw new LogicException('Optional-only neighbor row accepted');} catch(RuntimeException $e) {echo "PASS: an SNMP view exposing only optional neighbor fields does not look empty\n";}
expect(tp_lldp_ifindex(7,bin2hex('1'),tp_interfaces($a))===0,'locally assigned port ID is not guessed as ifIndex');
echo "NEIGHBOR TESTS COMPLETE\n";
