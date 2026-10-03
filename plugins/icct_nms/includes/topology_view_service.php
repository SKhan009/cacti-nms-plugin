<?php
require_once __DIR__.'/topology_configuration_service.php';
require_once __DIR__.'/connection_service.php';
/** Visual positions are independent of rack units and geographic coordinates. */
function icct_nms_topology_layout() {
    $raw=(string)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['topology_layout']);
    return $raw ? json_decode($raw,true,512,JSON_THROW_ON_ERROR) : [];
}
function icct_nms_topology_data($mapData) {
    $devices=$mapData['unlocated'];foreach($mapData['sites'] as $site)$devices=array_merge($devices,$site['devices']);
    $inventory=[];foreach(icct_nms_inventory() as $host)$inventory[(int)$host['id']]=$host;
    foreach($devices as &$device){$device['fault_counts']=array_fill_keys(['Critical','Major','Minor','Warning','Information'],0);foreach(icct_nms_fault_observations($inventory[$device['id']]) as $fault)if(isset($device['fault_counts'][$fault['state']]))$device['fault_counts'][$fault['state']]++;$device['fault_count']=array_sum($device['fault_counts']);}unset($device);
    $index=[];foreach($devices as $d)foreach([$d['name'],$d['address']] as $value)$index[strtolower($value)][]=$d['id'];
    $links=[];$discoveryHosts=array_column(icct_backend_nd_hosts(),null,'id');
    foreach(array_column($devices,'id') as $id){
        $rows=db_fetch_assoc_prepared("SELECT data_json,protocol,status,succeeded_at,config_hash FROM plugin_icct_nms_discovery_snapshots WHERE host_id=? AND protocol IN ('cdp','lldp') AND status='success'",[$id]);
        foreach($rows as $row)foreach((icct_nms_discovery_current($row,$discoveryHosts[$id]??null)?(json_decode($row['data_json'],true)['neighbors'] ?? []):[]) as $neighbor){
            if(isset($neighbor['present']) && !$neighbor['present'])continue;
            $candidates=[];foreach(array_merge([$neighbor['peer_label']??'', $neighbor['remote_name']??''],$neighbor['management_addresses']??[]) as $value)if(is_string($value))$candidates=array_merge($candidates,$index[strtolower($value)]??[]);
            $candidates=array_values(array_unique(array_diff($candidates,[$id])));if(count($candidates)!==1)continue;
            $pair=[$id,$candidates[0]];sort($pair);$links[implode('-',$pair)]=['source'=>$pair[0],'target'=>$pair[1],'protocol'=>$row['protocol']];
        }
    }
    $profiles=icct_nms_connections();$allowed=array_column($devices,null,'id');
    foreach(icct_nms_manual_links() as $key=>$link)if(isset($allowed[$link['source']],$allowed[$link['target']])){$pair=[$link['source'],$link['target']];sort($pair);$links[implode('-',$pair)]=['source'=>$link['source'],'target'=>$link['target'],'protocol'=>'manual','color'=>$profiles[$link['profile']]['color']??'#555','label'=>($profiles[$link['profile']]['name']??'Manual').' · '.($link['source_port']?:'?').' / '.($link['target_port']?:'?')];}
    $layout=icct_nms_topology_layout();
    return ['devices'=>$devices,'links'=>array_values($links),'layout'=>$layout,'revision'=>hash('sha256',json_encode($layout)),'management'=>is_realm_allowed(3)];
}
function icct_nms_topology_save($positions,$revision){
    icct_backend_require_management(3);
    if(!is_array($positions)||count($positions)>5000)throw new InvalidArgumentException('Invalid topology layout.');
    $clean=[];foreach($positions as $id=>$point){$id=icct_backend_topology_integer($id,1,2147483647,'Device ID');icct_backend_require_device_access($id);
        if(!is_array($point)||count($point)!==2)throw new InvalidArgumentException('Invalid device position.');
        foreach($point as $v)if(!is_numeric($v)||!is_finite((float)$v)||$v<0||$v>1)throw new InvalidArgumentException('Position must be inside the topology canvas.');
        $clean[$id]=array_map('floatval',array_values($point));
    }
    $lock='icct_topology_layout';if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,10)',[$lock])!==1)throw new RuntimeException('Layout is busy. Retry shortly.');
    try{$layout=icct_nms_topology_layout();if(!hash_equals(hash('sha256',json_encode($layout)),(string)$revision))throw new InvalidArgumentException('Layout changed in another page. Reload before saving.');
        foreach($clean as $id=>$point)$layout[$id]=$point;
        icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['topology_layout',json_encode($layout,JSON_THROW_ON_ERROR)]);
    }finally{db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}
