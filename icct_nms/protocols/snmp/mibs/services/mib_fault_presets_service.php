<?php
/** Template-owned alarms use explicit thresholds, independently of RRD bounds. */
function icct_mib_fault_presets($input){
    if(is_string($input))$input=json_decode($input,true,16,JSON_THROW_ON_ERROR);
    if(!is_array($input)||count($input)>8)throw new InvalidArgumentException('Use no more than eight fault presets per OID.');
    $out=[];$names=[];
    foreach($input as $rule){
        if(!is_array($rule))throw new InvalidArgumentException('Invalid fault preset.');
        $threshold=$rule['threshold']??'';
        if($threshold===''||$threshold===null){if(!empty($rule['enabled']))throw new InvalidArgumentException('Enter a numeric threshold for each enabled fault preset.');continue;}
        if(!is_scalar($threshold)||!is_numeric($threshold)||!is_finite((float)$threshold))throw new InvalidArgumentException('Fault thresholds must be finite numbers.');
        $condition=$rule['condition']??'above';if(!in_array($condition,['above','below','at_or_above','at_or_below','equals','not_equals'],true))throw new InvalidArgumentException('Choose a valid fault comparator.');
        $severity=$rule['severity']??'Major';if(!in_array($severity,['Information','Minor','Warning','Major','Critical'],true))throw new InvalidArgumentException('Choose a valid fault severity.');
        $clean=[];foreach(['name'=>120,'description'=>1000,'corrective_action'=>1000] as $key=>$limit){$value=$rule[$key]??'';if(!is_string($value)||strlen($value)>$limit||str_contains($value,"\0"))throw new InvalidArgumentException('Invalid fault preset '.$key.'.');$clean[$key]=trim($value);}
        if($clean['name']==='')throw new InvalidArgumentException('Enter a fault preset name.');
        $key=strtolower($clean['name']);if(isset($names[$key]))throw new InvalidArgumentException('Fault preset names must be unique per OID.');$names[$key]=true;
        $out[]=$clean+['threshold'=>(float)$threshold,'condition'=>$condition,'severity'=>$severity,'enabled'=>!empty($rule['enabled'])];
    }
    return $out;
}
function icct_mib_store_fault_presets($graphId,$metricId,$record){
    $rules=[];
    foreach($record['fault_presets']??[] as $i=>$preset){
        $lower=in_array($preset['condition'],['below','at_or_below'],true);
        $rules[]=['template_id'=>(int)$graphId,'metric_id'=>(int)$metricId,'source'=>'rrd','parameter'=>substr($record['label'],0,120),'oid'=>'','units'=>$record['units'],'scale'=>1,'minimum'=>$lower?$preset['threshold']:null,'maximum'=>$lower?null:$preset['threshold'],'name'=>$preset['name'],'description'=>$preset['description'],'corrective_action'=>$preset['corrective_action'],'condition'=>$preset['condition'],'severity'=>$preset['severity'],'enabled'=>$preset['enabled'],'email'=>false,'audio'=>false,'email_recipients'=>'','preset_key'=>hash('sha256',$graphId.':'.$metricId.':'.$i)];
    }
    if($rules)icct_mib_write('REPLACE INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',['graph_fault_presets_'.$graphId,json_encode($rules,JSON_THROW_ON_ERROR)]);
}

/** Read saved presets by native data-source/graph association, never by label. */
function icct_mib_saved_fault_presets($record,$bundle){
    $rules=[];
    foreach($bundle['rows']??[] as $row){
        if(($row['oid']??'')!==$record['oid'])continue;
        foreach($row['settings']['fault_presets']??[] as $rule)$rules[]= $rule;
    }
    $sources=icct_mib_existing_source_indexes([$record]);
    if(isset($sources[0])){
        $stored=db_fetch_assoc_prepared("SELECT DISTINCT m.meta_value,r.id AS metric_id FROM graph_templates_item gi JOIN graph_templates gt ON gt.id=gi.graph_template_id JOIN data_template_rrd r ON r.id=gi.task_item_id JOIN plugin_icct_nms_meta m ON m.meta_key=CONCAT('graph_fault_presets_',gt.id) WHERE r.data_template_id=? AND r.local_data_id=0 AND gi.local_graph_id=0",[(int)$sources[0]]);
        foreach($stored?:[] as $source)foreach(json_decode($source['meta_value'],true,32,JSON_THROW_ON_ERROR) as $rule){
            if((int)($rule['metric_id']??0)!==(int)$source['metric_id'])continue;
            $rules[]=$rule;
        }
    }
    $unique=[];
    foreach($rules as $rule)foreach(icct_mib_fault_presets([$rule]) as $preset)$unique[hash('sha256',json_encode($preset,JSON_THROW_ON_ERROR))]=$preset;
    return array_values($unique);
}

