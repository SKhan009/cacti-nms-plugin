<?php
/** Repository browser presents saved MIB definitions and native Cacti template state. */
function icct_mib_browser_tree($records){
    $root=['label'=>'MIB Tree','children'=>[],'records'=>[]];
    $labels=['1'=>'iso','1.3'=>'org','1.3.6'=>'dod','1.3.6.1'=>'internet','1.3.6.1.2'=>'mgmt','1.3.6.1.2.1'=>'mib-2','1.3.6.1.4'=>'private','1.3.6.1.4.1'=>'enterprises','1.3.6.1.2.1.1'=>'System','1.3.6.1.2.1.2'=>'Interfaces','1.3.6.1.2.1.3'=>'at','1.3.6.1.2.1.4'=>'ip','1.3.6.1.2.1.5'=>'icmp'];
    foreach($records as $id=>$record){
        if(!preg_match('/^[0-9]+(?:\.[0-9]+)*$/D',$record['base_oid']??''))continue;
        $node=&$root;$prefix='';
        foreach(explode('.',$record['base_oid']) as $part){
            $prefix.=($prefix===''?'':'.').$part;
            if(!isset($node['children'][$part]))$node['children'][$part]=['label'=>$labels[$prefix]??$part,'children'=>[],'records'=>[]];
            $node=&$node['children'][$part];
        }
        $node['records'][]=$id;$node['label']=$record['name'];unset($node);
    }
    $compress=function($node)use(&$compress){
        uksort($node['children'],fn($a,$b)=>(int)$a<=>(int)$b);
        foreach($node['children'] as &$child){
            while(!$child['records']&&count($child['children'])===1){$next=reset($child['children']);$next['label']=$child['label'].'.'.$next['label'];$child=$next;}
            $child=$compress($child);
        }unset($child);return $node;
    };
    return $compress($root);
}
function icct_mib_browser_data($bundles){
    $records=[];$warnings=[];
    $native=array_fill_keys(array_column(db_fetch_assoc('SELECT id FROM data_template')?:[],'id'),true);
    foreach($bundles as $bundle){
        if(!empty($bundle['parse_error']))$warnings[]=$bundle['name'].': '.$bundle['parse_error'];
        try{$objects=icct_mib_objects($bundle);}
        catch(Throwable $e){$warnings[]=$bundle['name'].': '.$e->getMessage();continue;}
        $texts=[];
        foreach($bundle['files'] as $i=>$file){try{$texts[$file['module']]=icct_mib_file($bundle,$i);}catch(Throwable $e){$warnings[]=$file['name'].': '.$e->getMessage();}}
        foreach($objects as $objectIndex=>$object){
            [$module,$name]=explode('::',$object['symbol'],2);$id=count($records);$created=false;
            foreach($bundle['rows'] as $row)if(($row['symbol']??'')===$object['symbol']||($row['settings']['symbol']??'')===$object['symbol']||($row['oid']??'')===($object['oid']??'')){
                if(!empty($row['data_template_id'])&&isset($native[$row['data_template_id']]))$created=true;
            }
            $definition='';$text=$texts[$module]??'';
            if(preg_match('/\b'.preg_quote($name,'/').'\s+'.preg_quote($object['kind'],'/').'\b(.*?)::=/s',$text,$match))$definition=$match[1];
            preg_match('/\bSTATUS\s+([A-Za-z-]+)/',$definition,$status);
            preg_match('/\bDEFVAL\s*\{(.*?)\}/s',$definition,$default);
            preg_match('/\b(?:INDEX|AUGMENTS)\s*\{(.*?)\}/s',$definition,$indexes);
            $records[]=$object+['name'=>$name,'module'=>$module,'bundle'=>$bundle['id'],'object_index'=>$objectIndex,'created'=>$created,
                'status'=>$status[1]??'','defval'=>isset($default[1])?trim($default[1]):'',
                'indexes'=>isset($indexes[1])?trim(preg_replace('/\s+/',' ',$indexes[1])):(!empty($object['table'])?($object['index_description']??''):'')];
        }
    }
    $existing=icct_mib_existing_source_indexes($records);$unique=[];
    foreach($records as $i=>$record){
        if(isset($existing[$i]))$record['created']=true;
        $key=$record['symbol'].'|'.$record['base_oid'];
        if(!isset($unique[$key])||$record['created'])$unique[$key]=$record;
    }
    $records=array_values($unique);
    foreach($records as $i=>&$record){
        $targets=icct_mib_creation_targets($records,$i);$created=0;foreach($targets as $target)if(!empty($records[$target]['created']))$created++;
        $record['source_count']=count($targets);$record['created_count']=$created;$record['can_create']=count($targets)>$created;
        $record['source_state']=!$targets?'unsupported':($created===count($targets)?'exists':($created?'partial':'missing'));
        $record['source_status']=['unsupported'=>'Data Source Not Supported','exists'=>'Data Source Exists','partial'=>'Data Sources Partly Created','missing'=>'Data Source Not Created'][$record['source_state']];
    }unset($record);
    // Columns inherit their row's INDEX/AUGMENTS declaration from the saved MIB.
    foreach($records as &$record)if($record['indexes']===''){
        $closest='';
        foreach($records as $parent)if($parent['module']===$record['module']&&$parent['indexes']!==''&&str_starts_with($record['base_oid'],$parent['base_oid'].'.')&&strlen($parent['base_oid'])>strlen($closest)){$record['indexes']=$parent['indexes'];$closest=$parent['base_oid'];}
    }unset($record);
    return ['records'=>$records,'tree'=>icct_mib_browser_tree($records),'warnings'=>$warnings];
}

