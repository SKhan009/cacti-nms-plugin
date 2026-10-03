<?php
require_once __DIR__.'/topology_configuration_service.php';
require_once __DIR__.'/connection_service.php';
/** Select one central switch deterministically; shape alone does not imply device role. */
function icct_nms_topology_core_id($devices){
    foreach($devices as $device)if(preg_match('/core.*(?:switch|sw)|(?:switch|sw).*core/i',$device['description']??$device['name']??''))return (int)$device['id'];
    foreach($devices as $device)if(preg_match('/core.*(?:switch|sw)|(?:switch|sw).*core/i',$device['device_type']??''))return (int)$device['id'];
    foreach($devices as $device)if(preg_match('/switch|\bsw\b/i',($device['device_type']??'').' '.($device['description']??$device['name']??'')))return (int)$device['id'];
    return 0;
}
/** Visual positions are independent of rack units and geographic coordinates. */
function icct_nms_topology_layout() {
    $raw=(string)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['topology_layout']);
    return $raw ? json_decode($raw,true,512,JSON_THROW_ON_ERROR) : [];
}
function icct_nms_topology_data($mapData) {
    $devices=$mapData['unlocated'];foreach($mapData['sites'] as $site)$devices=array_merge($devices,$site['devices']);
    $inventory=[];foreach(icct_nms_inventory() as $host)$inventory[(int)$host['id']]=$host;
    foreach($devices as &$device){$device['fault_counts']=array_fill_keys(['Critical','Major','Minor','Warning','Information'],0);foreach(icct_nms_fault_observations($inventory[$device['id']]) as $fault)if(isset($device['fault_counts'][$fault['state']]))$device['fault_counts'][$fault['state']]++;$device['fault_count']=array_sum($device['fault_counts']);}unset($device);
    $types=icct_nms_device_types();
    $index=[];$snapshots=[];$discoveryHosts=array_column(icct_backend_nd_hosts(),null,'id');
    $addIdentity=static function($value,$id) use (&$index) {
        if(is_string($value) && trim($value)!=='') $index[strtolower(trim($value))][]=$id;
    };
    foreach($devices as &$device){
        $host=$inventory[$device['id']];
        $device['diagnostics']=is_realm_allowed(3)?icct_backend_diag_selected_labels($device['id']):[];
        $device['discovery']=icct_nms_device_discovery_summary($device['id'],$discoveryHosts[$device['id']]??null);
        $device['short_name']=trim((string)($host['short_name']??'')) ?: icct_backend_short_name_generate($device['name'],$host['device_type']??'',(int)$device['id']);
        $device['network_asset']=icct_nms_type_icon_asset('device');
        foreach($types as $type) if((int)$type['category_id']===(int)($host['category_id']??0) && $type['name']===($host['device_type']??'')){$device['network_asset']=icct_nms_type_asset($type,'network');break;}
        foreach([$device['name'],$device['address'],$host['snmp_sysName']??''] as $value)$addIdentity($value,$device['id']);
        $rows=db_fetch_assoc_prepared("SELECT data_json,protocol,status,succeeded_at,config_hash FROM plugin_icct_nms_discovery_snapshots WHERE host_id=? AND protocol IN ('cdp','lldp') AND status='success'",[$device['id']]);
        foreach($rows as $row){
            if(!icct_nms_discovery_current($row,$discoveryHosts[$device['id']]??null))continue;
            $payload=json_decode($row['data_json'],true)??[];
            // Index each device's own reported identity before resolving any neighbours.
            foreach([$payload['name']??'',$payload['identity']??''] as $value)$addIdentity($value,$device['id']);
            $snapshots[$device['id']][]=['protocol'=>$row['protocol'],'data'=>$payload];
        }
    }unset($device);
    $links=[];
    foreach($snapshots as $id=>$rows)foreach($rows as $row)foreach($row['data']['neighbors']??[] as $neighbor){
        if(isset($neighbor['present']) && !$neighbor['present'])continue;
        // A unique chassis/device identity is stronger evidence than a shared IP address.
        $candidates=array_values(array_unique(array_diff($index[strtolower(trim($neighbor['peer_key']??''))]??[],[$id])));
        if(count($candidates)>1)continue;
        if(!$candidates){
            $candidates=[];
            foreach(array_merge([$neighbor['peer_label']??'', $neighbor['remote_name']??''],$neighbor['management_addresses']??[]) as $value)if(is_string($value))$candidates=array_merge($candidates,$index[strtolower(trim($value))]??[]);
            $candidates=array_values(array_unique(array_diff($candidates,[$id])));
        }
        if(count($candidates)!==1)continue;
        $pair=[$id,$candidates[0]];sort($pair);$links[implode('-',$pair)]=['source'=>$id,'target'=>$candidates[0],'protocol'=>$row['protocol'],'label'=>strtoupper($row['protocol']).' · '.($neighbor['local_port']??'?').' / '.($neighbor['remote_port']??'?')];
    }
    $profiles=icct_nms_connections();$allowed=array_column($devices,null,'id');
    foreach(icct_nms_manual_links() as $key=>$link)if(isset($allowed[$link['source']],$allowed[$link['target']])){$pair=[$link['source'],$link['target']];sort($pair);$links[implode('-',$pair)]=['source'=>$link['source'],'target'=>$link['target'],'protocol'=>'manual','color'=>$profiles[$link['profile']]['color']??'#555','label'=>($profiles[$link['profile']]['name']??'Manual').' · '.($link['source_port']?:'?').' / '.($link['target_port']?:'?')];}
    $layout=icct_nms_topology_layout();
    return ['core_id'=>icct_nms_topology_core_id(array_values($inventory)),'devices'=>$devices,'links'=>array_values($links),'layout'=>$layout,'revision'=>hash('sha256',json_encode($layout)),'management'=>is_realm_allowed(3)];
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
        $coreId=icct_nms_topology_core_id(icct_nms_inventory());if($coreId)$layout[$coreId]=[0.5,0.5];
        icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['topology_layout',json_encode($layout,JSON_THROW_ON_ERROR)]);
    }finally{db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}
