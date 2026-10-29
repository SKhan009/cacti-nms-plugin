<?php
/** Saved-template editing keeps native identities and device RRD bindings. */
function icct_mib_edit_revision_state($bundle){
    $state=[db_fetch_assoc_prepared('SELECT meta_key,meta_value FROM plugin_icct_nms_meta WHERE meta_key=? OR meta_key LIKE ? ORDER BY meta_key',['mib_repository_'.$bundle['id'],'mib_rows_'.$bundle['id'].'_%'])];
    foreach(array_unique(array_column($bundle['rows'],'data_template_id')) as $id){
        $state[]=db_fetch_row_prepared('SELECT * FROM data_template WHERE id=?',[$id]);
        $state[]=db_fetch_row_prepared('SELECT * FROM data_template_data WHERE data_template_id=? AND local_data_id=0',[$id]);
        $state[]=db_fetch_assoc_prepared('SELECT * FROM data_template_rrd WHERE data_template_id=? AND local_data_id=0 ORDER BY id',[$id]);
        $state[]=db_fetch_assoc_prepared('SELECT did.* FROM data_input_data did JOIN data_template_data d ON d.id=did.data_template_data_id WHERE d.data_template_id=? AND d.local_data_id=0 ORDER BY did.data_input_field_id',[$id]);
    }
    foreach(array_unique(array_column($bundle['rows'],'graph_template_id')) as $id){
        $state[]=db_fetch_row_prepared('SELECT * FROM graph_templates WHERE id=?',[$id]);
        $state[]=db_fetch_row_prepared('SELECT * FROM graph_templates_graph WHERE graph_template_id=? AND local_graph_id=0',[$id]);
        $state[]=db_fetch_assoc_prepared('SELECT * FROM graph_templates_item WHERE graph_template_id=? AND local_graph_id=0 ORDER BY sequence,id',[$id]);
    }
    $id=(int)$bundle['host_template_id'];
    $state[]=db_fetch_row_prepared('SELECT * FROM host_template WHERE id=?',[$id]);
    $state[]=db_fetch_assoc_prepared('SELECT * FROM host_template_graph WHERE host_template_id=? ORDER BY graph_template_id',[$id]);
    $state[]=db_fetch_assoc_prepared('SELECT * FROM host_template_snmp_query WHERE host_template_id=? ORDER BY snmp_query_id',[$id]);
    return $state;
}

function icct_mib_edit_revision($bundle){return hash('sha256',json_encode(icct_mib_edit_revision_state($bundle),JSON_THROW_ON_ERROR));}

