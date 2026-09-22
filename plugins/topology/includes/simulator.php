<?php
/** Isolated simulator import workflow. Uploaded samples are never returned as live observations. */
require_once(__DIR__.'/model.php');
require_once(__DIR__.'/records.php');

/** Load the single administrator-owned simulator configuration; no default endpoint or protocol. */
function tp_sim_config() {
    global $config;
    $file=__DIR__.'/../simulator.local.php';
    if(!is_file($file) || is_link($file) || fileowner($file)!==0 || (fileperms($file)&0022)) throw new RuntimeException('Simulator is not configured. Install its administrator-owned simulator.local.php first.');
    $c=require($file);
    foreach(array('data_dir','address','port','poller_id','data_template_id','graph_template_id','interface_query_id','status_file') as $key) if(!isset($c[$key])) throw new RuntimeException('Simulator setting missing: '.$key);
    if($c['address']!=='127.0.0.1') throw new RuntimeException('Phase 1 simulator supports only the explicitly configured local loopback address.');
    foreach(array('port','poller_id','data_template_id','graph_template_id','interface_query_id') as $key) $c[$key]=tp_id($c[$key]);
    if($c['port']>65535 || (int)$config['poller_id']!==$c['poller_id']) throw new RuntimeException('Simulator endpoint or assigned collector is invalid.');
    if(!db_fetch_cell_prepared('SELECT id FROM poller WHERE id=? AND disabled=""',array($c['poller_id']))) throw new RuntimeException('The configured native collector is not enabled.');
    if(!is_dir($c['data_dir']) || is_link($c['data_dir']) || !is_writable($c['data_dir'])) throw new RuntimeException('The configured simulator data directory is unavailable or not writable.');
    if(strpos(realpath($c['data_dir']),realpath($config['base_path']).DIRECTORY_SEPARATOR)===0) throw new RuntimeException('Simulator records must be outside the Cacti web root.');
    foreach(array('snmp_timeout','snmp_retries') as $key) {
        $value=read_config_option($key);
        if(!preg_match('/^[0-9]+$/D',(string)$value) || ($key==='snmp_timeout' && (int)$value===0)) throw new RuntimeException('Configure valid native Cacti '.$key.'.');
        $c[$key]=(int)$value;
    }
    return $c;
}
/** Resolve explicitly selected native Generic OID templates and validate their linkage. */
function tp_sim_templates($c) {
    $base=db_fetch_row_prepared('SELECT * FROM data_template_data WHERE data_template_id=? AND local_data_id=0',array($c['data_template_id']));
    if(!$base) throw new RuntimeException('Configured native base data template is missing.');
    $input=db_fetch_row_prepared('SELECT * FROM data_input WHERE id=?',array($base['data_input_id']));
    if(!$input || (int)$input['type_id']!==DATA_INPUT_TYPE_SNMP) throw new RuntimeException('Base data template must use native SNMP Get.');
    $oid=db_fetch_cell_prepared('SELECT id FROM data_input_fields WHERE data_input_id=? AND data_name="oid" AND input_output="in"',array($input['id']));
    $items=db_fetch_assoc_prepared('SELECT id FROM data_template_rrd WHERE data_template_id=? AND local_data_id=0',array($c['data_template_id']));
    if(!$oid || count($items)!==1) throw new RuntimeException('Base data template must contain an OID input and exactly one data-source item.');
    $graphItems=db_fetch_assoc_prepared('SELECT DISTINCT task_item_id FROM graph_templates_item WHERE graph_template_id=? AND local_graph_id=0 AND task_item_id>0',array($c['graph_template_id']));
    if(count($graphItems)!==1 || (int)$graphItems[0]['task_item_id']!==(int)$items[0]['id']) throw new RuntimeException('Configured graph template must reference the selected base data template.');
    if(!db_fetch_cell_prepared('SELECT id FROM snmp_query WHERE id=?',array($c['interface_query_id']))) throw new RuntimeException('Configured native interface data query is missing.');
    return array('oid_field'=>(int)$oid);
}
/** Record ownership of generated native objects without duplicating their contents. */
function tp_object($import,$type,$id,$oid='') {
    tp_exec('INSERT INTO plugin_topology_objects (import_id,object_type,object_id,oid) VALUES (?,?,?,?)',array($import,$type,$id,$oid));
}
/** Stage a validated upload and queue isolated responder reload; this step creates no Cacti device. */
function tp_sim_stage($name,$unitId,$category,$community,$content) {
    tp_sim_permission();
    $c=tp_sim_config(); tp_sim_templates($c);
    $name=tp_text($name);$unit=tp_unit($unitId);$site=(int)$unit['site_id'];$category=tp_category($category);$community=tp_community($community);
    $records=tp_records($content); $content=tp_record_content($records); $hash=hash('sha256',$content);
    if(db_fetch_cell_prepared('SELECT id FROM plugin_topology_imports WHERE community=? OR digest=?',array($community,$hash))) throw new InvalidArgumentException('This record or community is already imported. Use its existing entry.');
    $path=$c['data_dir'].'/'.$community.'.snmprec';
    if(file_exists($path) || is_link($path)) throw new RuntimeException('A record with this community already exists.');
    $lock='topology_stage';
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,2)',array($lock))!==1) throw new RuntimeException('Another import is in progress. Try again.');
    $temp='';$published=false;
    tp_exec('START TRANSACTION');
    try {
        // Recheck under the lock, before exclusive publication.
        if(file_exists($path) || db_fetch_cell_prepared('SELECT id FROM plugin_topology_imports WHERE community=? OR digest=?',array($community,$hash))) throw new RuntimeException('Record already exists.');
        tp_exec('INSERT INTO plugin_topology_imports (community,name,site_id,category_id,content,digest,state,record_count,metric_count,created_by,created_at,unit_id,auto_provision) VALUES (?,?,?,?,?,?,"pending",?,?,?,NOW(),?,1)',array($community,$name,$site,$category,$content,$hash,count($records),count(array_filter($records,function($r){return $r['metric'];})),(int)$_SESSION['sess_user_id'],$unit['id']));
        $id=(int)db_fetch_cell('SELECT LAST_INSERT_ID()');
        $temp=tempnam($c['data_dir'],'.upload-');
        if(!$temp || file_put_contents($temp,$content,LOCK_EX)!==strlen($content) || !chmod($temp,0640) || !link($temp,$path)) throw new RuntimeException('Could not publish simulator record.');
        $published=true; unlink($temp); $temp='';
        tp_audit('stage_import',$id); tp_exec('COMMIT');
        // If queueing fails the retained pending row can be explicitly reactivated.
        tp_sim_reload($c);
        return $id;
    } catch(Throwable $e) {
        db_execute('ROLLBACK');
        if($temp && is_file($temp)) unlink($temp);
        if($published && !db_fetch_cell_prepared('SELECT id FROM plugin_topology_imports WHERE community=?',array($community))) unlink($path);
        throw $e;
    } finally { db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',array($lock)); }
}
/** Queue the one configured service through a marker; web PHP never runs privileged commands. */
function tp_sim_reload($c) {
    $path=$c['data_dir'].'/.reload.pending';
    if(is_link($path) || file_put_contents($path,(string)time(),LOCK_EX)===false) throw new RuntimeException('Record retained, but activation could not be queued. Check the directory and retry activation.');
}
/** Verify this import's identity via a real SNMP request using only the configured endpoint. */
function tp_sim_probe($row,$c) {
    global $config,$snmp_error;
    require_once($config['base_path'].'/lib/snmp.php');
    $snmp_error='';
    $value=cacti_snmp_get($c['address'],$row['community'],'1.3.6.1.2.1.1.5.0',2,'','','','','','',$c['port'],$c['snmp_timeout'],$c['snmp_retries'],'TOPOLOGY','',SNMP_STRING_OUTPUT_ASCII);
    if($snmp_error!=='' || !is_string($value) || $value==='' || $value==='U' || stripos($value,'No Such')!==false) throw new RuntimeException('Live SNMP identity check failed. Check activation and the simulator service. No device or data sources were created.');
    $expected=tp_records($row['content'])['1.3.6.1.2.1.1.5.0']['value'];
    if(trim($value,'"')!==$expected) throw new RuntimeException('The live sysName does not match this record. Check the endpoint and community.');
    return $value;
}
/** Duplicate native templates for a metric, preserving Cacti's data input and profile. */
function tp_sim_pair($row,$record,$c,$base) {
    $name='Topology Lab '.$row['id'].' '.substr(hash('sha256',$record['oid']),0,10).' - '.$record['label'];
    $dt=(int)api_duplicate_data_source(0,$c['data_template_id'],$name);
    if(!$dt) throw new RuntimeException('Cacti data-template creation failed.');
    tp_object($row['id'],'data_template',$dt,$record['oid']);
    $dtd=(int)db_fetch_cell_prepared('SELECT id FROM data_template_data WHERE data_template_id=? AND local_data_id=0',array($dt));
    $rrd=(int)db_fetch_cell_prepared('SELECT id FROM data_template_rrd WHERE data_template_id=? AND local_data_id=0',array($dt));
    if(!$dtd || !$rrd) throw new RuntimeException('Cacti returned an incomplete data template.');
    $ds='tp_'.substr(hash('sha256',$record['oid']),0,16);
    tp_exec('UPDATE data_template_data SET name=?,active="on" WHERE id=?',array('|host_description| - '.$record['label'],$dtd));
    tp_exec('UPDATE data_template_rrd SET data_source_name=?,data_source_type_id=?,rrd_minimum=?,rrd_maximum="U" WHERE id=?',array($ds,in_array($record['tag'],array('65','70'))?2:1,$record['tag']==='2'?'U':'0',$rrd));
    tp_exec('REPLACE INTO data_input_data (data_input_field_id,data_template_data_id,t_value,value) VALUES (?,?,"",?)',array($base['oid_field'],$dtd,$record['oid']));
    $gt=(int)api_duplicate_graph(0,$c['graph_template_id'],$name,false);
    if(!$gt) throw new RuntimeException('Cacti graph-template creation failed.');
    tp_object($row['id'],'graph_template',$gt,$record['oid']);
    tp_exec('UPDATE graph_templates_graph SET title=? WHERE graph_template_id=? AND local_graph_id=0',array('|host_description| - '.$record['label'],$gt));
    tp_exec('UPDATE graph_templates_item SET task_item_id=? WHERE graph_template_id=? AND local_graph_id=0 AND task_item_id>0',array($rrd,$gt));
    tp_exec('INSERT INTO host_template_graph (host_template_id,graph_template_id) VALUES (?,?)',array($row['template_id'],$gt));
    return $gt;
}
/** Provision exactly one native device and its data sources, with idempotence and transactional metadata. */
function tp_sim_provision($id) {
    global $config;
    foreach(array('host.php','host_templates.php','data_templates.php','graph_templates.php','graphs.php') as $page) tp_require_core($page);
    foreach(array('api_device','api_data_source','api_graph','api_automation','template','utility') as $lib) require_once($config['base_path'].'/lib/'.$lib.'.php');
    $id=tp_id($id);$c=tp_sim_config();$base=tp_sim_templates($c);$lock='topology_import_'.$id;
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,2)',array($lock))!==1) throw new RuntimeException('This import is already being provisioned.');
    try {
        $row=db_fetch_row_prepared('SELECT * FROM plugin_topology_imports WHERE id=?',array($id));
        if(!$row) throw new InvalidArgumentException('Import not found.');
        $unit=tp_unit($row['unit_id']);tp_category($row['category_id']);
        if($row['host_id']) { tp_host($row['host_id']); return (int)$row['host_id']; }
        tp_sim_probe($row,$c);
        $records=tp_records($row['content']);
        tp_exec('START TRANSACTION');
        try {
            $row['template_id']=tp_core_save(array('id'=>0,'hash'=>get_hash_host_template(0),'name'=>'Topology Lab '.$id.' - '.$row['name']),'host_template');
            tp_object($id,'host_template',$row['template_id']);
            $graphs=array();
            foreach($records as $r) if($r['metric']) $graphs[tp_sim_pair($row,$r,$c,$base)]=$r['oid'];
            $hasInterfaces=isset($records['1.3.6.1.2.1.2.1.0']);
            if($hasInterfaces) tp_exec('INSERT INTO host_template_snmp_query (host_template_id,snmp_query_id) VALUES (?,?)',array($row['template_id'],$c['interface_query_id']));
            // The explicit loopback proxy permits several simulated communities at the same address.
            set_request_var('proxy','on');
            $host=(int)api_device_save(0,$row['template_id'],$row['name'],$c['address'],$row['community'],2,'','',$c['port'],$c['snmp_timeout'],'',2,3,23,$c['snmp_timeout'],$c['snmp_retries'],'Topology simulator import '.$id,'','','','','',5,1,$c['poller_id'],$row['site_id']);
            unset_request_var('proxy');
            if(!$host) throw new RuntimeException('Cacti could not create the simulator device. Check native validation messages.');
            tp_object($id,'device',$host);
            tp_exec('INSERT INTO plugin_topology_devices (host_id,category_id,device_type,role,protocol,updated_by,updated_at,unit_id) VALUES (?,?,?,?,?,?,NOW(),?)',array($host,$row['category_id'],'Simulated equipment','Lab','none',(int)$_SESSION['sess_user_id'],$unit['id']));
            foreach($graphs as $gt=>$oid) {
                $suggested=array();$created=create_complete_graph_from_template($gt,$host,null,$suggested);
                if(empty($created['local_graph_id']) || empty($created['local_data_id'])) throw new RuntimeException('Native graph/data-source creation failed.');
                tp_object($id,'graph',(int)$created['local_graph_id'],$oid);
                foreach((array)$created['local_data_id'] as $ds) { tp_object($id,'data_source',(int)$ds,$oid); push_out_host($host,(int)$ds); }
            }
            tp_exec('UPDATE plugin_topology_imports SET host_id=?,template_id=?,state="provisioned",last_error="" WHERE id=?',array($host,$row['template_id'],$id));
            tp_audit('provision_import',$id);tp_exec('COMMIT');
            set_config_option('time_last_change_graph',time());set_config_option('time_last_change_data_source',time());
            return $host;
        } catch(Throwable $e) { db_execute('ROLLBACK'); unset_request_var('proxy'); throw $e; }
    } finally { db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',array($lock)); }
}

