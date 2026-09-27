<?php
require __DIR__ . '/../../plugins/nms/includes/discovery_identity.php';
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function integer($value) { return ['type' => 2, 'value' => (string) $value]; }
$legacy = '1.3.6.1.2.1.4.20.1.2.';
$modern = '1.3.6.1.2.1.4.34.1.';
$v6index = '2.16.' . implode('.', unpack('C*', inet_pton('2001:db8::1')));
$values = [$legacy . '10.0.0.1' => integer(1), $legacy . '10.0.0.2' => integer(2), $legacy . '999.0.0.1' => integer(1), $legacy . '10.0.0.3' => integer(0), $modern . '3.' . $v6index => integer(3), $modern . '4.' . $v6index => integer(1), $modern . '7.' . $v6index => integer(1)];
$rows = nms_nd_own_addresses($values);
check(count($rows) === 3, 'Own IPv4/IPv6 parsing and malformed rows');
check(in_array('2001:db8::1', array_column($rows, 'address')), 'IPv6 normalized');
$scoped = '4.20.' . implode('.', unpack('C*', inet_pton('fe80::1'))) . '.0.0.0.4';
$scopedRows = nms_nd_own_addresses([$modern . '3.' . $scoped => integer(4), $modern . '4.' . $scoped => integer(1), $modern . '7.' . $scoped => integer(1)]);
check($scopedRows[0]['zone'] === 4 && !nms_nd_address_matchable($scopedRows[0]), 'Scoped IPv6 retained but not matched');
foreach (['127.0.0.1', '169.254.1.1', '224.0.0.1', '::1', '::', 'fe80::1', 'ff02::1'] as $ip) check(!nms_nd_address_matchable(['address'=>$ip,'type'=>1,'status'=>1]), 'Unsafe cross-host address '.$ip);
check(!nms_nd_address_matchable(['address'=>'10.0.0.1','type'=>2,'status'=>1]), 'Anycast excluded');
check(!nms_nd_address_matchable(['address'=>'10.0.0.1','type'=>1,'status'=>7]), 'Duplicate address excluded');
$hosts = [];
foreach ([1,2,3] as $id) $hosts[$id] = ['id'=>$id,'hostname'=>'10.0.0.'.$id,'site_id'=>1,'poller_id'=>1,'snmp_context'=>''];
$own = nms_nd_own_addresses([$legacy.'10.0.0.1'=>integer(1),$legacy.'10.0.0.2'=>integer(2)]);
$snaps = [];
foreach ([1,2] as $id) $snaps[] = ['host_id'=>$id,'protocol'=>'identity','status'=>'success','valid'=>true,'data'=>['own_addresses'=>$own,'hardware'=>['chassis'=>[['model'=>'Router','serial'=>'SERIAL123']]]]];
$r = nms_nd_device_identities($hosts,$snaps);
check($r[1]['matches'][0]['state'] === 'Matching device evidence', 'Reciprocal addresses plus chassis identify same device');
check(count($r[1]['addresses']) === 2 && !$r[3]['matches'], 'Multiple IPs retained on one device without unrelated matches');
$bad=$snaps; $bad[1]['data']['hardware']['chassis'][0]['serial']='OTHER';
check(nms_nd_device_identities($hosts,$bad)[1]['matches'][0]['state']==='Conflicting identity','Shared VIP must not merge distinct routers');
$bad=$snaps; foreach ($bad as &$s) $s['data']['hardware']=[]; unset($s);
check(nms_nd_device_identities($hosts,$bad)[1]['matches'][0]['state']==='Possible same device / shared address','IP overlap alone not conclusive');
foreach (['stale','failed','running'] as $state) { $bad=$snaps; foreach($bad as &$s) $s['status']=$state; unset($s); check(!nms_nd_device_identities($hosts,$bad)[1]['addresses'], 'Reject '.$state); }
$bad=$snaps;foreach($bad as &$s) $s['valid']=false;unset($s);
check(!nms_nd_device_identities($hosts,$bad)[1]['matches'],'Changed configuration excluded');
$r=nms_nd_device_identities([1=>$hosts[1]],$snaps);check(!$r[1]['matches'] && !isset($r[2]),'Hidden hosts cannot leak');
foreach (['site_id','poller_id','snmp_context'] as $field) { $different=$hosts;$different[2][$field]=2;check(!nms_nd_device_identities($different,$snaps)[1]['matches'],'Isolate '.$field); }
check(nms_nd_chassis_key(['hardware'=>['chassis'=>[['model'=>'Router','serial'=>'unknown']]]])==='','Placeholder serial excluded');
check(!nms_nd_device_identities($hosts,[])[1]['addresses'],'Older/missing snapshots supported');
echo "PASS: own IPv4/IPv6, scoped IPs, malformed rows, same-device evidence, shared VIPs, stale/failed/config-changed evidence, scope and visibility\n";
// A modern duplicate/anycast status must override legacy data regardless of walk order.
$idx='1.4.10.0.0.1';
$both=[$modern.'3.'.$idx=>integer(1),$modern.'4.'.$idx=>integer(2),$modern.'7.'.$idx=>integer(7),$legacy.'10.0.0.1'=>integer(1)];
$parsed=nms_nd_own_addresses($both);check(count($parsed)===1 && !nms_nd_address_matchable($parsed[0]),'Modern unsafe address must not fall back to legacy unicast');
// A chassis-only match is advisory; LLDP disagreement blocks strong identity.
$bad=$snaps;$bad[1]['data']['own_addresses']=[];
check(nms_nd_device_identities($hosts,$bad)[1]['matches'][0]['state']==='Possible same device / shared address','Chassis alone insufficient');
$bad=$snaps;foreach([1=>'4:001122334455',2=>'4:001122334466'] as $id=>$identity) $bad[]=['host_id'=>$id,'protocol'=>'lldp','status'=>'success','valid'=>true,'data'=>['identity'=>$identity]];
check(nms_nd_device_identities($hosts,$bad)[1]['matches'][0]['state']==='Conflicting identity','LLDP conflict blocks strong match');
function nms_h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$address_identity=nms_nd_device_identities($hosts,$snaps)[1];
$address_hosts=$hosts;$address_hosts[2]['description']='<script>alert(1)</script>';
ob_start();require __DIR__.'/../../plugins/nms/templates/devices/address_identity.php';$html=ob_get_clean();
check(strpos($html,'<script>')===false && strpos($html,'&lt;script&gt;')!==false,'Peer names escaped');
check(strpos($html,'10.0.0.1')!==false && strpos($html,'10.0.0.2')!==false && strpos($html,'Matching device evidence')!==false,'Address and identity UI renders');
$address_hosts=[];ob_start();require __DIR__.'/../../plugins/nms/templates/devices/address_identity.php';$html=ob_get_clean();check(strpos($html,'id=2')===false,'UI refuses unknown peer');
echo "PASS: modern table precedence, chassis-only ambiguity, LLDP conflicts, rendered addresses, escaped names and inaccessible peers\n";
