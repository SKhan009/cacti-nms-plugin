<?php
/** Read form definitions and choices from the installed Cacti version. */
function icct_mib_core_graph_fields(){
    global $config,$struct_graph,$fields_graph_template_template_edit;
    static $cache=null;if($cache!==null)return $cache;
    if(!isset($struct_graph)){extract($GLOBALS,EXTR_SKIP);require $config['base_path'].'/include/global_form.php';}
    $columns=array_column(db_fetch_assoc('SHOW COLUMNS FROM graph_templates_graph'),'Type','Field');$section='';$out=[];
    foreach($struct_graph as $key=>$definition){
        if(($definition['method']??'')==='spacer'){$section=strip_tags($definition['friendly_name']);continue;}
        if(!isset($columns[$key])||!in_array($definition['method']??'',['textbox','checkbox','drop_array','drop_sql','radio'],true))continue;
        $choices=$definition['array']??null;
        if(($definition['method']??'')==='radio'){$choices=[];foreach($definition['items'] as $item)$choices[(string)$item['radio_value']]=strip_tags($item['radio_caption']);}
        if(($definition['method']??'')==='drop_sql'){$choices=[];foreach(db_fetch_assoc($definition['sql'])?:[] as $row)$choices[(string)$row['id']]=$row['name'];}
        if(isset($definition['none_value']))$choices=[0=>strip_tags($definition['none_value'])]+($choices??[]);
        preg_match('/(?:var)?char\((\d+)\)/i',$columns[$key],$length);
        $out[$key]=['label'=>trim(preg_replace('/\s*\(--.*$/','',strip_tags($definition['friendly_name']))),'section'=>$section,'method'=>$definition['method'],'choices'=>$choices,'help'=>trim(preg_replace('/\s+/',' ',strip_tags($definition['description']??''))),'max_length'=>(int)($length[1]??$definition['max_length']??255),'default'=>$definition['default']??'','sql_type'=>$columns[$key]];
    }
    foreach(['multiple','test_source'] as $key){$definition=$fields_graph_template_template_edit[$key]??null;if(!$definition)continue;$out[$key]=['label'=>strip_tags($definition['friendly_name']),'section'=>'Template Details','method'=>'checkbox','choices'=>null,'help'=>strip_tags($definition['description']),'max_length'=>2,'default'=>$definition['default']??'','sql_type'=>'char(2)'];}
    if(!$out)throw new RuntimeException('Cacti graph form definitions are unavailable.');return $cache=$out;
}
function icct_mib_core_graph_defaults($name,$units){
    $row=db_fetch_row("SELECT gg.*,gt.multiple,gt.test_source FROM graph_templates_graph gg JOIN graph_templates gt ON gt.id=gg.graph_template_id WHERE gt.name='SNMP - Generic OID Template' AND gg.local_graph_id=0 LIMIT 1");
    if(!$row)throw new RuntimeException('Cacti Generic OID graph template is not installed.');
    $values=[];foreach(icct_mib_core_graph_fields() as $key=>$field)$values[$key]=(string)($row[$key]??$field['default']);
    $values['title']='|host_description| - '.$name;$values['vertical_label']=$units;return $values;
}
function icct_mib_graph_options($input,$name,$units){
    if(is_string($input))$input=json_decode($input,true,16,JSON_THROW_ON_ERROR);
    if(!is_array($input))throw new InvalidArgumentException('Invalid graph options.');
    $fields=icct_mib_core_graph_fields();if(array_diff_key($input,$fields))throw new InvalidArgumentException('Graph options changed in Cacti. Reload this step.');
    $values=icct_mib_core_graph_defaults($name,$units);
    foreach($input as $key=>$value){
        if(!is_scalar($value)||str_contains((string)$value,"\0"))throw new InvalidArgumentException('Invalid '.$fields[$key]['label'].'.');$value=trim((string)$value);$field=$fields[$key];
        if(strlen($value)>$field['max_length'])throw new InvalidArgumentException($field['label'].' is too long.');
        if($field['method']==='checkbox'&&!in_array($value,['','on'],true))throw new InvalidArgumentException('Invalid '.$field['label'].'.');
        if(is_array($field['choices'])&&!array_key_exists($value,$field['choices']))throw new InvalidArgumentException('Choose an installed Cacti '.$field['label'].'.');
        if(preg_match('/^(?:tiny|small|medium|big)?int/i',$field['sql_type'])&&$value!==''&&!ctype_digit($value))throw new InvalidArgumentException($field['label'].' must be a whole number.');
        if(in_array($key,['upper_limit','lower_limit'],true)&&$value!==''&&$value!=='U'&&(!is_numeric($value)||!is_finite((float)$value)))throw new InvalidArgumentException('Graph limits must be numeric or U.');
        if($key==='right_axis'&&$value!==''&&!preg_match('/^-?(?:\d+(?:\.\d*)?|\.\d+):-?(?:\d+(?:\.\d*)?|\.\d+)$/D',$value))throw new InvalidArgumentException('Right axis requires scale:shift.');
        if(in_array($key,['height','width','base_value'],true)&&(!ctype_digit($value)||(int)$value<1||(int)$value>16777215))throw new InvalidArgumentException($field['label'].' must be a positive whole number.');
        $values[$key]=$value;
    }
    return $values;
}
