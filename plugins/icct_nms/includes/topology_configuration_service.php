<?php
/** Manual connections are plugin metadata; discovery remains owned by the collectors. */
function icct_nms_manual_links() {
    $json=db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['topology_manual_links']);
    return $json ? json_decode($json,true,512,JSON_THROW_ON_ERROR) : [];
}
function icct_nms_connection_save($input) {
    icct_backend_require_management(3);
    $source=icct_backend_topology_integer($input['source']??'',1,16777215,'Source device');
    $target=icct_backend_topology_integer($input['target']??'',1,16777215,'Target device');
    if($source===$target)throw new InvalidArgumentException('Select two different devices.');
    foreach([$source,$target] as $id){icct_backend_require_device_access($id);if(!db_fetch_cell_prepared("SELECT id FROM host WHERE id=? AND deleted=''",[$id]))throw new InvalidArgumentException('Device no longer exists.');}
    $profiles=icct_nms_connections();$profile=$input['profile']??'';
    if(!isset($profiles[$profile]))throw new InvalidArgumentException('Select a network connection preset.');
    $ports=[];foreach(['source_port','target_port'] as $key){$v=$input[$key]??'';if(!is_string($v)||strlen($v)>100||preg_match('/[\x00-\x1f\x7f]/',$v))throw new InvalidArgumentException('Port names must be at most 100 characters.');$ports[$key]=trim($v);}
    $lock='icct_topology_connections';if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,10)',[$lock])!==1)throw new RuntimeException('Connections are busy. Retry shortly.');
    try{$links=icct_nms_manual_links();$key=$input['link_id']??'';
        if($key!==''&&!isset($links[$key]))throw new InvalidArgumentException('Connection changed. Reload before saving.');
        if($key!==''){icct_backend_require_device_access($links[$key]['source']);icct_backend_require_device_access($links[$key]['target']);}
        foreach($links as $other=>$link)if($other!==$key&&(($source===$link['source']&&$target===$link['target']&&$ports['source_port']===$link['source_port']&&$ports['target_port']===$link['target_port'])||($source===$link['target']&&$target===$link['source']&&$ports['source_port']===$link['target_port']&&$ports['target_port']===$link['source_port'])))throw new InvalidArgumentException('This connection already exists.');
        if($key==='')$key=bin2hex(random_bytes(12));
        if(count($links)>=5000&&!isset($links[$key]))throw new InvalidArgumentException('Connection limit reached.');
        $links[$key]=['source'=>$source,'target'=>$target,'profile'=>$profile]+$ports+['updated_by'=>icct_backend_current_user_id(),'updated_at'=>date('Y-m-d H:i:s')];
        icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['topology_manual_links',json_encode($links,JSON_THROW_ON_ERROR)]);
    }finally{db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function icct_nms_connection_delete($key){
    icct_backend_require_management(3);
    $lock='icct_topology_connections';if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,10)',[$lock])!==1)throw new RuntimeException('Connections are busy.');
    try{$links=icct_nms_manual_links();if(!is_string($key)||!isset($links[$key]))throw new InvalidArgumentException('Connection no longer exists.');foreach(['source','target'] as $end)icct_backend_require_device_access($links[$key][$end]);unset($links[$key]);icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['topology_manual_links',json_encode($links,JSON_THROW_ON_ERROR)]);}finally{db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function icct_nms_discovery_current($snapshot,$host){
    return $host && !empty($host['enabled']) && !empty($host['collection_enabled']) && $snapshot['status']==='success' && in_array($snapshot['protocol'],icct_backend_nd_host_methods($host),true) && hash_equals(icct_backend_nd_hash($host),(string)($snapshot['config_hash']??'')) && strtotime($snapshot['succeeded_at']??'')>=time()-(int)$host['stale_seconds'];
}
function icct_nms_topology_discovery_rows($devices){
    $rows=[];$hosts=array_column(icct_backend_nd_hosts(),null,'id');
    foreach($devices as $device){
        $host=$hosts[$device['id']]??null;
        if(!$host || empty($host['enabled']) || empty($host['collection_enabled'])) continue;
        $selectedMethods=icct_backend_nd_host_methods($host);
        foreach(db_fetch_assoc_prepared('SELECT protocol,status,succeeded_at,attempted_at,config_hash,data_json,error FROM plugin_icct_nms_discovery_snapshots WHERE host_id=? AND protocol IN (?,?,?,?)',[$device['id'],'lldp','cdp','arp','fdb']) as $snapshot){
            if(!in_array($snapshot['protocol'],$selectedMethods,true)) continue;
            $data=json_decode($snapshot['data_json'],true)??[];
            $items=array_merge($data['neighbors']??[],$data['endpoints']??[]);
            $fresh=icct_nms_discovery_current($snapshot,$hosts[$device['id']]??null);
            foreach($items as $item){if(isset($item['present'])&&!$item['present'])continue;$rows[]=['name'=>$item['remote_name']??($item['peer_label']??($item['ip']??($item['mac']??'Unknown'))),'address'=>implode(', ',$item['management_addresses']??(isset($item['ip'])?[$item['ip']]:[])),'reporter'=>$device['description'],'protocol'=>strtoupper($snapshot['protocol']),'port'=>$item['local_port']??($item['interface']??''),'remote_port'=>$item['remote_port']??'','status'=>$fresh?'Current':'Stale','last_check'=>$snapshot['succeeded_at']??'','detail'=>in_array($snapshot['protocol'],['lldp','cdp'])?'Neighbour adjacency':($snapshot['protocol']==='arp'?'IP neighbour observation':'Learned MAC; direct link unconfirmed')];}
        }
    }
    return $rows;
}

/** Use the same selected methods, hashes and freshness rules as topology links. */
function icct_nms_device_discovery_summary($id,$host=null){
    if($host===null){$hosts=array_column(icct_backend_nd_hosts(true),null,'id');$host=$hosts[$id]??null;}
    if(!$host)return [];
    $active=($host['disabled']??'')===''&&!empty($host['enabled'])&&!empty($host['collection_enabled']);
    $selected=icct_backend_nd_host_methods($host);$configured=icct_backend_nd_host_methods($host,false);
    $snapshots=array_column(db_fetch_assoc_prepared('SELECT protocol,status,succeeded_at,config_hash,data_json FROM plugin_icct_nms_discovery_snapshots WHERE host_id=?',[$id]),null,'protocol');
    $rows=[];
    foreach($configured as $method){
        $snapshot=$snapshots[$method]??null;$enabled=$active&&in_array($method,$selected,true);
        $current=$enabled&&$snapshot&&icct_nms_discovery_current($snapshot,$host);
        $status=!$enabled?'Disabled':(!$snapshot?'Not collected':($current?'Current':($snapshot['status']==='failed'?'Failed':'Stale')));
        $data=$current?(json_decode($snapshot['data_json'],true)??[]):[];
        $items=array_filter(array_merge($data['neighbors']??[],$data['endpoints']??[]),fn($item)=>!isset($item['present'])||$item['present']);
        $rows[]=['method'=>$method,'label'=>icct_backend_nd_method_labels()[$method], 'status'=>$status,'count'=>$current?count($items):null,'last_check'=>$snapshot['succeeded_at']??'', 'evidence'=>in_array($method,['lldp','cdp'],true)?'Layer 2 neighbour adjacency':($method==='arp'?'IPv4 / IPv6 neighbour observation':'Learned MAC observation')];
    }
    return $rows;
}
