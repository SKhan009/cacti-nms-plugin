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
        foreach($objects as $object){
            [$module,$name]=explode('::',$object['symbol'],2);$id=count($records);$created=false;
            foreach($bundle['rows'] as $row)if(($row['symbol']??'')===$object['symbol']||($row['settings']['symbol']??'')===$object['symbol']||($row['oid']??'')===($object['oid']??'')){
                if(!empty($row['data_template_id'])&&isset($native[$row['data_template_id']]))$created=true;
            }
            $definition='';$text=$texts[$module]??'';
            if(preg_match('/\b'.preg_quote($name,'/').'\s+'.preg_quote($object['kind'],'/').'\b(.*?)::=/s',$text,$match))$definition=$match[1];
            preg_match('/\bSTATUS\s+([A-Za-z-]+)/',$definition,$status);
            preg_match('/\bDEFVAL\s*\{(.*?)\}/s',$definition,$default);
            preg_match('/\b(?:INDEX|AUGMENTS)\s*\{(.*?)\}/s',$definition,$indexes);
            $records[]=$object+['name'=>$name,'module'=>$module,'bundle'=>$bundle['id'],'created'=>$created,
                'status'=>$status[1]??'','defval'=>isset($default[1])?trim($default[1]):'',
                'indexes'=>isset($indexes[1])?trim(preg_replace('/\s+/',' ',$indexes[1])):(!empty($object['table'])?($object['index_description']??''):'')];
        }
    }
    // Columns inherit their row's INDEX/AUGMENTS declaration from the saved MIB.
    foreach($records as &$record)if($record['indexes']===''){
        $closest='';
        foreach($records as $parent)if($parent['module']===$record['module']&&$parent['indexes']!==''&&str_starts_with($record['base_oid'],$parent['base_oid'].'.')&&strlen($parent['base_oid'])>strlen($closest)){$record['indexes']=$parent['indexes'];$closest=$parent['base_oid'];}
    }unset($record);
    return ['records'=>$records,'tree'=>icct_mib_browser_tree($records),'warnings'=>$warnings];
}
