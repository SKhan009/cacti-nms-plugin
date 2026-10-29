<?php
/** Native graph templates are offered by identity, never matched by display name. */
function icct_mib_device_graph_options($bundle,$records){
    $options=[];
    foreach(icct_mib_graph_groups($records) as $group)$options[$group['name']]=['key'=>$group['name'],'name'=>$group['name'],'source'=>'Prepared','sources'=>array_map(fn($r)=>'prepared:'.$r['symbol'],$group['records']),'queries'=>[],'pending_queries'=>($group['records'][0]['resolved_method']??'')==='indexed'?[$group['name']]:[]];
    $native=db_fetch_assoc('SELECT id,name FROM graph_templates ORDER BY name,id')?:[];
    foreach($native as $row){$key='core:'.$row['id'];$options[$key]=['key'=>$key,'name'=>$row['name'],'id'=>(int)$row['id'],'source'=>'Cacti Core','sources'=>[],'queries'=>[],'pending_queries'=>[]];}
    foreach(db_fetch_assoc('SELECT DISTINCT gi.graph_template_id,r.data_template_id FROM graph_templates_item gi JOIN data_template_rrd r ON r.id=gi.task_item_id AND r.local_data_id=0 WHERE gi.local_graph_id=0')?:[] as $source){$key='core:'.$source['graph_template_id'];if(isset($options[$key]))$options[$key]['sources'][]='core:'.$source['data_template_id'];}
    foreach(db_fetch_assoc('SELECT DISTINCT graph_template_id,snmp_query_id FROM snmp_query_graph')?:[] as $row){$key='core:'.$row['graph_template_id'];if(isset($options[$key]))$options[$key]['queries'][]=(int)$row['snmp_query_id'];}
    return $options;
}
/** Prefer saved native Class, then match the saved device type to installed choices. */
function icct_mib_device_auto_class($bundle,$graphIds=[]){
    $field=icct_mib_device_core_fields()['class']??[];$choices=$field['choices']??[];
    $type=icct_nms_device_types()[$bundle['type_id']??'']??[];
    $text=strtolower(trim(($type['name']??$bundle['type_name']??'').' '.($type['icon']??'')));
    $tokens=preg_split('/[^a-z0-9]+/',$text,-1,PREG_SPLIT_NO_EMPTY);$scores=[];
    foreach($choices as $key=>$label){
        $words=preg_split('/[^a-z0-9]+/',strtolower($key.' '.$label),-1,PREG_SPLIT_NO_EMPTY);
        $matches=array_intersect($tokens,$words);if($matches)$scores[$key]=count(array_unique($matches));
    }
    if($scores){$best=max($scores);$matches=array_keys($scores,$best,true);if(count($matches)===1)return (string)$matches[0];}
    $classes=[];
    foreach(array_unique($graphIds) as $id)foreach(db_fetch_assoc_prepared('SELECT DISTINCT ht.class FROM host_template ht JOIN host_template_graph htg ON htg.host_template_id=ht.id WHERE htg.graph_template_id=?',[(int)$id])?:[] as $row)if(isset($choices[$row['class']]))$classes[$row['class']]=true;
    if(count($classes)===1)return (string)array_key_first($classes);
    return (string)($field['default']??'');
}
function icct_mib_device_core_defaults($bundle,$records){
    $name=$bundle['name'];$class='';$queries=[];$chosen=array_column(icct_mib_graph_groups($records),'name');$hostId=(int)($bundle['host_template_id']??0);$graphIds=[];
    if($hostId){$native=db_fetch_row_prepared('SELECT * FROM host_template WHERE id=?',[$hostId]);if($native){$name=$native['name'];$class=$native['class']??'';}$queries=array_map('intval',array_column(db_fetch_assoc_prepared('SELECT snmp_query_id FROM host_template_snmp_query WHERE host_template_id=?',[$hostId])?:[],'snmp_query_id'));
        foreach(db_fetch_assoc_prepared('SELECT graph_template_id FROM host_template_graph WHERE host_template_id=?',[$hostId])?:[] as $row){$graphIds[]=(int)$row['graph_template_id'];$chosen[]='core:'.$row['graph_template_id'];}
    }
    // Existing MIB outputs carry exact native IDs, even for graph-only saves.
    foreach($bundle['rows']??[] as $row){if(!empty($row['graph_template_id'])){$graphIds[]=(int)$row['graph_template_id'];if(!$records)$chosen[]='core:'.$row['graph_template_id'];}if(!empty($row['snmp_query_id']))$queries[]=(int)$row['snmp_query_id'];}
    foreach(array_unique($graphIds) as $graphId)foreach(db_fetch_assoc_prepared('SELECT DISTINCT snmp_query_id FROM snmp_query_graph WHERE graph_template_id=?',[$graphId])?:[] as $row)$queries[]=(int)$row['snmp_query_id'];
    if($class==='')$class=icct_mib_device_auto_class($bundle,$graphIds);
    return ['name'=>$name,'class'=>$class,'queries'=>array_values(array_unique($queries)),'graphs'=>array_values(array_unique($chosen))];
}
function icct_mib_device_graph_selection($bundle,$records,$chosen,$allowEmpty=false){
    $allowed=icct_mib_device_graph_options($bundle,$records);
    if(!is_array($chosen)||(!$chosen&&!$allowEmpty)||array_filter($chosen,fn($key)=>!is_string($key))||array_diff($chosen,array_keys($allowed)))throw new InvalidArgumentException('Select graph templates available in Cacti or this prepared batch.');
    $prepared=[];$core=[];foreach(array_unique($chosen) as $key){if(isset($allowed[$key]['id']))$core[]=$allowed[$key]['id'];else $prepared[]=$key;}
    return ['graphs'=>$prepared,'core_graphs'=>$core];
}

