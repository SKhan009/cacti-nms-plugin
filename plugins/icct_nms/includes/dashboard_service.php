<?php
/** Personal widget layouts; only known widgets, at most five dashboards. */
function icct_nms_dashboard_validate($value) {
    if(!is_array($value)||!isset($value['dashboards'],$value['selected'])||!is_array($value['dashboards'])||count($value['dashboards'])<1||count($value['dashboards'])>5)throw new InvalidArgumentException('Choose one to five dashboards.');
    $clean=['selected'=>(int)$value['selected'],'dashboards'=>[]];
    foreach(array_values($value['dashboards']) as $dashboard){
        if(!is_array($dashboard)||!isset($dashboard['widgets'])||!is_array($dashboard['widgets'])||count($dashboard['widgets'])>5)throw new InvalidArgumentException('Invalid dashboard widgets.');
        $widgets=array_values($dashboard['widgets']);
        foreach($widgets as $widget)if(!is_string($widget))throw new InvalidArgumentException('Invalid widget.');
        if(count(array_unique($widgets))!==count($widgets)||array_diff($widgets,['topology','birds','alarms','ack','escalation']))throw new InvalidArgumentException('Unknown or duplicate widget.');
        $clean['dashboards'][]=['widgets'=>$widgets];
    }
    if($clean['selected']<0||$clean['selected']>=count($clean['dashboards']))throw new InvalidArgumentException('Dashboard unavailable.');
    return $clean;
}
function icct_nms_dashboard_preferences() {
    $raw=db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['dashboard_user_'.icct_backend_current_user_id()]);
    try{if($raw)return icct_nms_dashboard_validate(json_decode($raw,true,512,JSON_THROW_ON_ERROR));}catch(Throwable $e){}
    return ['selected'=>0,'dashboards'=>[['widgets'=>['topology','birds','alarms','ack','escalation']]]];
}
function icct_nms_dashboard_save($value) {
    $clean=icct_nms_dashboard_validate($value);
    icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['dashboard_user_'.icct_backend_current_user_id(),json_encode($clean,JSON_THROW_ON_ERROR)]);
    return $clean;
}
function icct_nms_dashboard_readings($mapData) {
    $severities=array_fill_keys(['Critical','Major','Minor','Warning','Information'],0);$segments=[];
    $devices=$mapData['unlocated'];$nodes=[];
    foreach($mapData['sites'] as $site){$devices=array_merge($devices,$site['devices']);$summary=icct_nms_map_node_summary($site);$nodes[]=['id'=>$site['id'],'name'=>$site['name'],'coordinates'=>$site['coordinates'],'status'=>$summary['status']];}
    foreach($devices as $device){$total=0;foreach($device['fault_counts']??[] as $key=>$count){if(isset($severities[$key])){$severities[$key]+=(int)$count;$total+=(int)$count;}}$segment=trim($device['category']??'')?:'Unassigned';$segments[$segment]=($segments[$segment]??0)+$total;}
    arsort($segments);
    $total=array_sum($severities);
    $unknown=$total>0?null:0;
    return ['ack'=>['Ack'=>$unknown,'Not_Ack'=>$unknown],'escalation'=>['Esc'=>$unknown,'Not_Esc'=>$unknown],'nodes'=>$nodes,'severity'=>$severities,'segments'=>$segments,'total'=>array_sum($severities),'updated'=>date(DATE_ATOM)];
}