function icct_mib_edit_open($bundle,$token=null){
    icct_backend_require_management(3);
    $selected=[];$records=[];
    foreach($bundle['rows'] as $row){
        if(!isset($row['object_index'],$row['settings']))throw new InvalidArgumentException('This older template has no saved field definitions. Review it in Cacti.');
        $r=$row['settings'];$id=(int)$row['data_template_id'];
        $data=db_fetch_row_prepared('SELECT dt.name,d.* FROM data_template dt JOIN data_template_data d ON d.data_template_id=dt.id AND d.local_data_id=0 WHERE dt.id=?',[$id]);
        $rrd=db_fetch_row_prepared('SELECT * FROM data_template_rrd WHERE data_template_id=? AND local_data_id=0',[$id]);
        if(!$data||!$rrd)throw new InvalidArgumentException('A saved data template was removed. Reload the repository.');
        $r['data_name']=db_fetch_cell_prepared('SELECT name FROM data_template WHERE id=?',[$id]);
        $r['active']=$data['active']==='on'?'1':'0';$r['profile_id']=(int)$data['data_source_profile_id'];
        if(($r['resolved_method']??'get')==='get'){
            $nativeOid=db_fetch_cell_prepared("SELECT did.value FROM data_input_data did JOIN data_input_fields f ON f.id=did.data_input_field_id WHERE did.data_template_data_id=? AND f.data_name='oid'",[$data['id']]);
            if($nativeOid)$r['oid']=ltrim($nativeOid,'.');
            if(!empty($r['table']))$r['instance_index']=substr($r['oid'],strlen($r['base_oid'])+1);
        }
        $r['min']=$rrd['rrd_minimum'];$r['max']=$rrd['rrd_maximum'];$r['ds_type']=(int)$rrd['data_source_type_id'];
        $graph=db_fetch_row_prepared('SELECT g.*,t.name,t.multiple,t.test_source FROM graph_templates t JOIN graph_templates_graph g ON g.graph_template_id=t.id AND g.local_graph_id=0 WHERE t.id=?',[(int)$row['graph_template_id']]);
        if($graph){
            $r['graph_name']=$graph['name'];$r['graph_options']=array_intersect_key($graph,icct_mib_core_graph_fields());
        }
        $selected[$row['object_index']]=1;$records[$row['object_index']]=$r;
    }
    if(!$records)throw new InvalidArgumentException('No saved templates are available to edit.');
    $token=$token??icct_mib_generation_draft_put($bundle['id'],'ready',['values'=>['selected'=>$selected,'records'=>$records],'object'=>null]);
    $draft=icct_mib_generation_draft_get($token,$bundle['id'],'ready');
    $draft['review']=['values'=>['selected'=>$selected,'records'=>$records],'object'=>null];
    $draft['saved_edit']=true;unset($draft['saved_readonly'],$draft['committed']);
    $draft['edit_revision']=icct_mib_edit_revision($bundle);
    $draft['device_template']=icct_mib_device_core_defaults($bundle,array_values($records));
    // Represent saved MIB graphs once, by their prepared group, rather than twice as core IDs.
    $owned=array_map(fn($id)=>'core:'.$id,array_unique(array_column($bundle['rows'],'graph_template_id')));
    $draft['device_template']['graphs']=array_values(array_diff($draft['device_template']['graphs'],$owned));
    if(!empty($bundle['host_template_id'])){
        $actualGraphs=array_map('intval',array_column(db_fetch_assoc_prepared('SELECT graph_template_id FROM host_template_graph WHERE host_template_id=?',[$bundle['host_template_id']]),'graph_template_id'));
        $actualQueries=array_map('intval',array_column(db_fetch_assoc_prepared('SELECT snmp_query_id FROM host_template_snmp_query WHERE host_template_id=?',[$bundle['host_template_id']]),'snmp_query_id'));
        $chosen=[];$ownedIds=array_map('intval',array_column($bundle['rows'],'graph_template_id'));
        foreach($actualGraphs as $gid)if(!in_array($gid,$ownedIds,true))$chosen[]='core:'.$gid;
        foreach($bundle['rows'] as $row)if(in_array((int)$row['graph_template_id'],$actualGraphs,true)||(!empty($row['snmp_query_id'])&&in_array((int)$row['snmp_query_id'],$actualQueries,true)))$chosen[]=$records[$row['object_index']]['graph_name'];
        $draft['device_template']['graphs']=array_values(array_unique($chosen));$draft['device_template']['queries']=$actualQueries;
    }
    $work=['stage'=>!empty($bundle['host_template_id'])?4:3,'results'=>[],'plans'=>[]];
    foreach([2,3,4] as $stage){
        if($stage>$work['stage'])break;
        $plan=icct_mib_staging_plan($bundle,$draft,$stage);$work['plans'][$stage]=$plan;
        $work['results'][$stage]=icct_mib_generation_results($bundle,$stage);
    }
    $draft['work']=$work;$_SESSION['icct_mib_generation_'.$token]=$draft;
    return $token;
}

function icct_mib_edit_name($name,$table,$id){
    if(!in_array($table,['data_template','graph_templates','host_template'],true))throw new InvalidArgumentException('Invalid template type.');
    if(db_fetch_cell_prepared('SELECT id FROM '.$table.' WHERE name=? AND id<>? LIMIT 1',[$name,$id]))throw new InvalidArgumentException('Another Cacti template already uses the name '.$name.'.');
}

