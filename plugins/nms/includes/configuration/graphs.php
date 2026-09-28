<?php
/** Native Cacti templates for documented numeric equipment fields. */
require_once __DIR__.'/monitoring.php';
require_once __DIR__.'/graph_schedule.php';
require_once dirname(__DIR__).'/graph_template_manager.php';
require_once $config['base_path'].'/lib/api_data_source.php';
require_once $config['base_path'].'/lib/utility.php';

function nms_config_native_save($table,array $row)
{
    $id=(int)sql_save($row,$table);
    if(!$id) throw new RuntimeException('Cacti could not save equipment graph object: '.$table);
    return $id;
}

/** Stable ownership hashes make partial provisioning safely resumable. */
function nms_config_native_object($table,$type,$identity,array $row)
{
    $hash=md5('nms:equipment:v1:'.$identity);
    $id=(int)db_fetch_cell_prepared("SELECT id FROM $table WHERE hash=?",[$hash]);
    if($id) {
        if(!nms_managed_object_exists($type,$id)) throw new RuntimeException('Equipment graph identity belongs to an unmanaged Cacti object.');
        return $id;
    }
    $id=nms_config_native_save($table,['id'=>0,'hash'=>$hash]+$row);
    nms_managed_object_record($type,$id);
    return $id;
}

/** Automatic numeric graphs need a visible line; native editor defaults allow no color. */
function nms_config_graph_color()
{
    $id=(int)db_fetch_cell("SELECT id FROM colors WHERE UPPER(hex)='0000FF' ORDER BY id LIMIT 1");
    if(!$id) $id=(int)db_fetch_cell("SELECT id FROM colors WHERE LENGTH(hex)=6 AND UPPER(hex)<>'FFFFFF' ORDER BY id LIMIT 1");
    if(!$id) throw new RuntimeException('Add a visible graph color in Cacti before creating equipment graphs.');
    return $id;
}

function nms_config_graphs_create($host_id)
{
    nms_require_management(); nms_require_device_access($host_id);
    $lock='nms_equipment_graphs';
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,10)',[$lock])!==1) throw new RuntimeException('Equipment graph provisioning is busy.');
    try {
        $target=nms_config_target($host_id);
        $profile=nms_config_graph_profile(db_fetch_assoc('SELECT id,step,heartbeat,`default` FROM data_source_profiles'),
            $target['assignment']['interval_seconds'],nms_poller_interval());
        $graphs=[];
        foreach($target['fields'] as $key=>$field) {
            if($field['type']==='string') continue;
            $model=(int)$target['profile']['id']; $revision=(int)$target['profile']['revision'];
            $identity=$model.':'.$revision.':'.$key.':'.$profile['id'];
            $title=mb_substr('NMS Equipment '.$model.' '.$field['label'].' '.$profile['step'].'s',0,150);
            $input=nms_config_native_object('data_input','data_input','input:'.$identity,[
                'name'=>$title,'type_id'=>1,'input_string'=>'"<path_php_binary>" -q "<path_cacti>/plugins/nms/serial_value.php" <host_id> '.$key.' '.$model.' '.$revision]);
            $fields=[];
            foreach(['host_id','value'] as $name) {
                $hash=md5('nms:equipment:v1:'.$identity.':'.$name);
                $fid=(int)db_fetch_cell_prepared('SELECT id FROM data_input_fields WHERE hash=? AND data_input_id=?',[$hash,$input]);
                if(!$fid) $fid=nms_config_native_save('data_input_fields',['id'=>0,'hash'=>$hash,'data_input_id'=>$input,'name'=>$name==='host_id'?'Cacti device ID':$field['label'],'data_name'=>$name,'input_output'=>$name==='host_id'?'in':'out','update_rra'=>$name==='value'?'on':'','sequence'=>1,'type_code'=>$name==='host_id'?'host_id':'','regexp_match'=>$name==='host_id'?'^[0-9]+$':'','allow_nulls'=>'']);
                $fields[$name]=$fid;
            }
            $template=nms_config_native_object('data_template','data_template','template:'.$identity,['name'=>$title]);
            $data=(int)db_fetch_cell_prepared('SELECT id FROM data_template_data WHERE data_template_id=? AND local_data_id=0',[$template]);
            if(!$data) $data=nms_config_native_save('data_template_data',['id'=>0,'data_template_id'=>$template,'local_data_id'=>0,'local_data_template_data_id'=>0,'data_input_id'=>$input,'name'=>'|host_description| - '.$field['label'],'t_name'=>'on','active'=>'on','rrd_step'=>$profile['step'],'data_source_profile_id'=>$profile['id']]);
            nms_category_execute('INSERT IGNORE INTO data_input_data(data_input_field_id,data_template_data_id,t_value,value) VALUES (?,?,?,?)',[$fields['host_id'],$data,'','']);
            $rrd=(int)db_fetch_cell_prepared('SELECT id FROM data_template_rrd WHERE data_template_id=? AND local_data_id=0',[$template]);
            if(!$rrd) $rrd=nms_config_native_save('data_template_rrd',['id'=>0,'hash'=>md5('nms:equipment:v1:rrd:'.$identity),'data_template_id'=>$template,'local_data_id'=>0,'local_data_template_rrd_id'=>0,'data_source_name'=>'value','data_source_type_id'=>1,'rrd_minimum'=>(string)$field['min'],'rrd_maximum'=>(string)$field['max'],'rrd_heartbeat'=>$profile['heartbeat'],'data_input_field_id'=>$fields['value']]);
            $graph_template=(int)db_fetch_cell_prepared("SELECT i.graph_template_id FROM graph_templates_item i JOIN plugin_nms_managed_objects m ON m.object_id=i.graph_template_id AND m.object_type='graph_template' WHERE i.task_item_id=? AND i.local_graph_id=0 LIMIT 1",[$rrd]);
            if(!$graph_template) $graph_template=nms_graph_template_create($rrd,$title,$field['unit'],['color_id'=>nms_config_graph_color()]);
            nms_category_execute('REPLACE INTO host_graph(host_id,graph_template_id) VALUES (?,?)',[$host_id,$graph_template]);
            $graph=(int)db_fetch_cell_prepared('SELECT id FROM graph_local WHERE host_id=? AND graph_template_id=?',[$host_id,$graph_template]);
            if(!$graph) {
                $suggested=[];
                $created=create_complete_graph_from_template($graph_template,$host_id,null,$suggested);
                $graph=(int)($created['local_graph_id'] ?? 0);
                if(!$graph) throw new RuntimeException('Cacti could not instantiate the equipment graph.');
                nms_managed_object_record('graph',$graph);
                foreach($created['local_data_id'] ?? [] as $data_id) { nms_managed_object_record('data_source',(int)$data_id); push_out_host($host_id,(int)$data_id); }
            }
            // Repair legacy automatic LINE1 items with no color; preserve explicit styling.
            nms_category_execute('UPDATE graph_templates_item SET color_id=? WHERE graph_template_id=? AND local_graph_id IN (0,?) AND graph_type_id=4 AND color_id=0',
                [nms_config_graph_color(),$graph_template,$graph]);
            $graphs[$key]=$graph;
        }
        if(!$graphs) throw new RuntimeException('This equipment profile has no numeric fields to graph.');
        set_config_option('time_last_change_graph',time());
        set_config_option('time_last_change_data_source',time());
        return $graphs;
    } finally { db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]); }
}
