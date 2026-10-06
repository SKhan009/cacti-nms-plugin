<?php
/** Integration with native Cacti templates and poller items, isolated per DB connection. */
if(PHP_SAPI!=='cli')exit(1);
define('IN_CACTI_INSTALL',true);require '/var/www/html/cacti/include/cli_check.php';
api_plugin_load_realms();require_once $config['base_path'].'/lib/auth.php';
require_once __DIR__.'/../services/bootstrap.php';icct_nms_backend();
require_once __DIR__.'/../../presets/services/device_type_service.php';
require_once __DIR__.'/../../protocols/snmp/mibs/services/mib_repository_service.php';
$_SESSION=['sess_user_id'=>1];
function collection_check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
$generated=[];
try {
    $tables=['data_local','graph_local','host_template','host_template_graph','host_template_snmp_query','host_snmp_query','host_snmp_cache','snmp_query','snmp_query_graph','snmp_query_graph_rrd','data_template','data_template_data','data_template_rrd','data_input_data','graph_templates','graph_templates_graph','graph_templates_item','graph_template_input','graph_template_input_defs','plugin_icct_nms_meta','settings','colors','host_graph','poller_item','poller_reindex','poller_command','data_source_stats_hourly','data_source_stats_daily','data_source_stats_weekly','data_source_stats_monthly','data_source_stats_yearly'];
    foreach($tables as $table){
        $ddl=db_fetch_row('SHOW CREATE TABLE `'.$table.'`');$ddl=array_values($ddl)[1];
        if(!db_execute('CREATE TEMPORARY TABLE qa_collection_copy LIKE `'.$table.'`')||!db_execute('INSERT INTO qa_collection_copy SELECT * FROM `'.$table.'`')||!db_execute(preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$ddl))||!db_execute('INSERT INTO `'.$table.'` SELECT * FROM qa_collection_copy'))throw new RuntimeException('Cannot isolate '.$table);
        db_execute('DROP TEMPORARY TABLE qa_collection_copy');
    }
    $type=array_key_first(icct_nms_device_types());
    $preview=icct_mib_preview(['name'=>['IF-MIB.txt'],'tmp_name'=>[__DIR__.'/../assets/mibs/IF-MIB.txt'],'error'=>[UPLOAD_ERR_OK]],$type);
    $column=null;$scalar=null;foreach($preview['records'] as $i=>$record){if($record['label']==='ifInOctets')$column=$i;if($record['label']==='ifNumber')$scalar=$i;}
    $name='Collection QA '.bin2hex(random_bytes(4));
    $input=['type_id'=>$type,'template_name'=>$name,'create_data'=>1,'create_graph'=>1,'create_device'=>1,'selected'=>[$column=>1,$scalar=>1]];
    $plan=icct_mib_plan($preview,$input);
    $methods=array_column($plan['records'],'resolved_method','label');
    collection_check($methods['ifInOctets']==='indexed'&&$methods['ifNumber']==='get','Auto chooses indexed columns and scalar GET');
    collection_check(str_contains($preview['records'][$column]['index_description'],'ifIndex'),'MIB index metadata retained');
    $xml=icct_mib_query_xml($preview['records'][$column]);$parsed=simplexml_load_string($xml);
    $pattern='/'.str_replace('OID/REGEXP:','',(string)$parsed->oid_index_parse).'/';
    collection_check(preg_match($pattern,$preview['records'][$column]['base_oid'].'.1001.2',$match)&&$match[1]==='1001.2','Indexed query preserves composite OID suffixes');
    $generated[]=__DIR__.'/../../protocols/snmp/mibs/queries/'.hash('sha256',$xml).'.xml';
    $nativeFile=$config['base_path'].'/resource/snmp_queries/icct_nms/'.hash('sha256',$xml).'.xml';if(!is_file($nativeFile))$generated[]=$nativeFile;
    // Existing test templates are irrelevant; isolate repository and generic OID bindings.
    db_execute("DELETE FROM plugin_icct_nms_meta WHERE meta_key LIKE 'mib_%'");
    foreach($plan['records'] as $record)db_execute_prepared("DELETE did FROM data_input_data did JOIN data_input_fields f ON f.id=did.data_input_field_id WHERE f.data_name='oid' AND did.value=?",[$record['oid']]);
    $bundle=icct_mib_save($preview,$plan);
    $indexed=current(array_filter($bundle['rows'],fn($r)=>!empty($r['snmp_query_id'])));

    collection_check($indexed['snmp_query_id']>0&&$indexed['snmp_query_graph_id']>0,'Creates native SNMP query and graph mapping');
    $data=db_fetch_row_prepared('SELECT data_input_id,rrd_step,data_source_profile_id FROM data_template_data WHERE data_template_id=? AND local_data_id=0',[$indexed['data_template_id']]);
    collection_check((int)db_fetch_cell_prepared('SELECT type_id FROM data_input WHERE id=?',[$data['data_input_id']])===3&&(int)$data['rrd_step']===300,'Indexed native input and polling profile saved separately from RRD type');
    collection_check((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM host_template_snmp_query WHERE host_template_id=? AND snmp_query_id=?',[$bundle['host_template_id'],$indexed['snmp_query_id']])===1,'Device template includes indexed data query');
    $again=$preview;$again['id']=bin2hex(random_bytes(16));$repeat=$input;$repeat['template_name'].=' duplicate';$repeatPlan=icct_mib_plan($again,$repeat);
    try{icct_mib_save($again,$repeatPlan);throw new LogicException('Duplicate accepted');}catch(InvalidArgumentException $e){collection_check(str_contains($e->getMessage(),'already exists'),'Duplicate OID rejected across uploads despite new template names');}
    $specific=$input;$specific['selected']=[$column=>1];$specific['records']=[$column=>['collection_method'=>'get','instance_index'=>'1001.2']];
    collection_check(icct_mib_plan($preview,$specific)['records'][0]['oid']===$preview['records'][$column]['base_oid'].'.1001.2','Specific GET accepts explicit composite instance');
    foreach([['selected'=>[$scalar=>1],'records'=>[$scalar=>['collection_method'=>'indexed']]],['selected'=>[$column=>1],'records'=>[$column=>['collection_method'=>'get']]],['selected'=>[$scalar=>1],'records'=>[$scalar=>['collection_method'=>'script']]],['selected'=>[$scalar=>1],'records'=>[$scalar=>['profile_id'=>999999]]]] as $bad){try{icct_mib_plan($preview,array_replace($input,$bad));throw new LogicException('Invalid method accepted');}catch(InvalidArgumentException $e){collection_check(true,'Invalid collection/instance/profile rejected');}}
    // Discover actual local SNMP instances, then create native graph/data/poller instances.
    $hostId=2;$query=$indexed['snmp_query_id'];
    db_execute_prepared('REPLACE INTO host_snmp_query(host_id,snmp_query_id,reindex_method) VALUES(?,?,?)',[$hostId,$query,2]);
    collection_check(run_data_query($hostId,$query)!==false,'Native SNMP query discovers instances from the real local device');
    $count=icct_backend_device_activate_imported_templates($hostId,$bundle['host_template_id']);
    collection_check($count===3,'Native activation creates two discovered indexed graphs and one scalar graph');
    collection_check((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_local WHERE host_id=? AND snmp_query_id=?',[$hostId,$query])===2,'Indexed graph instances bound to native Cacti query');
    collection_check(icct_backend_device_activate_imported_templates($hostId,$bundle['host_template_id'])===0,'Repeated device sync does not duplicate instances');
    collection_check((int)db_fetch_cell_prepared("SELECT COUNT(*) FROM poller_item pi JOIN data_local dl ON dl.id=pi.local_data_id WHERE dl.data_template_id=? AND pi.host_id=? AND TRIM(LEADING '.' FROM arg1) IN (?,?)",[$indexed['data_template_id'],$hostId,$preview['records'][$column]['base_oid'].'.1',$preview['records'][$column]['base_oid'].'.2'])>=2,'Native poller items use the discovered instance OIDs');
    echo "MIB collection integration passed.\n";
} catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");$failed=true;}
finally{foreach($generated as $file)if(is_file($file))unlink($file);}

if(!empty($failed))exit(1);
