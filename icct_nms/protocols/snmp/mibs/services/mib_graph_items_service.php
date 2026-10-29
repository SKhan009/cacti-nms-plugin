<?php
/** Graph item editor metadata comes from this installation's native form and schema. */
function icct_mib_core_graph_item_fields(){
    global $config,$struct_graph_item;
    if(!isset($struct_graph_item)){extract($GLOBALS,EXTR_SKIP);require $config['base_path'].'/include/global_form.php';}
    $columns=array_column(db_fetch_assoc('SHOW COLUMNS FROM graph_templates_item'),'Type','Field');$out=[];
    $base=db_fetch_row("SELECT gi.* FROM graph_templates_item gi JOIN graph_templates gt ON gt.id=gi.graph_template_id WHERE gt.name='SNMP - Generic OID Template' AND gi.local_graph_id=0 ORDER BY gi.sequence,gi.id LIMIT 1")?:[];
    foreach($struct_graph_item as $key=>$field){
        if(!isset($columns[$key])||in_array($key,['task_item_id','sequence'],true))continue;
        $choices=$field['array']??null;
        if(($field['method']??'')==='drop_sql')$choices=array_column(db_fetch_assoc($field['sql'])?:[],'name','id');
        if(($field['method']??'')==='drop_color')$choices=[0=>__('None')]+array_column(db_fetch_assoc('SELECT id,hex FROM colors ORDER BY hex,id')?:[],'hex','id');
        if(isset($field['none_value']))$choices=[0=>strip_tags($field['none_value'])]+($choices??[]);
        preg_match('/(?:var)?char\((\d+)\)/i',$columns[$key],$length);
        $default=(string)($key==='line_width'?($field['default']??''):($base[$key]??$field['default']??''));
        if(is_array($choices)&&!array_key_exists($default,$choices))$default=(string)array_key_first($choices);
        $out[$key]=['label'=>strip_tags($field['friendly_name']),'help'=>strip_tags($field['description']??''),'method'=>$field['method'],'choices'=>$choices,'default'=>$default,'max_length'=>(int)($length[1]??$field['max_length']??255),'sql_type'=>$columns[$key]];
    }
    return $out;
}
function icct_mib_graph_items($input){
    if(is_string($input))$input=json_decode($input,true,16,JSON_THROW_ON_ERROR);
    if(!is_array($input)||array_values($input)!==$input||count($input)>64)throw new InvalidArgumentException('Choose up to 64 additional graph items per data source.');
    if(!$input)return [];$fields=icct_mib_core_graph_item_fields();$out=[];
    foreach($input as $item){
        if(!is_array($item)||array_diff_key($item,$fields))throw new InvalidArgumentException('Invalid graph item fields.');$values=[];
        foreach($fields as $key=>$field){$value=$item[$key]??$field['default'];if(!is_scalar($value))throw new InvalidArgumentException('Invalid graph item '.$field['label']);$value=trim((string)$value);
            if(str_contains($value,"\0")||strlen($value)>$field['max_length'])throw new InvalidArgumentException('Invalid graph item '.$field['label']);
            if(is_array($field['choices'])&&!array_key_exists($value,$field['choices']))throw new InvalidArgumentException('Choose an installed Cacti '.$field['label']);
            if($field['method']==='checkbox'&&!in_array($value,['','on'],true))throw new InvalidArgumentException('Invalid graph item switch.');
            if(preg_match('/^(?:tiny|small|medium|big)?int/i',$field['sql_type'])&&$value!==''&&!ctype_digit($value))throw new InvalidArgumentException($field['label'].' must be a whole number.');
            if(in_array($key,['line_width','dash_offset'],true)&&$value!==''&&(!is_numeric($value)||!is_finite((float)$value)))throw new InvalidArgumentException($field['label'].' must be numeric.');
            $values[$key]=$value;
        }$out[]=$values;
    }return $out;
}
function icct_mib_native_add_graph_items($graphId,$rrdId,$items){
    if(!$items)return;$sequence=(int)db_fetch_cell_prepared('SELECT MAX(sequence) FROM graph_templates_item WHERE graph_template_id=?',[$graphId]);
    $inputs=db_fetch_assoc_prepared("SELECT id FROM graph_template_input WHERE graph_template_id=? AND column_name='task_item_id'",[$graphId])?:[];
    foreach(icct_mib_graph_items($items) as $item){
        $id=(int)sql_save($item+['id'=>0,'hash'=>bin2hex(random_bytes(16)),'graph_template_id'=>$graphId,'local_graph_id'=>0,'task_item_id'=>$rrdId,'sequence'=>++$sequence],'graph_templates_item');
        if(!$id)throw new RuntimeException('Could not save graph template item.');
        foreach($inputs as $input)icct_mib_write('INSERT INTO graph_template_input_defs(graph_template_input_id,graph_template_item_id) VALUES(?,?)',[$input['id'],$id]);
    }
}

function icct_mib_deleted_graph_items($input) {
    if (is_string($input)) $input = json_decode($input, true);
    if (!is_array($input)) return [];
    $valid = db_fetch_assoc("SELECT sequence FROM graph_templates_item WHERE local_graph_id=0 AND graph_template_id=(SELECT id FROM graph_templates WHERE name='SNMP - Generic OID Template' LIMIT 1)") ?: [];
    $allowed = array_map('intval', array_column($valid, 'sequence'));
    return array_values(array_unique(array_intersect(array_map('intval', $input), $allowed)));
}
