<?php
require_once __DIR__.'/mib_graph_options_service.php';
require_once __DIR__.'/mib_device_core_service.php';
require_once __DIR__.'/mib_edit_service.php';
/** Generate prepares session-only definitions; Add is the sole persistent write. */
function icct_mib_staging_plan($bundle,$draft,$stage=4){
    $values=$draft['review']['values'];
    if(!empty($draft['saved_edit']))foreach($values['records'] as &$editRecord)if(($editRecord['resolved_method']??'get')==='get'&&!empty($editRecord['table'])&&!empty($editRecord['instance_index']))unset($editRecord['oid']);unset($editRecord);
    $eligible=!empty($draft['saved_edit'])?array_fill_keys(array_column($bundle['rows'],'object_index'),1):icct_mib_generation_input($bundle,2,$draft['review']['object']??null)['selected'];
    $values=icct_mib_generation_prepare($eligible,$values,true)+['type_id'=>$bundle['type_id'],'template_name'=>$bundle['name'],'create_data'=>1,'create_graph'=>$stage>=3?1:0,'create_device'=>$stage===4?1:0,'preparation_stage'=>$stage];
    $plan=icct_mib_plan(['records'=>icct_mib_objects($bundle),'parse_error'=>$bundle['parse_error']??''],$values);
    if($stage>=3){$objects=icct_mib_objects($bundle);$indexes=array_flip(array_column($objects,'symbol'));foreach($plan['records'] as &$record){$items=icct_mib_graph_items($draft['review']['values']['records'][$indexes[$record['symbol']]]['graph_items']??[]);if($items)$record['graph_items']=$items;$record['graph_deleted_items']=icct_mib_deleted_graph_items($draft['review']['values']['records'][$indexes[$record['symbol']]]['graph_deleted_items']??[]);$raw=$draft['review']['values']['records'][$indexes[$record['symbol']]]['graph_options']??null;if($raw!==null)$record['graph_options']=icct_mib_graph_options($raw,$record['graph_name'],$record['units']);}unset($record);}
    if($stage===4){$defaults=icct_mib_device_core_defaults($bundle,$plan['records']);$chosen=$draft['device_template']['graphs']??$defaults['graphs'];$queries=icct_mib_device_query_selection($draft['device_template']['queries']??$defaults['queries']);$queries=array_values(array_unique(array_merge($queries,icct_mib_device_required_queries($bundle,$plan['records'],$chosen))));sort($queries);$selection=icct_mib_device_graph_selection($bundle,$plan['records'],$chosen,(bool)$queries);$plan['device_template']=icct_mib_device_details($draft['device_template']['name']??$defaults['name'],(($draft['device_template']['class']??'')?:$defaults['class']))+$selection+['queries'=>$queries];}
    return $plan;
}
/** Earlier drafts that prepared every type at once must be generated stage by stage. */
function icct_mib_staging_work($draft){
    return isset($draft['work']['plans'])?$draft['work']:['stage'=>1,'results'=>[],'plans'=>[]];
}
function icct_mib_staging_data_definition($plan){
    unset($plan['options'],$plan['device_template']);
    foreach($plan['records'] as &$record)foreach(['graph_name','graph_type','cf','color','legend','statistics','threshold_low','threshold_high','fault_presets','graph_options','graph_items','graph_deleted_items'] as $key)unset($record[$key]);
    unset($record);return $plan;
}
function icct_mib_staging_graph_definition($plan){unset($plan['options'],$plan['device_template']);return $plan;}
function icct_mib_staging_generate($id,$token,$stage,$emit){
    icct_backend_require_management(3);
    if(!in_array($stage,[2,3,4],true))throw new InvalidArgumentException('Invalid generation stage.');
    $draft=icct_mib_generation_draft_get($token,$id,'ready');$work=icct_mib_staging_work($draft);
    if($stage>$work['stage']+1)throw new InvalidArgumentException('Generate the previous stage first.');
    $bundle=icct_mib_wizard_get($id);
    $emit(['event'=>'status','message'=>'Reading saved MIB definitions and current Cacti template information…']);
    $plan=icct_mib_staging_plan($bundle,$draft,$stage);
    if($stage>=3&&icct_mib_staging_data_definition($work['plans'][2])!==icct_mib_staging_data_definition($plan))throw new InvalidArgumentException('Data source settings changed. Generate step 2 again before continuing.');
    if($stage===4&&icct_mib_staging_graph_definition($work['plans'][3])!==icct_mib_staging_graph_definition($plan))throw new InvalidArgumentException('Graph settings changed. Generate step 3 again before continuing.');
    $results=[];$label=[2=>'Data Source',3=>'Graph Template',4=>'Device Template'][$stage];
    if($stage===4){$results[]=['name'=>$plan['device_template']['name'],'oid'=>'','kind'=>$label,'graph_templates'=>count($plan['device_template']['graphs'])+count($plan['device_template']['core_graphs'])];}
    elseif($stage===3)foreach(icct_mib_graph_groups($plan['records']) as $group)$results[]=['name'=>$group['name'],'oid'=>'','kind'=>$label,'data_sources'=>count($group['records'])];
    else foreach($plan['records'] as $record)$results[]=['name'=>$record[$stage===2?'data_name':'graph_name'],'oid'=>$record['oid'],'kind'=>$label];
    $table=[2=>'data_template',3=>'graph_templates',4=>'host_template'][$stage];
    if($stage!==4||empty($bundle['host_template_id']))icct_mib_validate_template_names(array_column($results,'name'),$table);
    if($stage===4&&!empty($bundle['host_template_id'])&&db_fetch_cell_prepared('SELECT id FROM host_template WHERE name=? AND id<>? LIMIT 1',[$plan['device_template']['name'],$bundle['host_template_id']]))throw new InvalidArgumentException('Another Cacti device template already uses this name.');
    if($stage===2)icct_mib_validate_duplicates($plan['records']);
    $total=count($results);$emit(['event'=>'start','total'=>$total,'kind'=>$label]);
    foreach($results as $i=>$result)$emit(['event'=>'item','completed'=>$i+1,'total'=>$total,'result'=>$result]);
    // Regenerating an earlier step invalidates only the later prepared steps.
    foreach([2,3,4] as $later)if($later>$stage)unset($work['results'][$later],$work['plans'][$later]);
    $work['stage']=$stage;$work['results'][$stage]=$results;$work['plans'][$stage]=$plan;
    $_SESSION['icct_mib_generation_'.$token]['work']=$work;
    if($stage===2)unset($_SESSION['icct_mib_generation_'.$token]['selection_changed']);
    return $work;
}
function icct_mib_staging_commit($id,$token,$includeDevice=true){
    global $config;
    icct_backend_require_management(3);
    $draft=icct_mib_generation_draft_get($token,$id,'ready');
    if(!empty($draft['saved_readonly']))throw new InvalidArgumentException('Updating existing Cacti templates from this screen is not supported yet.');
    $commitStage=icct_mib_staging_work($draft)['stage']??0;if(!$includeDevice&&$commitStage>=3)$commitStage=3;if(!in_array($commitStage,[3,4],true))throw new InvalidArgumentException('Prepare graph templates before Add.');
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,10)',['icct_mib_templates'])!==1)throw new RuntimeException('Template creation is busy. Retry shortly.');
    try{
        $bundle=icct_mib_wizard_get($id);$plan=icct_mib_staging_plan($bundle,$draft,$commitStage);
        $prepared=$draft['work']['plans'][$commitStage];
        // Device defaults and associations are validated from current core data on Save.
        // They may change after automatic Class/query resolution without invalidating
        // the already prepared data source and graph definitions.
        $currentDefinition=$plan;$preparedDefinition=$prepared;
        if($commitStage===4){unset($currentDefinition['device_template'],$preparedDefinition['device_template']);}
        if($currentDefinition!==$preparedDefinition)throw new InvalidArgumentException('MIB or Cacti defaults changed. Generate the stages again.');
        icct_mib_validate_template_names(array_column($plan['records'],'data_name'),'data_template');
        icct_mib_validate_template_names(array_column(icct_mib_graph_groups($plan['records']),'name'),'graph_templates');
        $hostId=(int)$bundle['host_template_id'];
        if($hostId&&!db_fetch_cell_prepared('SELECT id FROM host_template WHERE id=?',[$hostId]))throw new InvalidArgumentException('The associated device template was removed. Reload the repository.');
        if($commitStage===4&&!$hostId)icct_mib_validate_template_names([$plan['device_template']['name']],'host_template');
        if($commitStage===4&&$hostId&&db_fetch_cell_prepared('SELECT id FROM host_template WHERE name=? AND id<>? LIMIT 1',[$plan['device_template']['name'],$hostId]))throw new InvalidArgumentException('Another Cacti device template already uses this name.');
        icct_mib_validate_duplicates($plan['records']);
        require_once $config['base_path'].'/lib/template.php';
        if(!db_execute('START TRANSACTION'))throw new RuntimeException('Cannot begin saving templates.');
        try{
            if($commitStage===4&&!$hostId){$hostId=(int)sql_save(['id'=>0,'hash'=>get_hash_host_template(0),'name'=>$plan['device_template']['name']],'host_template');if(!$hostId)throw new RuntimeException('Could not create device template.');}
            if($commitStage===4){$deviceValues=['id'=>$hostId,'name'=>$plan['device_template']['name']];if(isset($plan['device_template']['class']))$deviceValues['class']=$plan['device_template']['class'];if(!(int)sql_save($deviceValues,'host_template'))throw new RuntimeException('Could not save device template details.');foreach($plan['device_template']['queries'] as $queryId)icct_mib_write('REPLACE INTO host_template_snmp_query(host_template_id,snmp_query_id) VALUES(?,?)',[$hostId,$queryId]);}
            $objects=icct_mib_objects($bundle);
            foreach(icct_mib_graph_groups($plan['records']) as $group)foreach(icct_mib_native_graph_group($group) as $entry){
                $record=$entry['record'];$pair=$entry['pair'];
                if($commitStage===4&&in_array($group['name'],$plan['device_template']['graphs'],true)){
                if($pair['snmp_query_id'])icct_mib_write('REPLACE INTO host_template_snmp_query(host_template_id,snmp_query_id) VALUES(?,?)',[$hostId,$pair['snmp_query_id']]);
                else icct_mib_write('REPLACE INTO host_template_graph(host_template_id,graph_template_id) VALUES(?,?)',[$hostId,$pair['graph_template_id']]);
                }
                $bundle['rows'][]=$pair+['object_index'=>array_search($record['symbol'],array_column($objects,'symbol'),true),'oid'=>$record['oid'],'name'=>$record['label'],'deferred_instance'=>!empty($record['deferred_instance']),'settings'=>$record];
            }
            if($commitStage===4)foreach($plan['device_template']['core_graphs'] as $graphId){
                icct_mib_write('REPLACE INTO host_template_graph(host_template_id,graph_template_id) VALUES(?,?)',[$hostId,$graphId]);
            }
            $bundle['host_template_id']=$hostId;if($commitStage===4)$bundle['device_template']=$plan['device_template'];$bundle['wizard_step']=$commitStage-1;
            foreach(['name','type_id','type_name','category_id','options'] as $key)$bundle[$key]=$plan[$key];
            icct_mib_wizard_store($bundle);
            if(!db_execute('COMMIT'))throw new RuntimeException('Could not save templates.');
        }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
    }finally{db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',['icct_mib_templates']);}
    if($commitStage===4)$_SESSION['icct_mib_generation_'.$token]['committed']=true;
    else unset($_SESSION['icct_mib_generation_'.$token]);
    set_config_option('time_last_change_graph',time());set_config_option('time_last_change_data_source',time());
    return $bundle;
}

