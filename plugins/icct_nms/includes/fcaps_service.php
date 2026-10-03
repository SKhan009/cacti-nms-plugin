<?php
/** Device-owned thresholds. Graph rendering limits are not changed by fault rules. */
function icct_nms_fault_catalogue() {
    return db_fetch_assoc("SELECT DISTINCT gt.id AS template_id,gt.name AS template_name,dtr.id AS metric_id,dtr.data_template_id,dtr.data_source_name,dtr.rrd_minimum,dtr.rrd_maximum FROM graph_templates gt JOIN graph_templates_item gti ON gti.graph_template_id=gt.id AND gti.local_graph_id=0 JOIN data_template_rrd dtr ON dtr.id=gti.task_item_id WHERE dtr.local_data_id=0 ORDER BY gt.name,dtr.data_source_name");
}
function icct_nms_fault_rules($id) {
    $rules=json_decode((string)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['fault_rules_'.$id]),true);
    return is_array($rules)?$rules:[];
}
function icct_nms_fault_validate($rules,$catalogue,$associated) {
    if (!is_array($rules) || count($rules)>100) throw new InvalidArgumentException('Use no more than 100 fault rules.');
    $out=[];
    foreach ($rules as $rule) {
        if (!is_array($rule)) throw new InvalidArgumentException('Invalid fault rule.');
        $template=icct_nms_id($rule['template_id'] ?? 0);$metric=icct_nms_id($rule['metric_id'] ?? 0);
        $valid=false;foreach($catalogue as $item) if((int)$item['template_id']===$template && (int)$item['metric_id']===$metric)$valid=true;
        if (!$valid || !in_array($template,$associated,true)) throw new InvalidArgumentException('Choose a data source from a graph template associated with this device.');
        $bounds=[];
        foreach(['minimum','maximum'] as $key) {
            $value=$rule[$key] ?? '';if($value===null || $value===''){$bounds[$key]=null;continue;}
            if(!is_scalar($value) || !is_numeric($value) || !is_finite((float)$value))throw new InvalidArgumentException('Thresholds must be finite numbers.');
            $bounds[$key]=(float)$value;
        }
        if($bounds['minimum']===null && $bounds['maximum']===null)throw new InvalidArgumentException('Enter at least one minimum or maximum threshold.');
        if($bounds['minimum']!==null && $bounds['maximum']!==null && $bounds['minimum']>=$bounds['maximum'])throw new InvalidArgumentException('Minimum must be less than maximum.');
        $severity=$rule['severity'] ?? '';if(!in_array($severity,['Information','Minor','Warning','Major','Critical'],true))throw new InvalidArgumentException('Select a fault severity.');
        $out[]=['template_id'=>$template,'metric_id'=>$metric]+$bounds+['severity'=>$severity,'enabled'=>!empty($rule['enabled'])];
    }
    return $out;
}
function icct_nms_save_faults($id,$input) {
    icct_backend_require_management(3);icct_backend_require_device_access($id);
    $rules=json_decode((string)($input['fault_rules'] ?? ''),true,512,JSON_THROW_ON_ERROR);
    $associated=array_map('intval',array_column(db_fetch_assoc_prepared('SELECT graph_template_id FROM host_graph WHERE host_id=?',[$id]),'graph_template_id'));
    $rules=icct_nms_fault_validate($rules,icct_nms_fault_catalogue(),$associated);
    icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['fault_rules_'.$id,json_encode($rules,JSON_THROW_ON_ERROR)]);
}
function icct_nms_fault_state($rule,$value) {
    if (!$rule['enabled']) return 'Disabled';
    if ($value===null || !is_numeric($value) || !is_finite((float)$value))return 'Unknown';
    return ($rule['minimum']!==null && $value<$rule['minimum']) || ($rule['maximum']!==null && $value>$rule['maximum'])?$rule['severity']:'Normal';
}
function icct_backend_collect_faults() {
    global $config;
    require_once $config['base_path'].'/lib/rrd.php';
    $collector=icct_backend_inventory_collector_id();$now=time();$interval=max(60,(int)read_config_option('poller_interval'));
    $hosts=db_fetch_assoc_prepared("SELECT h.id FROM host h JOIN plugin_icct_nms_meta m ON m.meta_key=CONCAT('fault_rules_',h.id) WHERE h.deleted='' AND h.disabled='' AND h.poller_id=?",[$collector]);
    foreach($hosts as $host) {
        $id=(int)$host['id'];$rules=icct_nms_fault_rules($id);$results=[];$cache=[];
        foreach($rules as $number=>$rule) {
            // Bind the chosen template source to actual graphs on this host; never poll another host's data.
            $sources=db_fetch_assoc_prepared('SELECT DISTINCT gl.id AS graph_id,gtg.title_cache,dtr.local_data_id,dtr.data_source_name FROM graph_local gl JOIN host_graph hg ON hg.host_id=gl.host_id AND hg.graph_template_id=gl.graph_template_id JOIN graph_templates_graph gtg ON gtg.local_graph_id=gl.id JOIN graph_templates_item gti ON gti.local_graph_id=gl.id JOIN data_template_rrd dtr ON dtr.id=gti.task_item_id JOIN data_template_rrd origin ON origin.id=? AND origin.data_template_id=dtr.data_template_id AND origin.data_source_name=dtr.data_source_name WHERE gl.host_id=? AND gl.graph_template_id=?',[$rule['metric_id'],$id,$rule['template_id']]);
            if(!$sources)$results[]=['rule'=>$number,'graph_id'=>0,'graph'=>'No graph instance yet','value'=>null,'sample_time'=>0,'state'=>$rule['enabled']?'Unknown':'Disabled'];
            foreach($sources as $source) {
                $key=(int)$source['local_data_id'];$value=null;$sample=0;
                try {
                    if(!isset($cache[$key]))$cache[$key]=rrdtool_function_fetch($key,$now-3*$interval,$now,$interval,true,null,'AVERAGE');
                    $fetch=$cache[$key];$column=array_search($source['data_source_name'],$fetch['data_source_names'] ?? [],true);
                    $points=$column===false?[]:($fetch['values'][$column] ?? []);krsort($points,SORT_NUMERIC);
                    foreach($points as $timestamp=>$point) {
                        if((int)$timestamp>$now)continue;
                        $sample=(int)$timestamp;
                        if($now-$sample<=2*$interval && is_numeric($point) && is_finite((float)$point))$value=(float)$point;
                        break; // Never substitute an older good sample for an unknown latest sample.
                    }
                } catch(Throwable $failure) { $value=null; }
                $results[]=['rule'=>$number,'graph_id'=>(int)$source['graph_id'],'graph'=>$source['title_cache'],'value'=>$value,'sample_time'=>$sample,'state'=>icct_nms_fault_state($rule,$value)];
            }
        }
        $snapshot=['time'=>$now,'rules_hash'=>hash('sha256',json_encode($rules,JSON_THROW_ON_ERROR)),'results'=>$results];
        icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['fault_status_'.$id,json_encode($snapshot,JSON_THROW_ON_ERROR)]);
    }
}
function icct_nms_fault_observations($host) {
    $id=(int)$host['id'];$rules=icct_nms_fault_rules($id);
    $data=json_decode((string)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['fault_status_'.$id]),true);
    $age=time()-(int)($data['time'] ?? 0);
    if($host['disabled']!=='' || $age<0 || $age>2*max(60,(int)read_config_option('poller_interval')) || ($data['rules_hash'] ?? '')!==hash('sha256',json_encode($rules,JSON_THROW_ON_ERROR)))return [];
    $allowed=array_map('intval',array_column(icct_nms_device_graphs($id),'local_graph_id'));
    return array_values(array_filter($data['results'] ?? [],static fn($row)=>!(int)$row['graph_id'] || in_array((int)$row['graph_id'],$allowed,true)));
}
