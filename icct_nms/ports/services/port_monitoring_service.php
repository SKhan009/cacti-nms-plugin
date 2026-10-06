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
    if(in_array($port['oper']??null,[2,7],true))return $rule['oper_severity']?:'Unknown';
    if(($port['admin']??null)===1&&($port['oper']??null)===1)return 'Normal';
    return 'Unknown';
}
function icct_nms_port_faults($host) {
    $settings=icct_nms_port_monitoring((int)$host['id']);if(!$settings['enabled'])return [];
    $view=icct_backend_ports_view($host);$ports=array_column($view['ports'],null,'index');$out=[];
    $saved=icct_nms_port_alarm_snapshot((int)$host['id']);
    foreach($settings['ports'] as $index=>$rule){
        if(empty($rule['alarm']))continue;$port=$ports[$index]??[];
        $fresh=$view['fresh']&&($port['name']??'')===$rule['name'];
        $state=icct_nms_port_fault_state($port,$rule,$fresh);
        $previous=$saved['ports'][$index]??[];
        if($state==='Unknown' && ($host['disabled']??'')==='' && ($previous['name']??'')===$rule['name'] && ($saved['endpoint']??'')===icct_nms_port_alarm_endpoint($host) && icct_nms_port_alarm_severity($previous['active']??''))$state=$previous['active'];
        $out[]=['rule'=>'port:'.$index,'graph_id'=>0,'graph'=>$rule['name'].' link status','value'=>$fresh?$port['oper']:null,'sample_time'=>$fresh?$view['collected']:0,'state'=>$state];
    }
    return $out;
}

/** Persisted active state is separate from the last observed state. */
function icct_nms_port_alarm_snapshot($id) {
    $raw=db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['port_alarm_state_'.$id]);
    $value=json_decode((string)$raw,true);
    return is_array($value)?$value:['ports'=>[]];
}
function icct_nms_port_alarm_severity($value) {
    return in_array($value,['Information','Warning','Minor','Major','Critical'],true);
}
function icct_nms_port_alarm_endpoint($host) {
    return hash('sha256',json_encode([(int)$host['id'],(string)($host['hostname']??''),(int)($host['poller_id']??0)]));
}
/** Pure state transition: missing/stale readings never claim an alarm recovered. */
function icct_nms_port_alarm_transition($previous,$state,$name,$contextChanged=false) {
    $before=$previous['active']??'';
    $active=$state==='Unknown'&&!$contextChanged?$before:(icct_nms_port_alarm_severity($state)?$state:'');
    $event='Observed';
    if($contextChanged)$event='Configuration changed';
    elseif($state==='Disabled')$event='Monitoring disabled';
    elseif($state==='Unknown')$event='Observation unavailable';
    elseif($before!==''&&$active==='')$event='Cleared';
    elseif($before===''&&$active!=='')$event='Raised';
    elseif($before!==$active)$event='Severity changed';
    elseif(($previous['state']??'')==='Unknown')$event='Observation restored';
    $changed=!$previous||$contextChanged||($previous['state']??'')!==$state||$before!==$active||($previous['name']??'')!==$name;
    return ['name'=>$name,'state'=>$state,'active'=>$active,'event'=>$event,'changed'=>$changed];
}
/** Poller-owned history write; web views only read saved observations. */
function icct_nms_record_port_alarms($host,$view,$settings) {
    $id=(int)$host['id'];$lock='icct_nms_port_alarm_'.$id;
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,0)',[$lock])!==1)return;
    try {
        icct_backend_category_execute('START TRANSACTION',[]);
        $saved=icct_nms_port_alarm_snapshot($id);$next=['endpoint'=>icct_nms_port_alarm_endpoint($host),'ports'=>[]];
        $ports=array_column($view['ports'],null,'index');
        foreach(array_unique(array_merge(array_keys($settings['ports']),array_keys($saved['ports']??[]))) as $index) {
            $rule=$settings['ports'][$index]??null;$previous=$saved['ports'][$index]??[];
            $enabled=$settings['enabled']&&!empty($rule['alarm'])&&($host['disabled']??'')==='';
            if(!$enabled&&!$previous)continue;
            $name=$rule['name']??$previous['name'];$port=$ports[$index]??[];
            $fresh=$view['fresh']&&($port['name']??'')===$name;
            $state=$enabled?icct_nms_port_fault_state($port,$rule,$fresh):'Disabled';
            $changedContext=$previous&&(($saved['endpoint']??'')!==$next['endpoint']||($previous['name']??'')!==$name);
            $transition=icct_nms_port_alarm_transition($previous,$state,$name,$changedContext);
            $admin=$fresh?($port['admin']??null):null;$oper=$fresh?($port['oper']??null):null;
            if(!$transition['changed'] && (($previous['admin']??null)!==$admin || ($previous['oper']??null)!==$oper)) {
                $transition['changed']=true;$transition['event']='Interface state changed';
            }
            $transition['admin']=$admin;$transition['oper']=$oper;
            if($transition['changed']) {
                icct_backend_category_execute('INSERT INTO plugin_icct_nms_port_alarm_events(host_id,if_index,port_name,event,state_before,state_after,severity_before,severity_after,admin_status,oper_status,collected_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW())',[$id,(int)$index,$name,$transition['event'],$previous['state']??'',$state,$previous['active']??'',$transition['active'],$admin,$oper,$fresh?date('Y-m-d H:i:s',(int)$view['collected']):null]);
            }
            unset($transition['event'],$transition['changed']);$next['ports'][$index]=$transition;
        }
        icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['port_alarm_state_'.$id,json_encode($next,JSON_THROW_ON_ERROR)]);
        icct_backend_category_execute('COMMIT',[]);
    }catch(Throwable $error){icct_backend_category_execute('ROLLBACK',[]);throw $error;}
    finally{db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function icct_backend_collect_port_alarms() {
    $collector=icct_backend_inventory_collector_id();
    $hosts=db_fetch_assoc_prepared("SELECT h.* FROM host h JOIN plugin_icct_nms_meta m ON m.meta_key=CONCAT('port_monitoring_',h.id) WHERE h.deleted='' AND h.poller_id=?",[$collector]);
    foreach($hosts as $host) {
        try {icct_nms_record_port_alarms($host,icct_backend_ports_view($host),icct_nms_port_monitoring((int)$host['id']));}
        catch(Throwable $error){cacti_log('ICCT NMS port alarm history for device '.(int)$host['id'].': '.$error->getMessage(),false,'ICCT NMS');}
    }
}