/** Automatic provisioning always uses the uploader's current native permissions. */
function tp_sim_permission(){
    tp_require_core('topo_sim.php');
    foreach(array('host.php','host_templates.php','data_templates.php','graph_templates.php','graphs.php') as $page)tp_require_core($page);
}
function tp_sim_retry($id){
    tp_sim_permission();$id=tp_id($id);
    $r=db_fetch_row_prepared('SELECT * FROM plugin_topology_imports WHERE id=?',array($id));
    if(!$r)throw new InvalidArgumentException('Import not found.');
    if($r['host_id']){tp_host($r['host_id']);return;}
    tp_unit($r['unit_id']);tp_category($r['category_id']);
    tp_exec('UPDATE plugin_topology_imports SET state="pending",auto_provision=1,attempts=0,last_attempt=NULL,last_error="",created_by=? WHERE id=? AND state="failed"',array((int)$_SESSION['sess_user_id'],$id));
    tp_sim_reload(tp_sim_config());
}
/** One bounded automatic attempt; the import lock and core transaction make retries idempotent. */
function tp_sim_process_queue(){
    $r=db_fetch_row('SELECT * FROM plugin_topology_imports WHERE auto_provision=1 AND state="pending" AND host_id=0 AND (last_attempt IS NULL OR last_attempt<DATE_SUB(NOW(),INTERVAL 15 SECOND)) ORDER BY id LIMIT 1');
    if(!$r)return;
    $_SESSION=array('sess_user_id'=>(int)$r['created_by']);
    tp_exec('UPDATE plugin_topology_imports SET attempts=attempts+1,last_attempt=NOW() WHERE id=?',array($r['id']));
    try{
        if(!db_fetch_cell_prepared('SELECT id FROM user_auth WHERE id=? AND enabled="on"',array($r['created_by'])))throw new RuntimeException('Uploader account is disabled or missing.');
        tp_sim_permission();tp_sim_provision($r['id']);
        echo 'Import '.(int)$r['id'].': provisioned'.PHP_EOL;
    }catch(Throwable $e){
        $state=((int)$r['attempts']+1>=5)?'failed':'pending';
        $message=$e instanceof RuntimeException||$e instanceof InvalidArgumentException?$e->getMessage():'Provisioning failed; check the Cacti log.';
        tp_exec('UPDATE plugin_topology_imports SET state=?,last_error=? WHERE id=? AND host_id=0',array($state,substr($message,0,255),$r['id']));
        echo 'Import '.(int)$r['id'].': '.$state.PHP_EOL;
    }
}