/** Replace graph definitions while preserving the graph/template IDs and local RRD IDs. */
function icct_mib_edit_graph($group,$existing){
    $target=(int)$existing[$group['records'][0]['symbol']]['graph_template_id'];
    if(!$target||!db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE id=?',[$target]))throw new InvalidArgumentException('A graph template was removed. Reload the repository.');
    icct_mib_edit_name($group['name'],'graph_templates',$target);
    $bindings=[];
    foreach(db_fetch_assoc_prepared('SELECT DISTINCT i.local_graph_id,r.local_data_template_rrd_id,r.id FROM graph_templates_item i JOIN data_template_rrd r ON r.id=i.task_item_id WHERE i.graph_template_id=? AND i.local_graph_id>0',[$target]) as $binding)$bindings[$binding['local_graph_id']][$binding['local_data_template_rrd_id']]=$binding['id'];
    $entries=icct_mib_native_graph_group($group,$existing);$temporary=(int)$entries[0]['pair']['graph_template_id'];
    $details=db_fetch_row_prepared('SELECT * FROM graph_templates_graph WHERE graph_template_id=? AND local_graph_id=0',[$temporary]);
    $details['id']=(int)db_fetch_cell_prepared('SELECT id FROM graph_templates_graph WHERE graph_template_id=? AND local_graph_id=0',[$target]);$details['graph_template_id']=$target;
    if(!sql_save($details,'graph_templates_graph'))throw new RuntimeException('Could not save graph settings.');
    $flags=db_fetch_row_prepared('SELECT multiple,test_source FROM graph_templates WHERE id=?',[$temporary]);
    icct_mib_write('UPDATE graph_templates SET name=?,multiple=?,test_source=? WHERE id=?',[$group['name'],$flags['multiple'],$flags['test_source'],$target]);
    icct_mib_write('DELETE d FROM graph_template_input_defs d JOIN graph_template_input i ON i.id=d.graph_template_input_id WHERE i.graph_template_id=?',[$target]);
    icct_mib_write('DELETE FROM graph_template_input WHERE graph_template_id=?',[$target]);
    icct_mib_write('DELETE FROM graph_templates_item WHERE graph_template_id=? AND local_graph_id=0',[$target]);
    icct_mib_write('UPDATE graph_templates_item SET graph_template_id=? WHERE graph_template_id=?',[$target,$temporary]);
    icct_mib_write('UPDATE graph_template_input SET graph_template_id=? WHERE graph_template_id=?',[$target,$temporary]);
    $faults=json_decode((string)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['graph_fault_presets_'.$temporary]),true)?:[];
    foreach($faults as &$fault)$fault['template_id']=$target;unset($fault);
    icct_mib_write('REPLACE INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',['graph_fault_presets_'.$target,json_encode($faults,JSON_THROW_ON_ERROR)]);
    icct_mib_write('DELETE FROM plugin_icct_nms_meta WHERE meta_key=?',['graph_fault_presets_'.$temporary]);
    icct_mib_write('DELETE FROM graph_templates_graph WHERE graph_template_id=?',[$temporary]);
    icct_mib_write('DELETE FROM graph_templates WHERE id=?',[$temporary]);
    push_out_graph($details['id']);
    $items=db_fetch_assoc_prepared('SELECT * FROM graph_templates_item WHERE graph_template_id=? AND local_graph_id=0 ORDER BY sequence,id',[$target]);
    foreach(db_fetch_assoc_prepared('SELECT id FROM graph_local WHERE graph_template_id=?',[$target]) as $graph){
        $rows=[];
        foreach($items as $item){
            $nativeId=$item['task_item_id'];$item['local_graph_template_item_id']=$item['id'];$item['id']=0;$item['hash']='';$item['local_graph_id']=$graph['id'];
            if($nativeId&&!isset($bindings[$graph['id']][$nativeId]))throw new RuntimeException('An existing graph has a custom data-source binding. Review it in Cacti before changing its template.');
            $item['task_item_id']=$nativeId?$bindings[$graph['id']][$nativeId]:0;$rows[]=$item;
        }
        icct_mib_write('DELETE FROM graph_templates_item WHERE local_graph_id=?',[$graph['id']]);
        foreach($rows as $item)if(!sql_save($item,'graph_templates_item'))throw new RuntimeException('Could not update graph items.');
        api_reapply_suggested_graph_title($graph['id']);
    }
    return $target;
}