/** Match saved native SNMP definitions without creating or modifying templates. */
function icct_mib_existing_source_indexes($records){
    $sources=db_fetch_assoc("SELECT DISTINCT dt.id AS data_template_id,did.value AS oid FROM data_template dt JOIN data_template_data dtd ON dtd.data_template_id=dt.id JOIN data_input_data did ON did.data_template_data_id=dtd.id JOIN data_input_fields f ON f.id=did.data_input_field_id WHERE f.data_name='oid' AND did.value<>''")?:[];
    $sources=array_merge($sources,db_fetch_assoc('SELECT DISTINCT dl.data_template_id,pi.arg1 AS oid FROM poller_item pi JOIN data_local dl ON dl.id=pi.local_data_id JOIN data_template dt ON dt.id=dl.data_template_id WHERE pi.action=0')?:[]);
    $native=array_fill_keys(array_column(db_fetch_assoc('SELECT id FROM data_template')?:[],'id'),true);
    foreach(icct_mib_list() as $bundle)foreach($bundle['rows'] as $row)if(!empty($row['data_template_id'])&&isset($native[$row['data_template_id']]))$sources[]=['data_template_id'=>$row['data_template_id'],'oid'=>$row['oid']??''];
    $found=[];
    foreach($records as $i=>$record){
        if(empty($record['numeric']))continue;
        $base=$record['base_oid']??'';if($base==='')continue;
        foreach($sources as $source){
            $oid=ltrim(trim((string)($source['oid']??'')),'.');
            $matches=!empty($record['table'])?($oid===$base||(str_starts_with($oid,$base.'.')&&preg_match('/^[0-9]+(?:\.[0-9]+)*$/D',substr($oid,strlen($base)+1)))):$oid===$base.'.0';
            if($matches){$found[$i]=(int)$source['data_template_id'];break;}
        }
    }
    return $found;
}
function icct_mib_upload_summary($preview){
    $records=$preview['records'];$supported=count(array_filter($records,fn($record)=>!empty($record['numeric'])));
    $existing=icct_mib_existing_source_indexes($records);
    return ['name'=>implode(', ',array_column($preview['files'],'module')),'object_count'=>count($records),'supported_count'=>$supported,'existing_count'=>count($existing),'can_configure'=>empty($preview['parse_error'])&&$supported>count($existing),'parse_error'=>$preview['parse_error']??''];
}

/** A table/entry opens the supported columns beneath it; a leaf opens itself. */
function icct_mib_creation_targets($objects,$index){
    if(!is_int($index)||!isset($objects[$index]))throw new InvalidArgumentException('Choose a valid MIB object.');
    $parent=$objects[$index];$module=explode('::',$parent['symbol'])[0];$prefix=($parent['base_oid']??'').'.';$targets=[];
    foreach($objects as $i=>$object)if(!empty($object['numeric'])&&($i===$index||(explode('::',$object['symbol'])[0]===$module&&str_starts_with($object['base_oid']??'',$prefix))))$targets[]=$i;
    return $targets;
}
