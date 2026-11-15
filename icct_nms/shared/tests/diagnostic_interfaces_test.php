<?php
require __DIR__ . '/../../inventory/diagnostics/services/backend/diagnostics.php';
function icct_backend_inventory_collector_id() { return 1; }
function db_fetch_cell_prepared($sql, $args) {
    if ($args !== ['diagnostic_runner_2']) throw new Exception('Wrong collector');
    return json_encode(['arp_interfaces'=>['ens9'=>128,'br0'=>16,'lo'=>0]]);
}
function checkArp($ok, $message) { if (!$ok) throw new Exception($message); }
$ether=['link_type'=>'ether','flags'=>['UP','BROADCAST'],'physical'=>true];
checkArp(icct_backend_diag_arp_classify($ether)===1,'Ethernet');
foreach (['wireless'=>2,'usb'=>4,'vm_nic'=>128] as $flag=>$bit) checkArp(icct_backend_diag_arp_classify($ether+[$flag=>true])===$bit,$flag);
foreach (['vlan'=>8,'bridge'=>16,'bond'=>32,'veth'=>64,'macvlan'=>512,'vxlan'=>1024] as $kind=>$bit) checkArp(icct_backend_diag_arp_classify($ether+['linkinfo'=>['info_kind'=>$kind]])===$bit,$kind);
checkArp(icct_backend_diag_arp_classify($ether+['linkinfo'=>['info_kind'=>'tun'],'tap'=>true])===256,'TAP');
checkArp(icct_backend_diag_arp_classify($ether+['linkinfo'=>['info_kind'=>'tun']])===0,'Exclude TUN');
checkArp(icct_backend_diag_arp_classify(array_replace($ether,['flags'=>['NOARP']]))===0,'Exclude NOARP');
checkArp(icct_backend_diag_arp_classify(['link_type'=>'loopback'])===0,'Exclude loopback');
checkArp(array_keys(icct_backend_diag_interface_choices(2))===[16,128],'Remote collector type choices');
$input=['diagnostic_profile_name'=>'ARP types','diagnostic_tools'=>['arp'],'arp_types_present'=>1,'arp_types'=>['8','16','128']];
$profile=icct_backend_diag_profile_validate($input);
checkArp($profile['arp_interface']==='@arp:152','Multiple types persist within existing column');
$inventory=['eth0'=>1,'vlan10'=>8,'vlan20'=>8,'br0'=>16,'ens160'=>128];
checkArp(icct_backend_diag_arp_resolve($profile['arp_interface'],$inventory)===['vlan10','vlan20','br0','ens160'],'Resolve every matching interface once');
checkArp(icct_backend_diag_arp_resolve('@arp:2',$inventory)===[],'Missing type must not broaden scope');
checkArp(icct_backend_diag_arp_resolve('@arp:0',$inventory)===array_keys($inventory),'Empty means all eligible');
checkArp(icct_backend_diag_arp_resolve('vlan10',$inventory)===['vlan10'],'Legacy profile preserves interface');
checkArp(icct_backend_diag_arp_selected('vlan10',$inventory)===[8],'Legacy UI selects type');
foreach ([['9999'],['-1'],['1;id'],['16',[]]] as $bad) {
 try { icct_backend_diag_profile_validate(array_replace($input,['arp_types'=>$bad])); throw new Exception('Invalid type accepted'); }
 catch (InvalidArgumentException $expected) {}
}
echo "PASS: ARP type classification, exclusions, remote scope, multi-selection persistence, resolution and validation.\n";