function icct_mib_edit_save($id,$token,$stage){
    global $config;
    icct_backend_require_management(3);
    $draft=icct_mib_generation_draft_get($token,$id,'ready');
    if(empty($draft['saved_edit'])||!in_array($stage,[2,3,4],true))throw new InvalidArgumentException('Open a saved template in Edit mode first.');
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,10)',['icct_mib_templates'])!==1)throw new RuntimeException('Template saving is busy. Retry shortly.');
    $hosts=[];$tunes=[];
    try{
        $bundle=icct_mib_wizard_get($id);
        if(!hash_equals($draft['edit_revision'],icct_mib_edit_revision($bundle)))throw new InvalidArgumentException('These templates changed in another session. Reopen Edit to load the latest values.');
        $plan=icct_mib_staging_plan($bundle,$draft,$stage);$existing=array_column($bundle['rows'],null,'object_index');
        if(count($plan['records'])!==count($existing))throw new InvalidArgumentException('Edit preserves the saved OID selection. Use Create to add new OIDs.');
        $bySymbol=[];$graphGroups=[];$names=[];
        foreach($existing as $row){$bySymbol[$row['settings']['symbol']]=$row;$graphGroups[$row['graph_template_id']][]=$row['settings']['symbol'];}
        foreach($plan['records'] as $record){
            $row=$bySymbol[$record['symbol']]??null;if(!$row)throw new InvalidArgumentException('The saved OID selection changed. Reopen Edit.');
            $names[]=strtolower($record['data_name']);icct_mib_edit_name($record['data_name'],'data_template',$row['data_template_id']);
            if($stage===2){
                if($record['resolved_method']!==($row['settings']['resolved_method']??'get'))throw new InvalidArgumentException('Changing collection method requires a new template. Existing instance bindings are preserved in Edit.');
                $locals=db_fetch_assoc_prepared('SELECT id,host_id FROM data_local WHERE data_template_id=?',[$row['data_template_id']]);
                $oldProfile=(int)db_fetch_cell_prepared('SELECT data_source_profile_id FROM data_template_data WHERE data_template_id=? AND local_data_id=0',[$row['data_template_id']]);
                if($locals&&$oldProfile!==$record['profile_id'])throw new InvalidArgumentException('A polling profile change needs RRD migration for existing devices. Keep the current profile or create a new template.');
                if($record['resolved_method']==='get'&&db_fetch_cell_prepared("SELECT dt.id FROM data_template dt JOIN data_template_data d ON d.data_template_id=dt.id AND d.local_data_id=0 JOIN data_input_data did ON did.data_template_data_id=d.id JOIN data_input_fields f ON f.id=did.data_input_field_id WHERE f.data_name='oid' AND TRIM(LEADING '.' FROM did.value)=? AND dt.id<>? LIMIT 1",[$record['oid'],$row['data_template_id']]))throw new InvalidArgumentException('Another template already polls this OID. Keep its current instance or use the saved template.');
                foreach($locals as $local)$hosts[(int)$local['host_id']]=true;
            }
        }
        if(count($names)!==count(array_unique($names)))throw new InvalidArgumentException('Data source template names must be unique.');
        if($stage===3)foreach(icct_mib_graph_groups($plan['records']) as $group){
            $members=array_column($group['records'],'symbol');$target=$bySymbol[$members[0]]['graph_template_id'];$old=$graphGroups[$target];sort($members);sort($old);
            if($members!==$old)throw new InvalidArgumentException('Edit preserves graph source groups. Create a new graph template to split or combine sources.');
            icct_mib_edit_name($group['name'],'graph_templates',$target);
        }
        if($stage===4)icct_mib_edit_name($plan['device_template']['name'],'host_template',(int)$bundle['host_template_id']);
        if(!db_execute('START TRANSACTION'))throw new RuntimeException('Cannot begin saving templates.');
        try{
            if($stage===2)foreach($plan['records'] as $record){
                $row=$bySymbol[$record['symbol']];$tid=(int)$row['data_template_id'];
                $record['fault_presets']=icct_mib_fault_presets($draft['review']['values']['records'][$row['object_index']]['fault_presets']??[]);
                $record['fault_presets_reviewed']=$draft['review']['values']['records'][$row['object_index']]['fault_presets_reviewed']??($row['settings']['fault_presets_reviewed']??'0');
                $rrd=db_fetch_row_prepared('SELECT * FROM data_template_rrd WHERE data_template_id=? AND local_data_id=0',[$tid]);
                icct_mib_native_metric($record,['data'=>true,'graph'=>false,'preserve_query'=>true],$row);
                // A parameter label is not an RRD schema rename: retain its stable DS key and history.
                icct_mib_write('UPDATE data_template_rrd SET data_source_name=? WHERE id=?',[$rrd['data_source_name'],$rrd['id']]);
                icct_mib_write('UPDATE data_template SET name=? WHERE id=?',[$record['data_name'],$tid]);
                $dataId=(int)db_fetch_cell_prepared('SELECT id FROM data_template_data WHERE data_template_id=? AND local_data_id=0',[$tid]);
                push_out_data_source($dataId);push_out_data_source_item($rrd['id']);push_out_data_source_custom_data($tid);
                foreach(db_fetch_assoc_prepared('SELECT id FROM data_local WHERE data_template_id=?',[$tid]) as $local){
                    api_reapply_suggested_data_source_data($local['id']);
                    $tunes[]=['id'=>(int)$local['id'],'name'=>$rrd['data_source_name'],'record'=>$record];
                }
                $index=$row['object_index'];$row['settings']=array_replace($row['settings'],$record);$row['oid']=$record['oid'];$row['name']=$record['label'];$existing[$index]=$row;
            }
            if($stage===3)foreach(icct_mib_graph_groups($plan['records']) as $group){
                $target=icct_mib_edit_graph($group,$bySymbol);
                foreach($group['records'] as $record){$row=$bySymbol[$record['symbol']];$oldName=$row['settings']['graph_name'];$row['settings']=$record;$existing[$row['object_index']]=$row;if(isset($bundle['device_template']['graphs']))$bundle['device_template']['graphs']=array_map(fn($name)=>$name===$oldName?$record['graph_name']:$name,$bundle['device_template']['graphs']);}
                icct_mib_write('UPDATE snmp_query_graph SET name=? WHERE graph_template_id=?',[substr($group['name'],0,100),$target]);
            }
            if($stage===2){
                // Faults belong to the data source editor but are keyed by native graph/RRD identity.
                $rules=[];
                foreach($existing as $row){$rrd=(int)db_fetch_cell_prepared('SELECT id FROM data_template_rrd WHERE data_template_id=? AND local_data_id=0',[$row['data_template_id']]);icct_mib_write('DELETE FROM plugin_icct_nms_meta WHERE meta_key=?',['graph_fault_presets_'.$row['graph_template_id']]);icct_mib_store_fault_presets($row['graph_template_id'],$rrd,$row['settings']);$stored=json_decode((string)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['graph_fault_presets_'.$row['graph_template_id']]),true)?:[];$rules[$row['graph_template_id']]=array_merge($rules[$row['graph_template_id']]??[],$stored);}
                foreach(array_keys($graphGroups) as $gid)icct_mib_write('REPLACE INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',['graph_fault_presets_'.$gid,json_encode($rules[$gid]??[],JSON_THROW_ON_ERROR)]);
            }
            if($stage===4){
                $device=$plan['device_template'];$hostId=(int)$bundle['host_template_id'];
                if(!$hostId)$hostId=(int)sql_save(['id'=>0,'hash'=>get_hash_host_template(0),'name'=>$device['name']],'host_template');
                if(!$hostId||!sql_save(['id'=>$hostId]+array_intersect_key($device,array_flip(['name','class'])),'host_template'))throw new RuntimeException('Could not save device template.');
                $graphs=$device['core_graphs'];$queries=$device['queries'];
                foreach($existing as $row)if(in_array($row['settings']['graph_name'],$device['graphs'],true)){if($row['snmp_query_id'])$queries[]=(int)$row['snmp_query_id'];else $graphs[]=(int)$row['graph_template_id'];}
                icct_mib_write('DELETE FROM host_template_graph WHERE host_template_id=?',[$hostId]);
                icct_mib_write('DELETE FROM host_template_snmp_query WHERE host_template_id=?',[$hostId]);
                foreach(array_unique($graphs) as $gid)icct_mib_write('INSERT INTO host_template_graph(host_template_id,graph_template_id) VALUES(?,?)',[$hostId,$gid]);
                foreach(array_unique($queries) as $qid)icct_mib_write('INSERT INTO host_template_snmp_query(host_template_id,snmp_query_id) VALUES(?,?)',[$hostId,$qid]);
                $bundle['host_template_id']=$hostId;$bundle['device_template']=$device;$bundle['wizard_step']=3;
            }
            $bundle['rows']=array_values($existing);icct_mib_wizard_store($bundle);
            if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit template changes.');
        }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
        // RRDtool execution closes Cacti's PHP session. Refresh the saved editor first.
        icct_mib_edit_open($bundle,$token);
        // Native poller cache and RRD tuning preserve IDs, paths, and recorded samples.
        require_once $config['base_path'].'/lib/rrd.php';
        foreach($tunes as $tune){
            $path=get_data_source_path($tune['id'],true);if(!is_file($path))continue;$r=$tune['record'];
            $types=[1=>'GAUGE',2=>'COUNTER',3=>'DERIVE',4=>'ABSOLUTE'];
            $command='tune '.cacti_escapeshellarg($path);
            foreach(['heartbeat'=>$r['heartbeat'],'minimum'=>$r['min'],'maximum'=>$r['max'],'data-source-type'=>$types[$r['ds_type']]] as $key=>$value)$command.=' --'.$key.' '.cacti_escapeshellarg($tune['name'].':'.$value);
            $error=rrdtool_execute($command,false,RRDTOOL_OUTPUT_STDERR);
            if($error)throw new RuntimeException('Template saved, but RRD tuning needs attention: '.strip_tags($error));
        }
        foreach(array_keys($hosts) as $host)push_out_host($host);
        set_config_option('time_last_change_graph',time());set_config_option('time_last_change_data_source',time());
        return $bundle;
    }finally{db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',['icct_mib_templates']);}
}
