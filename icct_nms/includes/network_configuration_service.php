<?php
/** Plugin editor for Cacti's native automation network records. */
function icct_nms_network_options(){
    global $ping_methods;
    return [
        'poller_id'=>array_column(db_fetch_assoc('SELECT id,name FROM poller ORDER BY name'),'name','id'),
        'site_id'=>[0=>'None']+array_column(db_fetch_assoc('SELECT id,name FROM sites ORDER BY name'),'name','id'),
        'snmp_id'=>array_column(db_fetch_assoc('SELECT id,name FROM automation_snmp ORDER BY name'),'name','id'),
        'sched_type'=>[1=>'Manual',2=>'Daily',3=>'Weekly',4=>'Monthly',5=>'Monthly on Day'],
        'threads'=>array_combine([1,2,3,4,5,6,7,8,9,10,20,50],array_map(fn($n)=>$n.' Threads',[1,2,3,4,5,6,7,8,9,10,20,50])),
        'run_limit'=>[60=>'1 Minute',300=>'5 Minutes',600=>'10 Minutes',1200=>'20 Minutes',1800=>'30 Minutes',3600=>'1 Hour',7200=>'2 Hours',14400=>'4 Hours',28800=>'8 Hours'],
        'ping_method'=>$ping_methods+[PING_SNMP=>'SNMP Get',0=>'None'],
        'recur_every'=>array_combine(range(1,7),range(1,7)),
        'day_of_week'=>[1=>'Sunday',2=>'Monday',3=>'Tuesday',4=>'Wednesday',5=>'Thursday',6=>'Friday',7=>'Saturday'],
        'month'=>array_combine(range(1,12),['January','February','March','April','May','June','July','August','September','October','November','December']),
        'day_of_month'=>array_combine(range(1,31),range(1,31))+[32=>'Last'],
        'monthly_week'=>[1=>'First',2=>'Second',3=>'Third',32=>'Last'],
        'monthly_day'=>[1=>'Sunday',2=>'Monday',3=>'Tuesday',4=>'Wednesday',5=>'Thursday',6=>'Friday',7=>'Saturday'],
    ];
}
function icct_nms_network_defaults($options){
    $defaults=['id'=>0,'name'=>'','subnet_range'=>'','dns_servers'=>'','sched_type'=>1,'threads'=>1,'run_limit'=>1200,'start_at'=>date('Y-m-d H:i:s'),'recur_every'=>1,'snmp_id'=>array_key_first($options['snmp_id'])??0];
    foreach(['poller_id'=>'default_poller','site_id'=>'default_site','ping_method'=>'ping_method','ping_port'=>'ping_port','ping_timeout'=>'ping_timeout','ping_retries'=>'ping_retries'] as $key=>$setting)$defaults[$key]=read_config_option($setting);
    if(!isset($options['poller_id'][$defaults['poller_id']]))$defaults['poller_id']=array_key_first($options['poller_id']);
    if(!isset($options['site_id'][$defaults['site_id']]))$defaults['site_id']=0;
    return $defaults;
}
function icct_nms_network_get($id){
    $id=icct_backend_topology_integer($id,1,16777215,'Network ID');
    $row=db_fetch_row_prepared('SELECT * FROM automation_networks WHERE id=?',[$id]);
    if(!$row)throw new InvalidArgumentException('Network no longer exists.');return $row;
}
function icct_nms_network_save($input){
    icct_backend_require_management(23);
    global $config;
    require_once $config['base_path'].'/lib/api_automation.php';
    $options=icct_nms_network_options();
    $id=icct_backend_topology_integer($input['network_id']??0,0,16777215,'Network ID');$existing=$id?icct_nms_network_get($id):[];$save=['id'=>$id];
    foreach(['name'=>250,'subnet_range'=>1024,'dns_servers'=>250,'notification_email'=>250,'notification_fromname'=>32,'notification_fromemail'=>128] as $key=>$limit){$v=$input[$key]??'';if(!is_string($v)||strlen($v)>$limit||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$v))throw new InvalidArgumentException('Invalid '.str_replace('_',' ',$key).'.');$save[$key]=trim($v);}
    if($save['name']===''||$save['subnet_range']==='')throw new InvalidArgumentException('Name and subnet range are required.');
    foreach(['poller_id','site_id','snmp_id','sched_type','threads','run_limit','ping_method','recur_every'] as $key){$v=$input[$key]??'';if(!is_scalar($v)||!isset($options[$key][$v]))throw new InvalidArgumentException('Select a valid '.str_replace('_',' ',$key).'.');$save[$key]=(int)$v;}
    foreach(['ping_port'=>[0,65535],'ping_timeout'=>[0,99999],'ping_retries'=>[0,99999]] as $key=>$bounds)$save[$key]=icct_backend_topology_integer($input[$key]??'',...array_merge($bounds,[str_replace('_',' ',$key)]));
    if(in_array($save['ping_method'],[1,2,3,5],true)&&$save['ping_timeout']===0)throw new InvalidArgumentException('Ping timeout must be greater than zero.');
    if(in_array($save['ping_method'],[2,3,5],true)&&$save['ping_port']===0)throw new InvalidArgumentException('TCP/UDP ping requires a port from 1 to 65535.');
    foreach(['enabled','enable_netbios','add_to_cacti','same_sysname','rerun_data_queries','notification_enabled'] as $key)$save[$key]=empty($input[$key])?'':'on';
    foreach(['day_of_week','month','day_of_month','monthly_week','monthly_day'] as $key){$v=$input[$key]??[];if(!is_array($v)||array_filter($v,fn($n)=>!is_scalar($n)||!isset($options[$key][$n])))throw new InvalidArgumentException('Select valid schedule days and months.');$save[$key]=implode(',',array_unique(array_map('intval',$v)));}
    $required=[3=>['day_of_week'],4=>['month','day_of_month'],5=>['month','monthly_week','monthly_day']];
    foreach($required[$save['sched_type']]??[] as $key)if($save[$key]==='')throw new InvalidArgumentException('Select '.str_replace('_',' ',$key).' for this schedule.');
    $start=$input['start_at']??'';if(!is_string($start))throw new InvalidArgumentException('Invalid starting date/time.');$start=str_replace('T',' ',trim($start));if(strlen($start)===16)$start.=':00';
    $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$start);if($start!=='0000-00-00 00:00:00'&&(!$date||$date->format('Y-m-d H:i:s')!==$start))throw new InvalidArgumentException('Enter a valid starting date/time.');
    // Preserve Cacti's existing zero start sentinel on an unchanged legacy schedule.
    if($start==='0000-00-00 00:00:00'&&$save['sched_type']!==1&&(($existing['start_at']??'')!==$start||(int)($existing['sched_type']??0)!==$save['sched_type']))throw new InvalidArgumentException('Select a starting date/time for this schedule.');
    $save['start_at']=$start;
    if(($existing['start_at']??'')!==$start||(int)($existing['sched_type']??0)!==$save['sched_type'])$save['next_start']='0000-00-00 00:00:00';
    if($save['notification_fromemail']!==''&&!filter_var($save['notification_fromemail'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid sender email address.');
    if($save['notification_email']!==''){foreach(preg_split('/[;,\s]+/',$save['notification_email'],-1,PREG_SPLIT_NO_EMPTY) as $email)if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter valid notification email addresses.');}
    if($save['notification_enabled']==='on'&&$save['notification_email']==='')throw new InvalidArgumentException('Notification email is required when notifications are enabled.');
    $save['subnet_range']=implode(',',array_map('trim',preg_split('/[,\r\n]+/',$save['subnet_range'],-1,PREG_SPLIT_NO_EMPTY)));$save['total_ips']=0;
    foreach(explode(',',$save['subnet_range']) as $range){$count=automation_calculate_total_ips($range);if($count===false)throw new InvalidArgumentException('Invalid subnet range: '.$range);$save['total_ips']+=$count;}
    $result=sql_save($save,'automation_networks');if(!$result)throw new RuntimeException('Unable to save network.');return (int)$result;
}
function icct_nms_network_run($id){
    icct_backend_require_management(23);global $config;
    require_once $config['base_path'].'/lib/poller.php';
    $network=icct_nms_network_get($id);if($network['enabled']!=='on')throw new InvalidArgumentException('Enable this network before running discovery.');
    if(db_fetch_cell_prepared('SELECT COUNT(*) FROM automation_processes WHERE network_id=?',[$network['id']]))throw new InvalidArgumentException('Discovery is already running or finishing for this network.');
    if((int)$config['poller_id']===(int)$network['poller_id'])exec_background(read_config_option('path_php_binary'),['-q',read_config_option('path_webroot').'/poller_automation.php','--network='.(int)$network['id'],'--force']);
    else call_remote_data_collector($network['poller_id'],$config['url_path'].'remote_agent.php?action=discover&network='.(int)$network['id'],'AUTOM8');
}
