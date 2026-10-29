<?php
ob_start();
/** Integration with native Cacti templates and poller items, isolated per DB connection. */
if(PHP_SAPI!=='cli')exit(1);
define('IN_CACTI_INSTALL',true);require '/var/www/html/cacti/include/cli_check.php';
api_plugin_load_realms();require_once $config['base_path'].'/lib/auth.php';
require_once __DIR__.'/../services/bootstrap.php';icct_nms_backend();
if(!function_exists('api_reapply_suggested_data_source_data'))throw new RuntimeException('Device activation must load native data source APIs before any MIB template is generated.');
require_once __DIR__.'/../../presets/services/device_type_service.php';
require_once __DIR__.'/../../protocols/snmp/mibs/services/mib_repository_service.php';
require_once __DIR__.'/../../protocols/snmp/mibs/services/mib_generation_service.php';
require_once __DIR__.'/../../protocols/snmp/mibs/services/mib_staging_service.php';
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
    foreach($bundle['rows'] as $i=>&$savedRow){$savedRow['settings']=$plan['records'][$i];$savedRow['object_index']=array_search($plan['records'][$i]['symbol'],array_column($preview['records'],'symbol'),true);}unset($savedRow);$bundle['wizard_step']=3;icct_mib_wizard_store($bundle);
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
    $counts=function(){return [(int)db_fetch_cell('SELECT COUNT(*) FROM data_template'),(int)db_fetch_cell('SELECT COUNT(*) FROM graph_templates'),(int)db_fetch_cell('SELECT COUNT(*) FROM host_template'),(int)db_fetch_cell('SELECT COUNT(*) FROM graph_local'),(int)db_fetch_cell('SELECT COUNT(*) FROM data_local')];};
    $before=$counts();$ids=array_column($bundle['rows'],'data_template_id');$gids=array_column($bundle['rows'],'graph_template_id');
    // Exercise the real RRDtool session-close behavior using an isolated RRD file.
    require_once $config['base_path'].'/lib/rrd.php';
    $scalarPair=current(array_filter($bundle['rows'],fn($row)=>$row['object_index']===$scalar));
    $scalarLocal=(int)db_fetch_cell_prepared('SELECT id FROM data_local WHERE host_id=? AND data_template_id=?',[$hostId,$scalarPair['data_template_id']]);
    $dsName=db_fetch_cell_prepared('SELECT data_source_name FROM data_template_rrd WHERE local_data_id=?',[$scalarLocal]);
    $testRrd=get_data_source_path($scalarLocal,true);if(is_file($testRrd))throw new RuntimeException('Refusing to overwrite an existing RRD fixture path.');$generated[]=$testRrd;
    $error=rrdtool_execute('create '.cacti_escapeshellarg($testRrd).' --start '.(time()-300).' --step 300 '.cacti_escapeshellarg('DS:'.$dsName.':GAUGE:600:U:U').' RRA:AVERAGE:0.5:1:12',false,RRDTOOL_OUTPUT_STDERR);
    if($error||!is_file($testRrd))throw new RuntimeException('Cannot create isolated RRD fixture.');
    ini_set('session.save_path',sys_get_temp_dir());session_cache_limiter('');session_id('icct-mib-edit-'.bin2hex(random_bytes(8)));
    $sessionMemory=$_SESSION;session_start();$_SESSION=$sessionMemory;
    $token=icct_mib_edit_open($bundle);
    $fault=['name'=>'Edit QA alarm','enabled'=>true,'condition'=>'above','threshold'=>'9','severity'=>'Major','description'=>'Saved edit alarm','corrective_action'=>'Inspect interface'];
    icct_mib_staging_review($bundle['id'],$token,2,['records'=>[$scalar=>['data_name'=>$name.' renamed data','label'=>'Edited label','active'=>'0','min'=>'0','max'=>'9999','fault_presets'=>json_encode([$fault])]]]);
    $edited=icct_mib_edit_save($bundle['id'],$token,2);$scalarRow=current(array_filter($edited['rows'],fn($r)=>$r['object_index']===$scalar));
    collection_check(session_status()===PHP_SESSION_NONE,'Native RRD tuning closes the PHP session during an edit save');
    $_SESSION=[];session_start();$roundTrip=icct_mib_generation_draft_get($token,$bundle['id'],'ready');
    collection_check($roundTrip['work']['plans'][2]['records'][0]['data_name']===$name.' renamed data'&&hash_equals($roundTrip['edit_revision'],icct_mib_edit_revision($edited)),'The refreshed editor survives RRD tuning and a new PHP request');
    collection_check($counts()===$before&&array_column($edited['rows'],'data_template_id')===$ids,'Data edits preserve template, graph and live data source IDs/counts');
    collection_check(db_fetch_cell_prepared('SELECT name FROM data_template WHERE id=?',[$scalarRow['data_template_id']])===$name.' renamed data','Data template rename updates the existing native row');
    collection_check((int)db_fetch_cell_prepared("SELECT COUNT(*) FROM data_template_data WHERE data_template_id=? AND active='on'",[$scalarRow['data_template_id']])===0,'Data source active switch propagates to existing devices');
    collection_check((int)db_fetch_cell_prepared("SELECT COUNT(*) FROM data_template_rrd WHERE data_template_id=? AND rrd_maximum='9999'",[$scalarRow['data_template_id']])>=2,'RRD bounds propagate without recreating sources');
    $nativeRrd=(int)db_fetch_cell_prepared('SELECT id FROM data_template_rrd WHERE data_template_id=? AND local_data_id=0',[$scalarRow['data_template_id']]);
    $rules=json_decode(db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['graph_fault_presets_'.$scalarRow['graph_template_id']]),true);
    collection_check($rules[0]['metric_id']===$nativeRrd&&$rules[0]['severity']==='Major','Data edits persist faults with the native metric identity');
    icct_mib_staging_review($bundle['id'],$token,3,['records'=>[$scalar=>['graph_name'=>$name.' renamed graph','color'=>'FF7325','legend'=>'Edited legend','threshold_high'=>'7','statistics'=>'0']]]);
    $edited=icct_mib_edit_save($bundle['id'],$token,3);
    collection_check($counts()===$before&&array_column($edited['rows'],'graph_template_id')===$gids,'Graph edits retain native graph template IDs and live graphs');
    $gid=$scalarRow['graph_template_id'];
    collection_check((int)db_fetch_cell_prepared("SELECT COUNT(*) FROM graph_templates_item i JOIN colors c ON c.id=i.color_id WHERE i.graph_template_id=? AND i.text_format='Edited legend' AND c.hex='FF7325'",[$gid])>=2,'Graph legend and color propagate to the existing graph');
    collection_check((int)db_fetch_cell_prepared("SELECT COUNT(*) FROM graph_templates_item WHERE graph_template_id=? AND graph_type_id=2 AND value='7'",[$gid])>=2,'Threshold lines update template and instantiated graph');
    collection_check((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_templates_item WHERE graph_template_id=? AND local_graph_id>0 AND graph_type_id IN (4,5,6,7) AND task_item_id=0',[$gid])===0,'Graph edits preserve live RRD bindings');
    collection_check((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_templates_item WHERE graph_template_id=? AND graph_type_id=9',[$gid])===0,'Turning off graph statistics removes existing GPRINT items');
    $draft=icct_mib_generation_draft_get($token,$bundle['id'],'ready');$device=$draft['device_template'];
    icct_mib_staging_review($bundle['id'],$token,4,['device_name'=>$name.' renamed device','device_class'=>$device['class'],'device_graphs'=>json_encode([$name.' renamed graph']),'device_queries'=>'[]']);
    $edited=icct_mib_edit_save($bundle['id'],$token,4);
    collection_check($counts()===$before&&$edited['host_template_id']===$bundle['host_template_id'],'Device edits preserve the native device template ID');
    collection_check(db_fetch_cell_prepared('SELECT name FROM host_template WHERE id=?',[$bundle['host_template_id']])===$name.' renamed device','Device name changes save to Cacti');
    collection_check(array_map('intval',array_column(db_fetch_assoc_prepared('SELECT graph_template_id FROM host_template_graph WHERE host_template_id=?',[$bundle['host_template_id']]),'graph_template_id'))===[$gid]&&(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM host_template_snmp_query WHERE host_template_id=?',[$bundle['host_template_id']])===0,'Device graph/query removals are persisted, not only appended');
    $stale=icct_mib_edit_open($edited);$current=icct_mib_edit_open($edited);
    icct_mib_staging_review($bundle['id'],$current,2,['records'=>[$scalar=>['active'=>'1']]]);icct_mib_edit_save($bundle['id'],$current,2);
    try{icct_mib_edit_save($bundle['id'],$stale,2);throw new LogicException('Stale save accepted');}catch(InvalidArgumentException $e){collection_check(str_contains($e->getMessage(),'another session'),'Stale edit sessions cannot overwrite saved changes');}
    $fresh=icct_mib_edit_open(icct_mib_wizard_get($bundle['id']));$state=icct_mib_generation_draft_get($fresh,$bundle['id'],'ready');
    collection_check($state['review']['values']['records'][$scalar]['active']==='1'&&$state['review']['values']['records'][$scalar]['graph_name']===$name.' renamed graph','Reopening Edit loads saved data and graph changes');
    echo "MIB collection integration passed.\n";
} catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");$failed=true;}
finally{foreach($generated as $file)if(is_file($file))unlink($file);if(session_id()!==''){if(session_status()!==PHP_SESSION_ACTIVE)session_start();session_destroy();}}
ob_end_flush();

if(!empty($failed))exit(1);
