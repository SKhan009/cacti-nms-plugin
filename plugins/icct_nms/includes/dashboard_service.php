<?php
/** Personal widget layouts; only known widgets, at most five dashboards. */
function icct_nms_dashboard_validate($value) {
    if(!is_array($value)||!isset($value['dashboards'],$value['selected'])||!is_array($value['dashboards'])||count($value['dashboards'])<1||count($value['dashboards'])>5)throw new InvalidArgumentException('Choose one to five dashboards.');
    $clean=['selected'=>(int)$value['selected'],'dashboards'=>[]];
    foreach(array_values($value['dashboards']) as $dashboard){
        if(!is_array($dashboard)||!isset($dashboard['widgets'])||!is_array($dashboard['widgets'])||count($dashboard['widgets'])>6)throw new InvalidArgumentException('Invalid dashboard widgets.');
        $widgets=array_values($dashboard['widgets']);
        foreach($widgets as $widget)if(!is_string($widget))throw new InvalidArgumentException('Invalid widget.');
        if(count(array_unique($widgets))!==count($widgets)||array_diff($widgets,['topology','birds','alarms','ack','escalation','frequent']))throw new InvalidArgumentException('Unknown or duplicate widget.');
        $clean['dashboards'][]=['widgets'=>array_merge(['topology'],array_values(array_diff($widgets,['topology'])))];
    }
    if($clean['selected']<0||$clean['selected']>=count($clean['dashboards']))throw new InvalidArgumentException('Dashboard unavailable.');
    return $clean;
}
function icct_nms_dashboard_preferences() {
    $raw=db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['dashboard_user_'.icct_backend_current_user_id()]);
    try{if($raw)return icct_nms_dashboard_validate(json_decode($raw,true,512,JSON_THROW_ON_ERROR));}catch(Throwable $e){}
    return ['selected'=>0,'dashboards'=>[['widgets'=>['topology','birds','alarms','ack','escalation','frequent']]]];
}
function icct_nms_dashboard_save($value) {
    $clean=icct_nms_dashboard_validate($value);
    icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['dashboard_user_'.icct_backend_current_user_id(),json_encode($clean,JSON_THROW_ON_ERROR)]);
    return $clean;
}
function icct_nms_dashboard_readings($mapData) {
    $severities=array_fill_keys(['Critical','Major','Minor','Warning','Information'],0);$segments=[];
    $devices=$mapData['unlocated'];$nodes=[];$frequent=[];
    foreach($mapData['sites'] as $site){$devices=array_merge($devices,$site['devices']);$summary=icct_nms_map_node_summary($site);$nodes[]=['id'=>$site['id'],'name'=>$site['name'],'coordinates'=>$site['coordinates'],'status'=>$summary['status']];}
    foreach($devices as $device){foreach($device['fault_alarms'] ?? [] as $alarm){$key=json_encode([$alarm['name'],$alarm['severity']]);if(!isset($frequent[$key]))$frequent[$key]=$alarm+['count'=>0];$frequent[$key]['count']++;}$total=0;foreach($device['fault_counts']??[] as $key=>$count){if(isset($severities[$key])){$severities[$key]+=(int)$count;$total+=(int)$count;}}$segment=trim($device['category']??'')?:'Unassigned';$segments[$segment]=($segments[$segment]??0)+$total;}
    arsort($segments);
    $frequent=array_values($frequent);usort($frequent,static fn($a,$b)=>($b['count']<=>$a['count']) ?: strcmp($a['name'],$b['name']) ?: strcmp($a['severity'],$b['severity']));
    $total=array_sum($severities);
    $unknown=$total>0?null:0;
    return ['frequent'=>array_slice($frequent,0,10),'ack'=>['Ack'=>$unknown,'Not_Ack'=>$unknown],'escalation'=>['Esc'=>$unknown,'Not_Esc'=>$unknown],'nodes'=>$nodes,'severity'=>$severities,'segments'=>$segments,'total'=>array_sum($severities),'updated'=>date(DATE_ATOM)];
}

/** Resolve the server by the native poller identity, never an arbitrary demo site. */
function icct_nms_dashboard_server_center($hosts, $poller) {
    $hostname=strtolower(trim($poller['hostname'] ?? ''));
    $matches=array_values(array_filter($hosts,static function($host)use($hostname){
        return $hostname!=='' && (strtolower(trim($host['hostname'] ?? ''))===$hostname || strtolower(trim($host['snmp_sysName'] ?? ''))===$hostname);
    }));
    $center=['name'=>$poller['name'] ?? 'Cacti server','status'=>'Unknown','device_id'=>null,'site_id'=>null,'coordinates'=>null];
    if(count($matches)!==1)return $center;
    $host=$matches[0];
    return array_replace($center,['name'=>$host['description'],'device_id'=>(int)$host['id'],'site_id'=>(int)$host['site_id'],'status'=>['Up'=>'Online','Down'=>'Offline','Disabled'=>'Disabled'][$host['status_label']] ?? 'Unknown']);
}
function icct_nms_dashboard_server() {
    global $config;
    $poller=db_fetch_row_prepared('SELECT name,hostname FROM poller WHERE id=?',[(int)($config['poller_id'] ?? 1)]);
    $center=icct_nms_dashboard_server_center(icct_nms_inventory(),$poller ?: []);
    if($center['site_id']){
        $site=db_fetch_row_prepared('SELECT latitude,longitude FROM sites WHERE id=?',[$center['site_id']]);
        $center['coordinates']=icct_nms_map_coordinates($site['latitude'] ?? null,$site['longitude'] ?? null);
    }
    return $center;
}
