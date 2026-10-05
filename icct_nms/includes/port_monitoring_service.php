<?php
/** Device-owned alarm settings bound to a real interface index and name. */
function icct_nms_port_monitoring($id) {
    $raw=db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['port_monitoring_'.$id]);
    $settings=json_decode((string)$raw,true);
    return is_array($settings)&&is_bool($settings['enabled']??null)&&is_array($settings['ports']??null)?$settings:['enabled'=>true,'ports'=>[]];
}
function icct_nms_port_monitor_validate($settings,$known) {
    if(!is_array($settings)||!is_bool($settings['enabled']??null)||!is_array($settings['ports']??null)||count($settings['ports'])>4096)throw new InvalidArgumentException('Invalid port monitoring settings.');
    $out=['enabled'=>$settings['enabled'],'ports'=>[]];
    foreach($settings['ports'] as $key=>$row){
        // Older browser drafts used sparse arrays, which JSON pads with null.
        if($row===null)continue;
        if(!ctype_digit((string)$key)||(int)$key<1||!is_array($row)||($known[$key]??null)!==($row['name']??null))throw new InvalidArgumentException('Port identity changed. Reload the device before saving.');
        foreach(['oper_severity','admin_severity'] as $field)if(!in_array($row[$field]??'',['','Information','Warning','Minor','Major','Critical'],true))throw new InvalidArgumentException('Select a valid port severity.');
        if(!empty($row['alarm'])&&(($row['oper_severity']??'')===''||($row['admin_severity']??'')===''))throw new InvalidArgumentException('Select both down-state severities for enabled port alarms.');
        $out['ports'][$key]=['name'=>$row['name'],'alarm'=>!empty($row['alarm']),'cnms'=>!empty($row['cnms']),'oper_severity'=>$row['oper_severity'],'admin_severity'=>$row['admin_severity']];
    }
    return $out;
}
function icct_nms_save_port_monitoring($id,$input) {
    icct_backend_require_management(3);$host=icct_nms_device($id);
    $raw=$input['port_settings']??'';if(!is_string($raw)||strlen($raw)>1000000)throw new InvalidArgumentException('Port settings exceeded limit.');
    $known=array_column(icct_backend_ports_view($host)['ports'],'name','index');
    foreach(icct_nms_port_monitoring($id)['ports'] as $index=>$row)if(!isset($known[$index]))$known[$index]=$row['name'];
    $settings=icct_nms_port_monitor_validate(json_decode($raw,true,512,JSON_THROW_ON_ERROR),$known);
    icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['port_monitoring_'.$id,json_encode($settings,JSON_THROW_ON_ERROR)]);
}
function icct_nms_port_fault_state($port,$rule,$fresh) {
    if(!$fresh)return 'Unknown';
    if(($port['admin']??null)===2)return $rule['admin_severity']?:'Unknown';
    if(($port['oper']??null)===2)return $rule['oper_severity']?:'Unknown';
    if(($port['admin']??null)===1&&($port['oper']??null)===1)return 'Normal';
    return 'Unknown';
}
function icct_nms_port_faults($host) {
    $settings=icct_nms_port_monitoring((int)$host['id']);if(!$settings['enabled'])return [];
    $view=icct_backend_ports_view($host);$ports=array_column($view['ports'],null,'index');$out=[];
    foreach($settings['ports'] as $index=>$rule){
        if(empty($rule['alarm']))continue;$port=$ports[$index]??[];
        $fresh=$view['fresh']&&($port['name']??'')===$rule['name'];
        $out[]=['rule'=>'port:'.$index,'graph_id'=>0,'graph'=>$rule['name'].' link status','value'=>$fresh?$port['oper']:null,'sample_time'=>$fresh?$view['collected']:0,'state'=>icct_nms_port_fault_state($port,$rule,$fresh)];
    }
    return $out;
}