/** Suggest discrete fault states declared by a MIB; ranges are never alarm limits. */
function icct_mib_mib_fault_presets($record){
    if(empty($record['numeric']))return [];
    if(!preg_match('/\{([^}]+)\}/s',$record['syntax']??'',$enum))return icct_mib_range_fault_presets($record);
    preg_match_all('/([A-Za-z][A-Za-z0-9_-]*)\s*\(\s*(-?\d+)\s*\)/',$enum[1],$states,PREG_SET_ORDER);
    if(!$states)return [];
    $healthy=function($name){return (bool)preg_match('/^(ok|up|normal|healthy|operational|running|ready|available|enabled|online)$/i',$name);};
    $levels=['critical'=>'Critical','major'=>'Major','minor'=>'Minor','warning'=>'Warning','warn'=>'Warning','information'=>'Information','info'=>'Information'];
    $severityStates=array_values(array_filter($states,fn($state)=>isset($levels[strtolower($state[1])])));
    if(!$severityStates&&!preg_match('/status|state|health/i',$record['label']??$record['symbol']??'')&&!array_filter($states,fn($state)=>$healthy($state[1])))return [];
    $faults=$severityStates?:array_values(array_filter($states,fn($state)=>!$healthy($state[1])));
    if(count($faults)>8)return [];
    return array_map(fn($state)=>['name'=>substr(($record['label']??$record['symbol']).' - '.$state[1],0,120),'condition'=>'equals','threshold'=>(float)$state[2],'severity'=>$levels[strtolower($state[1])]??'Warning','enabled'=>false,'description'=>'Declared MIB state: '.$state[1].' ('.$state[2].') for '.$record['symbol'].'.','corrective_action'=>''],$faults);
}

/** Shared source for both the sidebar indicator and the editable fault cards. */
function icct_mib_review_fault_presets($record,$bundle,$settings){
    $rules=$settings['fault_presets']??[];$suggested=false;
    if(!$rules&&empty($settings['fault_presets_reviewed'])){
        $rules=icct_mib_saved_fault_presets($record,$bundle);
        if(!$rules){$rules=icct_mib_mib_fault_presets($record);$suggested=(bool)$rules;}
    }
    return ['rules'=>array_map(fn($rule)=>icct_mib_fault_context($record,$rule),$rules),'suggested'=>$suggested];
}

/** Fill missing operator text from this MIB object without replacing custom text. */
function icct_mib_fault_context($record,$rule){
    $symbol=$record['symbol'];$oid=$record['oid'];$state='';
    if(($rule['condition']??'')==='equals'&&preg_match_all('/([A-Za-z][A-Za-z0-9_-]*)\s*\(\s*(-?\d+)\s*\)/',$record['syntax']??'',$matches,PREG_SET_ORDER)){
        foreach($matches as $match)if((float)$match[2]===(float)($rule['threshold']??0)){$state=$match[1].' ('.$match[2].')';break;}
    }
    $condition=$state!==''?'Declared MIB state '.$state:'Configured condition '.($rule['condition']??'').' '.($rule['threshold']??'');
    if(trim($rule['description']??'')===''||str_starts_with($rule['description'],'Declared MIB state:')){
        $text=$condition.' for '.$symbol.'. '.trim(preg_replace('/\s+/u',' ',$record['description']??''));
        while(strlen($text)>1000)$text=preg_replace('/.$/us','',$text);
        $rule['description']=trim($text);
    }
    if(trim($rule['corrective_action']??'')===''){
        $rule['corrective_action']='Suggested investigation: review device diagnostics for '.$symbol.($state!==''?' reporting '.$state:'').'; verify the monitored component using the MIB description, address the reported condition, then poll '.$oid.' again to confirm recovery.';
    }
    return $rule;
}

/** Explicit MIB ranges support out-of-range validation, not guessed operating limits. */
function icct_mib_range_fault_presets($record){
    if(!preg_match('/\(\s*(-?\d+)\s*\.\.\s*(-?\d+)\s*\)/',$record['syntax']??'',$range))return [];
    if((float)$range[1]>(float)$range[2])return [];
    $rules=[];
    foreach([['below',$range[1],'Below MIB Minimum'],['above',$range[2],'Above MIB Maximum']] as [$condition,$bound,$title]){
        $rules[]=['name'=>substr(($record['label']??$record['symbol']).' - '.$title,0,120),'condition'=>$condition,'threshold'=>(float)$bound,'severity'=>'Warning','enabled'=>false,'description'=>'Range validation: a reading '.$condition.' '.$bound.' is outside the range '.$range[1].' to '.$range[2].' declared by '.$record['symbol'].'. Review application-specific operating limits separately.','corrective_action'=>''];
    }
    return $rules;
}
