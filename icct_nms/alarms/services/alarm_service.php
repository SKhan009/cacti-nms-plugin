<?php
/** Device-linked alarm journal. Pollers own observations; operators own workflow actions. */
function icct_nms_alarm_transition($previous,$observation) {
    $severity=$observation['state'];
    $active=in_array($severity,['Critical','Major','Minor','Warning','Information'],true);
    if(!$active&&!in_array($severity,['Normal','Disabled'],true))return null;
    if(!$previous&&!$active)return null;
    $state=$active?'Active':($severity==='Disabled'?'Disabled':'Resolved');
    $raised=$active&&(!$previous||in_array($previous['state'],['Resolved','Disabled'],true));
    // A manually closed alarm stays closed until an observed recovery and recurrence.
    if($previous&&$previous['state']==='Closed'&&$active)$state='Closed';
    return ['state'=>$state,'severity'=>$active?$severity:$previous['severity'],'raised'=>$raised,'occurrences'=>(int)($previous['occurrences']??0)+($raised?1:0),'event'=>$raised?'Raised':($state!==$previous['state']?$state:($active&&$previous['severity']!==$severity?'Severity changed':''))];
}
function icct_nms_alarm_key($host,$rule,$observation) {
    return hash('sha256',json_encode([(int)$host['id'],(string)($host['hostname']??''),(string)($rule['preset_key']??''),$rule['source']??'rrd',$rule['name']??$observation['graph'],$rule['template_id']??0,$rule['metric_id']??0,$rule['oid']??'',(string)$observation['graph_id'],$rule['condition']??'', $rule['minimum']??null,$rule['maximum']??null],JSON_THROW_ON_ERROR));
}
function icct_nms_alarm_history_write($key,$event,$actor=0,$note='') {
    icct_backend_category_execute('INSERT INTO plugin_icct_nms_alarm_history(alarm_key,event,actor_id,note,created_at) VALUES(?,?,?,?,NOW())',[$key,$event,$actor,$note]);
}
function icct_nms_alarm_record($host,$rule,$observation) {
    $key=icct_nms_alarm_key($host,$rule,$observation);$lock='icct_alarm_'.substr($key,0,40);
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,0)',[$lock])!==1)return;
    try {
        icct_backend_category_execute('START TRANSACTION',[]);
        $previous=db_fetch_row_prepared('SELECT * FROM plugin_icct_nms_alarms WHERE alarm_key=? FOR UPDATE',[$key]);
        $sample=(int)($observation['sample_time']??0);
        $change=$previous&&$sample>0&&$sample<(int)$previous['sample_time']?null:icct_nms_alarm_transition($previous,$observation);
        if($change){
            $name=mb_substr(trim($rule['name']??'')?:$observation['graph'],0,160);$source=str_starts_with((string)$observation['rule'],'port:')?'Port':(($rule['source']??'rrd')==='snmp'?'SNMP':(($rule['source']??'')==='availability'?'Availability':'Cacti'));
            $description=$rule['description']??'';$fix=$rule['corrective_action']??'';
            if(!$previous){
                icct_backend_category_execute('INSERT INTO plugin_icct_nms_alarms(alarm_key,host_id,source,graph_id,name,description,fix,severity,state,first_seen,last_seen,sample_time) VALUES(?,?,?,?,?,?,?,?,?,NOW(),NOW(),?)',[$key,(int)$host['id'],$source,(int)$observation['graph_id'],$name,$description,$fix,$change['severity'],$change['state'],(int)($observation['sample_time']??0)]);
            }else{
                icct_backend_category_execute('UPDATE plugin_icct_nms_alarms SET graph_id=?,name=?,description=?,fix=?,severity=?,state=?,last_seen=NOW(),sample_time=?,occurrences=?,acknowledged=?,escalated=?,ticket_state=?,revision=revision+1 WHERE alarm_key=?',[(int)$observation['graph_id'],$name,$description,$fix,$change['severity'],$change['state'],(int)($observation['sample_time']??0),$change['occurrences'],$change['raised']?0:(int)$previous['acknowledged'],$change['raised']?0:(int)$previous['escalated'],$change['raised']?'':$previous['ticket_state'],$key]);
            }
            if($change['event'])icct_nms_alarm_history_write($key,$change['event'],0,$change['severity']);
        }
        icct_backend_category_execute('COMMIT',[]);
    }catch(Throwable $e){icct_backend_category_execute('ROLLBACK',[]);throw $e;}
    finally{db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function icct_nms_collect_alarms() {
    foreach(db_fetch_assoc_prepared("SELECT * FROM host WHERE deleted='' AND poller_id=?",[icct_backend_inventory_collector_id()]) as $host){
        $rules=icct_nms_fault_rules((int)$host['id']);$keys=[];
        $stamp=strtotime((string)($host['last_updated']??''));$fresh=$stamp&&time()-$stamp>=0&&time()-$stamp<=2*max(60,(int)read_config_option('poller_interval'));
        $reachability=['rule'=>'availability','graph_id'=>0,'graph'=>'Host Unreachable / Poller Timeout','sample_time'=>$fresh?$stamp:0,'state'=>$host['disabled']!==''?'Disabled':($fresh?([1=>'Major',2=>'Normal'][(int)$host['status']]??'Unknown'):'Unknown')];
        $reachRule=['source'=>'availability','name'=>'Host Unreachable / Poller Timeout','description'=>'Cacti reports this device as unreachable during its configured availability checks.','corrective_action'=>'Check host reachability, saved availability settings, credentials, and poller timeout/retry settings.'];
        $keys[]=icct_nms_alarm_key($host,$reachRule,$reachability);icct_nms_alarm_record($host,$reachRule,$reachability);
        foreach(icct_nms_fault_observations($host,true) as $observation){
            $rule=$rules[$observation['rule']]??[];
            if(str_starts_with((string)$observation['rule'],'port:'))$rule=['name'=>$observation['graph'],'source'=>'port'];
            $keys[]=icct_nms_alarm_key($host,$rule,$observation);
            icct_nms_alarm_record($host,$rule,$observation);
        }
        $snapshot=json_decode((string)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['fault_status_'.$host['id']]),true);
        $current=!$rules||($snapshot['rules_hash']??'')===hash('sha256',json_encode($rules,JSON_THROW_ON_ERROR))&&time()-(int)($snapshot['time']??0)>=0&&time()-(int)($snapshot['time']??0)<=2*max(60,(int)read_config_option('poller_interval'));
        if($current||$host['disabled']!==''){
            foreach(db_fetch_assoc_prepared("SELECT * FROM plugin_icct_nms_alarms WHERE host_id=? AND state IN ('Active','Closed')",[(int)$host['id']]) as $saved){
                if(in_array($saved['alarm_key'],$keys,true)&&$host['disabled']==='')continue;
                icct_backend_category_execute("UPDATE plugin_icct_nms_alarms SET state='Disabled',revision=revision+1 WHERE alarm_key=? AND state IN ('Active','Closed')",[$saved['alarm_key']]);
                icct_nms_alarm_history_write($saved['alarm_key'],'Disabled',0,'Device disabled or rule removed/changed.');
            }
        }
    }
}
function icct_nms_alarm_rows() {
    $devices=array_column(icct_nms_inventory(),null,'id');if(!$devices)return [];
    $ids=array_map('intval',array_keys($devices));
    $rows=db_fetch_assoc('SELECT *,UNIX_TIMESTAMP(first_seen) AS occurred_epoch,UNIX_TIMESTAMP(last_seen) AS observed_epoch FROM plugin_icct_nms_alarms WHERE host_id IN ('.implode(',',$ids).') ORDER BY first_seen DESC,alarm_key');
    $graphs=[];
    $rows=array_values(array_filter($rows,static function($row)use(&$graphs){if(!(int)$row['graph_id'])return true;$id=(int)$row['host_id'];if(!isset($graphs[$id]))$graphs[$id]=array_map('intval',array_column(icct_nms_device_graphs($id),'local_graph_id'));return in_array((int)$row['graph_id'],$graphs[$id],true);}));
    foreach($rows as &$row){$host=$devices[$row['host_id']];$row['device_name']=$host['description'];$row['short_name']=$host['short_name']??'';$row['address']=$host['hostname'];$row['network_type']=$host['device_type']??'';}unset($row);
    return $rows;
}
function icct_nms_alarm_require_access($row) {
    icct_backend_require_device_access((int)$row['host_id']);
    if((int)($row['graph_id']??0)&&!in_array((int)$row['graph_id'],array_map('intval',array_column(icct_nms_device_graphs((int)$row['host_id']),'local_graph_id')),true))throw new RuntimeException('Alarm unavailable.');
}
function icct_nms_alarm_action($input) {
    icct_backend_require_management(3);
    $key=$input['alarm_key']??'';$action=$input['alarm_action']??'';
    if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key)||!in_array($action,['ack','escalate','close','ticket_close','ticket_reopen'],true))throw new InvalidArgumentException('Choose a valid alarm action.');
    $rawNote=$input['note']??'';if(!is_string($rawNote))throw new InvalidArgumentException('Enter a text note.');$note=trim($rawNote);if(strlen($note)>1000)throw new InvalidArgumentException('Use no more than 1000 characters.');
    icct_backend_category_execute('START TRANSACTION',[]);
    try{
        $row=db_fetch_row_prepared('SELECT * FROM plugin_icct_nms_alarms WHERE alarm_key=? FOR UPDATE',[$key]);
        if(!$row)throw new RuntimeException('Alarm unavailable.');icct_nms_alarm_require_access($row);
        if((int)($input['revision']??0)!==(int)$row['revision'])throw new RuntimeException('Alarm changed. Refresh before retrying.');
        if($action==='ack'){if($row['acknowledged']||$row['state']==='Closed')throw new RuntimeException('This alarm cannot be acknowledged.');$sql='acknowledged=1';$event='Acknowledged';}
        elseif($action==='escalate'){if(!$row['acknowledged']||$row['escalated']||$row['state']==='Closed')throw new RuntimeException('Acknowledge this alarm before escalation.');$sql='escalated=1';$event='Escalated';}
        elseif($action==='close'){$sql="state='Closed'";$event='Closed';if($row['state']==='Closed')throw new RuntimeException('Alarm already closed.');}
        elseif($action==='ticket_close'){$sql="ticket_state='Closed'";$event='Ticket closed';}
        else{$sql="ticket_state='Open'";$event='Ticket reopened';}
        icct_backend_category_execute('UPDATE plugin_icct_nms_alarms SET '.$sql.',revision=revision+1 WHERE alarm_key=?',[$key]);
        icct_nms_alarm_history_write($key,$event,icct_backend_current_user_id(),$note);
        icct_backend_category_execute('COMMIT',[]);
    }catch(Throwable $e){icct_backend_category_execute('ROLLBACK',[]);throw $e;}
}
