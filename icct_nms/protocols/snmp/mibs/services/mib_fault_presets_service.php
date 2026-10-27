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