/** Keep reviewed fields in the session; generation validates them before use. */
function icct_mib_staging_review($id,$token,$stage,$input){
    icct_backend_require_management(3);
    $draft=icct_mib_generation_draft_get($token,$id,'ready');
    if($stage===4){$graphs=json_decode($input['device_graphs']??'[]',true,16,JSON_THROW_ON_ERROR);$prepared=icct_mib_staging_work($draft)['plans'][3]??null;if(!$prepared)throw new InvalidArgumentException('Prepare graph templates first.');$bundle=icct_mib_wizard_get($id);$defaults=icct_mib_device_core_defaults($bundle,$prepared['records']);$queries=icct_mib_device_query_selection($input['device_queries']??$defaults['queries']);icct_mib_device_graph_selection($bundle,$prepared['records'],$graphs,(bool)$queries);$_SESSION['icct_mib_generation_'.$token]['device_template']=icct_mib_device_details($input['device_name']??'',(($input['device_class']??'')?:$defaults['class']))+['graphs'=>array_values(array_unique($graphs)),'queries'=>$queries];return;}
    $fields=[2=>['data_name','label','units','min','max','collection_method','profile_id','active','ds_type','instance_index','fault_presets','fault_presets_reviewed'],3=>['graph_name','legend','color','graph_type','cf','statistics','threshold_low','threshold_high','graph_options','graph_items','graph_deleted_items'],4=>[]][$stage]??[];
    $values=$draft['review']['values'];
    if(!is_array($input['records']??null))throw new InvalidArgumentException('Invalid prepared fields.');
    foreach($input['records']??[] as $index=>$record){
        if(!isset($values['selected'][$index])||!is_array($record))throw new InvalidArgumentException('Invalid prepared OID.');
        foreach(array_intersect_key($record,array_flip($fields)) as $key=>$value){
            if(!is_scalar($value))throw new InvalidArgumentException('Invalid prepared field.');
            $values['records'][$index][$key]=$key==='fault_presets'?icct_mib_fault_presets((string)$value):(string)$value;
        }
    }
    $_SESSION['icct_mib_generation_'.$token]['review']['values']=$values;
}

