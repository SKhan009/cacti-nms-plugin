<?php
require __DIR__ . '/../../ports/services/backend/interface_rates.php';
require __DIR__ . '/../../dashboard/topology/services/topology_link_service.php';
function verifyInterface($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$port = ['index'=>7,'name'=>'Gi1/0/24','name_hex'=>bin2hex('Gi1/0/24'),
    'description'=>'Ethernet port','description_hex'=>bin2hex('Ethernet port'),'mac_hex'=>'001122334455',
    'in_octets'=>'18446744070000000000','out_octets'=>'1000000','discontinuity'=>'0',
    'admin'=>'1','oper'=>'1','high_speed_mbps'=>1000];
$previous = ['collected'=>1000,'uptime'=>100000,'interfaces'=>[7=>$port]];
$current = $previous; $current['collected']=1300; $current['uptime']=130000;
$current['interfaces'][7]['in_octets']='18446744070000037500';
$current['interfaces'][7]['out_octets']='1075000';
$rates = icct_backend_interface_rates($current,$previous,900);
verifyInterface($rates['interfaces'][7]['in_bps']===1000.0 && $rates['interfaces'][7]['out_bps']===2000.0,'Large Counter64 deltas must retain precision.');
verifyInterface($rates['interfaces'][7]['measurement_state']==='valid','Two consistent samples must be valid.');
$zero=$current; $zero['interfaces']=$previous['interfaces'];
$zero=icct_backend_interface_rates($zero,$previous,900);
verifyInterface($zero['interfaces'][7]['in_bps']===0.0 && $zero['interfaces'][7]['measurement_state']==='valid','Zero traffic is a valid rate.');
$first=icct_backend_interface_rates($current,[],900);
verifyInterface($first['interfaces'][7]['measurement_state']==='waiting_for_second_sample','First sample must wait.');
$denied=$current; $denied['interfaces'][7]['in_octets']=null;
$denied=icct_backend_interface_rates($denied,$previous,900);
verifyInterface($denied['interfaces'][7]['measurement_state']==='counters_inaccessible','Denied counters must differ from first sample.');
foreach (['reboot','discontinuity','identity','decrease','gap'] as $case) {
    $changed=$current;
    if($case==='reboot') $changed['uptime']=50;
    if($case==='discontinuity') $changed['interfaces'][7]['discontinuity']='1';
    if($case==='identity') $changed['interfaces'][7]['mac_hex']='ffeeddccbbaa';
    if($case==='decrease') $changed['interfaces'][7]['in_octets']='10';
    if($case==='gap') { $changed['collected']=3000; $changed['uptime']=300000; }
    $r=icct_backend_interface_rates($changed,$previous,900)['interfaces'][7];
    verifyInterface($r['measurement_state']!=='valid' && $r['in_bps']===null,"$case must not produce a rate");
}
$fingerprint=icct_backend_interface_identity($port);
verifyInterface(icct_nms_link_port([7=>$port],'Gi1/0/24',7,$fingerprint)===$port,'Saved identity must resolve.');
$changed=$port;$changed['mac_hex']='ffeeddccbbaa';
verifyInterface(icct_nms_link_port([7=>$changed],'Gi1/0/24',7,$fingerprint)===null,'Reused ifIndex must not retarget a link.');
verifyInterface(icct_nms_link_port([7=>$port,8=>$port],'Gi1/0/24')===null,'Ambiguous legacy labels must fail.');
verifyInterface(icct_nms_link_port([7=>$port],'arbitrary label')===null,'Unindexed legacy labels must match an actual interface.');
verifyInterface(icct_nms_link_port([7=>$port],'LLDP-local-port',7)===$port,'Fresh discovery indexes remain authoritative.');
$host=['id'=>2,'enabled'=>1,'collection_enabled'=>1,'stale_seconds'=>900,'interval_seconds'=>300];
$snapshot=['status'=>'success','succeeded_at'=>date('Y-m-d H:i:s'),'config_hash'=>'current','data_json'=>json_encode($rates)];
function icct_backend_nd_hosts() { global $host; return [$host]; }
function icct_backend_protocol_enabled($id,$protocol) { return true; }
function icct_backend_nd_hash($host) { return 'current'; }
function db_fetch_row_prepared($sql,$args) { global $snapshot; return $snapshot; }
$r=icct_nms_link_reading(2,'Gi1/0/24',7,$fingerprint);
verifyInterface($r['measurement_state']==='valid' && $r['in_bps']===1000.0,'Popup must report selected interface rates.');
verifyInterface($r['sample_started']===1000 && $r['sample_ended']===1300,'Popup must retain both sample timestamps.');
$snapshot['data_json']=json_encode($zero);
verifyInterface(icct_nms_link_reading(2,'Gi1/0/24',7,$fingerprint)['in_bps']===0.0,'Popup must retain genuine zero.');
verifyInterface(icct_nms_link_reading(2,'',0)['measurement_state']==='interface_not_mapped','Unmapped state must be explicit.');
$snapshot['data_json']=json_encode($first);
verifyInterface(icct_nms_link_reading(2,'Gi1/0/24',7,$fingerprint)['measurement_state']==='waiting_for_second_sample','Popup must show first sample.');
$snapshot['data_json']=json_encode($denied);
verifyInterface(icct_nms_link_reading(2,'Gi1/0/24',7,$fingerprint)['measurement_state']==='counters_inaccessible','Popup must show inaccessible counters.');
$snapshot['succeeded_at']=date('Y-m-d H:i:s',time()-901);
verifyInterface(icct_nms_link_reading(2,'Gi1/0/24',7,$fingerprint)['measurement_state']==='collection_stale','Stale collection must not report live rates.');
verifyInterface(icct_nms_link_interface_choices(2)['interfaces']===[],'Stale interfaces must not be offered for mapping.');
echo "PASS: Counter64 precision, zero rates, reboot/reset/identity/gap guards, saved mappings and all five measurement states.\n";

// Exercise the real save boundary with isolated database/authorization adapters.
require __DIR__ . '/../../dashboard/topology/services/topology_configuration_service.php';
function icct_backend_require_management($realm=0) {}
function icct_backend_require_device_access($id) {}
function icct_backend_current_user_id() { return 1; }
function icct_nms_connections() { return ['ethernet'=>['name'=>'Ethernet']]; }
function icct_backend_topology_integer($value,$min,$max,$label) {
    if(!is_scalar($value) || !preg_match('/^[0-9]+$/D',(string)$value) || $value<$min || $value>$max) throw new InvalidArgumentException($label);
    return (int)$value;
}
function db_fetch_cell_prepared($sql,$args) {
    return str_contains($sql,'meta_value') ? '{}' : 1;
}
$saved=[];
function icct_backend_category_execute($sql,$args) { global $saved; $saved=json_decode($args[1],true); }
$snapshot['succeeded_at']=date('Y-m-d H:i:s');$snapshot['data_json']=json_encode($rates);
$input=['source'=>'2','target'=>'3','profile'=>'ethernet','source_ifindex'=>'7',
    'source_identity'=>$fingerprint,'target_ifindex'=>'0','source_port'=>'made-up label'];
icct_nms_connection_save($input);
$link=reset($saved);
verifyInterface($link['source']===2 && $link['source_ifindex']===7 && $link['source_identity']===$fingerprint && $link['source_port']==='Gi1/0/24','Save must bind device, index and observed identity, ignoring supplied free text.');
foreach ([['source_identity'=>str_repeat('0',64)],['source_ifindex'=>'999'],['source_ifindex'=>'0']] as $change) {
    try { icct_nms_connection_save(array_replace($input,$change)); throw new LogicException('Invalid mapping accepted'); }
    catch (InvalidArgumentException $expected) {}
}
$snapshot['succeeded_at']=date('Y-m-d H:i:s',time()-901);
try { icct_nms_connection_save($input); throw new LogicException('Stale mapping accepted'); }
catch (InvalidArgumentException $expected) {}
echo "PASS: Save uses observed identity and rejects changed, missing, forged and stale endpoints.\n";