function icct_mib_device_core_fields(){
    global $config,$fields_host_template_edit;
    static $fields=null;if($fields!==null)return $fields;
    if(!isset($fields_host_template_edit)){extract($GLOBALS,EXTR_SKIP);require $config['base_path'].'/include/global_form.php';}
    $columns=array_column(db_fetch_assoc('SHOW COLUMNS FROM host_template'),'Type','Field');$fields=[];
    foreach(['name','class'] as $key){if(!isset($columns[$key],$fields_host_template_edit[$key]))continue;$field=$fields_host_template_edit[$key];preg_match('/(?:var)?char\((\d+)\)/i',$columns[$key],$length);$fields[$key]=['label'=>strip_tags($field['friendly_name']),'help'=>strip_tags($field['description']??''),'choices'=>$field['array']??null,'default'=>$field['default']??'','max_length'=>(int)($length[1]??$field['max_length']??255)];}return $fields;
}
function icct_mib_device_queries(){return array_column(db_fetch_assoc('SELECT id,name FROM snmp_query ORDER BY name,id')?:[],'name','id');}
function icct_mib_device_query_selection($input){
    if(is_string($input))$input=json_decode($input,true,16,JSON_THROW_ON_ERROR);$choices=icct_mib_device_queries();if(!is_array($input))throw new InvalidArgumentException('Invalid data-query selection.');$out=[];foreach($input as $id){if(!is_scalar($id)||!ctype_digit((string)$id)||!isset($choices[(int)$id]))throw new InvalidArgumentException('Choose an installed Cacti data query.');$out[]=(int)$id;}sort($out);return array_values(array_unique($out));
}
function icct_mib_device_details($name,$class){
    $fields=icct_mib_device_core_fields();$out=['name'=>icct_mib_name($name,$fields['name']['max_length'])];if(isset($fields['class'])){$class=(string)$class;if($class!==''&&!array_key_exists($class,$fields['class']['choices']))throw new InvalidArgumentException('Choose a Class from Cacti core.');$out['class']=$class;}return $out;
}

/** Required native queries follow selected graph IDs; prepared indexed queries are created on Add. */
function icct_mib_device_required_queries($bundle,$records,$chosen){
    $options=icct_mib_device_graph_options($bundle,$records);$ids=[];
    foreach($chosen as $key)$ids=array_merge($ids,$options[$key]['queries']??[]);
    $ids=array_values(array_unique($ids));sort($ids);return $ids;
}