/** Re-entering unchanged step 1 preserves the completed session definitions. */
function icct_mib_staging_selection($id,$token,$values){
    icct_backend_require_management(3);
    $draft=icct_mib_generation_draft_get($token,$id,'ready');
    $previous=$draft['review']['values']['selected'];$chosen=$values['selected'];
    if(!array_diff_key($previous,$chosen)&&!array_diff_key($chosen,$previous))return $token;
    $draft['review']['values']=['selected'=>$chosen,'records'=>array_intersect_key($draft['review']['values']['records'],$chosen)];
    unset($draft['device_template']);
    $draft['work']=['stage'=>1,'results'=>[],'plans'=>[]];$draft['selection_changed']=true;
    $_SESSION['icct_mib_generation_'.$token]=$draft;return $token;
}

/** A shared graph name groups sources; source settings remain independent. */
function icct_mib_graph_groups($records){
    $groups=[];
    foreach($records as $record){$name=icct_mib_name($record['graph_name'],190);$key=strtolower($name);if(isset($groups[$key])&&$groups[$key]['name']!==$name)throw new InvalidArgumentException('Use consistent capitalization for a shared graph template name.');$groups[$key]['name']=$name;$groups[$key]['records'][]=$record;}
    foreach($groups as $group){
        $first=$group['records'][0];
        foreach($group['records'] as $record){
            if($record['resolved_method']!==$first['resolved_method']||$record['profile_id']!==$first['profile_id'])throw new InvalidArgumentException($group['name'].': combined sources must use the same collection method and polling profile.');
            if($first['resolved_method']==='indexed'&&preg_replace('/\.[0-9]+$/','',$record['base_oid'])!==preg_replace('/\.[0-9]+$/','',$first['base_oid']))throw new InvalidArgumentException($group['name'].': indexed sources must belong to the same table entry. Use separate graph templates for different indexes.');
        }
    }
    return array_values($groups);
}
/** Build a native graph with each source's own items and input mappings. */
function icct_mib_native_graph_group($group,$existing=[]){
    $entries=[];$target=0;$sequence=0;$faults=[];
    foreach($group['records'] as $record){
        $pair=icct_mib_native_metric($record,['data'=>true,'graph'=>true,'reuse_data'=>(bool)$existing,'skip_query'=>(bool)$existing],$existing[$record['symbol']]??[]);$graph=$pair['graph_template_id'];
        if(!$target)$target=$graph;
        foreach(db_fetch_assoc_prepared('SELECT id FROM graph_templates_item WHERE graph_template_id=? AND local_graph_id=0 ORDER BY sequence,id',[$graph]) as $item)icct_mib_write('UPDATE graph_templates_item SET graph_template_id=?,sequence=? WHERE id=?',[$target,++$sequence,$item['id']]);
        $stored=json_decode((string)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['graph_fault_presets_'.$graph]),true)?:[];
        foreach($stored as $rule){$rule['template_id']=$target;$faults[]=$rule;}
        if($graph!==$target){
            icct_mib_write('UPDATE graph_template_input SET graph_template_id=? WHERE graph_template_id=?',[$target,$graph]);
            icct_mib_write('DELETE FROM graph_templates_graph WHERE graph_template_id=?',[$graph]);icct_mib_write('DELETE FROM graph_templates WHERE id=?',[$graph]);
            icct_mib_write('DELETE FROM plugin_icct_nms_meta WHERE meta_key=?',['graph_fault_presets_'.$graph]);
        }
        // Replace individual query mappings with one query containing all outputs.
        if(!$existing&&count($group['records'])>1&&$pair['snmp_query_graph_id']){icct_mib_write('DELETE FROM snmp_query_graph_rrd WHERE snmp_query_graph_id=?',[$pair['snmp_query_graph_id']]);icct_mib_write('DELETE FROM snmp_query_graph WHERE id=?',[$pair['snmp_query_graph_id']]);}
        $pair['graph_template_id']=$target;$entries[]=['record'=>$record,'pair'=>$pair];
    }
    $units=array_unique(array_column($group['records'],'units'));
    $graphOptions=icct_mib_graph_options($group['records'][0]['graph_options']??[],$group['name'],count($units)===1?reset($units):'');
    $flags=array_intersect_key($graphOptions,array_flip(['multiple','test_source']));if($flags)icct_mib_write('UPDATE graph_templates SET multiple=?,test_source=? WHERE id=?',[$flags['multiple'],$flags['test_source'],$target]);$graphOptions=array_diff_key($graphOptions,$flags);
    $assignments=[];$parameters=[];foreach($graphOptions as $key=>$value){$assignments[]='`'.$key.'`=?';$assignments[]='`t_'.$key.'`=\'\'';$parameters[]=$value;}$parameters[]=$target;
    icct_mib_write('UPDATE graph_templates_graph SET '.implode(',',$assignments).' WHERE graph_template_id=? AND local_graph_id=0',$parameters);
    if($faults)icct_mib_write('REPLACE INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',['graph_fault_presets_'.$target,json_encode($faults,JSON_THROW_ON_ERROR)]);
    if(!$existing&&count($entries)>1&&$group['records'][0]['resolved_method']==='indexed'){
        $first=$group['records'][0];$first['label']=$group['name'];$first['symbol']=$group['name'];
        $xml=icct_mib_query_xml($first);$outputs='';
        foreach($entries as $i=>$entry){$r=$entry['record'];$outputs.='<source'.$i.'><name>'.htmlspecialchars($r['label'],ENT_XML1|ENT_QUOTES,'UTF-8').'</name><method>walk</method><source>value</source><direction>output</direction><oid>.'.$r['base_oid'].'</oid></source'.$i.'>';}
        $xml=preg_replace('~<reading>.*?</reading>~s',$outputs,$xml);
        $query=icct_mib_native_query($first,(int)db_fetch_cell('SELECT id FROM data_input WHERE type_id=3 ORDER BY id LIMIT 1'),$xml);
        $queryGraph=(int)sql_save(['id'=>0,'hash'=>bin2hex(random_bytes(16)),'snmp_query_id'=>$query,'name'=>substr($group['name'],0,100),'graph_template_id'=>$target],'snmp_query_graph');
        if(!$queryGraph)throw new RuntimeException('Could not link combined indexed graph template.');
        foreach($entries as $i=>&$entry){$pair=&$entry['pair'];$rrd=(int)db_fetch_cell_prepared('SELECT id FROM data_template_rrd WHERE data_template_id=? AND local_data_id=0',[$pair['data_template_id']]);icct_mib_write('INSERT INTO snmp_query_graph_rrd(snmp_query_graph_id,data_template_id,data_template_rrd_id,snmp_field_name) VALUES(?,?,?,?)',[$queryGraph,$pair['data_template_id'],$rrd,'source'.$i]);$pair['snmp_query_id']=$query;$pair['snmp_query_graph_id']=$queryGraph;unset($pair);}unset($entry);
    }
    return $entries;
}

