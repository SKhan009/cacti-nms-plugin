<?php
/** Prepare reusable MIB templates before any device or live data source exists. */
require_once __DIR__.'/mib_import.php';

function nms_mib_template_bundles()
{
    $rows=db_fetch_assoc("SELECT meta_value FROM plugin_nms_meta WHERE meta_key LIKE 'mib_bundle_%' ORDER BY updated_at DESC");
    return array_values(array_filter(array_map(static fn($row)=>json_decode($row['meta_value'],true),$rows),'is_array'));
}

function nms_mib_prepare_templates($preview,$selected)
{
    nms_require_management(10);
    if(!empty($preview['host_id']))throw new InvalidArgumentException('Expected a template-only preview.');
    $name=nms_template_clean_name($preview['template_name']??'');
    $category=(int)($preview['category_id']??0);
    if(!$name || !nms_category_exists($category))throw new InvalidArgumentException('Choose a valid device type and template name.');
    $records=array_filter($preview['records'],static fn($key)=>in_array((string)$key,$selected,true),ARRAY_FILTER_USE_KEY);
    if(!$records)throw new InvalidArgumentException('Select at least one metric.');
    $lock='nms_mib_templates';
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,10)',[$lock])!==1)throw new RuntimeException('Template preparation is busy. Try again.');
    try {
        if(db_fetch_cell_prepared('SELECT id FROM host_template WHERE name=?',[$name]))throw new InvalidArgumentException('A device template already uses this name. Choose a new name.');
        if(!db_execute('START TRANSACTION'))throw new RuntimeException('Cannot begin template preparation.');
        try {
            $host_template_id=nms_template_host($name);
            nms_managed_object_record('host_template',$host_template_id);
            nms_assign_template_category($host_template_id,$category);
            $rows=[];
            foreach($records as $record) {
                $pair=nms_template_pair($name,$record);
                if($record['type']===2)nms_storage_execute("UPDATE data_template_rrd SET rrd_minimum='U',t_rrd_minimum='' WHERE data_template_id=? AND local_data_id=0",[$pair['data_template_id']]);
                if($record['units']!=='')nms_storage_execute('UPDATE graph_templates_graph SET vertical_label=? WHERE graph_template_id=? AND local_graph_id=0',[substr($record['units'].(in_array($record['type'],[65,70],true)?'/s':''),0,20),$pair['graph_template_id']]);
                nms_storage_execute('INSERT INTO host_template_graph (host_template_id,graph_template_id) VALUES (?,?)',[$host_template_id,$pair['graph_template_id']]);
                nms_managed_object_record('data_template',$pair['data_template_id']);
                nms_managed_object_record('graph_template',$pair['graph_template_id']);
                $rows[]=array_merge($pair,['oid'=>$record['oid'],'name'=>$record['section']]);
            }
            $bundle=['host_template_id'=>$host_template_id,'name'=>$name,'category_id'=>$category,'files'=>$preview['files'],'modules'=>$preview['modules'],'rows'=>$rows,'created_at'=>date('Y-m-d H:i:s')];
            $json=json_encode($bundle,JSON_THROW_ON_ERROR);
            if(strlen($json)>60000)throw new RuntimeException('Template report exceeds storage limit.');
            nms_storage_execute('INSERT INTO plugin_nms_meta (meta_key,meta_value,updated_at) VALUES (?,?,NOW())',['mib_bundle_'.$host_template_id,$json]);
            if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit templates.');
        } catch(Throwable $e) {db_execute('ROLLBACK');throw $e;}
    } finally {db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
    set_config_option('time_last_change_graph',time());
    set_config_option('time_last_change_data_source',time());
    return $bundle;
}
