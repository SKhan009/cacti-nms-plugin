<?php
require_once __DIR__.'/audit.php';
/** Native Cacti network adapter; no duplicate network table and no core source changes. */

/** Return only network configuration fields, excluding runtime counters and status. */
function nms_workspace_network_fields()
{
    return ['name','poller_id','site_id','subnet_range','snmp_id','enabled','add_to_cacti','sched_type','start_at','recur_every','day_of_week','month','day_of_month','monthly_week','monthly_day','threads','run_limit'];
}

/** Optimistic form revision excludes native scan progress so scans do not invalidate editing. */
function nms_workspace_network_revision($network)
{
    $data=[];foreach(nms_workspace_network_fields() as $field)$data[$field]=(string)($network[$field] ?? '');
    return hash('sha256',json_encode($data));
}

/** Validate integer form values without accepting arrays, fractions or exponent notation. */
function nms_workspace_integer($value,$min,$max,$label)
{
    if(!is_scalar($value) || !preg_match('/^[0-9]+$/D',(string)$value) || strlen((string)$value)>10 || (float)$value<$min || (float)$value>$max) throw new InvalidArgumentException('Invalid '.$label.'.');
    return (int)$value;
}

/** Validate the editor's native settings; native range counting is injected for unit tests. */
function nms_workspace_network_validate($input,$count)
{
    $save=[];
    foreach(['name'=>128,'subnet_range'=>1024] as $field=>$max) {
        $value=$input[$field] ?? null;
        if(!is_string($value) || trim($value)==='' || strlen($value)>$max || preg_match('/[\x00-\x1f]/',$value))throw new InvalidArgumentException('Enter a valid '.$field.'.');
        $save[$field]=trim($value);
    }
    foreach(['poller_id'=>[1,2147483647],'site_id'=>[0,2147483647],'snmp_id'=>[0,2147483647],'sched_type'=>[1,5],'recur_every'=>[1,7],'threads'=>[1,50],'run_limit'=>[60,86400]] as $field=>$bounds) $save[$field]=nms_workspace_integer($input[$field] ?? '',$bounds[0],$bounds[1],$field);
    foreach(['enabled','add_to_cacti'] as $field) {
        if(isset($input[$field]) && $input[$field]!=='on')throw new InvalidArgumentException('Invalid '.$field.'.');
        $save[$field]=isset($input[$field])?'on':'';
    }
    $save['total_ips']=0;
    foreach(explode(',',$save['subnet_range']) as $range) {
        $total=$count(trim($range));
        if($total===false || $total<1 || $save['total_ips']+$total>4294967295)throw new InvalidArgumentException('Invalid or excessive native network range.');
        $save['total_ips']+=(int)$total;
    }
    $start=$input['start_at'] ?? '';
    $date=is_string($start)?DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$start):false;
    if(!$date || $date->format('Y-m-d H:i:s')!==$start)throw new InvalidArgumentException('Starting date/time must be YYYY-MM-DD HH:MM:SS.');
    $save['start_at']=$start;
    foreach(['day_of_week'=>7,'month'=>12,'day_of_month'=>32,'monthly_week'=>32,'monthly_day'=>7] as $field=>$max) {
        $value=$input[$field] ?? '';
        if(!is_string($value) || strlen($value)>100)throw new InvalidArgumentException('Invalid schedule list.');
        $values=[];
        if(trim($value)!=='')foreach(explode(',',$value) as $part) {
            $v=nms_workspace_integer(trim($part),1,$max,$field);
            if($field==='monthly_week' && !in_array($v,[1,2,3,32],true))throw new InvalidArgumentException('Monthly week must be 1, 2, 3 or 32 (last).');
            $values[$v]=$v;
        }
        sort($values);$save[$field]=implode(',',$values);
    }
    $needed=[3=>['day_of_week'],4=>['month','day_of_month'],5=>['month','monthly_week','monthly_day']][$save['sched_type']] ?? [];
    foreach($needed as $field)if($save[$field]==='')throw new InvalidArgumentException('This schedule requires '.$field.'.');
    return $save;
}

/** Save through the same Cacti sql_save API as api_networks_save; preserve advanced settings. */
function nms_workspace_network_save($input)
{
    global $config;
    nms_require_management(23);
    require_once $config['base_path'].'/lib/api_automation.php';
    $id=nms_workspace_integer($input['network_id'] ?? 0,0,2147483647,'network ID');
    $lock='nms_network_edit_'.$id;
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,2)',[$lock])!==1)throw new RuntimeException('Network settings are busy.');
    try {
        $old=$id?db_fetch_row_prepared('SELECT * FROM automation_networks WHERE id=?',[$id]):[];
        if($id && !$old)throw new InvalidArgumentException('Network no longer exists.');
        if($id && (!is_string($input['revision'] ?? null) || !hash_equals(nms_workspace_network_revision($old),$input['revision'])))throw new RuntimeException('Network settings changed. Reload and review before saving.');
        if($id && db_fetch_cell_prepared("SELECT COUNT(*) FROM automation_processes WHERE network_id=? AND status<>'done'",[$id]))throw new RuntimeException('Wait for the current native scan to finish before changing its settings.');
        $save=nms_workspace_network_validate($input,'automation_calculate_total_ips');
        if(!db_fetch_cell_prepared('SELECT id FROM poller WHERE id=?',[$save['poller_id']]))throw new InvalidArgumentException('Collector not found.');
        if($save['site_id'] && !db_fetch_cell_prepared('SELECT id FROM sites WHERE id=?',[$save['site_id']]))throw new InvalidArgumentException('Site not found.');
        if(!db_fetch_cell_prepared('SELECT id FROM automation_snmp WHERE id=?',[$save['snmp_id']]))throw new InvalidArgumentException('Select a native SNMP option set.');
        $save['id']=$id;
        if(!$id) {
            $save+=['ping_method'=>(int)read_config_option('ping_method'),'ping_port'=>(int)read_config_option('ping_port'),'ping_timeout'=>(int)read_config_option('ping_timeout'),'ping_retries'=>(int)read_config_option('ping_retries'),'enable_netbios'=>'','same_sysname'=>'','rerun_data_queries'=>''];
        }
        foreach(['sched_type','start_at','recur_every','day_of_week','month','day_of_month','monthly_week','monthly_day'] as $field) if((string)($old[$field]??'')!==(string)$save[$field]) {$save['next_start']='0000-00-00 00:00:00';break;}
        db_execute('START TRANSACTION');
        try {
            $saved=sql_save($save,'automation_networks');
            if(!$saved)throw new RuntimeException('Cacti could not save the network.');
            $changed=[];foreach(nms_workspace_network_fields() as $field)if((string)($old[$field]??'')!==(string)$save[$field])$changed[]=$field;
            nms_workspace_audit($id?'network_updated':'network_created',['name'=>$save['name'],'changed_fields'=>$changed],0,(int)$saved);
            if(!db_execute('COMMIT'))throw new RuntimeException('Cacti could not commit network settings.');
            return (int)$saved;
        } catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
    } finally {db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}