/** Expose this user's active prepared work without treating it as saved Cacti data. */
function icct_mib_staging_pending($bundleId){
    $latest=null;
    foreach($_SESSION as $key=>$draft){
        if(!str_starts_with((string)$key,'icct_mib_generation_')||!is_array($draft)||!empty($draft['committed'])||($draft['id']??'')!==$bundleId||($draft['phase']??'')!=='ready')continue;
        $token=substr($key,strlen('icct_mib_generation_'));if(!preg_match('/^[a-f0-9]{32}$/D',$token)||time()-($draft['last_access']??$draft['time']??0)>14400)continue;
        $work=icct_mib_staging_work($draft);if(empty($work['plans'][2]['records']))continue;
        $candidate=['token'=>$token,'stage'=>$work['stage'],'data_sources'=>count($work['plans'][2]['records']),'graph_templates'=>count($work['results'][3]??[]),'last_access'=>$draft['last_access']??$draft['time']];
        if(!$latest||$candidate['last_access']>$latest['last_access'])$latest=$candidate;
    }
    return $latest;
}

/** Finish becomes available only for a persisted native device template. */
function icct_mib_can_finish($bundle){
    return (int)($bundle['wizard_step']??0)>=3&&!empty($bundle['host_template_id'])&&(bool)db_fetch_cell_prepared('SELECT id FROM host_template WHERE id=?',[(int)$bundle['host_template_id']]);
}
function icct_mib_saved_destination($bundle,$token=''){
    $base='protocols/snmp/mibs/controllers/mib_repository.php';
    return icct_mib_can_finish($bundle)&&$token!==''?$base.'?generate='.rawurlencode($bundle['id']).'&stage=4&review_prepared=1&ready='.rawurlencode($token).'&finish=1':$base.'?saved=1';
}
